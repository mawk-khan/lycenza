<?php

namespace Tests\Feature\Finance\Concerns;

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Finance\Application\Periods\FinancialBalanceVerifier;
use App\Domain\Finance\Application\Periods\FinancialPeriodCloseService;
use App\Domain\Finance\Application\Periods\FinancialPeriodSummary;
use App\Domain\Finance\Infrastructure\FinancialPeriod;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payments\Application\ChargeOutstandingReader;
use App\Domain\Payments\Application\StudentFeeStatementReadService;
use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollAccountingConfigurationService;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Application\SalaryComponentService;
use App\Domain\Payroll\Application\SalaryStructureService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\School;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Fees\Concerns\CreatesReceiptFixtures;

/**
 * E21.3A (ADR 0064): a School (Asia/Kolkata, April start) with ledger
 * history in two financial years:
 *
 * FY 2025-26 (posted on 2025-06-15):
 * - charges c1 1000.00, c2 500.00, c3 800.00 for one Student;
 * - a 300.00 payment on c1 and a 50.00 payment on c3;
 * - approved concessions of 200.00 on c1 (adjustment a1) and 100.00 on c3
 *   (adjustment a3);
 * - a manual 75.00 journal entry (`manual`).
 *
 * FY 2026-27 (posted on 2026-05-10): a 100.00 payment on c1 and charge c4
 * 400.00.
 *
 * The clock is then back at the real today (after 2026-03-31), so 2025-26
 * has ended and 2026-27 is current.
 */
trait CreatesFinancialPeriodFixtures
{
    use CreatesReceiptFixtures;

    protected function twoYearWorld(): array
    {
        $this->travelTo(Carbon::parse('2025-06-15 06:00:00', 'UTC'));
        $w = $this->concessionWorld();
        $w['c1'] = $w['charge'];
        $w['c2'] = $this->assessCharge($w['school'], $w['student'], $w['year'], $w['receivable'], $w['revenue'], '500.00');
        $w['c3'] = $this->assessCharge($w['school'], $w['student'], $w['year'], $w['receivable'], $w['revenue'], '800.00');
        $w['p1'] = $this->pay($w, '300.00', null, $w['c1']);
        $this->pay($w, '50.00', null, $w['c3']);
        $this->concessions()->approve($w['school'], $this->requestTargeted($w, '200.00', charge: $w['c1'])->id, $w['checker']);
        $this->concessions()->approve($w['school'], $this->requestTargeted($w, '100.00', charge: $w['c3'])->id, $w['checker']);
        $w['a1'] = $this->adjustmentsOf($w, $w['c1']->id)->sole();
        $w['a3'] = $this->adjustmentsOf($w, $w['c3']->id)->sole();
        $w['manual'] = $this->postBalancedJournalEntry($w['school'], $w['settlement'], $w['revenue'], '75.00');

        $this->travelTo(Carbon::parse('2026-05-10 06:00:00', 'UTC'));
        $this->pay($w, '100.00', null, $w['c1']);
        $w['c4'] = $this->assessCharge($w['school'], $w['student'], $w['year'], $w['receivable'], $w['revenue'], '400.00');
        $this->travelBack();

        $w['closer'] = $this->createUserWithCapabilities($w['school'], ['finance.ledger.view', 'finance.periods.manage']);
        $w['fy2526'] = $this->periodByKey($w['school'], '2025-26');
        $w['fy2627'] = $this->periodByKey($w['school'], '2026-27');

        return $w;
    }

    /**
     * Cleanup for tests that COMMIT their fixtures. A posted ledger, frozen
     * payroll rows and closed periods refuse an ordinary cascade delete of
     * the School, and residue (outbox events, test roles) would leak into
     * later tests that count globally. The migration role therefore removes
     * every row of the School with triggers off (replica mode, test
     * database only), then the School and the test capability roles its
     * members held.
     */
    protected function purgeCommittedSchools(array $schools): void
    {
        $admin = DB::connection('pgsql_admin');
        foreach ($schools as $school) {
            $roles = $admin->table('membership_role_assignments as a')->join('roles as r', 'r.id', '=', 'a.role_id')
                ->where('a.school_id', $school->id)->where('r.key', 'like', 'test.capability_grant.%')->distinct()->pluck('r.id')->all();
            $admin->transaction(function () use ($admin, $school, $roles) {
                $admin->statement("SET LOCAL session_replication_role = 'replica'");
                $tables = $admin->select(
                    "SELECT c.table_name FROM information_schema.columns c
                       JOIN pg_class t ON t.relname = c.table_name AND t.relkind = 'r' AND t.relnamespace = 'public'::regnamespace
                      WHERE c.table_schema = 'public' AND c.column_name = 'school_id'",
                );
                foreach ($tables as $row) {
                    $admin->table($row->table_name)->where('school_id', $school->id)->delete();
                }
                $admin->table('schools')->where('id', $school->id)->delete();
                $admin->table('role_capabilities')->whereIn('role_id', $roles)->delete();
                $admin->table('roles')->whereIn('id', $roles)->delete();
            });
        }
    }

