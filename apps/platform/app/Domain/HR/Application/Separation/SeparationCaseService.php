<?php

namespace App\Domain\HR\Application\Separation;

use App\Domain\HR\Application\EmployeePlacementLock;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\HrEmployeeAccess;
use App\Domain\HR\Application\HrSelfAdministrationGuard;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\EmploymentSeparationCase;
use App\Domain\HR\Infrastructure\EmploymentSeparationEvent;
use App\Domain\HR\Infrastructure\SeparationClearanceItem;
use App\Domain\HR\Infrastructure\SeparationClearanceRequirement;
use App\Domain\Identity\Application\Staff\StaffAccessState;
use App\Models\School;
use App\Models\User;
use App\Support\Approval\ApprovalDecisionAuthority;
use App\Support\Approval\ApprovalParticipants;
use App\Support\Approval\ApprovalProvenance;
use App\Support\Approval\MakerCheckerPolicy;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * HR.12 (owner decisions HR-061, 2026-11-04; ADR 0028 HR.12 amendment): the
 * one writer of separation cases and their clearance items.
 *
 *   record -> requested --approve--> approved (employment: notice_period; the last day is now decided)
 *                       --reject---> rejected
 *                       --withdraw-> withdrawn
 *   approved --cancel----> cancelled (employment: back to active)
 *            --finalize--> finalized (EmploymentService::end() on the planned last day)
 *   finalized --complete-> completed (mandatory clearance cleared, nothing open)
 *
 * Every operation: the whole-School capability (Gate), then in ONE
 * transaction the Employee placement lock (the EmploymentService::end()
 * lock order), "never one's own employee", the employment FOR UPDATE, the
 * case FOR UPDATE, the stale-form version. A decision re-reads the decider's
 * authority (ApprovalDecisionAuthority) and runs the shared maker/checker:
 * the requester never decides. The database backs the graph, maker/checker
 * and "never the subject" (trg_employment_separation_cases_guard).
 *
 * A separation never decides, cancels or adjusts another module's records:
 * it shows their owners' answers (SeparationObligations).
 */
final class SeparationCaseService
{
    public const string WORKFLOW = 'employment_separation_cases';

    public function __construct(
        private readonly SchoolOperationalGuard $operational,
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly HrEmployeeAccess $access,
        private readonly HrSelfAdministrationGuard $selfAdministration,
        private readonly EmployeePlacementLock $placement,
        private readonly EmploymentService $employments,
        private readonly ApprovalDecisionAuthority $authority,
        private readonly MakerCheckerPolicy $makerChecker,
        private readonly SeparationObligations $obligations,
        private readonly StaffAccessState $staffAccess,
    ) {}

    /** The material participants of a case: whoever recorded it never decides it. */
    public static function participants(EmploymentSeparationCase $case): ApprovalParticipants
    {
        return new ApprovalParticipants(self::WORKFLOW, $case->id, ['requester' => [$case->requested_by_user_id]]);
    }

    public function record(School $school, string $employmentRecordId, SeparationReason $reason, string $notifiedOn, string $plannedLastDay, User $actor): EmploymentSeparationCase
    {
        $this->access->requireWholeSchool($actor, SeparationCapabilities::MANAGE, $school);
        $notifiedOn = self::date($notifiedOn);
        $plannedLastDay = self::date($plannedLastDay);

        return $this->inSchool($school, function () use ($school, $employmentRecordId, $reason, $notifiedOn, $plannedLastDay, $actor): EmploymentSeparationCase {
            $employment = $this->lockEmployment($school, $employmentRecordId, $actor, 'separation.record');

            if ($employment->status !== 'active' || $employment->ends_on !== null) {
                throw SeparationException::conflict('SEPARATION_EMPLOYMENT_NOT_ACTIVE', 'A separation is recorded only for a current, active employment.');
            }
            if ($notifiedOn > SchoolTimezone::today($school)) {
                throw SeparationException::invalid('SEPARATION_NOTIFIED_IN_FUTURE', 'The date notice was given cannot be in the future.');
            }
            $this->assertPlannedDay($employment, $notifiedOn, $plannedLastDay);

            try {
                $case = DB::transaction(function () use ($employment, $reason, $notifiedOn, $plannedLastDay, $actor): EmploymentSeparationCase {
                    return EmploymentSeparationCase::query()->forceCreate([
                        'employment_record_id' => $employment->id,
                        'reason_code' => $reason->value,
                        'notified_on' => $notifiedOn,
                        'planned_last_day' => $plannedLastDay,
                        'requested_by_user_id' => $actor->id,
                        'requested_at' => now(),
                    ]);
                });
            } catch (UniqueConstraintViolationException) {
                throw SeparationException::conflict('SEPARATION_CASE_EXISTS', 'This employment already has a separation case in progress.');
            } catch (QueryException $e) {
                // WO-18 review L-1: the database's own refusal (a link that changed after the service read it).
                if (str_contains($e->getMessage(), 'hr_employment_separation_own_case')) {
                    throw self::ownCase();
                }
                throw $e;
            }

            $this->event($case, 'requested', $actor);
            $this->audit->school($school, 'hr.separation.requested', actor: $actor, subject: $case, metadata: [
                'employmentRecordId' => $employment->id,
                'employeeId' => $employment->employee_id,
                'reason' => $reason->value,
                ...ApprovalProvenance::metadata(self::participants($case), ApprovalProvenance::REQUESTED),
            ]);

            return $case->refresh();
        });
    }

