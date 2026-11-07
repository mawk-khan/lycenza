<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Application\Exceptions\EmployeeCategoryNotFoundException;
use App\Domain\HR\Application\Exceptions\EmploymentAlreadyEndedException;
use App\Domain\HR\Application\Exceptions\EmploymentOverlapException;
use App\Domain\HR\Application\Exceptions\InvalidEmploymentEffectiveDateException;
use App\Domain\HR\Application\Exceptions\InvalidEmploymentStatusException;
use App\Domain\HR\Application\Exceptions\InvalidEmploymentStatusTransitionException;
use App\Domain\HR\Application\Exceptions\InvalidEmploymentTermsException;
use App\Domain\HR\Application\Reporting\ReportingRelationshipService;
use App\Domain\HR\Application\Workforce\EmploymentType;
use App\Domain\HR\Application\Workforce\StaffType;
use App\Domain\HR\Events\EmploymentEnded;
use App\Domain\HR\Events\EmploymentStarted;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned write path for EmploymentRecord (docs/modules/HR.md
 * "Temporal data strategy"/"Rehire strategy"), mirroring EmployeeService's
 * shape. `create()` doubles as the rehire pathway at the schema level --
 * a second call for an Employee whose first EmploymentRecord has
 * already ended is exactly what a rehire is; nothing here distinguishes
 * "first hire" from "rehire" beyond it being the Employee's 2nd+ row.
 * Phase 8A.13 adds `App\Domain\HR\Application\EmployeeLifecycleService`
 * as the explicit REHIRE COMMAND on top of this primitive (enforcing
 * the "prior history must exist" business precondition) -- it still
 * calls straight through to this `create()`, never duplicating its
 * overlap/allocation logic.
 *
 * Phase 8A.10: every public method requires a real `User $actor` and
 * authorizes `hr.employees.assignments.manage` at the Employee's School
 * (docs/modules/HR.md 8A.10 as-built: Employment/Assignment history and
 * reporting-manager changes share one capability boundary).
 *
 * Phase 8A.13: `end()` additionally enforces a closed terminal-status
 * set, rejects a repeated end on an already-ended row (concurrency-safe
 * via a row lock), and rejects an effective date before the
 * EmploymentRecord's own `starts_on` -- see `end()`'s own docblock.
 */
class EmploymentService
{
    /**
     * The closed set of terminal Employment statuses
     * (docs/modules/HR.md "Employee lifecycle -- state responsibility
     * matrix") -- the only valid `end()` targets. `draft`/`pre_joining`/
     * `active`/`notice_period` are non-terminal Employment states and
     * are never a valid "ending" transition.
     */
    public const array TERMINAL_STATUSES = ['separated', 'terminated', 'retired', 'deceased'];

    /**
     * HR.1A (S-8): the only statuses a NEW employment may start in. Terminal
     * statuses arrive only through end(); `notice_period` only for an
     * employment already running. `pre_joining` is for a start after the
     * School-local today (there is no later pre_joining -> active transition,
     * so a started pre_joining employment could never become current).
     */
    public const array CREATE_STATUSES = ['draft', 'pre_joining', 'active'];

    /** HR.1A (X-3): the terms fields -- changed only by appending employment_terms. */
    public const array TERMS_FIELDS = ['employment_type', 'staff_type', 'employee_category_id'];

    public function __construct(
        private readonly SchoolOperationalGuard $operational,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly ReportingRelationshipService $reportingLines,
        private readonly HrSelfAdministrationGuard $selfAdministration,
        private readonly HrEmployeeAccess $access,
        private readonly EmployeePlacementLock $placement,
        /** @var iterable<EmploymentEndParticipant> */
        #[Tag(EmploymentEndParticipant::TAG)] private readonly iterable $endParticipants = [],
    ) {}

    /**
     * @param  array{employment_type: string, starts_on: string, ends_on?: string|null, status?: string, probation_ends_on?: string|null, employee_category_id?: string|null, staff_type?: string|null}  $attributes
     */
    public function create(Employee $employee, array $attributes, User $actor): EmploymentRecord
    {
        $school = $employee->school;
        // ORG.7E-1 (ADR 0076 §43): a new employment for an existing Employee
        // (a first hire after identity-only create, a rehire, a second
        // contract) is School-wide employment administration: whole-School
        // only. Campus onboarding creates its first employment atomically.
        $this->access->requireWholeSchool($actor, 'hr.employees.assignments.manage', $school);

        return $this->context->withSchool($school, fn () => DB::transaction(fn () => $this->createRecord($employee, $attributes, $actor)));
    }

