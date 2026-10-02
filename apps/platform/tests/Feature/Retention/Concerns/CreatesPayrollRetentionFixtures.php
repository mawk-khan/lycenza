<?php

namespace Tests\Feature\Retention\Concerns;

use App\Domain\Finance\Application\LedgerBalanceReader;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollAccountingConfigurationService;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollPostingService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Application\SalaryComponentService;
use App\Domain\Payroll\Application\SalaryStructureService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Statutory\Application\StatutoryAccountingConfigurationService;
use App\Domain\Payroll\Statutory\Application\StatutoryPayrollPostingService;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Finance\Concerns\CreatesFinanceRetentionFixtures;

/**
 * E21.3F (E21-D9 x E21-D8): posted payroll built in the past, so that BOTH
 * database floors genuinely pass against the database's real clock (8
 * calendar years before the real today): runs in 2015-2016, financial year
 * 2015-16 closed on 2016-04-05. The PHP clock is back at the real today
 * after every helper, so the PHP cutoffs agree with the database.
 *
 * Every paid Employee is employed from 2014-01-01 on a 50000.00 basic and is
 * still ACTIVE until `separate()` ends the employment.
 */
trait CreatesPayrollRetentionFixtures
{
    use CreatesFinanceRetentionFixtures;

    /** @return array{actor: User, structure: mixed, line: string, payable: LedgerAccount, expense: LedgerAccount} */
    protected function payrollSetup(School $school): array
    {
        $actor = $this->createUser();

        return $this->inSchool($school, function () use ($school, $actor): array {
            $expense = LedgerAccount::factory()->for($school, 'school')->type('expense')->create();
            $payable = LedgerAccount::factory()->for($school, 'school')->type('liability')->create();
            app(PayrollAccountingConfigurationService::class)->configure($school, $expense->id, $payable->id, $actor);

            $structures = app(SalaryStructureService::class);
            $structure = $structures->createDraft($school, 'GRADE-RT', 'Grade retention', $actor);
            $basic = app(SalaryComponentService::class)->create($school, 'BASIC', 'Basic', 'earning', null, $actor);
            $line = $structures->addComponent($structure, new AddStructureComponentData($basic->id, 'fixed_amount', null, null, 1), $actor);

            return ['actor' => $actor, 'structure' => $structures->activate($structure, $actor), 'line' => $line->id, 'payable' => $payable, 'expense' => $expense];
        });
    }

    /** Statutory accounting, so a run's statutory results can be posted. */
    protected function statutorySetup(School $school, array $setup): void
    {
        $this->inSchool($school, function () use ($school, $setup): void {
            $liability = fn () => LedgerAccount::factory()->for($school, 'school')->type('liability')->create()->id;
            $expense = fn () => LedgerAccount::factory()->for($school, 'school')->type('expense')->create()->id;
            app(StatutoryAccountingConfigurationService::class)->configure($school, [
                'employee_pf_payable_ledger_account_id' => $liability(), 'employer_eps_payable_ledger_account_id' => $liability(),
                'employer_epf_payable_ledger_account_id' => $liability(), 'pf_admin_charge_payable_ledger_account_id' => $liability(),
                'edli_payable_ledger_account_id' => $liability(), 'esi_payable_ledger_account_id' => $liability(),
                'tds_payable_ledger_account_id' => $liability(), 'professional_tax_payable_ledger_account_id' => $liability(),
                'lwf_payable_ledger_account_id' => $liability(), 'employer_pf_contribution_expense_ledger_account_id' => $expense(),
                'pf_admin_charge_expense_ledger_account_id' => $expense(), 'edli_expense_ledger_account_id' => $expense(),
                'employer_esi_contribution_expense_ledger_account_id' => $expense(), 'employer_lwf_contribution_expense_ledger_account_id' => $expense(),
            ], $setup['actor']);
        });
    }

    /** A compensated Employee, employed from 2014-01-01 and still active. */
    protected function paidEmployee(School $school, array $setup): Employee
    {
        $employee = $this->createEmployee($school);
        $record = $this->createEmploymentRecord($employee, ['status' => 'active', 'starts_on' => '2014-01-01', 'ends_on' => null]);
        $this->inSchool($school, fn () => app(CompensationService::class)->assign(
            $school, $record, $setup['structure'], Carbon::parse('2014-01-01'), [new FixedComponentValueInput($setup['line'], '50000.00')], $setup['actor'],
        ));

        return $employee;
    }

