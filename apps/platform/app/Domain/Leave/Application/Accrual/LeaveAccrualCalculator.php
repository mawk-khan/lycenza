<?php

namespace App\Domain\Leave\Application\Accrual;

use App\Domain\HR\Application\EmploymentCoverage;
use App\Domain\Leave\Application\Policy\LeaveEligibilityOutcome;
use App\Domain\Leave\Application\Policy\LeaveEligibilityResolver;
use App\Domain\Leave\Application\Policy\LeaveEligibilityRow;
use App\Domain\Leave\Infrastructure\LeaveLedgerEntry;
use App\Models\School;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * HR.10 (ADR 0065 §29.5): THE accrual evaluation of one batch of
 * employments for one leave type, leave year and month -- the same code for
 * the preview (no locks, no writes) and for execution (inside the batch
 * transaction, after the locks: then it reflects the committed facts).
 *
 * Per batch, a FIXED number of queries whatever the batch holds:
 * - HR's whole-month coverage (`EmploymentCoverage::holdRecordsSpanning()`,
 *   FOR SHARE when executing): the employment's dated range must contain the
 *   whole month -- HR.1A's dated facts, not today's status;
 * - the type's assignments overlapping the month, with their version
 *   (FOR SHARE when executing, so an assignment end waits);
 * - the account's existing accrual for the month and any annual allocation;
 * - HR.9's eligibility for the batch on the month's first day (drift only).
 *
 * Decision order (first match wins; disjoint by construction):
 * partial_period -> no_policy -> assignment_not_whole_period ->
 * not_monthly_policy -> already_credited -> annual_allocation_exists ->
 * not_due -> credit.
 *
 * Never: proration (a part month is reported, not credited -- ADR 0065
 * §22.2), today's Department or category, a gender/marital/health fact.
 */
final class LeaveAccrualCalculator
{
    public function __construct(
        private readonly EmploymentCoverage $coverage,
        private readonly LeaveEligibilityResolver $eligibility,
    ) {}