    /**
     * The one EmploymentRecord INSERT (create() and EmployeeOnboardingService):
     * the Employee lock, the self-administration guard, overlap, audit and the
     * EmploymentStarted event. No authorization of its own; run inside the
     * caller's transaction and the School's TenantContext.
     *
     * HR.1A: `employment_type` must be an EmploymentType, `staff_type` (when
     * given) a StaffType, and the status one of CREATE_STATUSES. The database
     * creates the employment's first terms from these values
     * (`trg_employment_records_initial_terms`) and refuses an overlapping
     * employment of the same Employee (`trg_employment_records_no_overlap`).
     *
     * @param  array{employment_type: string, starts_on: string, ends_on?: string|null, status?: string, probation_ends_on?: string|null, employee_category_id?: string|null, staff_type?: string|null}  $attributes
     */
    public function createRecord(Employee $employee, array $attributes, User $actor): EmploymentRecord
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('EmploymentService::createRecord() runs only inside the creating transaction.');
        }
        $school = $employee->school;
        // HR.R1-C11 (HRC-11, rule 86): every HR writer re-checks the School when it executes.
        $this->operational->requireOperational($school->id);
        $startsOn = $attributes['starts_on'];
        $endsOn = $attributes['ends_on'] ?? null;
        $categoryId = $attributes['employee_category_id'] ?? null;
        $status = $attributes['status'] ?? 'active';

        // TCH.1 (ADR 0063 section 5): `status` is an ActingEmployee input,
        // so only the closed catalogue is ever written (the database
        // enforces the same list: employment_records_status_check).
        if (! in_array($status, EmploymentRecord::STATUSES, true)) {
            throw new InvalidEmploymentStatusException($status);
        }
        // HR.R1-C2 (HRC-1): an employment begins open. `ends_on` is the ACTUAL last day -- writing it ends the
        // record -- so a planned end on creation was a dead end (never separable, never retention-eligible).
        // It ends only through end() / an approved separation (the database refuses it for the runtime role too).
        if ($endsOn !== null) {
            throw new InvalidEmploymentTermsException('ends_on_on_create', 'A new employment begins open; it ends only by ending it (or through an approved separation).');
        }
        // HR.1A (S-8): a new employment starts, it never begins ended or in notice.
        if (! in_array($status, self::CREATE_STATUSES, true)) {
            throw new InvalidEmploymentTermsException('status_not_a_start', 'A new employment starts as draft, pre-joining or active; it is ended only by ending it.');
        }
        if ($status === 'pre_joining' && CarbonImmutable::parse($startsOn)->toDateString() <= SchoolTimezone::today($school)) {
            throw new InvalidEmploymentTermsException('pre_joining_not_future', 'A pre-joining employment starts after today.');
        }
        // HR.1A (S-12): the closed catalogues.
        if (EmploymentType::tryFrom((string) $attributes['employment_type']) === null) {
            throw new InvalidEmploymentTermsException('employment_type', 'The employment type is not one of the recognised types.');
        }
        $staffType = $attributes['staff_type'] ?? null;
        if ($staffType !== null && StaffType::tryFrom($staffType) === null) {
            throw new InvalidEmploymentTermsException('staff_type', 'The staff type is not one of the recognised types.');
        }

        // Lock the Employee row itself (not just existing EmploymentRecord
        // rows) so two concurrent create() calls for the SAME employee --
        // including the very first hire, where no EmploymentRecord rows
        // exist yet to lock -- serialize on this row instead of racing.
        // Mirrors EmployeeNumberAllocator/AcademicYearService's lock-then-
        // check-then-write pattern.
        $identity = Employee::query()->where('id', $employee->id)->lockForUpdate()->firstOrFail();

        // HR.R1-C2 (HRC-12): an Employee whose employment ended in death never receives another one -- no contract
        // permits it, and a new employment would not correct the death record (correcting an erroneous death record
        // is a separate, undecided matter). Asked first: restoring the identity would not change this answer.
        if (EmploymentRecord::query()->where('school_id', $school->id)->where('employee_id', $employee->id)->where('status', 'deceased')->exists()) {
            throw new InvalidEmploymentTermsException('employee_deceased', 'A death is recorded for this Employee; a new employment cannot be recorded.');
        }

        // HR.R1-C9 (HRC-12, owner decision 2026-11-07): an archived identity is restored first. The answer comes from
        // the row locked above, because the model passed in may be stale. Database-backed
        // (`trg_employment_records_archived_identity`). Restoring grants nothing else (HR.md "Rehire strategy").
        if ($identity->record_status === 'archived') {
            throw new InvalidEmploymentTermsException('employee_archived', 'This Employee is archived. Restore the Employee first, then record the new employment.');
        }

        // SR.4 (ADR 0071 §26.2): never your own employment.
        $this->selfAdministration->refuseOwnEmployee($actor, $employee->id, 'employment.create');

        $this->assertNoOverlap($employee, $startsOn, $endsOn);

        try {
            $employment = EmploymentRecord::query()->forceCreate([
                'school_id' => $school->id,
                'employee_id' => $employee->id,
                'employment_type' => $attributes['employment_type'],
                'staff_type' => $staffType,
                'employee_category_id' => $categoryId,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'probation_ends_on' => $attributes['probation_ends_on'] ?? null,
                'status' => $status,
            ]);
        } catch (QueryException $e) {
            if ($categoryId !== null && $this->violatesEmployeeCategoryForeignKey($e)) {
                throw new EmployeeCategoryNotFoundException($categoryId);
            }

            throw $e;
        }

        $this->audit->school($school, 'hr.employment.created', actor: $actor, subject: $employment, metadata: [
            'employeeId' => $employee->id,
            'employmentType' => $employment->employment_type,
            'staffType' => $employment->staff_type,
            'startsOn' => $startsOn,
            'status' => $status,
        ]);

        event(new EmploymentStarted($school->id, $employee->id, $employment->id));

        return $employment;
    }

    /**
     * Simple-field update only: `probation_ends_on`. starts_on/ends_on/status
     * changes each have their own reasoning: ends_on+status transition through
     * end() (see below); starts_on is immutable (the database's eligibility
     * guard refuses it).
     *
     * HR.1A (X-3): the terms (`employment_type`, `staff_type`,
     * `employee_category_id`) are never overwritten here -- they change only
     * by appending dated, reasoned terms through
     * App\Domain\HR\Application\Workforce\EmploymentTermsService, and the
     * database refuses a direct update of them.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(EmploymentRecord $employment, array $attributes, User $actor): EmploymentRecord
    {
        $school = $employment->school;
        // ORG.7E-1: employment-level administration stays whole-School.
        $this->access->requireWholeSchool($actor, 'hr.employees.assignments.manage', $school);

        if (array_intersect(array_keys($attributes), self::TERMS_FIELDS) !== []) {
            throw new InvalidEmploymentTermsException('terms_change_required', 'Employment type, staff type and category change only as dated terms with a reason.');
        }
        $attributes = array_intersect_key($attributes, ['probation_ends_on' => true]);

        return $this->context->withSchool($school, function () use ($school, $employment, $attributes, $actor) {
            return DB::transaction(function () use ($school, $employment, $attributes, $actor) {
                $this->operational->requireOperational($school->id);
                $this->selfAdministration->refuseOwnEmployee($actor, $employment->employee_id, 'employment.update');

                $employment->update($attributes);

                $this->audit->school($school, 'hr.employment.updated', actor: $actor, subject: $employment, metadata: [
                    // Phase 8A.11: added so the Employee Activity Timeline
                    // can resolve this event to its Employee directly from
                    // metadata, without depending on EmploymentRecord still
                    // being reachable -- purely additive, does not change
                    // this event's existing semantics.
                    'employeeId' => $employment->employee_id,
                    'fields' => array_keys($attributes),
                ]);

                return $employment->fresh();
            });
        });
    }

    /**
     * Ends the EmploymentRecord and, in the SAME transaction, closes
     * every currently-open Assignment under it to the same $endsOn
     * date (docs/modules/HR.md leaves this checkpoint's decision
     * explicit: an ended Employment must never leave an Assignment
     * open indefinitely -- Option A from the brief, chosen over
     * rejecting the call until assignments are manually closed first,
     * since that would make ending an Employment error-prone for the
     * common case). Never touches `users`/`school_memberships` --
     * account deactivation is a distinct, explicit action outside this
     * checkpoint's scope (docs/modules/HR.md principle 2.6).
     *
     * Phase 8A.13: `$status` must be one of the closed terminal set
     * (`InvalidEmploymentStatusTransitionException` otherwise -- no
     * arbitrary caller string is ever written). The target row is
     * re-fetched with `lockForUpdate()` INSIDE the transaction and its
     * `ends_on` re-checked from that locked read before writing
     * (`EmploymentAlreadyEndedException` if already ended) -- this is
     * what makes two genuinely concurrent `end()` calls for the SAME
     * EmploymentRecord serialize safely rather than racing to overwrite
     * each other's end date/status (checkpoint 8A.13 sections 21/36).
     * `$endsOn` before the row's own `starts_on` is rejected with a
     * clean exception (`InvalidEmploymentEffectiveDateException`)
     * rather than surfacing the database's own CHECK-constraint
     * violation.
     *
     * S7 (ADR 0063 §47): after HR's own assignments close, every
     * `EmploymentEndParticipant` ends the authority this employment
     * granted (teaching ownership, required and elective) in the SAME
     * transaction -- a later rehire of the same Employee therefore never
     * resurrects it. A participant that throws refuses the whole end.
     */
    public function end(EmploymentRecord $employment, string $endsOn, User $actor, string $status = 'separated'): EmploymentRecord
    {
        $school = $employment->school;
        // ORG.7E-1 (ADR 0076 §43): ending employment is terminal and cascades
        // into teaching ownership on every Campus: whole-School only.
        $this->access->requireWholeSchool($actor, 'hr.employees.assignments.manage', $school);

        if (! in_array($status, self::TERMINAL_STATUSES, true)) {
            throw new InvalidEmploymentStatusTransitionException($employment->id, $status);
        }

        return $this->context->withSchool($school, function () use ($school, $employment, $endsOn, $status, $actor) {
            return DB::transaction(function () use ($school, $employment, $endsOn, $status, $actor) {
                $this->operational->requireOperational($school->id);
                // ORG.7E-1: the placement lock first (EmployeePlacementLock order).
                $this->placement->forPlacementChange($school, $employment->employee_id);
                // SR.4 (ADR 0071 §26.2): Employee FOR SHARE before the record, the ActingEmployee lock order.
                $this->selfAdministration->refuseOwnEmployee($actor, $employment->employee_id, 'employment.end');

                $locked = EmploymentRecord::query()->where('id', $employment->id)->lockForUpdate()->firstOrFail();

                if ($locked->ends_on !== null) {
                    throw new EmploymentAlreadyEndedException($locked->id, $locked->ends_on->toDateString());
                }

                if ($endsOn < $locked->starts_on->toDateString()) {
                    throw new InvalidEmploymentEffectiveDateException($locked->id, $endsOn, 'before_employment_start');
                }

                // HR.3A: every reporting line of the employment (either side) ends
                // with it, BEFORE its end is written (the database refuses an end
                // a live line outlives). A manager's reports are left with no
                // manager, never silently re-parented.
                $this->reportingLines->closeForEmploymentEnd($school, $locked, $endsOn, $actor);

                $locked->forceFill(['ends_on' => $endsOn, 'status' => $status])->save();

                // HR.R1-C2 (HRC-2): every placement ends within the employment. The employment row lock taken
                // above is what excludes other placement writers (the whole-School placement lock is FOR SHARE on
                // both sides): each of them holds this row FOR SHARE or stronger before it writes.
                // - one that applies on the last day (or after it, open or with a later planned end) is clipped
                //   to the last day;
                // - one that would only START after the last day (a future transfer / promotion) never took
                //   effect: it becomes the empty interval `ends_on = starts_on - 1` (the HR.3A / ADR 0078
                //   convention) -- it covers no day, its planned dates and its placement change stay as history.
                // Placements already ended on or before the last day are untouched.
                $closed = $locked->assignments()
                    ->where('starts_on', '<=', $endsOn)
                    ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>', $endsOn))
                    ->lockForUpdate()->orderBy('id')->get(['id', 'starts_on', 'ends_on']);
                $cancelled = $locked->assignments()
                    ->where('starts_on', '>', $endsOn)
                    ->where(fn ($q) => $q->whereNull('ends_on')->orWhereColumn('ends_on', '>=', 'starts_on'))
                    ->lockForUpdate()->orderBy('id')->get(['id', 'starts_on', 'ends_on']);
                $closedAssignmentIds = $closed->pluck('id');
                $cancelledAssignmentIds = $cancelled->pluck('id');

                // The rows below lose their recorded end, and a back-dated last day can precede a placement that had
                // already started on the record. Neither fact survives in the row, so the audit keeps both.
                $today = SchoolTimezone::today($school);
                $replacedEnds = $closed->concat($cancelled)->filter(fn (EmployeeAssignment $a) => $a->ends_on !== null)
                    ->mapWithKeys(fn (EmployeeAssignment $a) => [$a->id => $a->ends_on->toDateString()])->all();
                $startedBeforeRecorded = $cancelled->filter(fn (EmployeeAssignment $a) => $a->starts_on->toDateString() <= $today)->pluck('id')->all();

                if ($closedAssignmentIds->isNotEmpty()) {
                    EmployeeAssignment::query()->whereIn('id', $closedAssignmentIds)->update(['ends_on' => $endsOn]);
                }
                if ($cancelledAssignmentIds->isNotEmpty()) {
                    EmployeeAssignment::query()->whereIn('id', $cancelledAssignmentIds)->update(['ends_on' => DB::raw('starts_on - 1')]);
                }

                // S7 (ADR 0063 §47): authority the employment granted ends with it, in this transaction.
                foreach ($this->endParticipants as $participant) {
                    $participant->employmentEnded($school, $locked->employee_id, $endsOn, $actor);
                }

                $this->audit->school($school, 'hr.employment.ended', actor: $actor, subject: $locked, metadata: [
                    'employeeId' => $locked->employee_id,
                    'endsOn' => $endsOn,
                    'status' => $status,
                    'closedAssignmentIds' => $closedAssignmentIds->all(),
                    // HR.R1-C2: placements planned to start after the last day, recorded as never effective.
                    'cancelledAssignmentIds' => $cancelledAssignmentIds->all(),
                    'replacedAssignmentEnds' => $replacedEnds,
                    'startedBeforeRecordedAssignmentIds' => $startedBeforeRecorded,
                ]);

                event(new EmploymentEnded($school->id, $locked->employee_id, $locked->id, $status));

                return $locked->fresh();
            });
        });
    }

    /**
     * HR.12 (owner decision HR-061, 2026-11-04): an APPROVED separation case
     * puts its employment into `notice_period`. Called only by
     * SeparationCaseService, inside its transaction, after it took the
     * placement lock and locked this row FOR UPDATE and after the case row
     * became `approved` (the database admits `notice_period` only from
     * `active` under an approved case: trg_employment_records_separation_notice).
     * Its caller authorized the decision; this writes the one status column.
     */
    public function startNoticePeriod(School $school, EmploymentRecord $locked, string $caseId, User $actor): void
    {
        $this->changeNoticeStatus($school, $locked, 'active', 'notice_period', 'hr.employment.notice_started', $caseId, $actor);
    }

    /**
     * HR.12: a CANCELLED separation case returns its employment from
     * `notice_period` to `active` (the case row is already `cancelled`).
     * Same contract as startNoticePeriod().
     */
    public function endNoticePeriod(School $school, EmploymentRecord $locked, string $caseId, User $actor): void
    {
        $this->changeNoticeStatus($school, $locked, 'notice_period', 'active', 'hr.employment.notice_withdrawn', $caseId, $actor);
    }

    private function changeNoticeStatus(School $school, EmploymentRecord $locked, string $from, string $to, string $event, string $caseId, User $actor): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('A notice-period change runs only inside its separation case transaction.');
        }
        if ($locked->status !== $from || $locked->ends_on !== null) {
            throw new InvalidEmploymentStatusTransitionException($locked->id, $to);
        }

        $locked->forceFill(['status' => $to])->save();

        $this->audit->school($school, $event, actor: $actor, subject: $locked, metadata: [
            'employeeId' => $locked->employee_id,
            'separationCaseId' => $caseId,
            'fromStatus' => $from,
            'toStatus' => $to,
        ]);
    }

    /**
     * The clean refusal of an overlap, called only after the Employee row
     * lock above has been taken. The database backstop is the HR.1A
     * trigger `trg_employment_records_no_overlap`.
     */
    private function assertNoOverlap(Employee $employee, string $startsOn, ?string $endsOn): void
    {
        $existing = EmploymentRecord::query()->where('employee_id', $employee->id)->get();

        foreach ($existing as $record) {
            $existingStartsOn = $record->starts_on->toDateString();
            $existingEndsOn = $record->ends_on?->toDateString();

            $newStartsBeforeExistingEnds = $existingEndsOn === null || $startsOn <= $existingEndsOn;
            $existingStartsBeforeNewEnds = $endsOn === null || $existingStartsOn <= $endsOn;

            if ($newStartsBeforeExistingEnds && $existingStartsBeforeNewEnds) {
                throw new EmploymentOverlapException($employee->id, $record->id);
            }
        }
    }

    private function violatesEmployeeCategoryForeignKey(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'employment_records_employee_category_id_school_id_foreign');
    }
}
