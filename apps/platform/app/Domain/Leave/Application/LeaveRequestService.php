<?php

namespace App\Domain\Leave\Application;

use App\Domain\HR\Application\ActingEmployeeResolver;
use App\Domain\HR\Application\EmploymentCoverage;
use App\Domain\HR\Application\Exceptions\ActingEmployeeUnavailableException;
use App\Domain\HR\Application\ReportingLine;
use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Events\LeaveRequestApproved;
use App\Domain\Leave\Events\LeaveRequestCancelled;
use App\Domain\Leave\Infrastructure\LeaveDecision;
use App\Domain\Leave\Infrastructure\LeaveLedgerEntry;
use App\Domain\Leave\Infrastructure\LeavePolicyAssignment;
use App\Domain\Leave\Infrastructure\LeaveRequest;
use App\Domain\Leave\Infrastructure\LeaveRequestDay;
use App\Domain\Leave\Infrastructure\LeaveType;
use App\Domain\Leave\Infrastructure\LeaveYear;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * HRX.2 (ADR 0065 §5, §6, §23): the leave request lifecycle.
 *
 * Lifecycle:
 * - `submitted -> approved | rejected | withdrawn`;
 * - `approved -> cancelled`.
 *
 * There is no draft, no edit and no partial cancellation. Every step writes
 * decision evidence, and the database refuses a transition without it.
 *
 * Who decides:
 * - **Manager path:** `hr.leave.approve` AND the acting Employee
 *   (ActingEmployeeResolver::hold()) is the requester's current manager.
 *   That is read fresh from HR's ReportingLine and never snapshotted.
 *   Anything else is the private 404.
 * - **Administrative path:** `hr.leave.manage`, any request of the School.
 * - **Nobody** approves or rejects their own request, on either path
 *   (service check plus database CHECK).
 *
 * Approval is one transaction:
 * - the chargeable-day evidence is computed from the staff working calendar
 *   held under its shared lock;
 * - each chargeable date gets its leave year and (balance-tracked types) the
 *   policy assignment and version effective that day;
 * - the evidence is written once;
 * - there is one `consumption` per leave year;
 * - then the decision, the status, audit and the
 *   `leave.request.approved.v1` event.
 *
 * Cancellation reverses each consumption. If its year is closed, the
 * cancellation reconciles it in the same transaction (LeaveYearCloseService).
 *
 * Lock order (ADR 0065 §23.10):
 * 1. School;
 * 2. the request row;
 * 3. HR rows;
 * 4. schedule (shared), then calendar (shared), then assignment;
 * 5. years (shared, ascending);
 * 6. balance keys.
 */
