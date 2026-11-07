<?php

namespace App\Domain\HR\Application\Reporting;

use App\Domain\HR\Application\EmployeePlacementLock;
use App\Domain\HR\Application\Exceptions\InvalidReportingChangeException;
use App\Domain\HR\Application\Exceptions\ReportingHierarchyCycleException;
use App\Domain\HR\Application\HrEmployeeAccess;
use App\Domain\HR\Application\HrEmployeeCampusGuard;
use App\Domain\HR\Application\HrSelfAdministrationGuard;
use App\Domain\HR\Events\EmployeeReportingLineChanged;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\ReportingRelationship;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * HR.3A: the ONLY writer of `reporting_relationships` -- dated, reasoned,
 * employment-to-employment reporting lines (docs/modules/HR.md "Reporting
 * relationships (HR.3A)"). Every operation is named:
 *
 *   assign()   from a date: the subordinate's line covering it (if any) ends
 *              the day before (`changed`) and a new line to the manager
 *              starts; past, today or future
 *   remove()   from a date: the line covering it ends the day before
 *   correct()  a wrong line is voided (kept as evidence) and, when a manager
 *              is given, replaced by a line over the same dates
 *   closeForEmploymentEnd()  inside EmploymentService::end(): every line of
 *              the employment (either side) ends with it
 *
 * Nothing is edited in place and nothing is deleted: history answers any
 * date for ever (ReportingAsOfReader). A change never rewrites a later line:
 * when one exists the change is refused (`later_line_exists`) -- correct or
 * remove the later line first.
 *
 * In ONE transaction, in the HR lock order: the Employee placement locks of
 * both people (sorted; FOR SHARE, or FOR NO KEY UPDATE for a Campus-scoped
 * actor whose decision rests on their Campus sets) -> the self-administration
 * check -> the School's reporting advisory lock (the one the database
 * trigger takes, so concurrent writers serialize and the second sees the
 * first's committed lines) -> both employment rows FOR SHARE (sorted; an
 * employment end waits, or committed first and is seen) -> the line rows
 * FOR UPDATE. The database (`trg_reporting_relationships_guard`) re-checks
 * overlap, both employments' dates, the same-Employee rule and loops on every
 * day as a backstop.
 *
 * Authorization: `hr.employees.assignments.manage`. A Campus-scoped actor
 * assigns or removes from today on, for a subordinate wholly in scope and a
 * manager current on an in-scope Campus (HrEmployeeCampusGuard); a
 * correction rewrites history and is whole-School. Never a line one is on
 * either side of (rule 95). A reporting line grants no capability and no
 * data access.
 */
final class ReportingRelationshipService
{
    use AuthorizesCampusScope;

