<?php

namespace Tests\Feature\Payroll\Statutory;

use App\Domain\Finance\Infrastructure\JournalLine;
use App\Domain\Finance\Infrastructure\LedgerAccount;
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
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryAlreadyPostedException;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryCalculationMissingException;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryEmployeeFactsMissingException;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryRunNotEligibleForCalculationException;
use App\Domain\Payroll\Statutory\Application\StatutoryAccountingConfigurationService;
use App\Domain\Payroll\Statutory\Application\StatutoryPayrollCalculationService;
use App\Domain\Payroll\Statutory\Application\StatutoryPayrollPostingService;
use App\Domain\Payroll\Statutory\Infrastructure\EmployeePfStatus;
use App\Domain\Payroll\Statutory\Infrastructure\EmployeeTaxProfile;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollSalaryComponentStatutoryClassification;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollStatutoryAccountingConfiguration;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollStatutoryCalculationResult;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Checkpoint 9.6F -- functional tests for
 * `StatutoryPayrollCalculationService`/`StatutoryPayrollPostingService`
 * (ADR 0036 correction addendum §1.11). The scenario below is chosen
 * so every branch resolves to a hand-verifiable figure: Basic
 * (core_wage) 20000 + HRA (tested_remuneration) 8000 = 28000 gross,
 * ABOVE the ESI wage threshold (21000, so ESI contribution is zero --
 * ESI's own branch coverage lives in Checkpoint 9.6B/9.6D's golden
 * fixtures, not repeated here) and ABOVE the PF membership wage
 * ceiling (15000, but `has_existing_pf_membership = true` so PF still
 * applies, capped at the ceiling) and yields a taxable income well
 * under the new-regime rebate threshold, so TDS is exactly zero this
 * cycle -- isolating this test to what Checkpoint 9.6F actually adds:
 * orchestration, persistence, and the statutory GL posting balance.
 */
class StatutoryPayrollCalculationAndPostingTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    private function calculationService(): StatutoryPayrollCalculationService
    {
        return app(StatutoryPayrollCalculationService::class);
    }

    private function postingService(): StatutoryPayrollPostingService
    {
        return app(StatutoryPayrollPostingService::class);
    }

    /**
     * @return array{run: PayrollRun, employmentRecordId: string, actor: User}
     */
    private function buildCalculatedRun(School $school, bool $withPfStatus = true, bool $withTaxProfile = true): array
    {
        $preparer = $this->createUser();
        $structureService = app(SalaryStructureService::class);
        $componentService = app(SalaryComponentService::class);

        $structure = $structureService->createDraft($school, 'GRADE-STAT', 'Grade Statutory', $preparer);
        $basic = $componentService->create($school, 'BASIC', 'Basic', 'earning', null, $preparer);
        $hra = $componentService->create($school, 'HRA', 'House Rent Allowance', 'earning', null, $preparer);

        PayrollSalaryComponentStatutoryClassification::query()->create([
            'school_id' => $school->id,
            'salary_component_id' => $basic->id,
            'pf_classification' => 'core_wage',
            'esi_wage_included' => true,
            'income_tax_treatment' => 'taxable',
            'effective_from' => '2026-04-01',
        ]);
        PayrollSalaryComponentStatutoryClassification::query()->create([
            'school_id' => $school->id,
            'salary_component_id' => $hra->id,
            'pf_classification' => 'tested_remuneration',
            'esi_wage_included' => true,
            'income_tax_treatment' => 'taxable',
            'effective_from' => '2026-04-01',
        ]);

        $basicSc = $structureService->addComponent($structure, new AddStructureComponentData($basic->id, 'fixed_amount', null, null, 1), $preparer);
        $hraSc = $structureService->addComponent($structure, new AddStructureComponentData($hra->id, 'fixed_amount', null, null, 2), $preparer);
        $structure = $structureService->activate($structure, $preparer);

        $employmentRecord = $this->createEmploymentRecord($this->createEmployee($school), ['starts_on' => '2025-01-01']);

        if ($withPfStatus) {
            EmployeePfStatus::query()->create([
                'school_id' => $school->id,
                'employment_record_id' => $employmentRecord->id,
                'has_existing_pf_membership' => true,
                'has_uan' => true,
                'has_approved_higher_wage_contribution' => false,
                'is_eps_eligible' => true,
            ]);
        }

        if ($withTaxProfile) {
            EmployeeTaxProfile::query()->create([
                'school_id' => $school->id,
                'employment_record_id' => $employmentRecord->id,
                'fiscal_year_start' => '2026-04-01',
                'regime' => 'new',
            ]);
        }

        app(CompensationService::class)->assign(
            $school, $employmentRecord, $structure, Carbon::parse('2025-01-01'),
            [
                new FixedComponentValueInput($basicSc->id, '20000.00'),
                new FixedComponentValueInput($hraSc->id, '8000.00'),
            ],
            $preparer,
        );

        $periodService = app(PayrollPeriodService::class);
        $runService = app(PayrollRunService::class);

        $period = $periodService->open($periodService->createPeriod($school, Carbon::parse('2026-09-01'), null, $preparer), $preparer);
        $run = $runService->createRun($period, $preparer);
        $runService->calculate($run, $preparer);

        return ['run' => $run->fresh(), 'employmentRecordId' => $employmentRecord->id, 'actor' => $preparer];
    }

    /**
     * @return array{payable: LedgerAccount, config: PayrollStatutoryAccountingConfiguration}
     */
    private function configureAccounting(School $school, User $actor): array
    {
        $salaryExpense = LedgerAccount::factory()->for($school, 'school')->type('expense')->create();
        $salaryPayable = LedgerAccount::factory()->for($school, 'school')->type('liability')->create();
        app(PayrollAccountingConfigurationService::class)->configure($school, $salaryExpense->id, $salaryPayable->id, $actor);

        $liability = fn () => LedgerAccount::factory()->for($school, 'school')->type('liability')->create()->id;
        $expense = fn () => LedgerAccount::factory()->for($school, 'school')->type('expense')->create()->id;

        $accountIds = [
            'employee_pf_payable_ledger_account_id' => $liability(),
            'employer_eps_payable_ledger_account_id' => $liability(),
            'employer_epf_payable_ledger_account_id' => $liability(),
            'pf_admin_charge_payable_ledger_account_id' => $liability(),
            'edli_payable_ledger_account_id' => $liability(),
            'esi_payable_ledger_account_id' => $liability(),
            'tds_payable_ledger_account_id' => $liability(),
            'professional_tax_payable_ledger_account_id' => $liability(),
            'lwf_payable_ledger_account_id' => $liability(),
            'employer_pf_contribution_expense_ledger_account_id' => $expense(),
            'pf_admin_charge_expense_ledger_account_id' => $expense(),
            'edli_expense_ledger_account_id' => $expense(),
            'employer_esi_contribution_expense_ledger_account_id' => $expense(),
            'employer_lwf_contribution_expense_ledger_account_id' => $expense(),
        ];

        $config = app(StatutoryAccountingConfigurationService::class)->configure($school, $accountIds, $actor);

        return ['payable' => $salaryPayable, 'config' => $config];
    }

    #[Test]
    public function it_calculates_and_persists_an_immutable_statutory_result_matching_hand_verified_figures(): void
    {
        $school = $this->createSchool();
        $built = $this->context()->withSchool($school, fn () => $this->buildCalculatedRun($school));

        $count = $this->context()->withSchool($school, fn () => $this->calculationService()->calculateForRun($built['run'], $built['actor']));
        $this->assertSame(1, $count);

        $result = $this->context()->withSchool(
            $school,
            fn () => PayrollStatutoryCalculationResult::query()
                ->whereHas('payrollRunResult', fn ($q) => $q->where('employment_record_id', $built['employmentRecordId']))
                ->firstOrFail(),
        );

        $this->assertFalse($result->is_pf_excluded_employee);
        $this->assertSame('20000.00', $result->pf_uncapped_statutory_wage);
        $this->assertSame('15000.00', $result->pf_contribution_base);
        $this->assertSame('1800.00', $result->employee_pf_mandatory);
        $this->assertSame('0.00', $result->employee_pf_voluntary);
        $this->assertSame('1800.00', $result->employer_pf_total);
        $this->assertSame('1250.00', $result->employer_eps);
        $this->assertSame('550.00', $result->employer_epf);
        $this->assertSame('75.00', $result->pf_edli);
        $this->assertSame('500.00', $result->pf_admin_charge);

        $this->assertFalse($result->esi_is_covered);
        $this->assertSame('0.00', $result->employee_esi);
        $this->assertSame('0.00', $result->employer_esi);

        $this->assertSame('200.00', $result->professional_tax);

        $this->assertTrue($result->lwf_charged);
        $this->assertSame('2.00', $result->employee_lwf);
        $this->assertSame('5.00', $result->employer_lwf);

        $this->assertSame('0.00', $result->tds_monthly_deduction);
        $this->assertNull($result->tds_residual_compliance_exception);
    }

    #[Test]
    public function calculation_is_rejected_once_the_run_is_approved(): void
    {
        $school = $this->createSchool();
        $built = $this->context()->withSchool($school, fn () => $this->buildCalculatedRun($school));
        $approver = $this->createUser();
        $this->context()->withSchool($school, fn () => app(PayrollRunService::class)->approve($built['run'], $approver));

        $this->expectException(StatutoryRunNotEligibleForCalculationException::class);

        $this->context()->withSchool($school, fn () => $this->calculationService()->calculateForRun($built['run']->fresh(), $built['actor']));
    }

    #[Test]
    public function calculation_fails_closed_when_pf_status_facts_are_missing(): void
    {
        $school = $this->createSchool();
        $built = $this->context()->withSchool($school, fn () => $this->buildCalculatedRun($school, withPfStatus: false));

        $this->expectException(StatutoryEmployeeFactsMissingException::class);

        $this->context()->withSchool($school, fn () => $this->calculationService()->calculateForRun($built['run'], $built['actor']));
    }

    #[Test]
    public function posting_produces_a_balanced_journal_entry_matching_hand_verified_totals(): void
    {
        $school = $this->createSchool();
        $poster = $this->createUser();
        $accounting = $this->context()->withSchool($school, fn () => $this->configureAccounting($school, $poster));
        $built = $this->context()->withSchool($school, fn () => $this->buildCalculatedRun($school));

        $this->context()->withSchool($school, fn () => $this->calculationService()->calculateForRun($built['run'], $built['actor']));

        $approver = $this->createUser();
        $run = $this->context()->withSchool($school, fn () => app(PayrollRunService::class)->approve($built['run']->fresh(), $approver));
        $this->context()->withSchool($school, fn () => app(PayrollPostingService::class)->post($run, $poster));
        $run = $this->context()->withSchool($school, fn () => $built['run']->fresh());

        $posting = $this->context()->withSchool($school, fn () => $this->postingService()->post($run, $poster));

        $lines = $this->context()->withSchool($school, fn () => JournalLine::query()->where('journal_entry_id', $posting->journal_entry_id)->get());

        $totalDebit = $lines->sum(fn ($l) => (float) $l->debit_amount);
        $totalCredit = $lines->sum(fn ($l) => (float) $l->credit_amount);
        $this->assertEqualsWithDelta(4382.00, $totalDebit, 0.001);
        $this->assertEqualsWithDelta(4382.00, $totalCredit, 0.001);

        $config = $accounting['config'];
        $salaryPayableLine = $lines->firstWhere('ledger_account_id', $accounting['payable']->id);
        $this->assertSame('2002.00', $salaryPayableLine->debit_amount);

        $employeePfLine = $lines->firstWhere('ledger_account_id', $config->employee_pf_payable_ledger_account_id);
        $this->assertSame('1800.00', $employeePfLine->credit_amount);

        $ptLine = $lines->firstWhere('ledger_account_id', $config->professional_tax_payable_ledger_account_id);
        $this->assertSame('200.00', $ptLine->credit_amount);

        $lwfPayableLine = $lines->firstWhere('ledger_account_id', $config->lwf_payable_ledger_account_id);
        $this->assertSame('7.00', $lwfPayableLine->credit_amount);

        $employerPfExpenseLine = $lines->firstWhere('ledger_account_id', $config->employer_pf_contribution_expense_ledger_account_id);
        $this->assertSame('1800.00', $employerPfExpenseLine->debit_amount);

        $pfAdminExpenseLine = $lines->firstWhere('ledger_account_id', $config->pf_admin_charge_expense_ledger_account_id);
        $this->assertSame('500.00', $pfAdminExpenseLine->debit_amount);
        $pfAdminPayableLine = $lines->firstWhere('ledger_account_id', $config->pf_admin_charge_payable_ledger_account_id);
        $this->assertSame('500.00', $pfAdminPayableLine->credit_amount);

        // ESI is zero this cycle -- its payable/expense accounts must
        // carry no line at all (zero-amount lines are never posted).
        $this->assertNull($lines->firstWhere('ledger_account_id', $config->esi_payable_ledger_account_id));
        $this->assertNull($lines->firstWhere('ledger_account_id', $config->employer_esi_contribution_expense_ledger_account_id));

        $event = $this->context()->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'payroll.statutory_posting.posted')->where('subject_id', $run->id)->first(),
        );
        $this->assertNotNull($event);
    }

    #[Test]
    public function posting_is_rejected_when_statutory_calculation_has_not_run(): void
    {
        $school = $this->createSchool();
        $poster = $this->createUser();
        $this->context()->withSchool($school, fn () => $this->configureAccounting($school, $poster));
        $built = $this->context()->withSchool($school, fn () => $this->buildCalculatedRun($school));

        $approver = $this->createUser();
        $run = $this->context()->withSchool($school, fn () => app(PayrollRunService::class)->approve($built['run']->fresh(), $approver));
        $run = $this->context()->withSchool($school, fn () => app(PayrollPostingService::class)->post($run, $poster));

        $this->expectException(StatutoryCalculationMissingException::class);

        $this->context()->withSchool($school, fn () => $this->postingService()->post($built['run']->fresh(), $poster));
    }

    #[Test]
    public function posting_twice_is_rejected(): void
    {
        $school = $this->createSchool();
        $poster = $this->createUser();
        $this->context()->withSchool($school, fn () => $this->configureAccounting($school, $poster));
        $built = $this->context()->withSchool($school, fn () => $this->buildCalculatedRun($school));
        $this->context()->withSchool($school, fn () => $this->calculationService()->calculateForRun($built['run'], $built['actor']));

        $approver = $this->createUser();
        $run = $this->context()->withSchool($school, fn () => app(PayrollRunService::class)->approve($built['run']->fresh(), $approver));
        $run = $this->context()->withSchool($school, fn () => app(PayrollPostingService::class)->post($run, $poster));

        $this->context()->withSchool($school, fn () => $this->postingService()->post($built['run']->fresh(), $poster));

        $this->expectException(StatutoryAlreadyPostedException::class);

        $this->context()->withSchool($school, fn () => $this->postingService()->post($built['run']->fresh(), $poster));
    }
}