    protected function periodByKey(School $school, string $key): FinancialPeriodSummary
    {
        return $this->inSchool($school, fn () => FinancialPeriodSummary::fromModel(FinancialPeriod::query()->where('period_key', $key)->firstOrFail()));
    }

    protected function closePeriod(array $w, FinancialPeriodSummary $period, ?string $confirmation = null): FinancialPeriodSummary
    {
        return app(FinancialPeriodCloseService::class)->close($w['school'], $period->id, $confirmation ?? $period->key, $w['closer']);
    }

    protected function verifyBalances(School $school): array
    {
        return app(FinancialBalanceVerifier::class)->verifySnapshot($school)->all();
    }

    /**
     * Every Finance reading a user or report sees today, from the whole
     * history: account totals, each charge's outstanding, the Student's
     * statement totals.
     *
     * @return array<string, mixed>
     */
    protected function financeReadings(array $w): array
    {
        $school = $w['school'];
        $accounts = $this->inSchool($school, fn () => collect(DB::select(
            'SELECT ledger_account_id, sum(coalesce(debit_amount, 0)) AS d, sum(coalesce(credit_amount, 0)) AS c FROM journal_lines WHERE school_id = ? GROUP BY 1 ORDER BY 1',
            [$school->id],
        ))->mapWithKeys(fn ($r) => [$r->ledger_account_id => "{$r->d}|{$r->c}"])->all());

        $amounts = [];
        $cancelled = [];
        foreach (app(ChargeService::class)->ledgerFacts($school) as $fact) {
            $amounts[$fact->id] = Money::of($fact->amount, $fact->currency);
            $cancelled[$fact->id] = $fact->cancelled;
        }
        $outstanding = array_map(fn (Money $m) => $m->amount(), $this->inSchool($school, fn () => app(ChargeOutstandingReader::class)->forCharges($school, $amounts)));
        foreach ($cancelled as $id => $isCancelled) {
            if ($isCancelled) {
                $outstanding[$id] = 'cancelled';
            }
        }
        ksort($outstanding);

        $statement = app(StudentFeeStatementReadService::class)->statementFor($school, $w['student']->id, null, $w['recorder']);

        $totals = array_map(fn ($v) => $v instanceof Money ? $v->amount() : $v, $statement->totals);

        return ['accounts' => $accounts, 'outstanding' => $outstanding, 'statement' => json_encode([$totals, $statement->lines])];
    }

    protected function entryPeriodKey(School $school, string $entryId): ?string
    {
        return $this->inSchool($school, fn () => FinancialPeriod::query()
            ->whereKey(JournalEntry::query()->whereKey($entryId)->value('financial_period_id'))
            ->value('period_key'));
    }

    /** An approved payroll run for one Employee (50000.00 basic), payroll month `$month`. */
    protected function approvedPayrollRun(School $school, string $month = '2026-09-01'): PayrollRun
    {
        $preparer = $this->createUser();

        return $this->inSchool($school, function () use ($school, $preparer, $month) {
            $expense = LedgerAccount::factory()->for($school, 'school')->type('expense')->create();
            $payable = LedgerAccount::factory()->for($school, 'school')->type('liability')->create();
            app(PayrollAccountingConfigurationService::class)->configure($school, $expense->id, $payable->id, $preparer);

            $structures = app(SalaryStructureService::class);
            $structure = $structures->createDraft($school, 'GRADE-FP', 'Grade period close', $preparer);
            $basic = app(SalaryComponentService::class)->create($school, 'BASIC', 'Basic', 'earning', null, $preparer);
            $component = $structures->addComponent($structure, new AddStructureComponentData($basic->id, 'fixed_amount', null, null, 1), $preparer);
            $structure = $structures->activate($structure, $preparer);

            $employee = Employee::factory()->for($school, 'school')->create();
            $record = EmploymentRecord::factory()->create(['school_id' => $school->id, 'employee_id' => $employee->id, 'starts_on' => '2025-01-01']);
            app(CompensationService::class)->assign($school, $record, $structure, Carbon::parse('2025-01-01'), [new FixedComponentValueInput($component->id, '50000.00')], $preparer);

            $periods = app(PayrollPeriodService::class);
            $period = $periods->open($periods->createPeriod($school, Carbon::parse($month), null, $preparer), $preparer);
            $runs = app(PayrollRunService::class);
            $run = $runs->createRun($period, $preparer);
            $runs->calculate($run, $preparer);

            return $runs->approve($run->fresh(), $this->createUser());
        });
    }
}