    /** HR-061: another person approves; the employment enters notice_period and the clearance checklist is taken. */
    public function approve(School $school, string $caseId, int $expectedVersion, User $actor, FreshMfaProof $mfa): EmploymentSeparationCase
    {
        $this->access->requireWholeSchool($actor, SeparationCapabilities::APPROVE, $school);

        return $this->inSchool($school, function () use ($school, $caseId, $expectedVersion, $actor, $mfa): EmploymentSeparationCase {
            [$case, $employment] = $this->lockCase($school, $caseId, $expectedVersion, $actor, 'separation.approve', ['requested']);
            $this->assertMayDecide($school, $case, $actor);

            if ($employment->status !== 'active' || $employment->ends_on !== null) {
                throw SeparationException::conflict('SEPARATION_EMPLOYMENT_NOT_ACTIVE', 'This employment is no longer active, so the separation cannot be approved.');
            }

            $this->transition($case, 'approved', ['decided_by_user_id' => $actor->id, 'decided_at' => now()]);
            $this->employments->startNoticePeriod($school, $employment, $case->id, $actor);
            $items = $this->snapshotClearance($school, $case, $employment);

            $this->event($case, 'approved', $actor);
            $this->audit->school($school, 'hr.separation.approved', actor: $actor, subject: $case, metadata: [
                'employmentRecordId' => $employment->id,
                'clearanceItemCount' => $items,
                ...ApprovalProvenance::metadata(self::participants($case), ApprovalProvenance::APPROVED, $actor, null, $mfa),
            ]);

            return $case->refresh();
        });
    }

    public function reject(School $school, string $caseId, int $expectedVersion, User $actor): EmploymentSeparationCase
    {
        $this->access->requireWholeSchool($actor, SeparationCapabilities::APPROVE, $school);

        return $this->inSchool($school, function () use ($school, $caseId, $expectedVersion, $actor): EmploymentSeparationCase {
            [$case] = $this->lockCase($school, $caseId, $expectedVersion, $actor, 'separation.reject', ['requested']);
            $this->assertMayDecide($school, $case, $actor);

            $this->transition($case, 'rejected', ['decided_by_user_id' => $actor->id, 'decided_at' => now()]);
            $this->event($case, 'rejected', $actor);
            $this->audit->school($school, 'hr.separation.rejected', actor: $actor, subject: $case, metadata: [
                'employmentRecordId' => $case->employment_record_id,
                ...ApprovalProvenance::metadata(self::participants($case), ApprovalProvenance::REJECTED, $actor),
            ]);

            return $case->refresh();
        });
    }

    /** Before a decision: the recorded resignation is withdrawn. The employment never changed. */
    public function withdraw(School $school, string $caseId, int $expectedVersion, User $actor): EmploymentSeparationCase
    {
        $this->access->requireWholeSchool($actor, SeparationCapabilities::MANAGE, $school);

        return $this->inSchool($school, function () use ($school, $caseId, $expectedVersion, $actor): EmploymentSeparationCase {
            [$case] = $this->lockCase($school, $caseId, $expectedVersion, $actor, 'separation.withdraw', ['requested']);

            $this->transition($case, 'withdrawn', ['withdrawn_by_user_id' => $actor->id, 'withdrawn_at' => now()]);
            $this->event($case, 'withdrawn', $actor);
            $this->audit->school($school, 'hr.separation.withdrawn', actor: $actor, subject: $case, metadata: ['employmentRecordId' => $case->employment_record_id]);

            return $case->refresh();
        });
    }

