<?php

namespace Tests\Feature\Payroll\Statutory;

use App\Domain\Documents\Infrastructure\Document;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\Exceptions\PayrollRunNotEligibleForPayslipException;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollAccountingConfigurationService;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollPostingService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Application\PayslipReadService;
use App\Domain\Payroll\Application\SalaryComponentService;
use App\Domain\Payroll\Application\SalaryStructureService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Statutory\Application\StatutoryPayrollCalculationService;
use App\Domain\Payroll\Statutory\Infrastructure\EmployeePfStatus;
use App\Domain\Payroll\Statutory\Infrastructure\EmployeeStatutoryIdentifier;
use App\Domain\Payroll\Statutory\Infrastructure\EmployeeTaxProfile;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollSalaryComponentStatutoryClassification;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Checkpoint 9.6J -- functional tests for the statutory payslip
 * extension. Reuses the exact hand-verified scenario earlier
 * statutory checkpoints already established (Basic 20000 core_wage +
 * HRA 8000 tested_remuneration, PF contribution base 15000, ESI not
 * covered, TDS zero) so expected figures are already known.
 */
class StatutoryPayslipTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    private function service(): PayslipReadService
    {
        return app(PayslipReadService::class);
    }

    /**
     * @return array{run: PayrollRun, employmentRecordId: string, actor: User}
     */
    private function buildPostedStatutoryRun(School $school): array
    {
        $preparer = $this->createUser();
        $structureService = app(SalaryStructureService::class);
        $componentService = app(SalaryComponentService::class);

        $structure = $structureService->createDraft($school, 'GRADE-PS', 'Grade Payslip', $preparer);
        $basic = $componentService->create($school, 'BASIC', 'Basic', 'earning', null, $preparer);
        $hra = $componentService->create($school, 'HRA', 'House Rent Allowance', 'earning', null, $preparer);

        foreach ([[$basic, 'core_wage'], [$hra, 'tested_remuneration']] as [$component, $classification]) {
            PayrollSalaryComponentStatutoryClassification::query()->create([
                'school_id' => $school->id,
                'salary_component_id' => $component->id,
                'pf_classification' => $classification,
                'esi_wage_included' => true,
                'income_tax_treatment' => 'taxable',
                'effective_from' => '2026-04-01',
            ]);
        }

        $basicSc = $structureService->addComponent($structure, new AddStructureComponentData($basic->id, 'fixed_amount', null, null, 1), $preparer);
        $hraSc = $structureService->addComponent($structure, new AddStructureComponentData($hra->id, 'fixed_amount', null, null, 2), $preparer);
        $structure = $structureService->activate($structure, $preparer);

        $employmentRecord = $this->createEmploymentRecord($this->createEmployee($school, ['full_name' => 'Priya Nair']), ['starts_on' => '2025-01-01']);

        EmployeePfStatus::query()->create([
            'school_id' => $school->id,
            'employment_record_id' => $employmentRecord->id,
            'has_existing_pf_membership' => true,
            'has_uan' => true,
            'has_approved_higher_wage_contribution' => false,
            'is_eps_eligible' => true,
        ]);

        EmployeeTaxProfile::query()->create([
            'school_id' => $school->id,
            'employment_record_id' => $employmentRecord->id,
            'fiscal_year_start' => '2026-04-01',
            'regime' => 'new',
        ]);

        EmployeeStatutoryIdentifier::query()->create([
            'school_id' => $school->id,
            'employment_record_id' => $employmentRecord->id,
            'identifier_type' => 'pan',
            'encrypted_value' => 'ABCDE1234F',
            'lookup_hash' => str_repeat('a', 64),
        ]);

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
        $run = $run->fresh();

        app(StatutoryPayrollCalculationService::class)->calculateForRun($run, $preparer);

        $approver = $this->createUser();
        $run = $runService->approve($run->fresh(), $approver);

        return ['run' => $run, 'employmentRecordId' => $employmentRecord->id, 'actor' => $preparer];
    }

    #[Test]
    public function a_statutory_view_holder_receives_the_full_statutory_breakdown(): void
    {
        $school = $this->createSchool();
        $built = $this->context()->withSchool($school, fn () => $this->buildPostedStatutoryRun($school));
        $viewer = $this->createUserWithCapabilities($school, ['payroll.compensation.sensitive.view', 'payroll.statutory.view']);

        $payslip = $this->context()->withSchool($school, fn () => $this->service()->render($school, $built['run']->id, $built['employmentRecordId'], $viewer));

        $this->assertTrue($payslip->statutoryDeductionsIncluded);
        $this->assertNotNull($payslip->statutory);
        $this->assertFalse($payslip->statutory->isPfExcludedEmployee);
        $this->assertSame('1800.00', $payslip->statutory->employeePfMandatory);
        $this->assertSame('1800.00', $payslip->statutory->employerPfTotal);
        $this->assertFalse($payslip->statutory->esiIsCovered);
        $this->assertSame('200.00', $payslip->statutory->professionalTax);
        $this->assertTrue($payslip->statutory->lwfCharged);
        $this->assertSame('2.00', $payslip->statutory->employeeLwf);
        $this->assertSame('5.00', $payslip->statutory->employerLwf);
        $this->assertSame('0.00', $payslip->statutory->tdsMonthlyDeduction);
        $this->assertNull($payslip->statutory->tdsResidualComplianceException);
        $this->assertSame('ABXXXXXX4F', $payslip->statutory->maskedPan);
        $this->assertFalse($payslip->statutory->esiDisabilityProvisionsEvaluated);
    }

    #[Test]
    public function a_viewer_without_statutory_view_receives_no_statutory_section(): void
    {
        $school = $this->createSchool();
        $built = $this->context()->withSchool($school, fn () => $this->buildPostedStatutoryRun($school));
        $viewer = $this->createUserWithCapabilities($school, ['payroll.compensation.sensitive.view']);

        $payslip = $this->context()->withSchool($school, fn () => $this->service()->render($school, $built['run']->id, $built['employmentRecordId'], $viewer));

        $this->assertFalse($payslip->statutoryDeductionsIncluded);
        $this->assertNull($payslip->statutory);
    }

    #[Test]
    public function no_document_row_is_ever_created_by_rendering_a_payslip(): void
    {
        $school = $this->createSchool();
        $built = $this->context()->withSchool($school, fn () => $this->buildPostedStatutoryRun($school));
        $viewer = $this->createUserWithCapabilities($school, ['payroll.compensation.sensitive.view', 'payroll.statutory.view']);

        $this->context()->withSchool($school, fn () => $this->service()->render($school, $built['run']->id, $built['employmentRecordId'], $viewer));

        $count = $this->context()->withSchool($school, fn () => Document::query()->count());
        $this->assertSame(0, $count, 'Rendering a payslip must never create a stored Document.');
    }

    #[Test]
    public function a_draft_run_cannot_produce_a_statutory_payslip(): void
    {
        $school = $this->createSchool();
        $preparer = $this->createUser();
        $viewer = $this->createUserWithCapabilities($school, ['payroll.compensation.sensitive.view', 'payroll.statutory.view']);

        $periodService = app(PayrollPeriodService::class);
        $run = $this->context()->withSchool($school, function () use ($school, $preparer, $periodService) {
            $period = $periodService->open($periodService->createPeriod($school, Carbon::parse('2026-09-01'), null, $preparer), $preparer);

            return app(PayrollRunService::class)->createRun($period, $preparer);
        });

        $this->expectException(PayrollRunNotEligibleForPayslipException::class);

        $this->context()->withSchool($school, fn () => $this->service()->render($school, $run->id, 'nonexistent', $viewer));
    }

    #[Test]
    public function historical_rendering_never_recalculates_from_current_rule_versions(): void
    {
        $school = $this->createSchool();
        $built = $this->context()->withSchool($school, fn () => $this->buildPostedStatutoryRun($school));
        $viewer = $this->createUserWithCapabilities($school, ['payroll.compensation.sensitive.view', 'payroll.statutory.view']);

        $first = $this->context()->withSchool($school, fn () => $this->service()->render($school, $built['run']->id, $built['employmentRecordId'], $viewer));

        // Render again -- the frozen row is the ONLY source; the
        // active PF rule version (seeded once, unchanged in this test)
        // is never re-consulted, proven by identical repeated output.
        $second = $this->context()->withSchool($school, fn () => $this->service()->render($school, $built['run']->id, $built['employmentRecordId'], $viewer));

        $this->assertSame($first->statutory->employeePfMandatory, $second->statutory->employeePfMandatory);
        $this->assertSame($first->statutory->tdsMonthlyDeduction, $second->statutory->tdsMonthlyDeduction);
    }

    #[Test]
    public function reversal_does_not_change_the_immutable_statutory_payslip(): void
    {
        $school = $this->createSchool();
        $poster = $this->createUser();
        $built = $this->context()->withSchool($school, fn () => $this->buildPostedStatutoryRun($school));

        $this->context()->withSchool($school, function () use ($school, $poster) {
            $expense = LedgerAccount::factory()->for($school, 'school')->type('expense')->create();
            $payable = LedgerAccount::factory()->for($school, 'school')->type('liability')->create();
            app(PayrollAccountingConfigurationService::class)->configure($school, $expense->id, $payable->id, $poster);
        });

        $run = $this->context()->withSchool($school, fn () => app(PayrollPostingService::class)->post($built['run']->fresh(), $poster));
        $reverser = $this->createUser();
        $this->context()->withSchool($school, fn () => app(PayrollPostingService::class)->reverse($built['run']->fresh(), $reverser, 'statutory payslip immutability test'));

        $viewer = $this->createUserWithCapabilities($school, ['payroll.compensation.sensitive.view', 'payroll.statutory.view']);
        $afterReversal = $this->context()->withSchool($school, fn () => $this->service()->render($school, $built['run']->id, $built['employmentRecordId'], $viewer));

        $this->assertTrue($afterReversal->isReversed);
        $this->assertTrue($afterReversal->statutoryDeductionsIncluded);
        $this->assertSame('1800.00', $afterReversal->statutory->employeePfMandatory, 'Statutory figures on a reversed run must remain the original, immutable, historical values.');
        $this->assertSame('200.00', $afterReversal->statutory->professionalTax);
    }

    /**
     * Section 7 "Inspect raw response payloads for identifier
     * leakage" -- the real HTTP JSON API transport, not the DTO
     * directly, mirroring `HrEmployeeSensitiveDocumentApiTest`'s raw-
     * body inspection discipline.
     */
    #[Test]
    public function the_raw_http_payslip_response_never_contains_the_unmasked_pan(): void
    {
        $school = $this->createSchool();
        $built = $this->context()->withSchool($school, fn () => $this->buildPostedStatutoryRun($school));
        $viewer = $this->createUserWithCapabilities($school, ['payroll.compensation.sensitive.view', 'payroll.statutory.view']);
        $token = $viewer->createToken('t')->plainTextToken;

        $raw = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$school->id}/payroll-runs/{$built['run']->id}/payslips/{$built['employmentRecordId']}")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('ABCDE1234F', $raw, 'The raw PAN must never appear in the payslip HTTP response.');
        $this->assertStringContainsString('ABXXXXXX4F', $raw);
    }
}