    private const CAPABILITY = 'hr.employees.assignments.manage';

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly HrSelfAdministrationGuard $selfAdministration,
        private readonly HrEmployeeAccess $access,
        private readonly EmployeePlacementLock $placement,
    ) {}

    /** Assigns (or changes) the subordinate's manager from $effectiveOn on. */
    public function assign(EmploymentRecord $subordinate, EmploymentRecord $manager, string $effectiveOn, ReportingChangeReason $reason, User $actor): ReportingRelationship
    {
        $school = $subordinate->school;
        $guard = $this->access->writeGuard($school, $this->authorizeCampusScopeFor($actor, self::CAPABILITY, $school));
        $effectiveOn = self::day($effectiveOn);
        if ($manager->school_id !== $subordinate->school_id) {
            throw new InvalidReportingChangeException('cross_school', 'That manager could not be found in this School.');
        }

        return $this->context->withSchool($school, fn (): ReportingRelationship => DB::transaction(function () use ($school, $subordinate, $manager, $effectiveOn, $reason, $actor, $guard): ReportingRelationship {
            [$sub, $mgr] = $this->lockParties($school, $subordinate, $manager, $actor, $guard);
            if ($sub->employee_id === $mgr->employee_id) {
                throw new InvalidReportingChangeException('same_employee', 'An Employee cannot report to themselves.');
            }
            $guard?->beforeReportingChange($sub, $mgr, $effectiveOn);
            $until = self::earliest($sub->ends_on?->toDateString(), $mgr->ends_on?->toDateString());
            if ($effectiveOn < $sub->starts_on->toDateString() || $effectiveOn < $mgr->starts_on->toDateString() || ($until !== null && $effectiveOn > $until)) {
                throw new InvalidReportingChangeException('outside_employment', 'Both people must be employed on the effective date.');
            }

            $lines = $this->lockLiveLines($school, $sub->id);
            $current = $this->covering($lines, $effectiveOn);
            $this->refuseLaterLines($lines, $effectiveOn);
            if ($current !== null && $current->manager_employment_id === $mgr->id && $current->ends_on === null) {
                throw new InvalidReportingChangeException('unchanged', 'That person is already the manager on that date.');
            }
            if ($this->loops($school->id, $sub->id, $mgr->id, $effectiveOn, $until, null)) {
                throw new ReportingHierarchyCycleException($sub->id, $mgr->id);
            }

            if ($current !== null) {
                $this->endLine($current, self::dayBefore($effectiveOn), 'changed', $reason->value, $actor);
            }
            $line = $this->insert($school, [
                'subordinate_employment_id' => $sub->id,
                'manager_employment_id' => $mgr->id,
                'starts_on' => $effectiveOn,
                'ends_on' => $until,
                'started_via' => $current === null ? 'assigned' : 'changed',
                'start_reason' => $reason->value,
                'started_by_user_id' => $actor->id,
            ]);

            $operation = $current === null ? 'assigned' : 'changed';
            $this->record($school, $actor, $line, "hr.reporting.{$operation}", $sub, $operation, $effectiveOn, $current?->manager_employment_id, $mgr->id, [
                'reason' => $reason->value,
                'previousRelationshipId' => $current?->id,
            ]);

            return $line->fresh() ?? $line;
        }));
    }

    /** Ends the subordinate's reporting line from $effectiveOn on (no manager from that day). */
    public function remove(EmploymentRecord $subordinate, string $effectiveOn, ReportingChangeReason $reason, User $actor): ReportingRelationship
    {
        $school = $subordinate->school;
        $guard = $this->access->writeGuard($school, $this->authorizeCampusScopeFor($actor, self::CAPABILITY, $school));
        $effectiveOn = self::day($effectiveOn);

        return $this->context->withSchool($school, fn (): ReportingRelationship => DB::transaction(function () use ($school, $subordinate, $effectiveOn, $reason, $actor, $guard): ReportingRelationship {
            $lines = $this->lockLiveLines($school, $subordinate->id, preview: true);
            $current = $this->covering($lines, $effectiveOn);
            if ($current === null) {
                throw new InvalidReportingChangeException('no_line_on_date', 'There is no manager on that date to remove.');
            }
            $manager = EmploymentRecord::query()->where('school_id', $school->id)->whereKey($current->manager_employment_id)->firstOrFail();
            [$sub] = $this->lockParties($school, $subordinate, $manager, $actor, $guard);
            $guard?->beforeReportingChange($sub, null, $effectiveOn);

            $lines = $this->lockLiveLines($school, $sub->id);
            $current = $this->covering($lines, $effectiveOn);
            if ($current === null || $current->manager_employment_id !== $manager->id) {
                throw new InvalidReportingChangeException('no_line_on_date', 'The reporting line changed while this was being recorded; try again.');
            }
            $this->refuseLaterLines($lines, $effectiveOn);

            $this->endLine($current, self::dayBefore($effectiveOn), 'removed', $reason->value, $actor);
            $this->record($school, $actor, $current, 'hr.reporting.removed', $sub, 'removed', $effectiveOn, $current->manager_employment_id, null, [
                'reason' => $reason->value,
            ]);

            return $current->fresh() ?? $current;
        }));
    }

    /**
     * Voids a recorded line as wrong (it stays, marked void, as evidence) and,
     * when $manager is given, records the corrected line over the SAME dates.
     * Whole-School only: it rewrites history.
     */
    public function correct(ReportingRelationship $line, ?EmploymentRecord $manager, ReportingChangeReason $reason, User $actor): ?ReportingRelationship
    {
        $school = $line->school;
        $this->access->requireWholeSchool($actor, self::CAPABILITY, $school);
        if ($manager !== null && $manager->school_id !== $line->school_id) {
            throw new InvalidReportingChangeException('cross_school', 'That manager could not be found in this School.');
        }

        return $this->context->withSchool($school, fn (): ?ReportingRelationship => DB::transaction(function () use ($school, $line, $manager, $reason, $actor): ?ReportingRelationship {
            $subordinate = EmploymentRecord::query()->where('school_id', $school->id)->whereKey($line->subordinate_employment_id)->firstOrFail();
            $previousManager = EmploymentRecord::query()->where('school_id', $school->id)->whereKey($line->manager_employment_id)->firstOrFail();
            $this->selfAdministration->refuseOwnEmployee($actor, $previousManager->employee_id, 'reporting_line');
            [$sub, $mgr] = $this->lockParties($school, $subordinate, $manager ?? $previousManager, $actor, null);

            $locked = ReportingRelationship::query()->where('school_id', $school->id)->whereKey($line->id)->lockForUpdate()->firstOrFail();
            if ($locked->voided_at !== null) {
                throw new InvalidReportingChangeException('already_voided', 'That reporting line was already corrected.');
            }
            $startsOn = $locked->starts_on->toDateString();
            $endsOn = $locked->ends_on?->toDateString();
            if ($manager !== null) {
                if ($mgr->id === $locked->manager_employment_id) {
                    throw new InvalidReportingChangeException('unchanged', 'That person is already the manager on this line.');
                }
                if ($sub->employee_id === $mgr->employee_id) {
                    throw new InvalidReportingChangeException('same_employee', 'An Employee cannot report to themselves.');
                }
                if (($endsOn === null || $endsOn >= $startsOn) && ($startsOn < $mgr->starts_on->toDateString()
                    || ($mgr->ends_on !== null && ($endsOn === null || $endsOn > $mgr->ends_on->toDateString())))) {
                    throw new InvalidReportingChangeException('outside_employment', 'The corrected manager must be employed for the whole line.');
                }
                if ($this->loops($school->id, $sub->id, $mgr->id, $startsOn, $endsOn, $locked->id)) {
                    throw new ReportingHierarchyCycleException($sub->id, $mgr->id);
                }
            }

            $locked->forceFill(['voided_at' => now(), 'voided_by_user_id' => $actor->id, 'void_reason' => $reason->value])->save();
            $replacement = null;
            if ($manager !== null) {
                $replacement = $this->insert($school, [
                    'subordinate_employment_id' => $sub->id,
                    'manager_employment_id' => $mgr->id,
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                    'started_via' => 'corrected',
                    'start_reason' => $reason->value,
                    'started_by_user_id' => $actor->id,
                    'ended_via' => $locked->ended_via,
                    'end_reason' => $locked->end_reason,
                    'ended_by_user_id' => $locked->ended_by_user_id,
                ]);
            }

            $this->record($school, $actor, $replacement ?? $locked, 'hr.reporting.corrected', $sub, 'corrected', $startsOn, $locked->manager_employment_id, $replacement?->manager_employment_id, [
                'reason' => $reason->value,
                'voidedRelationshipId' => $locked->id,
                'replacementRelationshipId' => $replacement?->id,
            ]);

            return $replacement?->fresh();
        }));
    }

    /**
     * Inside EmploymentService::end(), after the employment row is locked FOR
     * UPDATE and BEFORE its `ends_on` is written (the database refuses an end
     * that a live line outlives): every live line of the employment, on
     * either side, ends with it -- a line starting after the end is cancelled
     * (the empty interval). A manager's departure leaves the reports with no
     * manager; nobody is silently re-parented. Not authorized here: the
     * caller's authority (whole-School employment end) covers it. Takes no
     * advisory lock (the employment row lock already serializes it with every
     * writer, which share-locks the employment before writing).
     *
     * @return int the number of lines ended
     */
    public function closeForEmploymentEnd(School $school, EmploymentRecord $lockedEmployment, string $endsOn, User $actor): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Reporting lines close only inside the employment end transaction.');
        }
        $endsOn = self::day($endsOn);

        $lines = ReportingRelationship::query()->where('school_id', $school->id)->live()
            ->where(fn ($q) => $q->where('subordinate_employment_id', $lockedEmployment->id)->orWhere('manager_employment_id', $lockedEmployment->id))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>', $endsOn))
            ->orderBy('id')->lockForUpdate()->get();

        $subordinateEmployees = EmploymentRecord::query()->where('school_id', $school->id)
            ->whereIn('id', $lines->pluck('subordinate_employment_id')->unique()->all())->pluck('employee_id', 'id');

        foreach ($lines as $line) {
            $startsOn = $line->starts_on->toDateString();
            $newEnd = $startsOn > $endsOn ? self::dayBefore($startsOn) : $endsOn;
            $via = $line->subordinate_employment_id === $lockedEmployment->id ? 'subordinate_employment_ended' : 'manager_employment_ended';
            $line->forceFill(['ends_on' => $newEnd, 'ended_via' => $via, 'end_reason' => null, 'ended_by_user_id' => $actor->id])->save();

            $employeeId = (string) $subordinateEmployees->get($line->subordinate_employment_id);
            $this->audit->school($school, 'hr.reporting.ended', actor: $actor, subject: $line, metadata: [
                'employeeId' => $employeeId,
                'subordinateEmploymentId' => $line->subordinate_employment_id,
                'reportingRelationshipId' => $line->id,
                'managerEmploymentId' => $line->manager_employment_id,
                'operation' => $via,
                'endsOn' => $newEnd,
            ]);
            event(new EmployeeReportingLineChanged($school->id, $employeeId, $line->subordinate_employment_id, $via, self::dayAfter($newEnd), $line->manager_employment_id, null));
        }

        return $lines->count();
    }

    /**
     * Placement locks (manager first), self-administration, the reporting lock, then
     * both employments FOR SHARE (sorted), re-read.
     *
     * @return array{0: EmploymentRecord, 1: EmploymentRecord}
     */
    private function lockParties(School $school, EmploymentRecord $subordinate, EmploymentRecord $manager, User $actor, ?HrEmployeeCampusGuard $guard): array
    {
        // HR.R1-C9: the manager's Employee first, never id order. A decider on this line already holds the manager's
        // and goes on to the subordinate's (ReportingLine::holdManagerOf()), so id order deadlocked with a Campus
        // writer, which takes these FOR NO KEY UPDATE, whenever the subordinate's id was the lower.
        $employees = array_values(array_unique([$manager->employee_id, $subordinate->employee_id]));
        foreach ($employees as $employeeId) {
            $guard !== null ? $this->placement->forEmployeeWrite($school, $employeeId) : $this->placement->forPlacementChange($school, $employeeId);
        }
        // SR.4 (ADR 0071 §26.2): never a reporting line you are on either side of.
        foreach ($employees as $employeeId) {
            $this->selfAdministration->refuseOwnEmployee($actor, $employeeId, 'reporting_line');
        }

        DB::select("SELECT pg_advisory_xact_lock(hashtextextended('hr.reporting:' || ?::text, 0))", [$school->id]);

        $ids = array_values(array_unique([$subordinate->id, $manager->id]));
        sort($ids);
        $locked = [];
        foreach ($ids as $id) {
            $locked[$id] = EmploymentRecord::query()->where('school_id', $school->id)->whereKey($id)->sharedLock()->first()
                ?? throw (new ModelNotFoundException)->setModel(EmploymentRecord::class, [$id]);
        }

        return [$locked[$subordinate->id], $locked[$manager->id]];
    }

    /**
     * The subordinate's live lines (oldest first), FOR UPDATE unless only
     * previewing which manager to lock.
     *
     * @return list<ReportingRelationship>
     */
    private function lockLiveLines(School $school, string $subordinateEmploymentId, bool $preview = false): array
    {
        return ReportingRelationship::query()->where('school_id', $school->id)->where('subordinate_employment_id', $subordinateEmploymentId)
            ->live()->orderBy('starts_on')->when(! $preview, fn ($q) => $q->lockForUpdate())->get()->all();
    }

    /** @param list<ReportingRelationship> $lines */
    private function covering(array $lines, string $date): ?ReportingRelationship
    {
        foreach ($lines as $line) {
            if ($line->starts_on->toDateString() <= $date && ($line->ends_on === null || $line->ends_on->toDateString() >= $date)) {
                return $line;
            }
        }

        return null;
    }

    /** @param list<ReportingRelationship> $lines */
    private function refuseLaterLines(array $lines, string $date): void
    {
        foreach ($lines as $line) {
            if ($line->starts_on->toDateString() > $date) {
                throw new InvalidReportingChangeException('later_line_exists', 'A later reporting line is already recorded; correct or remove it first.');
            }
        }
    }

    private function endLine(ReportingRelationship $line, string $endsOn, string $via, ?string $reason, User $actor): void
    {
        $line->forceFill(['ends_on' => $endsOn, 'ended_via' => $via, 'end_reason' => $reason, 'ended_by_user_id' => $actor->id])->save();
    }

    /** @param array<string, mixed> $attributes */
    private function insert(School $school, array $attributes): ReportingRelationship
    {
        try {
            $line = new ReportingRelationship;
            $line->forceFill(['school_id' => $school->id] + $attributes)->save();

            return $line;
        } catch (QueryException $e) {
            // The database backstop (another writer cannot reach here first: the advisory lock serializes).
            if ($e->getCode() === '23P01') {
                throw new InvalidReportingChangeException('overlap', 'Another reporting line already covers part of this period; try again.');
            }
            if ($e->getCode() === '23514' && str_contains($e->getMessage(), 'never loops')) {
                throw new ReportingHierarchyCycleException((string) $attributes['subordinate_employment_id'], (string) $attributes['manager_employment_id']);
            }
            throw $e;
        }
    }

    /**
     * Would $manager's own chain, on any day of [$from, $to], reach
     * $subordinate? (The same date-aware walk as the database trigger.)
     */
    private function loops(string $schoolId, string $subordinateId, string $managerId, string $from, ?string $to, ?string $ignoreLineId): bool
    {
        $row = DB::selectOne(<<<'SQL'
            WITH RECURSIVE walk(node, lo, hi, depth) AS (
                SELECT ?::uuid, ?::date, COALESCE(?::date, 'infinity'::date), 0
                UNION ALL
                SELECT r.manager_employment_id, GREATEST(w.lo, r.starts_on), LEAST(w.hi, COALESCE(r.ends_on, 'infinity'::date)), w.depth + 1
                  FROM walk w JOIN reporting_relationships r
                    ON r.school_id = ? AND r.subordinate_employment_id = w.node AND r.voided_at IS NULL
                   AND r.starts_on <= w.hi AND COALESCE(r.ends_on, 'infinity'::date) >= w.lo AND (r.ends_on IS NULL OR r.ends_on >= r.starts_on)
                   AND r.id IS DISTINCT FROM ?::uuid
                 WHERE w.depth < 1000 AND w.node <> ?::uuid
            )
            SELECT EXISTS (SELECT 1 FROM walk WHERE node = ?::uuid) AS loops
            SQL, [$managerId, $from, $to, $schoolId, $ignoreLineId, $subordinateId, $subordinateId]);

        return (bool) $row->loops;
    }

    /**
     * One audit event (ids and closed codes only) and one outbox event, both on
     * the SUBORDINATE's Employee timeline.
     *
     * @param  array<string, mixed>  $extra
     */
    private function record(School $school, User $actor, ReportingRelationship $subject, string $eventType, EmploymentRecord $subordinate, string $operation, string $effectiveOn, ?string $previousManagerEmploymentId, ?string $managerEmploymentId, array $extra): void
    {
        $this->audit->school($school, $eventType, actor: $actor, subject: $subject, metadata: [
            'employeeId' => $subordinate->employee_id,
            'subordinateEmploymentId' => $subordinate->id,
            'reportingRelationshipId' => $subject->id,
            'effectiveOn' => $effectiveOn,
            'previousManagerEmploymentId' => $previousManagerEmploymentId,
            'managerEmploymentId' => $managerEmploymentId,
        ] + $extra);
        event(new EmployeeReportingLineChanged($school->id, $subordinate->employee_id, $subordinate->id, $operation, $effectiveOn, $previousManagerEmploymentId, $managerEmploymentId));
    }

    private static function day(string $date): string
    {
        return CarbonImmutable::parse($date)->toDateString();
    }

    private static function dayBefore(string $date): string
    {
        return CarbonImmutable::parse($date)->subDay()->toDateString();
    }

    private static function dayAfter(string $date): string
    {
        return CarbonImmutable::parse($date)->addDay()->toDateString();
    }

    private static function earliest(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }

        return $b === null ? $a : min($a, $b);
    }
}