    /**
     * @param  list<array{employmentRecordId: string, employeeId: string, employeeNumber: ?string, fullName: ?string, status: string, current: bool, employeeActive: bool}>  $page  one roster page (EmploymentRoster::pageOverlapping)
     * @return list<LeaveAccrualEvaluation> in the page's order
     */
    public function evaluate(School $school, string $leaveTypeId, string $leaveYearId, string $periodStart, array $page, string $today, bool $lock): array
    {
        if ($page === []) {
            return [];
        }
        $periodEnd = self::periodEnd($periodStart);
        $ids = array_column($page, 'employmentRecordId');

        $spanning = array_flip($lock
            ? $this->coverage->holdRecordsSpanning($school, $ids, $periodStart, $periodEnd)
            : $this->spanningUnlocked($school, $ids, $periodStart, $periodEnd));

        $assignments = DB::table('leave_policy_assignments as a')
            ->join('leave_policies as p', fn ($j) => $j->on('p.id', '=', 'a.leave_policy_id')->on('p.school_id', '=', 'a.school_id'))
            ->where('a.school_id', $school->id)->where('a.leave_type_id', $leaveTypeId)->whereIn('a.employment_record_id', $ids)
            ->where('a.effective_from', '<=', $periodEnd)
            ->where(fn ($q) => $q->whereNull('a.effective_to')->orWhere('a.effective_to', '>=', $periodStart))
            ->orderBy('a.employment_record_id')->orderBy('a.id')
            ->when($lock, fn ($q) => $q->lock('FOR SHARE OF a'))
            ->get(['a.id', 'a.employment_record_id', 'a.leave_policy_id', 'a.effective_from', 'a.effective_to',
                'p.entitlement_method', 'p.monthly_accrual_units', 'p.accrual_timing'])
            ->groupBy('employment_record_id');

        $entries = LeaveLedgerEntry::query()->where('school_id', $school->id)->where('leave_type_id', $leaveTypeId)
            ->where('leave_year_id', $leaveYearId)->whereIn('employment_record_id', $ids)
            ->where(fn ($q) => $q->where('kind', 'allocation')->orWhere(fn ($a) => $a->where('kind', 'accrual')->where('accrual_period', $periodStart)))
            ->get(['employment_record_id', 'kind', 'units'])->groupBy('employment_record_id');

        $drift = $this->drift($school, $leaveTypeId, $periodStart, $page);

        $result = [];
        foreach ($page as $row) {
            $id = $row['employmentRecordId'];
            $overlapping = $assignments->get($id) ?? collect();
            $assignment = $overlapping->count() === 1 ? $overlapping->first() : null;
            $whole = $assignment !== null && (string) $assignment->effective_from <= $periodStart
                && ($assignment->effective_to === null || (string) $assignment->effective_to >= $periodEnd);
            $existing = $entries->get($id) ?? collect();
            $accrued = $existing->firstWhere('kind', 'accrual');
            $timing = $assignment?->accrual_timing === null ? null : LeaveAccrualTiming::from((string) $assignment->accrual_timing);
            $postableOn = $timing?->postableOn($periodStart);

            $outcome = match (true) {
                ! isset($spanning[$id]) => LeaveAccrualOutcome::PARTIAL_PERIOD,
                $overlapping->isEmpty() => LeaveAccrualOutcome::NO_POLICY,
                ! $whole => LeaveAccrualOutcome::ASSIGNMENT_NOT_WHOLE_PERIOD,
                $assignment->entitlement_method !== LeaveEntitlementMethod::MONTHLY->value => LeaveAccrualOutcome::NOT_MONTHLY_POLICY,
                $accrued !== null => LeaveAccrualOutcome::ALREADY_CREDITED,
                $existing->contains('kind', 'allocation') => LeaveAccrualOutcome::ANNUAL_ALLOCATION_EXISTS,
                $postableOn !== null && $postableOn > $today => LeaveAccrualOutcome::NOT_DUE,
                default => LeaveAccrualOutcome::CREDITED,
            };
            $units = match ($outcome) {
                LeaveAccrualOutcome::ALREADY_CREDITED => (int) $accrued->units,
                LeaveAccrualOutcome::CREDITED => (int) $assignment->monthly_accrual_units,
                default => null,
            };
            $assignedPolicy = $whole ? (string) $assignment->leave_policy_id : null;

            $result[] = new LeaveAccrualEvaluation(
                employmentRecordId: $id,
                employeeNumber: $row['employeeNumber'],
                fullName: $row['fullName'],
                outcome: $outcome,
                units: $units,
                assignmentId: $whole ? (string) $assignment->id : null,
                policyId: $assignedPolicy,
                postableOn: $postableOn,
                eligibilityDrift: $assignedPolicy !== null && ($drift[$id] ?? null) !== $assignedPolicy,
            );
        }

        return $result;
    }

    public static function periodEnd(string $periodStart): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $periodStart)->endOfMonth()->toDateString();
    }

    /**
     * Each employment's single HR.9 resolution on the month's first day (null:
     * none or ambiguous) -- facts of THAT date, never today's.
     *
     * @param  list<array{employmentRecordId: string, employeeId: string, employeeNumber: ?string, fullName: ?string, status: string, current: bool, employeeActive: bool}>  $page
     * @return array<string, ?string>
     */
    private function drift(School $school, string $leaveTypeId, string $periodStart, array $page): array
    {
        $versions = $this->eligibility->activeVersions($school, $leaveTypeId);
        $map = [];
        foreach ($this->eligibility->resolve($school, $leaveTypeId, $periodStart, $versions, null, $page) as $row) {
            /** @var LeaveEligibilityRow $row */
            $map[$row->employmentRecordId] = count($row->matchingPolicyIds) === 1 && $row->outcome !== LeaveEligibilityOutcome::EMPLOYMENT_NOT_ELIGIBLE
                ? $row->matchingPolicyIds[0] : null;
        }

        return $map;
    }

    /**
     * The preview's read of the same HR rule: HR's batch reader in a short
     * read-only transaction of its own (its FOR SHARE ends at once).
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function spanningUnlocked(School $school, array $ids, string $from, string $to): array
    {
        return DB::transaction(fn (): array => $this->coverage->holdRecordsSpanning($school, $ids, $from, $to));
    }
}
