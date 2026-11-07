<?php

namespace App\Domain\Leave\Application\Accrual;

use App\Domain\HR\Application\EmploymentRoster;
use App\Domain\Leave\Application\LeaveCapabilities;
use App\Domain\Leave\Infrastructure\LeaveLedgerEntry;
use App\Domain\Leave\Infrastructure\LeaveType;
use App\Domain\Leave\Infrastructure\LeaveYear;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * HR.10 (ADR 0065 §29.8, D11-16): read models over the ONE ledger --
 * nothing is stored, nothing is computed in the browser.
 *
 * - statement(): one account (employment x type x year), every entry in
 *   order with its signed units, running balance and provenance (run,
 *   month, request, close, corrected entry), and the identity
 *   `opening + credits - debits = closing` computed from the entries by the
 *   ledger's one sign convention (LeaveLedgerEntry::CREDIT_KINDS).
 * - grid(): the monthly matrix for one type and year -- a page of
 *   employments (complete id-ordered traversal, server-paged), each month's
 *   POSTED accrual (null when nothing is posted: never a fabricated zero),
 *   the annual allocation if that funds the year, and the balance. A fixed
 *   number of queries per page.
 *
 * `hr.leave.view`; whole-School.
 */
final class LeaveStatementReader
{
    use AuthorizesCapability;

    private const OPENING_KINDS = ['opening', 'carry_forward_in'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly EmploymentRoster $roster,
    ) {}

    /** @return array<string, mixed> */
    public function statement(School $school, string $employmentRecordId, string $leaveTypeId, string $leaveYearId, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::VIEW, $school);

        return $this->context->withSchool($school, function () use ($school, $employmentRecordId, $leaveTypeId, $leaveYearId) {
            $type = LeaveType::query()->where('school_id', $school->id)->findOrFail($leaveTypeId);
            $year = LeaveYear::query()->where('school_id', $school->id)->findOrFail($leaveYearId);
            $entries = LeaveLedgerEntry::query()->where('school_id', $school->id)->where('employment_record_id', $employmentRecordId)
                ->where('leave_type_id', $type->id)->where('leave_year_id', $year->id)->orderBy('created_at')->orderBy('id')->get();

            $running = 0;
            $totals = ['opening' => 0, 'credits' => 0, 'debits' => 0];
            $byKind = array_fill_keys(LeaveLedgerEntry::KINDS, 0);
            $lines = [];
            foreach ($entries as $e) {
                $credit = LeaveLedgerEntry::isCredit($e->kind, $e->direction);
                $signed = $credit ? $e->units : -$e->units;
                $running += $signed;
                $byKind[$e->kind] += $signed;
                $bucket = in_array($e->kind, self::OPENING_KINDS, true) ? 'opening' : ($credit ? 'credits' : 'debits');
                $totals[$bucket] += $e->units;
                $lines[] = [
                    'id' => $e->id, 'kind' => $e->kind, 'direction' => $e->direction, 'signedUnits' => $signed, 'runningBalance' => $running,
                    'reasonCode' => $e->reason_code, 'accrualPeriod' => $e->accrual_period?->toDateString(), 'accrualRunId' => $e->accrual_run_id,
                    'allocationRunId' => $e->allocation_run_id, 'leaveRequestId' => $e->leave_request_id, 'reversesEntryId' => $e->reverses_entry_id,
                    'yearCloseId' => $e->year_close_id, 'leavePolicyAssignmentId' => $e->leave_policy_assignment_id,
                    'correctsEntryId' => $e->source_type === 'leave_ledger_entry' ? $e->source_id : null,
                    'createdAt' => $e->created_at->toIso8601String(),
                ];
            }
            $closing = $totals['opening'] + $totals['credits'] - $totals['debits'];

            return [
                'employmentRecordId' => $employmentRecordId, 'leaveTypeId' => $type->id, 'leaveYearId' => $year->id,
                'opening' => $totals['opening'], 'credits' => $totals['credits'], 'debits' => $totals['debits'], 'closing' => $closing,
                'byKind' => $byKind, 'identityHolds' => $closing === $running, 'lines' => $lines,
            ];
        });
    }

    /**
     * @return array{months: list<string>, rows: list<array<string, mixed>>, nextAfter: ?string}
     */
    public function grid(School $school, string $leaveTypeId, string $leaveYearId, User $actor, ?string $after = null, int $perPage = 50): array
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::VIEW, $school);
        $perPage = max(1, min($perPage, 200));

        return $this->context->withSchool($school, function () use ($school, $leaveTypeId, $leaveYearId, $after, $perPage) {
            $type = LeaveType::query()->where('school_id', $school->id)->findOrFail($leaveTypeId);
            $year = LeaveYear::query()->where('school_id', $school->id)->findOrFail($leaveYearId);
            $months = [];
            for ($m = CarbonImmutable::parse($year->starts_on->toDateString()); $m->toDateString() <= $year->ends_on->toDateString(); $m = $m->addMonthNoOverflow()) {
                $months[] = $m->toDateString();
            }
            $page = $this->roster->pageOverlapping($school, $year->starts_on->toDateString(), $year->ends_on->toDateString(), $after, $perPage);
            $ids = array_column($page, 'employmentRecordId');
            $entries = $ids === [] ? collect() : DB::table('leave_ledger_entries')->where('school_id', $school->id)->where('leave_type_id', $type->id)
                ->where('leave_year_id', $year->id)->whereIn('employment_record_id', $ids)
                ->groupBy('employment_record_id')
                ->selectRaw('employment_record_id, coalesce(sum('.LeaveLedgerEntry::signedUnitsSql().'), 0) AS balance, '
                    ."coalesce(sum(units) FILTER (WHERE kind = 'allocation'), 0) AS allocated, count(*) AS entries, "
                    ."jsonb_object_agg(accrual_period::text, units) FILTER (WHERE kind = 'accrual') AS accruals")
                ->get()->keyBy('employment_record_id');

            $rows = [];
            foreach ($page as $row) {
                $e = $entries->get($row['employmentRecordId']);
                $accruals = $e === null || $e->accruals === null ? [] : (array) json_decode((string) $e->accruals, true);
                $rows[] = [
                    'employmentRecordId' => $row['employmentRecordId'], 'employeeNumber' => $row['employeeNumber'], 'fullName' => $row['fullName'],
                    'employmentStatus' => $row['status'],
                    'months' => array_map(fn (string $m) => isset($accruals[$m]) ? (int) $accruals[$m] : null, $months),
                    'allocatedUnits' => $e === null || (int) $e->allocated === 0 ? null : (int) $e->allocated,
                    'balanceUnits' => $e === null ? null : (int) $e->balance,
                ];
            }

            return ['months' => $months, 'rows' => $rows, 'nextAfter' => count($page) === $perPage ? $page[array_key_last($page)]['employmentRecordId'] : null];
        });
    }
}
