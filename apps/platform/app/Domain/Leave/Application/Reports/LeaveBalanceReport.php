<?php

namespace App\Domain\Leave\Application\Reports;

use App\Domain\HR\Application\Reports\HrReportLabels;
use App\Domain\Leave\Infrastructure\LeaveLedgerEntry;
use App\Domain\Leave\Infrastructure\LeaveType;
use App\Domain\Leave\Infrastructure\LeaveYear;
use App\Models\School;
use App\Support\HrReports\HrReport;
use App\Support\HrReports\HrReportColumn as C;
use App\Support\HrReports\HrReportDefinition as D;
use App\Support\HrReports\HrReportRows;
use App\Support\HrReports\HrReportViewer;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR.14 (D20-23): Leave balances AS AT a date inside one Leave Year, read
 * from the ONE ledger -- never a second accounting engine, never a stored
 * balance. Per account (employment x leave type x year):
 *
 *   opening  = opening + carry_forward_in entries
 *   credits  = every other credit (allocation, accrual, reversal, credit adjustment)
 *   debits   = every debit (consumption, expiry, carry_forward_out, debit adjustment)
 *   balance  = opening + credits - debits
 *
 * by the ledger's one sign convention (LeaveLedgerEntry::CREDIT_KINDS /
 * signedUnitsSql()), so the identity holds per row and a year-end as-at
 * equals LeaveStatementReader's closing. "As at" is the POSTING clock: an
 * entry counts once it was recorded on or before the end of the chosen
 * School-local day (the ledger stores no other effective date for most
 * kinds). Units are half-days, shown as days. Whole-School `hr.leave.view`.
 * The Leave Year is the School's configured one, never an academic or
 * Payroll year.
 */
final class LeaveBalanceReport implements HrReport
{
    public const string KEY = 'hr.leave_balances';

    private const array OPENING_KINDS = ['opening', 'carry_forward_in'];

    public function __construct(private readonly TenantContext $context) {}

    public function definition(): D
    {
        return new D(
            key: self::KEY,
            title: 'Leave balances as at a date',
            description: 'Each leave account\'s opening, credits, debits and balance in one Leave Year, counting ledger entries recorded by the end of the chosen date.',
            category: 'leave',
            owner: 'Leave',
            authority: ['hr.leave.view'],
            route: D::RENDERED,
            basis: D::AS_OF,
            classification: D::EMPLOYMENT_OPERATIONS,
            reference: ['D20-23', 'D20-24', 'D11-16'],
            filters: ['leave_year_id', 'as_of', 'leave_type_id', 'q'],
            columns: [
                new C('employee', 'Staff member', 'employee'),
                new C('leave_type', 'Leave type'),
                new C('opening', 'Opening (days)', 'number'),
                new C('credits', 'Credits (days)', 'number'),
                new C('debits', 'Debits (days)', 'number'),
                new C('balance', 'Balance (days)', 'number'),
            ],
            sorts: ['name' => 'Name', 'balance' => 'Lowest balance first'],
            perPage: 25,
        );
    }

    public function options(School $school, HrReportViewer $viewer): array
    {
        return $this->context->withSchool($school, fn () => [
            'leave_year_id' => LeaveYear::query()->where('school_id', $school->id)->orderByDesc('starts_on')->limit(50)->get(['id', 'label'])
                ->map(fn (LeaveYear $y) => ['value' => $y->id, 'label' => $y->label])->values()->all(),
            'leave_type_id' => LeaveType::query()->where('school_id', $school->id)->orderBy('name')->limit(200)->get(['id', 'name'])
                ->map(fn (LeaveType $t) => ['value' => $t->id, 'label' => $t->name])->values()->all(),
        ]);
    }

    public function defaults(School $school, array $options): array
    {
        $today = SchoolTimezone::today($school);
        $year = $this->context->withSchool($school, fn () => LeaveYear::query()->where('school_id', $school->id)
            ->where('starts_on', '<=', $today)->where('ends_on', '>=', $today)->first()
            ?? LeaveYear::query()->where('school_id', $school->id)->orderByDesc('starts_on')->first());

        return $year === null ? [] : ['leave_year_id' => $year->id, 'as_of' => min($today, $year->ends_on->toDateString())];
    }

