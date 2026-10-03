<?php

namespace App\Domain\Leave\Application;

use App\Domain\HR\Application\EmploymentCoverage;
use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Infrastructure\LeaveAllocationRun;
use App\Domain\Leave\Infrastructure\LeaveLedgerEntry;
use App\Domain\Leave\Infrastructure\LeavePolicyAssignment;
use App\Domain\Leave\Infrastructure\LeaveRequest;
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
 * HRX.1/HRX.2 (ADR 0065 §4.5, §23): the ONE writer of the append-only
 * leave ledger -- allocations (explicit and by annual run) and adjustments
 * (HRX.1), and, for HRX.2's request and year-close services, consumption,
 * reversal and the close's carry-forward/expiry movements
 * (`record*()`: no capability of their own -- they run only inside the
 * calling service's authorized, locked transaction).
 *
 * - Every quantity is a POSITIVE integer of half-day units; odd units only
 *   for a type that allows half-days.
 * - At most one `allocation` per employment x type x leave year (database-
 *   unique). A mid-year joiner gets an explicit allocation of the exact
 *   units an administrator chooses: no proration is ever computed. A later
 *   entitlement change is an `adjustment`.
 * - A debit can never take the balance below zero: the database trigger
 *   sums the key under its own lock and refuses.
 *
 * Lock order, in one transaction: School FOR SHARE (operational guard),
 * the EmploymentRecord (HR, FOR SHARE), then the balance key
 * `leave.balance:{school}:{employment}:{type}:{year}` (advisory, the same
 * key the database trigger takes), then the insert.
 */
class LeaveLedgerService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly SchoolOperationalGuard $guard,
        private readonly EmploymentCoverage $coverage,
    ) {}

    public function allocate(School $school, string $employmentRecordId, string $leaveTypeId, string $leaveYearId, int $units, User $actor): LeaveLedgerEntry
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::MANAGE, $school);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $employmentRecordId, $leaveTypeId, $leaveYearId, $units, $actor) {
            $this->guard->requireOperational($school->id);
            [$type, $year] = $this->typeAndYear($school, $leaveTypeId, $leaveYearId);
            $this->requireUnits($type, $units);
            // The employment first: another School's id is the same 404 as an unknown one.
            $this->requireEmployment($school, $employmentRecordId, $year);
            $assignment = $this->assignmentFor($school, $employmentRecordId, $type, $year)
                ?? throw LeaveException::conflict('LEAVE_NO_POLICY_ASSIGNMENT', 'The employment has no policy of this leave type in that leave year.');
            $this->lockKey($school, $employmentRecordId, $type->id, $year->id);

            $entry = $this->append($school, [
                'employment_record_id' => $employmentRecordId, 'leave_type_id' => $type->id, 'leave_year_id' => $year->id,
                'leave_policy_assignment_id' => $assignment->id, 'kind' => 'allocation', 'units' => $units, 'actor_user_id' => $actor->id,
            ]);
            $this->audit->school($school, 'leave.allocation.granted', actor: $actor, subject: $entry, metadata: [
                'employmentRecordId' => $employmentRecordId, 'leaveTypeId' => $type->id, 'leaveYearId' => $year->id, 'units' => $units, 'source' => 'explicit',
            ]);

            return $entry;
        }));
    }

    /**
     * The employments an annual run would grant, read-only:
     * employment id => [assignment id, units] for every policy assignment of
     * this type overlapping the year, with a positive annual allocation and
     * no allocation yet (a mid-year joiner's explicit grant is never
     * duplicated).
     *
     * @return array<string, array{assignment_id: string, units: int}>
     */
    public function runCandidates(School $school, string $leaveTypeId, string $leaveYearId, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::MANAGE, $school);

        return $this->context->withSchool($school, function () use ($school, $leaveTypeId, $leaveYearId) {
            [$type, $year] = $this->typeAndYear($school, $leaveTypeId, $leaveYearId);

            return $this->candidates($school, $type, $year);
        });
    }

    public function executeRun(School $school, string $leaveTypeId, string $leaveYearId, User $actor): LeaveAllocationRun
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::MANAGE, $school);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $leaveTypeId, $leaveYearId, $actor) {
            $this->guard->requireOperational($school->id);
            [$type, $year] = $this->typeAndYear($school, $leaveTypeId, $leaveYearId);
            if (LeaveAllocationRun::query()->where('school_id', $school->id)->where('leave_year_id', $year->id)->where('leave_type_id', $type->id)->exists()) {
                throw LeaveException::conflict('LEAVE_RUN_ALREADY_EXECUTED', 'This leave type was already allocated for that leave year.');
            }

            // Plan under the locks (employment FOR SHARE, then each balance key),
            // then write the append-only header with its final count, then the grants.
            $plan = [];
            $skipped = 0;
            foreach ($this->candidates($school, $type, $year) as $employmentRecordId => $candidate) {
                if ($this->coverage->holdRecord($school, $employmentRecordId, $year->starts_on->toDateString(), $year->ends_on->toDateString()) !== EmploymentCoverage::COVERED) {
                    $skipped++;

                    continue;
                }
                $this->lockKey($school, $employmentRecordId, $type->id, $year->id);
                if ($this->hasAllocation($school, $employmentRecordId, $type->id, $year->id)) {
                    $skipped++;

                    continue;
                }
                $plan[$employmentRecordId] = $candidate;
            }

            try {
                $run = LeaveAllocationRun::query()->create([
                    'school_id' => $school->id, 'leave_year_id' => $year->id, 'leave_type_id' => $type->id,
                    'executed_by_user_id' => $actor->id, 'allocated_count' => count($plan), 'executed_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw LeaveException::conflict('LEAVE_RUN_ALREADY_EXECUTED', 'This leave type was already allocated for that leave year.');
            }
            foreach ($plan as $employmentRecordId => $candidate) {
                $this->append($school, [
                    'employment_record_id' => $employmentRecordId, 'leave_type_id' => $type->id, 'leave_year_id' => $year->id,
                    'leave_policy_assignment_id' => $candidate['assignment_id'], 'kind' => 'allocation', 'units' => $candidate['units'],
                    'allocation_run_id' => $run->id, 'actor_user_id' => $actor->id,
                ]);
            }

            $this->audit->school($school, 'leave.allocation_run.executed', actor: $actor, subject: $run, metadata: [
                'leaveTypeId' => $type->id, 'leaveYearId' => $year->id, 'allocated' => count($plan), 'skipped' => $skipped,
            ]);

            return $run;
        }));
    }

    public function adjust(School $school, string $employmentRecordId, string $leaveTypeId, string $leaveYearId, string $direction, int $units, string $reason, User $actor): LeaveLedgerEntry
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::MANAGE, $school);
        if (! in_array($direction, ['credit', 'debit'], true)) {
            throw LeaveException::invalid('LEAVE_ADJUSTMENT_DIRECTION_INVALID', 'An adjustment is a credit or a debit.');
        }
        if (! in_array($reason, LeaveLedgerEntry::ADJUSTMENT_REASONS, true)) {
            throw LeaveException::invalid('LEAVE_ADJUSTMENT_REASON_INVALID', 'Use one of the closed adjustment reason codes.');
        }

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $employmentRecordId, $leaveTypeId, $leaveYearId, $direction, $units, $reason, $actor) {
            $this->guard->requireOperational($school->id);
            [$type, $year] = $this->typeAndYear($school, $leaveTypeId, $leaveYearId);
            $this->requireUnits($type, $units);
            $this->requireEmployment($school, $employmentRecordId, $year);
            $this->lockKey($school, $employmentRecordId, $type->id, $year->id);
            if (! $this->hasAllocation($school, $employmentRecordId, $type->id, $year->id)) {
                throw LeaveException::conflict('LEAVE_NOT_ALLOCATED', 'An adjustment changes an existing allocation; allocate first.');
            }

            $entry = $this->append($school, [
                'employment_record_id' => $employmentRecordId, 'leave_type_id' => $type->id, 'leave_year_id' => $year->id,
                'kind' => 'adjustment', 'direction' => $direction, 'units' => $units, 'reason_code' => $reason, 'actor_user_id' => $actor->id,
            ]);
            $this->audit->school($school, 'leave.adjustment.recorded', actor: $actor, subject: $entry, metadata: [
                'employmentRecordId' => $employmentRecordId, 'leaveTypeId' => $type->id, 'leaveYearId' => $year->id,
                'direction' => $direction, 'units' => $units, 'reason' => $reason,
            ]);

            return $entry;
        }));
    }

    /**
     * HRX.2: an approval's consumption of one leave year. The database
     * requires it to equal that year's chargeable-day evidence and refuses a
     * negative balance or a second consumption of the same request x year.
     * The caller holds the year (shared) and balance locks.
     */
    public function recordConsumption(School $school, LeaveRequest $request, string $leaveYearId, int $units, User $actor): LeaveLedgerEntry
    {
        return $this->append($school, [
            'employment_record_id' => $request->employment_record_id, 'leave_type_id' => $request->leave_type_id, 'leave_year_id' => $leaveYearId,
            'kind' => 'consumption', 'units' => $units, 'leave_request_id' => $request->id, 'actor_user_id' => $actor->id,
        ]);
    }

    /** HRX.2: the reversal of exactly one consumption (one per entry, database-unique). */
    public function recordReversal(School $school, LeaveLedgerEntry $consumption, User $actor): LeaveLedgerEntry
    {
        return $this->append($school, [
            'employment_record_id' => $consumption->employment_record_id, 'leave_type_id' => $consumption->leave_type_id, 'leave_year_id' => $consumption->leave_year_id,
            'kind' => 'reversal', 'units' => $consumption->units, 'reverses_entry_id' => $consumption->id, 'leave_request_id' => $consumption->leave_request_id,
            'actor_user_id' => $actor->id,
        ]);
    }

    /**
     * HRX.2: a year close's (or a reconciliation's) `carry_forward_out`,
     * `carry_forward_in` or `expiry` movement. Linked to the close, and to the
     * reconciliation when it corrects a closed year.
     */
    public function recordCloseMovement(School $school, string $kind, string $employmentRecordId, string $leaveTypeId, string $leaveYearId, int $units, string $yearCloseId, ?string $reconciliationId, User $actor): LeaveLedgerEntry
    {
        if (! in_array($kind, ['carry_forward_out', 'carry_forward_in', 'expiry'], true)) {
            throw new \InvalidArgumentException("{$kind} is not a year-close movement.");
        }

        return $this->append($school, [
            'employment_record_id' => $employmentRecordId, 'leave_type_id' => $leaveTypeId, 'leave_year_id' => $leaveYearId,
            'kind' => $kind, 'units' => $units, 'year_close_id' => $yearCloseId, 'year_close_reconciliation_id' => $reconciliationId,
            'actor_user_id' => $actor->id,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function append(School $school, array $attributes): LeaveLedgerEntry
    {
        try {
            return LeaveLedgerEntry::query()->create(['school_id' => $school->id, 'tracks_balance' => true, ...$attributes]);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'leave_ledger_entries_one_allocation')) {
                throw LeaveException::conflict('LEAVE_ALREADY_ALLOCATED', 'This employment already has its allocation of this leave type for that year; use an adjustment.');
            }
            if (str_contains($e->getMessage(), 'leave_ledger_entries_one_consumption') || str_contains($e->getMessage(), 'leave_ledger_entries_one_reversal')) {
                throw LeaveException::conflict('LEAVE_REQUEST_ALREADY_DECIDED', 'This request has already been decided.');
            }
            throw $e;
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'leave_balance_negative')) {
                throw LeaveException::conflict('LEAVE_BALANCE_INSUFFICIENT', 'The leave balance cannot go below zero.');
            }
            if (str_contains($e->getMessage(), 'leave_year_closed')) {
                throw LeaveException::conflict('LEAVE_YEAR_CLOSED', 'That leave year is closed.');
            }
            throw $e;
        }
    }

    /** @return array{0: LeaveType, 1: LeaveYear} */
    private function typeAndYear(School $school, string $leaveTypeId, string $leaveYearId): array
    {
        $type = LeaveType::query()->where('school_id', $school->id)->sharedLock()->findOrFail($leaveTypeId);
        if (! $type->tracks_balance) {
            throw LeaveException::invalid('LEAVE_TYPE_UNTRACKED', 'This leave type does not track a balance.');
        }
        if ($type->status !== 'active') {
            throw LeaveException::conflict('LEAVE_TYPE_INACTIVE', 'This leave type is inactive.');
        }

        return [$type, LeaveYear::query()->where('school_id', $school->id)->findOrFail($leaveYearId)];
    }

    private function requireUnits(LeaveType $type, int $units): void
    {
        if ($units < 1) {
            throw LeaveException::invalid('LEAVE_UNITS_INVALID', 'Units are a positive whole number of half-days.');
        }
        if (! $type->allows_half_day && $units % 2 !== 0) {
            throw LeaveException::invalid('LEAVE_HALF_DAY_NOT_ALLOWED', 'This leave type is granted in whole days (even half-day units).');
        }
    }

    private function requireEmployment(School $school, string $employmentRecordId, LeaveYear $year): void
    {
        match ($this->coverage->holdRecord($school, $employmentRecordId, $year->starts_on->toDateString(), $year->ends_on->toDateString())) {
            EmploymentCoverage::COVERED => null,
            EmploymentCoverage::RECORD_NOT_FOUND => throw new ModelNotFoundException('No query results for the employment.'),
            default => throw LeaveException::conflict('LEAVE_EMPLOYMENT_NOT_ELIGIBLE', 'The employment is not planned or current in that leave year.'),
        };
    }

    private function assignmentFor(School $school, string $employmentRecordId, LeaveType $type, LeaveYear $year): ?LeavePolicyAssignment
    {
        return LeavePolicyAssignment::query()->where('school_id', $school->id)->where('employment_record_id', $employmentRecordId)
            ->where('leave_type_id', $type->id)->where('effective_from', '<=', $year->ends_on->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $year->starts_on->toDateString()))
            ->orderByDesc('effective_from')->first();
    }

    /** @return array<string, array{assignment_id: string, units: int}> */
    private function candidates(School $school, LeaveType $type, LeaveYear $year): array
    {
        $rows = DB::table('leave_policy_assignments as a')
            ->join('leave_policies as p', fn ($j) => $j->on('p.id', '=', 'a.leave_policy_id')->on('p.school_id', '=', 'a.school_id'))
            ->where('a.school_id', $school->id)->where('a.leave_type_id', $type->id)
            ->where('a.effective_from', '<=', $year->ends_on->toDateString())
            ->where(fn ($q) => $q->whereNull('a.effective_to')->orWhere('a.effective_to', '>=', $year->starts_on->toDateString()))
            ->where('p.annual_allocation_units', '>', 0)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('leave_ledger_entries as e')
                ->whereColumn('e.employment_record_id', 'a.employment_record_id')->where('e.leave_type_id', $type->id)
                ->where('e.leave_year_id', $year->id)->where('e.kind', 'allocation'))
            ->orderBy('a.employment_record_id')->orderByDesc('a.effective_from')
            ->get(['a.id', 'a.employment_record_id', 'p.annual_allocation_units']);

        $candidates = [];
        foreach ($rows as $row) {
            $candidates[(string) $row->employment_record_id] ??= ['assignment_id' => (string) $row->id, 'units' => (int) $row->annual_allocation_units];
        }

        return $candidates;
    }

    private function hasAllocation(School $school, string $employmentRecordId, string $leaveTypeId, string $leaveYearId): bool
    {
        return LeaveLedgerEntry::query()->where('school_id', $school->id)->where('employment_record_id', $employmentRecordId)
            ->where('leave_type_id', $leaveTypeId)->where('leave_year_id', $leaveYearId)->where('kind', 'allocation')->exists();
    }

    private function lockKey(School $school, string $employmentRecordId, string $leaveTypeId, string $leaveYearId): void
    {
        // The year (shared) before the key: the year close holds the year exclusively (ADR 0065 §23.10).
        LeaveLocks::year($school, $leaveYearId);
        LeaveLocks::balance($school, $employmentRecordId, $leaveTypeId, $leaveYearId);
    }
}