    /**
     * Before the decision, the planned last day may change; before and after
     * stay in the case timeline. Once approved it is a DECIDED term (the
     * approver approved that day): changing it means cancelling the case and
     * recording a new one, which another person decides again (security
     * review, HR.12).
     */
    public function changePlannedLastDay(School $school, string $caseId, int $expectedVersion, string $plannedLastDay, User $actor): EmploymentSeparationCase
    {
        $this->access->requireWholeSchool($actor, SeparationCapabilities::MANAGE, $school);
        $plannedLastDay = self::date($plannedLastDay);

        return $this->inSchool($school, function () use ($school, $caseId, $expectedVersion, $plannedLastDay, $actor): EmploymentSeparationCase {
            [$case, $employment] = $this->lockCase($school, $caseId, $expectedVersion, $actor, 'separation.change_last_day', ['requested']);
            $before = $case->planned_last_day->toDateString();
            if ($before === $plannedLastDay) {
                throw SeparationException::invalid('SEPARATION_LAST_DAY_UNCHANGED', 'Choose a different last working day.');
            }
            $this->assertPlannedDay($employment, $case->notified_on->toDateString(), $plannedLastDay);

            $this->transition($case, $case->status, ['planned_last_day' => $plannedLastDay]);
            EmploymentSeparationEvent::query()->create([
                'case_id' => $case->id, 'kind' => 'planned_day_changed', 'actor_user_id' => $actor->id, 'occurred_at' => now(),
                'planned_last_day_before' => $before, 'planned_last_day_after' => $plannedLastDay,
            ]);
            $this->audit->school($school, 'hr.separation.last_day_changed', actor: $actor, subject: $case, metadata: [
                'employmentRecordId' => $case->employment_record_id,
                'changedFields' => ['planned_last_day'],
            ]);

            return $case->refresh();
        });
    }

    /** An approved, not yet finalized separation is cancelled: the employment returns to active. */
    public function cancel(School $school, string $caseId, int $expectedVersion, SeparationCancelReason $reason, User $actor, FreshMfaProof $mfa): EmploymentSeparationCase
    {
        $this->access->requireWholeSchool($actor, SeparationCapabilities::MANAGE, $school);

        return $this->inSchool($school, function () use ($school, $caseId, $expectedVersion, $reason, $actor, $mfa): EmploymentSeparationCase {
            [$case, $employment] = $this->lockCase($school, $caseId, $expectedVersion, $actor, 'separation.cancel', ['approved']);

            $this->transition($case, 'cancelled', ['cancelled_by_user_id' => $actor->id, 'cancelled_at' => now(), 'cancel_reason' => $reason->value]);
            $this->employments->endNoticePeriod($school, $employment, $case->id, $actor);
            $this->event($case, 'cancelled', $actor);
            $this->audit->school($school, 'hr.separation.cancelled', actor: $actor, subject: $case, metadata: [
                'employmentRecordId' => $employment->id,
                'reason' => $reason->value,
                'freshMfa' => $mfa->isFor($actor->id),
            ]);

            return $case->refresh();
        });
    }

    /**
     * The separation takes effect: on or after the planned last day, through
     * the canonical EmploymentService::end() (reporting lines, assignments and
     * teaching ownership close there, in this transaction). Clearance and
     * other modules' obligations never block it (owner decision): they block
     * completion only.
     */
    public function finalize(School $school, string $caseId, int $expectedVersion, User $actor, FreshMfaProof $mfa): EmploymentSeparationCase
    {
        $this->access->requireWholeSchool($actor, SeparationCapabilities::MANAGE, $school);

        return $this->inSchool($school, function () use ($school, $caseId, $expectedVersion, $actor, $mfa): EmploymentSeparationCase {
            [$case, $employment] = $this->lockCase($school, $caseId, $expectedVersion, $actor, 'separation.finalize', ['approved']);
            $lastDay = $case->planned_last_day->toDateString();
            if ($lastDay > SchoolTimezone::today($school)) {
                throw SeparationException::conflict('SEPARATION_LAST_DAY_NOT_REACHED', 'The separation can be finalized on or after the planned last working day.');
            }

            $status = SeparationReason::from($case->reason_code)->terminalStatus();
            $this->employments->end($employment, $lastDay, $actor, $status);

            $this->transition($case, 'finalized', ['finalized_by_user_id' => $actor->id, 'finalized_at' => now(), 'final_ends_on' => $lastDay]);
            $this->event($case, 'finalized', $actor);
            $this->audit->school($school, 'hr.separation.finalized', actor: $actor, subject: $case, metadata: [
                'employmentRecordId' => $employment->id,
                'endsOn' => $lastDay,
                'status' => $status,
                'freshMfa' => $mfa->isFor($actor->id),
            ]);

            return $case->refresh();
        });
    }