    /**
     * One regular run for `$month` (Y-m-01), calculated and approved during
     * that month and posted at `$postedOn`. With `$statutory`, every result
     * also gets a statutory result (200.00 PT, 7.00 LWF) and an LWF annual
     * charge before approval, and the statutory posting follows the payroll
     * posting (unless `$postStatutory` is false). `$approve = false` leaves
     * it calculated.
     */
    protected function postedRun(School $school, string $month, ?string $postedOn, bool $statutory = false, bool $approve = true, bool $postStatutory = true): PayrollRun
    {
        $this->travelTo(Carbon::parse($month)->addDays(9)->setTime(6, 0));
        $preparer = $this->createUser();
        $run = $this->inSchool($school, function () use ($school, $month, $preparer): PayrollRun {
            $periods = app(PayrollPeriodService::class);
            $period = $periods->open($periods->createPeriod($school, Carbon::parse($month), null, $preparer), $preparer);
            $runs = app(PayrollRunService::class);
            $run = $runs->createRun($period, $preparer);
            $runs->calculate($run, $preparer);

            return $run->fresh();
        });

        if ($statutory) {
            $this->inSchool($school, function () use ($school, $run, $month): void {
                foreach (DB::table('payroll_run_results')->where('payroll_run_id', $run->id)->get(['id', 'employment_record_id']) as $result) {
                    DB::table('payroll_statutory_calculation_results')->insert([
                        'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'payroll_run_result_id' => $result->id,
                        'professional_tax' => '200.00', 'lwf_charged' => true, 'employee_lwf' => '7.00', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    DB::table('payroll_lwf_annual_charges')->insert([
                        'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'employment_record_id' => $result->employment_record_id,
                        'annual_cycle_year' => (int) substr($month, 0, 4), 'payroll_run_result_id' => $result->id, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            });
        }

        if ($approve) {
            $run = $this->inSchool($school, fn () => app(PayrollRunService::class)->approve($run, $this->createUser()));
        }
        if ($approve && $postedOn !== null) {
            $this->travelTo(Carbon::parse($postedOn.' 06:00:00'));
            $poster = $this->createUser();
            $this->inSchool($school, fn () => app(PayrollPostingService::class)->post($run->fresh(), $poster));
            if ($statutory && $postStatutory) {
                $this->inSchool($school, fn () => app(StatutoryPayrollPostingService::class)->post($run->fresh(), $poster));
            }
        }
        $this->travelBack();

        return $this->inSchool($school, fn () => $run->fresh());
    }

    /** Ends every employment of the Employee on `$endsOn`. */
    protected function separate(Employee $employee, ?string $endsOn, string $status = 'separated'): void
    {
        $this->inSchool($employee->school, fn () => DB::table('employment_records')->where('employee_id', $employee->id)->update(['status' => $status, 'ends_on' => $endsOn]));
    }

    /** Closes the School's financial year 2015-16 on 2016-04-05 (8+ years before the real today). */
    protected function closeOldYear(School $school): void
    {
        $w = ['school' => $school, 'closer' => $this->createUserWithCapabilities($school, ['finance.ledger.view', 'finance.periods.manage'])];
        $this->travelTo(Carbon::parse('2016-04-05 06:00:00', 'UTC'));
        $this->closePeriod($w, $this->periodByKey($school, '2015-16'));
        $this->travelBack();
    }

    /** @return list<string> the journal entries the run's postings (payroll and statutory) reference */
    protected function runEntries(School $school, string $runId): array
    {
        return $this->inSchool($school, fn () => DB::table('payroll_run_postings')->where('payroll_run_id', $runId)->pluck('journal_entry_id')
            ->merge(DB::table('payroll_statutory_run_postings')->where('payroll_run_id', $runId)->pluck('journal_entry_id'))->map(fn ($id) => (string) $id)->all());
    }

    /** Every account balance through the production read (payroll accounts included). */
    protected function balances(School $school): array
    {
        $readings = [];
        foreach (app(LedgerBalanceReader::class)->balances($school) as $key => $balance) {
            $readings[$key] = $balance['debit'].'|'.$balance['credit'];
        }
        ksort($readings);

        return $readings;
    }

    protected function rowsWhere(School $school, string $table, string $column, string $id): int
    {
        return $this->inSchool($school, fn () => DB::table($table)->where($column, $id)->count());
    }

    protected function enablePayrollRetention(): void
    {
        config(['retention.employee_ancillary_years' => 2, 'retention.employee_evidence_years' => 8, 'retention.hold_school_ids' => []]);
    }
}
