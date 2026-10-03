<?php

namespace App\Domain\Leave\Application;

use App\Domain\Leave\Infrastructure\LeaveAllocationRun;
use App\Domain\Leave\Infrastructure\LeaveLedgerEntry;
use App\Domain\Leave\Infrastructure\LeavePolicy;
use App\Domain\Leave\Infrastructure\LeavePolicyAssignment;
use App\Domain\Leave\Infrastructure\LeaveType;
use App\Domain\Leave\Infrastructure\LeaveYear;
use App\Domain\Leave\Infrastructure\StaffHoliday;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * HRX.1 (ADR 0065 §4): every Leave read, under `hr.leave.view`. Presenters
 * carry ids, codes, dates and integer half-day units only -- never Employee
 * contact, HR profile, compensation or account data.
 *
 * The balance is DERIVED: for each leave type, the sum of the key's ledger
 * entries (credits: allocation, credit adjustment, reversal,
 * carry_forward_in; debits: debit adjustment, consumption,
 * carry_forward_out, expiry). There is no balance column to read, and the
 * figure always reconciles to the entries it is listed with.
 */
class LeaveReadService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly TenantContext $context,
        private readonly LeaveYearService $years,
        private readonly StaffCalendarService $calendar,
    ) {}

    /** @return array<string, mixed> */
    public function settings(School $school, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::VIEW, $school);

        return ['leaveYearStartMonth' => $this->years->startMonth($school), 'yearsExist' => $this->read($school, fn () => LeaveYear::query()->where('school_id', $school->id)->exists())];
    }

    /** @return list<array<string, mixed>> */
    public function leaveYears(School $school, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::VIEW, $school);

        return $this->read($school, fn () => LeaveYear::query()->where('school_id', $school->id)->orderBy('starts_on')->get()
            ->map(fn (LeaveYear $y) => self::year($y))->all());
    }

    /** @return list<array<string, mixed>> */
    public function types(School $school, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::VIEW, $school);

        return $this->read($school, fn () => LeaveType::query()->where('school_id', $school->id)->orderBy('code')->get()
            ->map(fn (LeaveType $t) => self::type($t))->all());
    }

    /** @return list<array<string, mixed>> */
    public function policies(School $school, ?string $leaveTypeId, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::VIEW, $school);

        return $this->read($school, fn () => LeavePolicy::query()->where('school_id', $school->id)
            ->when($leaveTypeId !== null, fn ($q) => $q->where('leave_type_id', $leaveTypeId))
            ->orderBy('created_at')->orderBy('id')->get()->map(fn (LeavePolicy $p) => self::policy($p))->all());
    }

    /** @return array{weeklyPattern: array<int, string>, holidays: list<array<string, mixed>>} */
    public function calendar(School $school, string $from, string $to, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::VIEW, $school);

        return [
            'weeklyPattern' => $this->calendar->pattern($school),
            'holidays' => $this->read($school, fn () => StaffHoliday::query()->where('school_id', $school->id)->whereBetween('holiday_on', [$from, $to])
                ->orderBy('holiday_on')->get()->map(fn (StaffHoliday $h) => self::holiday($h))->all()),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function assignments(School $school, string $employmentRecordId, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::VIEW, $school);

        return $this->read($school, fn () => LeavePolicyAssignment::query()->where('school_id', $school->id)->where('employment_record_id', $employmentRecordId)
            ->orderBy('leave_type_id')->orderBy('effective_from')->get()->map(fn (LeavePolicyAssignment $a) => self::assignment($a))->all());
    }

    /** @return list<array<string, mixed>> */
    public function ledger(School $school, string $employmentRecordId, string $leaveYearId, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::VIEW, $school);

        return $this->read($school, fn () => LeaveLedgerEntry::query()->where('school_id', $school->id)->where('employment_record_id', $employmentRecordId)
            ->where('leave_year_id', $leaveYearId)->orderBy('created_at')->orderBy('id')->get()->map(fn (LeaveLedgerEntry $e) => self::entry($e))->all());
    }

    /**
     * The derived balance of every balance-tracked type for one employment
     * in one leave year, in integer half-day units.
     *
     * @return list<array{leaveTypeId: string, credits: int, debits: int, availableUnits: int}>
     */
    public function balances(School $school, string $employmentRecordId, string $leaveYearId, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::VIEW, $school);

        return $this->read($school, fn () => array_map(fn ($r) => [
            'leaveTypeId' => (string) $r->leave_type_id,
            'credits' => (int) $r->credits,
            'debits' => (int) $r->debits,
            'availableUnits' => (int) $r->credits - (int) $r->debits,
        ], DB::select(
            "SELECT leave_type_id,
                    coalesce(sum(units) FILTER (WHERE kind IN ('allocation', 'reversal', 'carry_forward_in') OR (kind = 'adjustment' AND direction = 'credit')), 0) AS credits,
                    coalesce(sum(units) FILTER (WHERE kind IN ('consumption', 'carry_forward_out', 'expiry') OR (kind = 'adjustment' AND direction = 'debit')), 0) AS debits
               FROM leave_ledger_entries
              WHERE school_id = ? AND employment_record_id = ? AND leave_year_id = ?
              GROUP BY leave_type_id ORDER BY leave_type_id",
            [$school->id, $employmentRecordId, $leaveYearId],
        )));
    }

    /** @return array<string, mixed> */
    public function run(School $school, string $runId, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::VIEW, $school);

        return $this->read($school, fn () => self::runSummary(LeaveAllocationRun::query()->where('school_id', $school->id)->findOrFail($runId)));
    }

    /** @return array<string, mixed> */
    public static function year(LeaveYear $y): array
    {
        return ['id' => $y->id, 'label' => $y->label, 'startsOn' => $y->starts_on->toDateString(), 'endsOn' => $y->ends_on->toDateString(), 'startMonth' => $y->start_month];
    }

    /** @return array<string, mixed> */
    public static function type(LeaveType $t): array
    {
        return ['id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'isPaid' => $t->is_paid, 'tracksBalance' => $t->tracks_balance, 'allowsHalfDay' => $t->allows_half_day, 'status' => $t->status];
    }

    /** @return array<string, mixed> */
    public static function policy(LeavePolicy $p): array
    {
        return [
            'id' => $p->id, 'leaveTypeId' => $p->leave_type_id, 'name' => $p->name, 'annualAllocationUnits' => $p->annual_allocation_units,
            'carryForwardAllowed' => $p->carry_forward_allowed, 'carryForwardCapUnits' => $p->carry_forward_cap_units,
            'carryForwardExpiryDays' => $p->carry_forward_expiry_days, 'status' => $p->status, 'supersedesPolicyId' => $p->supersedes_policy_id,
        ];
    }

    /** @return array<string, mixed> */
    public static function holiday(StaffHoliday $h): array
    {
        return ['id' => $h->id, 'date' => $h->holiday_on->toDateString(), 'portion' => $h->portion, 'name' => $h->name];
    }

    /** @return array<string, mixed> */
    public static function assignment(LeavePolicyAssignment $a): array
    {
        return [
            'id' => $a->id, 'employmentRecordId' => $a->employment_record_id, 'leavePolicyId' => $a->leave_policy_id, 'leaveTypeId' => $a->leave_type_id,
            'effectiveFrom' => $a->effective_from->toDateString(), 'effectiveTo' => $a->effective_to?->toDateString(), 'ended' => $a->ended_at !== null,
        ];
    }

    /** @return array<string, mixed> */
    public static function entry(LeaveLedgerEntry $e): array
    {
        return [
            'id' => $e->id, 'employmentRecordId' => $e->employment_record_id, 'leaveTypeId' => $e->leave_type_id, 'leaveYearId' => $e->leave_year_id,
            'kind' => $e->kind, 'direction' => $e->direction, 'units' => $e->units, 'reasonCode' => $e->reason_code,
            'allocationRunId' => $e->allocation_run_id, 'reversesEntryId' => $e->reverses_entry_id, 'createdAt' => $e->created_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function runSummary(LeaveAllocationRun $r): array
    {
        return ['id' => $r->id, 'leaveYearId' => $r->leave_year_id, 'leaveTypeId' => $r->leave_type_id, 'allocatedCount' => $r->allocated_count, 'executedAt' => $r->executed_at->toIso8601String()];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    private function read(School $school, callable $read): mixed
    {
        return $this->context->withSchool($school, $read);
    }
}