    /** One administrative clearance item is cleared by the holder of its responsibility's capability. */
    public function clearItem(School $school, string $caseId, string $itemId, User $actor): SeparationClearanceItem
    {
        return $this->inSchool($school, function () use ($school, $caseId, $itemId, $actor): SeparationClearanceItem {
            $item = SeparationClearanceItem::query()->where('school_id', $school->id)->where('case_id', $caseId)->whereKey($itemId)->first()
                ?? throw SeparationException::notFound();
            $this->access->requireWholeSchool($actor, ClearanceResponsibility::from($item->responsibility)->capability(), $school);

            $case = EmploymentSeparationCase::query()->where('school_id', $school->id)->whereKey($caseId)->first() ?? throw SeparationException::notFound();
            $this->lockEmployment($school, $case->employment_record_id, $actor, 'separation.clear');
            $case = EmploymentSeparationCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            if (! in_array($case->status, ['approved', 'finalized'], true)) {
                throw SeparationException::conflict('SEPARATION_CLEARANCE_CLOSED', 'Clearance is recorded on an approved or finalized separation.');
            }
            $item = SeparationClearanceItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($item->status !== 'pending') {
                throw SeparationException::conflict('SEPARATION_CLEARANCE_ALREADY_CLEARED', 'This clearance item was already cleared.');
            }

            try {
                $item->forceFill(['status' => 'cleared', 'cleared_by_user_id' => $actor->id, 'cleared_at' => now()])->save();
            } catch (QueryException $e) {
                // HR.R1-C35 WO-18: the database's own refusal (a link that changed after the service read it).
                if (str_contains($e->getMessage(), 'hr_separation_clearance_own_case')) {
                    throw self::ownCase();
                }
                throw $e;
            }
            EmploymentSeparationEvent::query()->create(['case_id' => $case->id, 'kind' => 'clearance_cleared', 'clearance_item_id' => $item->id, 'actor_user_id' => $actor->id, 'occurred_at' => now()]);
            $this->audit->school($school, 'hr.separation.clearance_cleared', actor: $actor, subject: $case, metadata: [
                'clearanceItemId' => $item->id,
                'code' => $item->code,
                'responsibility' => $item->responsibility,
            ]);

            return $item->refresh();
        });
    }

    /**
     * A finalized separation whose account still holds School staff access:
     * an access administrator records that the access is KEPT deliberately
     * (e.g. a continuing non-employee role). Off-boarding itself stays under
     * Staff accounts (rule 92); this never suspends or keeps anything.
     */
    public function recordAccessRetained(School $school, string $caseId, int $expectedVersion, User $actor): EmploymentSeparationCase
    {
        $this->access->requireWholeSchool($actor, 'school.members.manage', $school);

        return $this->inSchool($school, function () use ($school, $caseId, $expectedVersion, $actor): EmploymentSeparationCase {
            [$case] = $this->lockCase($school, $caseId, $expectedVersion, $actor, 'separation.access_retained', ['finalized']);
            if ($case->access_retained_at !== null) {
                throw SeparationException::conflict('SEPARATION_ACCESS_ALREADY_RECORDED', 'Kept School access was already recorded.');
            }
            $access = $this->obligations->forCase($school, $case);
            $open = array_filter($access, fn (SeparationObligation $o) => $o->code === SeparationObligations::SCHOOL_ACCESS && $o->state === SeparationObligation::OPEN);
            if ($open === []) {
                throw SeparationException::conflict('SEPARATION_NO_ACTIVE_ACCESS', 'This account holds no School staff access to keep.');
            }

            $this->transition($case, 'finalized', ['access_retained_by_user_id' => $actor->id, 'access_retained_at' => now()]);
            $this->event($case, 'access_retained', $actor);
            $this->audit->school($school, 'hr.separation.access_retained', actor: $actor, subject: $case, metadata: ['employmentRecordId' => $case->employment_record_id]);

            return $case->refresh();
        });
    }