class LeaveRequestService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly SchoolOperationalGuard $guard,
        private readonly EmploymentCoverage $coverage,
        private readonly ActingEmployeeResolver $acting,
        private readonly ReportingLine $reporting,
        private readonly StaffCalendarService $calendar,
        private readonly LeaveLedgerService $ledger,
        private readonly LeaveYearCloseService $closes,
    ) {}

    /** An administrator submits on an employee's behalf. HRX.4's self-service reuses submit(). */
    public function submitOnBehalf(School $school, string $employmentRecordId, string $leaveTypeId, string $startsOn, string $startPortion, string $endsOn, string $endPortion, ?string $reasonCode, User $actor): LeaveRequest
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::MANAGE, $school);

        return $this->submit($school, $employmentRecordId, $leaveTypeId, $startsOn, $startPortion, $endsOn, $endPortion, $reasonCode, $actor);
    }

    public function approve(School $school, string $leaveRequestId, User $actor): LeaveRequest
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::MANAGE, $school);

        return $this->decide($school, $leaveRequestId, 'approved', null, 'administrative', $actor);
    }

    public function approveAsManager(School $school, string $leaveRequestId, User $actor): LeaveRequest
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::APPROVE, $school);

        return $this->decide($school, $leaveRequestId, 'approved', null, 'manager', $actor);
    }

    public function reject(School $school, string $leaveRequestId, string $reasonCode, User $actor): LeaveRequest
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::MANAGE, $school);

        return $this->decide($school, $leaveRequestId, 'rejected', $reasonCode, 'administrative', $actor);
    }

    public function rejectAsManager(School $school, string $leaveRequestId, string $reasonCode, User $actor): LeaveRequest
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::APPROVE, $school);

        return $this->decide($school, $leaveRequestId, 'rejected', $reasonCode, 'manager', $actor);
    }

    /** An administrator withdraws a submitted request on the requester's behalf. */
    public function withdraw(School $school, string $leaveRequestId, string $reasonCode, User $actor): LeaveRequest
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::MANAGE, $school);

        return $this->decide($school, $leaveRequestId, 'withdrawn', $reasonCode, 'administrative', $actor);
    }

    /** An administrator cancels an approved request at any time; every consumption is reversed. */
    public function cancel(School $school, string $leaveRequestId, string $reasonCode, User $actor): LeaveRequest
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::MANAGE, $school);
        $this->requireReason($reasonCode, LeaveDecision::CLOSING_REASONS);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $leaveRequestId, $reasonCode, $actor) {
            $this->guard->requireOperational($school->id);
            $request = $this->lockRequest($school, $leaveRequestId);
            if ($request->status !== 'approved') {
                throw LeaveException::conflict('LEAVE_REQUEST_NOT_APPROVED', 'Only an approved request can be cancelled.');
            }
            $type = LeaveType::query()->where('school_id', $school->id)->findOrFail($request->leave_type_id);

            $consumptions = LeaveLedgerEntry::query()->where('school_id', $school->id)->where('leave_request_id', $request->id)->where('kind', 'consumption')->get();
            $years = $this->yearsWithNext($school, $consumptions->pluck('leave_year_id')->all());
            LeaveLocks::schedule($school, shared: true);
            foreach ($years as $year) {
                LeaveLocks::year($school, $year->id);
            }
            foreach ($years as $year) {
                LeaveLocks::balance($school, $request->employment_record_id, $request->leave_type_id, $year->id);
            }

            $reversed = [];
            foreach ($consumptions->sortBy(fn (LeaveLedgerEntry $c) => $years[$c->leave_year_id]->starts_on->toDateString()) as $consumption) {
                $close = $this->closes->closeOf($school, $consumption->leave_year_id);
                if ($close !== null && $this->closes->closeOf($school, $close->next_leave_year_id) !== null) {
                    throw LeaveException::conflict('LEAVE_CANCELLATION_CLOSE_CHAIN', 'This leave sits in a closed year whose next year is also closed; correct it with an adjustment in the open year.');
                }
                $reversal = $this->ledger->recordReversal($school, $consumption, $actor);
                if ($close !== null) {
                    $this->closes->reconcile($school, $close, $reversal, $request, $actor);
                }
                $reversed[] = ['leaveYearId' => $consumption->leave_year_id, 'units' => $consumption->units];
            }

            $this->recordDecision($school, $request, 'cancelled', 'administrative', $reasonCode, $actor);
            $this->transition($request, 'approved', 'cancelled');
            $this->audit->school($school, 'leave.request.cancelled', actor: $actor, subject: $request, metadata: [
                'path' => 'administrative', 'reasonCode' => $reasonCode, 'reversed' => $reversed,
            ]);
            event(new LeaveRequestCancelled($school->id, $request->id, $request->employment_record_id, $request->employee_id, $request->leave_type_id, $type->is_paid, $type->tracks_balance, $reversed));

            return $request->refresh();
        }));
    }

    private function submit(School $school, string $employmentRecordId, string $leaveTypeId, string $startsOn, string $startPortion, string $endsOn, string $endPortion, ?string $reasonCode, User $actor): LeaveRequest
    {
        $shape = LeaveRequestShape::of($startsOn, $startPortion, $endsOn, $endPortion);
        if ($reasonCode !== null) {
            $this->requireReason($reasonCode, LeaveRequest::REASONS);
        }

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $employmentRecordId, $leaveTypeId, $shape, $reasonCode, $actor) {
            $this->guard->requireOperational($school->id);
            $type = LeaveType::query()->where('school_id', $school->id)->sharedLock()->findOrFail($leaveTypeId);
            if ($type->status !== 'active') {
                throw LeaveException::conflict('LEAVE_TYPE_INACTIVE', 'This leave type is inactive.');
            }
            if ($shape->usesHalfDays() && ! $type->allows_half_day) {
                throw LeaveException::invalid('LEAVE_HALF_DAY_NOT_ALLOWED', 'This leave type is taken in whole days.');
            }
            $this->requireEmployment($school, $employmentRecordId, $shape);

            LeaveLocks::schedule($school, shared: true);
            LeaveLocks::calendar($school, shared: true);
            foreach ($this->yearsOverlapping($school, $shape) as $year) {
                LeaveLocks::year($school, $year->id);
                if ($this->closes->closeOf($school, $year->id) !== null) {
                    throw LeaveException::conflict('LEAVE_YEAR_CLOSED', 'The request falls in a closed leave year.');
                }
            }
            $units = array_sum(array_map(fn (DayPortion $p) => $p->units(), $shape->chargeable($this->calendar->calculator($school, $shape->startsOn, $shape->endsOn))));
            if ($units === 0) {
                throw LeaveException::invalid('LEAVE_REQUEST_NO_WORKING_DAYS', 'The request covers no staff working time.');
            }

            try {
                $request = LeaveRequest::query()->create([
                    'school_id' => $school->id, 'employment_record_id' => $employmentRecordId, 'leave_type_id' => $type->id,
                    'starts_on' => $shape->startsOn, 'start_portion' => $shape->startPortion->value, 'ends_on' => $shape->endsOn,
                    'end_portion' => $shape->endPortion->value, 'reason_code' => $reasonCode, 'submitted_units' => $units,
                    'status' => 'submitted', 'submitted_by_user_id' => $actor->id,
                ]);
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'leave_request_overlap')) {
                    throw LeaveException::conflict('LEAVE_REQUEST_OVERLAP', 'The employment already has a submitted or approved request on one of those half-days.');
                }
                throw $e;
            }
            $request->refresh();
            $this->audit->school($school, 'leave.request.submitted', actor: $actor, subject: $request, metadata: [
                'employmentRecordId' => $employmentRecordId, 'leaveTypeId' => $type->id, 'startsOn' => $shape->startsOn,
                'startPortion' => $shape->startPortion->value, 'endsOn' => $shape->endsOn, 'endPortion' => $shape->endPortion->value,
                'submittedUnits' => $units, 'reasonCode' => $reasonCode,
            ]);

            return $request;
        }));
    }

    private function decide(School $school, string $leaveRequestId, string $decision, ?string $reasonCode, string $path, User $actor): LeaveRequest
    {
        if ($decision === 'rejected') {
            $this->requireReason((string) $reasonCode, LeaveDecision::REJECTION_REASONS);
        }
        if ($decision === 'withdrawn') {
            $this->requireReason((string) $reasonCode, LeaveDecision::CLOSING_REASONS);
        }

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $leaveRequestId, $decision, $reasonCode, $path, $actor) {
            $this->guard->requireOperational($school->id);
            $request = $this->lockRequest($school, $leaveRequestId);

            $deciderEmployeeId = $path === 'manager' ? $this->requireManager($school, $request, $actor) : $this->linkedEmployee($school, $actor);
            if (in_array($decision, ['approved', 'rejected'], true) && $deciderEmployeeId === $request->employee_id) {
                throw LeaveException::forbidden('LEAVE_SELF_DECISION', 'Nobody approves or rejects their own leave request.');
            }
            if ($request->status !== 'submitted') {
                throw LeaveException::conflict('LEAVE_REQUEST_NOT_SUBMITTED', 'This request has already been decided.');
            }

            $days = $decision === 'approved' ? $this->charge($school, $request, $actor) : [];
            $this->recordDecision($school, $request, $decision, $path, $reasonCode, $actor);
            $this->transition($request, 'submitted', $decision);

            $this->audit->school($school, 'leave.request.'.$decision, actor: $actor, subject: $request, metadata: array_filter([
                'path' => $path, 'reasonCode' => $reasonCode,
                'days' => $decision === 'approved' ? $days : null,
                'units' => $decision === 'approved' ? array_sum(array_column($days, 'units')) : null,
            ], fn ($v) => $v !== null));
            if ($decision === 'approved') {
                $type = LeaveType::query()->where('school_id', $school->id)->findOrFail($request->leave_type_id);
                event(new LeaveRequestApproved($school->id, $request->id, $request->employment_record_id, $request->employee_id, $request->leave_type_id, $type->is_paid, $type->tracks_balance, $days));
            }

            return $request->refresh();
        }));
    }

    /**
     * The approval snapshot: compute chargeable halves from the calendar held
     * under its shared lock, resolve each date's leave year and policy, write
     * the evidence, then consume per leave year.
     *
     * @return list<array{date: string, portion: string, units: int, leaveYearId: string}>
     */
    private function charge(School $school, LeaveRequest $request, User $actor): array
    {
        $type = LeaveType::query()->where('school_id', $school->id)->sharedLock()->findOrFail($request->leave_type_id);
        $shape = LeaveRequestShape::of($request->starts_on->toDateString(), $request->start_portion, $request->ends_on->toDateString(), $request->end_portion);
        $this->requireEmployment($school, $request->employment_record_id, $shape);

        LeaveLocks::schedule($school, shared: true);
        LeaveLocks::calendar($school, shared: true);
        $chargeable = $shape->chargeable($this->calendar->calculator($school, $shape->startsOn, $shape->endsOn));
        if ($chargeable === []) {
            throw LeaveException::conflict('LEAVE_REQUEST_NO_WORKING_DAYS', 'Under the current staff calendar the request covers no working time.');
        }

        $years = $this->yearsOverlapping($school, $shape);
        $assignments = [];
        if ($type->tracks_balance) {
            LeaveLocks::assignment($school, $request->employment_record_id, $request->leave_type_id);
            $assignments = LeavePolicyAssignment::query()->where('school_id', $school->id)->where('employment_record_id', $request->employment_record_id)
                ->where('leave_type_id', $request->leave_type_id)->where('effective_from', '<=', $shape->endsOn)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $shape->startsOn))->get()->all();
        }

        $rows = [];
        foreach ($chargeable as $date => $portion) {
            $year = collect($years)->first(fn (LeaveYear $y) => $y->starts_on->toDateString() <= $date && $y->ends_on->toDateString() >= $date)
                ?? throw LeaveException::conflict('LEAVE_YEAR_NOT_OPEN', "Open the leave year containing {$date} first.");
            $assignment = null;
            if ($type->tracks_balance) {
                $assignment = collect($assignments)->first(fn (LeavePolicyAssignment $a) => $a->effective_from->toDateString() <= $date && ($a->effective_to === null || $a->effective_to->toDateString() >= $date))
                    ?? throw LeaveException::conflict('LEAVE_NO_POLICY_ASSIGNMENT', "No leave policy of this type applies on {$date}.");
            }
            $rows[] = ['date' => $date, 'portion' => $portion, 'year' => $year, 'assignment' => $assignment];
        }

        $usedYears = collect($rows)->pluck('year')->unique('id')->sortBy(fn (LeaveYear $y) => $y->starts_on->toDateString())->values();
        foreach ($usedYears as $year) {
            LeaveLocks::year($school, $year->id);
            if ($this->closes->closeOf($school, $year->id) !== null) {
                throw LeaveException::conflict('LEAVE_YEAR_CLOSED', 'The request charges a closed leave year.');
            }
        }
        if ($type->tracks_balance) {
            foreach ($usedYears as $year) {
                LeaveLocks::balance($school, $request->employment_record_id, $request->leave_type_id, $year->id);
            }
        }

        $days = [];
        foreach ($rows as $row) {
            LeaveRequestDay::query()->create([
                'school_id' => $school->id, 'leave_request_id' => $request->id, 'leave_date' => $row['date'], 'portion' => $row['portion']->value,
                'units' => $row['portion']->units(), 'leave_year_id' => $row['year']->id,
                'leave_policy_assignment_id' => $row['assignment']?->id, 'leave_policy_id' => $row['assignment']?->leave_policy_id,
            ]);
            $days[] = ['date' => $row['date'], 'portion' => $row['portion']->value, 'units' => $row['portion']->units(), 'leaveYearId' => $row['year']->id];
        }
        if ($type->tracks_balance) {
            foreach ($usedYears as $year) {
                $units = array_sum(array_column(array_filter($days, fn (array $d) => $d['leaveYearId'] === $year->id), 'units'));
                $this->ledger->recordConsumption($school, $request, $year->id, $units, $actor);
            }
        }

        return $days;
    }

    /** The manager path's ownership: the acting Employee IS the requester's current manager, or the private 404. */
    private function requireManager(School $school, LeaveRequest $request, User $actor): string
    {
        try {
            $acting = $this->acting->hold($actor, $school);
        } catch (ActingEmployeeUnavailableException) {
            throw new ModelNotFoundException('No query results for the leave request.');
        }
        $manager = $this->reporting->holdManagerOf($school, $request->employment_record_id, $acting->asOf);
        if ($manager === null || $manager !== $acting->employeeId) {
            throw new ModelNotFoundException('No query results for the leave request.');
        }

        return $acting->employeeId;
    }

    /** The decider's own Employee on the administrative path, for the self-decision refusal (the database derives it too). */
    private function linkedEmployee(School $school, User $actor): ?string
    {
        try {
            return $this->acting->resolve($actor, $school)->employeeId;
        } catch (ActingEmployeeUnavailableException) {
            return null;
        }
    }

    private function recordDecision(School $school, LeaveRequest $request, string $decision, string $path, ?string $reasonCode, User $actor): void
    {
        try {
            LeaveDecision::query()->create([
                'school_id' => $school->id, 'leave_request_id' => $request->id, 'decision' => $decision, 'path' => $path,
                'decided_by_user_id' => $actor->id, 'reason_code' => $reasonCode,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw LeaveException::conflict('LEAVE_REQUEST_ALREADY_DECIDED', 'This request has already been decided.');
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'leave_decisions_no_self_decision')) {
                throw LeaveException::forbidden('LEAVE_SELF_DECISION', 'Nobody approves or rejects their own leave request.');
            }
            throw $e;
        }
    }

    /** The conditional transition: exactly one decider wins (ADR 0065 §17). */
    private function transition(LeaveRequest $request, string $from, string $to): void
    {
        $updated = LeaveRequest::query()->where('school_id', $request->school_id)->whereKey($request->id)->where('status', $from)
            ->update(['status' => $to, 'updated_at' => now()]);
        if ($updated !== 1) {
            throw LeaveException::conflict('LEAVE_REQUEST_ALREADY_DECIDED', 'This request has already been decided.');
        }
    }

    private function lockRequest(School $school, string $leaveRequestId): LeaveRequest
    {
        return LeaveRequest::query()->where('school_id', $school->id)->lockForUpdate()->findOrFail($leaveRequestId);
    }

    private function requireEmployment(School $school, string $employmentRecordId, LeaveRequestShape $shape): void
    {
        match ($this->coverage->holdRecordCovering($school, $employmentRecordId, $shape->startsOn, $shape->endsOn)) {
            EmploymentCoverage::COVERED => null,
            EmploymentCoverage::RECORD_NOT_FOUND => throw new ModelNotFoundException('No query results for the employment.'),
            default => throw LeaveException::conflict('LEAVE_EMPLOYMENT_NOT_ELIGIBLE', 'The employment does not cover those dates.'),
        };
    }

    /** @return list<LeaveYear> materialized years overlapping the request, ascending */
    private function yearsOverlapping(School $school, LeaveRequestShape $shape): array
    {
        return LeaveYear::query()->where('school_id', $school->id)->where('starts_on', '<=', $shape->endsOn)->where('ends_on', '>=', $shape->startsOn)
            ->orderBy('starts_on')->get()->all();
    }

    /**
     * The consumption years plus each one's adjacent next year (a reconciliation writes there), keyed by id, ascending.
     *
     * @param  list<string>  $yearIds
     * @return array<string, LeaveYear>
     */
    private function yearsWithNext(School $school, array $yearIds): array
    {
        $years = LeaveYear::query()->where('school_id', $school->id)->whereIn('id', $yearIds)->get();
        $next = $years->isEmpty() ? collect() : LeaveYear::query()->where('school_id', $school->id)
            ->whereIn('starts_on', $years->map(fn (LeaveYear $y) => $y->ends_on->copy()->addDay()->toDateString())->all())->get();

        return $years->merge($next)->unique('id')->sortBy(fn (LeaveYear $y) => $y->starts_on->toDateString())->keyBy('id')->all();
    }

    /** @param  list<string>  $allowed */
    private function requireReason(string $reasonCode, array $allowed): void
    {
        if (! in_array($reasonCode, $allowed, true)) {
            throw LeaveException::invalid('LEAVE_REASON_INVALID', 'Choose one of the listed reasons.');
        }
    }
}