    public function page(School $school, HrReportViewer $viewer, array $filters, string $sort, int $page, array $columns): LengthAwarePaginator
    {
        return $this->context->withSchool($school, function () use ($school, $filters, $sort, $page) {
            $year = ($filters['leave_year_id'] ?? '') === '' ? null : LeaveYear::query()->where('school_id', $school->id)->find($filters['leave_year_id']);
            if ($year === null) {
                return new LengthAwarePaginator([], 0, 25, $page);
            }
            $asOf = $filters['as_of'] ?? '';
            if ($asOf === '' || $asOf < $year->starts_on->toDateString() || $asOf > $year->ends_on->toDateString()) {
                throw ValidationException::withMessages(['as_of' => 'Choose a date inside the selected Leave Year.']);
            }
            // Recorded by the end of the School-local day.
            $bound = SchoolTimezone::startOfNextDayUtc($school, $asOf);
            $credit = "(l.kind IN ('".implode("', '", LeaveLedgerEntry::CREDIT_KINDS)."') OR (l.kind = 'adjustment' AND l.direction = 'credit'))";
            $opening = "l.kind IN ('".implode("', '", self::OPENING_KINDS)."')";

            $accounts = DB::table('leave_ledger_entries as l')->where('l.school_id', $school->id)->where('l.leave_year_id', $year->id)
                ->where('l.created_at', '<', $bound)
                ->when(($filters['leave_type_id'] ?? '') !== '', fn ($q) => $q->where('l.leave_type_id', $filters['leave_type_id']))
                ->groupBy('l.employment_record_id', 'l.leave_type_id')
                ->selectRaw("l.employment_record_id, l.leave_type_id,
                    coalesce(sum(l.units) FILTER (WHERE {$opening}), 0) AS opening,
                    coalesce(sum(l.units) FILTER (WHERE {$credit} AND NOT ({$opening})), 0) AS credits,
                    coalesce(sum(l.units) FILTER (WHERE NOT {$credit}), 0) AS debits,
                    coalesce(sum(".LeaveLedgerEntry::signedUnitsSql('l').'), 0) AS balance');
            $query = DB::query()->fromSub($accounts, 'a');
            HrReportLabels::searchEmployment($query, 'a.employment_record_id', $filters['q'] ?? '');
            if ($sort === 'balance') {
                $query->orderBy('a.balance');
            }
            HrReportLabels::orderByEmploymentName($query, 'a.employment_record_id');
            $paginator = $query->orderBy('a.employment_record_id')->orderBy('a.leave_type_id')->paginate(25, ['a.*'], 'page', $page);

            $rows = $paginator->getCollection();
            $labels = app(HrReportLabels::class)->employments($school, $rows->pluck('employment_record_id')->map(fn ($id) => (string) $id)->unique()->values()->all());
            $types = LeaveType::query()->where('school_id', $school->id)->whereIn('id', $rows->pluck('leave_type_id')->unique()->values())->pluck('name', 'id');
            $days = fn ($units) => rtrim(rtrim(number_format(((int) $units) / 2, 1, '.', ''), '0'), '.');

            return HrReportRows::map($paginator, fn ($a) => [
                'key' => $a->employment_record_id.':'.$a->leave_type_id,
                'employee' => $labels[strtolower((string) $a->employment_record_id)]['employee'] ?? null,
                'leave_type' => $types[$a->leave_type_id] ?? null,
                'opening' => $days($a->opening),
                'credits' => $days($a->credits),
                'debits' => $days($a->debits),
                'balance' => $days($a->balance),
            ]);
        });
    }

    public function hasAny(School $school, HrReportViewer $viewer): bool
    {
        return $this->context->withSchool($school, fn () => LeaveLedgerEntry::query()->where('school_id', $school->id)->exists());
    }
}