    /** Offboarding is complete: every mandatory clearance item cleared and no owner reports open work. */
    public function complete(School $school, string $caseId, int $expectedVersion, User $actor): EmploymentSeparationCase
    {
        $this->access->requireWholeSchool($actor, SeparationCapabilities::MANAGE, $school);

        return $this->inSchool($school, function () use ($school, $caseId, $expectedVersion, $actor): EmploymentSeparationCase {
            [$case] = $this->lockCase($school, $caseId, $expectedVersion, $actor, 'separation.complete', ['finalized']);

            // The items are locked so a concurrent clear either committed (and is seen) or waits.
            $pending = SeparationClearanceItem::query()->where('case_id', $case->id)->lockForUpdate()->get()
                ->filter(fn (SeparationClearanceItem $i) => $i->is_mandatory && $i->status !== 'cleared')->count();
            if ($pending > 0) {
                throw SeparationException::conflict('SEPARATION_CLEARANCE_PENDING', "{$pending} mandatory clearance item(s) are not cleared yet.");
            }
            $blocking = SeparationObligations::blocking($this->obligations->forCase($school, $case));
            if ($blocking !== []) {
                throw SeparationException::conflict('SEPARATION_OBLIGATIONS_OPEN', 'Outstanding work remains: '.implode(', ', array_map(fn (SeparationObligation $o) => $o->label, $blocking)).'.');
            }

            $this->transition($case, 'completed', ['completed_by_user_id' => $actor->id, 'completed_at' => now()]);
            $this->event($case, 'completed', $actor);
            $this->audit->school($school, 'hr.separation.completed', actor: $actor, subject: $case, metadata: ['employmentRecordId' => $case->employment_record_id]);

            return $case->refresh();
        }, certifiesAccess: true);
    }

    private function assertMayDecide(School $school, EmploymentSeparationCase $case, User $actor): void
    {
        $this->authority->requireCurrent($actor, SeparationCapabilities::APPROVE, $school);

        if (! $this->makerChecker->permits($actor, self::participants($case))) {
            throw SeparationException::forbidden('SEPARATION_PARTICIPANT_DECISION', 'You recorded this separation; another person must decide it.');
        }
        // HR.R1-C34 (WO-16, C33): nor an account known to be the same person as the recorder or the separating
        // Employee (an uncorrected link, current or ended). Backed by trg_employment_separation_cases_guard.
        $employeeId = (string) EmploymentRecord::query()->where('school_id', $school->id)->whereKey($case->employment_record_id)->value('employee_id');
        if ($this->selfAdministration->knownSamePersonParticipation($school->id, $actor, self::participants($case)->all()) !== null
            || $this->selfAdministration->isKnownToBeEmployee($actor, $school->id, $employeeId)) {
            throw self::samePerson();
        }
    }

    private static function ownCase(): SeparationException
    {
        return SeparationException::forbidden('SEPARATION_OWN_CASE', 'One of your accounts is known to belong to the separating person; another person must do this.');
    }

    private static function samePerson(): SeparationException
    {
        return SeparationException::forbidden('SEPARATION_SAME_PERSON_DECISION', 'One of your accounts is known to belong to the separating person or to whoever recorded this separation; another person must decide it.');
    }

    /**
     * The lock order of every case write: Employee placement lock -> Employee
     * FOR SHARE (never one's own) -> employment FOR UPDATE.
     */
    private function lockEmployment(School $school, string $employmentRecordId, User $actor, string $operation): EmploymentRecord
    {
        $employeeId = EmploymentRecord::query()->where('school_id', $school->id)->whereKey($employmentRecordId)->value('employee_id')
            ?? throw SeparationException::notFound();

        $this->placement->forPlacementChange($school, $employeeId);
        $this->selfAdministration->refuseOwnEmployee($actor, $employeeId, $operation);
        // HR.R1-C35 WO-18 (C34-N3, D-C35-9): nor through an old (ended, uncorrected) or known-same account of the
        // separating person, for every write. The decisions keep C34's own check and codes (assertMayDecide()).
        // Backed by trg_employment_separation_cases_guard and trg_separation_clearance_items_guard.
        if (! in_array($operation, ['separation.approve', 'separation.reject'], true)
            && $this->selfAdministration->isKnownToBeEmployee($actor, $school->id, $employeeId)) {
            throw self::ownCase();
        }

        return EmploymentRecord::query()->whereKey($employmentRecordId)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  list<string>  $statuses
     * @return array{EmploymentSeparationCase, EmploymentRecord}
     */
    private function lockCase(School $school, string $caseId, int $expectedVersion, User $actor, string $operation, array $statuses): array
    {
        // Another School's id is the same "not found" as an unknown one.
        $employmentId = EmploymentSeparationCase::query()->where('school_id', $school->id)->whereKey($caseId)->value('employment_record_id')
            ?? throw SeparationException::notFound();

        $employment = $this->lockEmployment($school, $employmentId, $actor, $operation);
        $case = EmploymentSeparationCase::query()->whereKey($caseId)->lockForUpdate()->firstOrFail();

        if ($case->version !== $expectedVersion) {
            throw SeparationException::stale();
        }
        if (! in_array($case->status, $statuses, true)) {
            throw SeparationException::conflict('SEPARATION_INVALID_STATE', 'This separation cannot do that in its current state.');
        }

        return [$case, $employment];
    }

    /** @param  array<string, mixed>  $columns */
    private function transition(EmploymentSeparationCase $case, string $status, array $columns): void
    {
        try {
            $case->forceFill([...$columns, 'status' => $status, 'version' => $case->version + 1])->save();
        } catch (QueryException $e) {
            // HR.R1-C34: the database's own same-person refusal (a link that changed after the service read it).
            if (str_contains($e->getMessage(), 'hr_employment_separation_same_person')) {
                throw self::samePerson();
            }
            if (str_contains($e->getMessage(), 'hr_employment_separation_own_case')) {
                throw self::ownCase();
            }
            throw $e;
        }
    }

    private function assertPlannedDay(EmploymentRecord $employment, string $notifiedOn, string $plannedLastDay): void
    {
        if ($plannedLastDay < $notifiedOn) {
            throw SeparationException::invalid('SEPARATION_LAST_DAY_BEFORE_NOTICE', 'The last working day cannot be before the day notice was given.');
        }
        if ($plannedLastDay < $employment->starts_on->toDateString()) {
            throw SeparationException::invalid('SEPARATION_LAST_DAY_BEFORE_START', 'The last working day cannot be before the employment started.');
        }
    }

    /** The applicable active requirements at approval, by staff type (NULL = every staff type). */
    private function snapshotClearance(School $school, EmploymentSeparationCase $case, EmploymentRecord $employment): int
    {
        $requirements = SeparationClearanceRequirement::query()->where('school_id', $school->id)->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('staff_type')->orWhere('staff_type', $employment->staff_type))
            ->orderBy('sequence')->orderBy('code')->get();

        foreach ($requirements as $requirement) {
            SeparationClearanceItem::query()->forceCreate([
                'case_id' => $case->id,
                'requirement_id' => $requirement->id,
                'code' => $requirement->code,
                'label' => $requirement->label,
                'responsibility' => $requirement->responsibility,
                'is_mandatory' => $requirement->is_mandatory,
                'sequence' => $requirement->sequence,
            ]);
        }

        return $requirements->count();
    }

    private function event(EmploymentSeparationCase $case, string $kind, User $actor): void
    {
        EmploymentSeparationEvent::query()->create(['case_id' => $case->id, 'kind' => $kind, 'actor_user_id' => $actor->id, 'occurred_at' => now()]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function inSchool(School $school, callable $work, bool $certifiesAccess = false): mixed
    {
        // HR.R1-C11 (HRC-11, rule 86): every separation write re-checks the School when it executes.
        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $work, $certifiesAccess): mixed {
            // HR.16B (R-HR15-1, rule 141): School access is CERTIFIED, so no grant, revocation or off-boarding may
            // interleave: Identity's School access lock comes first of all, as its own rule says.
            if ($certifiesAccess) {
                $this->staffAccess->holdForCertification($school);
            }
            $this->operational->requireOperational($school->id);

            return $work();
        }));
    }

    private static function date(string $value): string
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value)?->toDateString() ?? throw new \InvalidArgumentException;
        } catch (\Throwable) {
            throw SeparationException::invalid('SEPARATION_INVALID_DATE', 'Enter a valid date.');
        }
    }
}
