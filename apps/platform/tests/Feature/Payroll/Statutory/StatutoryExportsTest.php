<?php

namespace Tests\Feature\Payroll\Statutory;

use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Application\SalaryComponentService;
use App\Domain\Payroll\Application\SalaryStructureService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryFormNotYetEffectiveException;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryIdentifierMissingException;
use App\Domain\Payroll\Statutory\Application\Export\StatutoryEcrExportService;
use App\Domain\Payroll\Statutory\Application\Export\StatutoryEsiContributionWorksheetExportService;
use App\Domain\Payroll\Statutory\Application\Export\StatutoryTdsDraftStatementExportService;
use App\Domain\Payroll\Statutory\Application\StatutoryPayrollCalculationService;
use App\Domain\Payroll\Statutory\Infrastructure\EmployeePfStatus;
use App\Domain\Payroll\Statutory\Infrastructure\EmployeeStatutoryIdentifier;
use App\Domain\Payroll\Statutory\Infrastructure\EmployeeTaxProfile;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollSalaryComponentStatutoryClassification;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Checkpoint 9.6G -- functional tests for the ECR/ESI-worksheet/
 * Form-138-draft data-preparation exports. Reuses the exact scenario
 * StatutoryPayrollCalculationAndPostingTest hand-verified (Basic 20000
 * core_wage + HRA 8000 tested_remuneration, PF contribution base
 * 15000, ESI not covered) so the expected export figures are already
 * known from that checkpoint.
 */
class StatutoryExportsTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    /**
     * @return array{run: PayrollRun, employmentRecordId: string, actor: User}
     */
    private function buildCalculatedAndStatutoryCalculatedRun(School $school, Carbon $periodMonth = new Carbon('2026-09-01')): array
    {
        $preparer = $this->createUser();
        $structureService = app(SalaryStructureService::class);
        $componentService = app(SalaryComponentService::class);

        $structure = $structureService->createDraft($school, 'GRADE-EXP', 'Grade Export', $preparer);
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

        $employmentRecord = $this->createEmploymentRecord($this->createEmployee($school, ['full_name' => 'Asha Rao']), ['starts_on' => '2025-01-01']);

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

        $period = $periodService->open($periodService->createPeriod($school, $periodMonth, null, $preparer), $preparer);
        $run = $runService->createRun($period, $preparer);
        $runService->calculate($run, $preparer);
        $run = $run->fresh();

        app(StatutoryPayrollCalculationService::class)->calculateForRun($run, $preparer);

        return ['run' => $run->fresh(), 'employmentRecordId' => $employmentRecord->id, 'actor' => $preparer];
    }

    #[Test]
    public function ecr_export_produces_the_exact_delimited_row_format(): void
    {
        $school = $this->createSchool();
        $built = $this->context()->withSchool($school, fn () => $this->buildCalculatedAndStatutoryCalculatedRun($school));

        $this->context()->withSchool($school, fn () => EmployeeStatutoryIdentifier::query()->create([
            'school_id' => $school->id,
            'employment_record_id' => $built['employmentRecordId'],
            'identifier_type' => 'uan',
            'encrypted_value' => '100123456789',
            'lookup_hash' => str_repeat('a', 64),
        ]));

        $exporter = $this->createUserWithCapabilities($school, [
            'payroll.statutory.exports.generate',
            'payroll.statutory.identifiers.view',
        ]);

        $content = $this->context()->withSchool($school, fn () => app(StatutoryEcrExportService::class)->generate($built['run'], $exporter));

        // Order: UAN, Member Name, Gross Wages, EPF Wages, EPS Wages,
        // EDLI Wages, EPF Contribution Remitted, EPS Contribution
        // Remitted, EPF-EPS Difference Remitted, NCP Days, Refund of
        // Advances. EPF/EPS/EDLI Wages are all the same 15000.00 here
        // because the seeded PF rule version's membership/EPS/EDLI
        // wage ceilings are all identical (15000.00) -- Contribution
        // Remitted figures are the already-computed employer_epf
        // (550.00) / employer_eps (1250.00) amounts, not the wage
        // bases.
        $expected = implode('#~#', [
            '100123456789', 'Asha Rao', '28000.00', '15000.00', '15000.00', '15000.00', '550.00', '1250.00', '550.00', '0', '0.00',
        ]);
        $this->assertSame($expected, $content);

        $event = $this->context()->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'payroll.statutory.export.ecr_generated')->first(),
        );
        $this->assertNotNull($event);
        $this->assertSame(1, $event->metadata['rowCount']);
    }

    #[Test]
    public function ecr_export_fails_closed_when_uan_is_not_recorded(): void
    {
        $school = $this->createSchool();
        $built = $this->context()->withSchool($school, fn () => $this->buildCalculatedAndStatutoryCalculatedRun($school));
        $exporter = $this->createUserWithCapabilities($school, [
            'payroll.statutory.exports.generate',
            'payroll.statutory.identifiers.view',
        ]);

        $this->expectException(StatutoryIdentifierMissingException::class);

        $this->context()->withSchool($school, fn () => app(StatutoryEcrExportService::class)->generate($built['run'], $exporter));
    }

    #[Test]
    public function ecr_export_is_denied_without_the_identifiers_capability(): void
    {
        $school = $this->createSchool();
        $built = $this->context()->withSchool($school, fn () => $this->buildCalculatedAndStatutoryCalculatedRun($school));
        $exporter = $this->createUserWithCapabilities($school, ['payroll.statutory.exports.generate']);

        $this->expectException(AuthorizationException::class);

        $this->context()->withSchool($school, fn () => app(StatutoryEcrExportService::class)->generate($built['run'], $exporter));
    }

    #[Test]
    public function esi_worksheet_export_reflects_uncovered_status_for_this_scenario(): void
    {
        $school = $this->createSchool();
        $built = $this->context()->withSchool($school, fn () => $this->buildCalculatedAndStatutoryCalculatedRun($school));
        $exporter = $this->createUserWithCapabilities($school, ['payroll.statutory.exports.generate', 'payroll.statutory.view']);

        $content = $this->context()->withSchool($school, fn () => app(StatutoryEsiContributionWorksheetExportService::class)->generate($built['run'], $exporter));

        $lines = explode("\n", $content);
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('"Asha Rao"', $lines[1]);
        $this->assertStringContainsString(',false,false,28000.00,0.00,0.00,0.00', $lines[1]);
    }

    #[Test]
    public function tds_draft_export_masks_pan_without_identifiers_capability_and_reveals_it_with(): void
    {
        $school = $this->createSchool();
        $built = $this->context()->withSchool($school, fn () => $this->buildCalculatedAndStatutoryCalculatedRun($school));

        $this->context()->withSchool($school, fn () => EmployeeStatutoryIdentifier::query()->create([
            'school_id' => $school->id,
            'employment_record_id' => $built['employmentRecordId'],
            'identifier_type' => 'pan',
            'encrypted_value' => 'ABCDE1234F',
            'lookup_hash' => str_repeat('b', 64),
        ]));

        $maskedExporter = $this->createUserWithCapabilities($school, ['payroll.statutory.exports.generate', 'payroll.statutory.view']);
        $maskedContent = $this->context()->withSchool($school, fn () => app(StatutoryTdsDraftStatementExportService::class)->generate($built['run'], $maskedExporter));
        $this->assertStringContainsString('ABXXXXXX4F', $maskedContent);
        $this->assertStringNotContainsString('ABCDE1234F', $maskedContent);

        $fullExporter = $this->createUserWithCapabilities($school, [
            'payroll.statutory.exports.generate', 'payroll.statutory.view', 'payroll.statutory.identifiers.view',
        ]);
        $fullContent = $this->context()->withSchool($school, fn () => app(StatutoryTdsDraftStatementExportService::class)->generate($built['run'], $fullExporter));
        $this->assertStringContainsString('ABCDE1234F', $fullContent);
    }

    /**
     * No statutory rule version is seeded before 1 April 2026 at all
     * (the whole regime takes effect that date -- a School would use
     * whatever pre-9.6 process it had for earlier periods, never this
     * module), so a March-2026 run can never reach a real statutory
     * calculation result to export in the first place. This test
     * proves the export's OWN date guard directly instead: a bare
     * `PayrollRun` for a March-2026 period is enough, since the guard
     * fires before any `payroll_statutory_calculation_results` query.
     */
    #[Test]
    public function tds_draft_export_is_refused_before_form_138_is_effective(): void
    {
        $school = $this->createSchool();
        $preparer = $this->createUser();

        $run = $this->context()->withSchool($school, function () use ($school, $preparer) {
            $periodService = app(PayrollPeriodService::class);
            $period = $periodService->open($periodService->createPeriod($school, Carbon::parse('2026-03-01'), null, $preparer), $preparer);

            return app(PayrollRunService::class)->createRun($period, $preparer);
        });

        $exporter = $this->createUserWithCapabilities($school, ['payroll.statutory.exports.generate', 'payroll.statutory.view']);

        $this->expectException(StatutoryFormNotYetEffectiveException::class);

        $this->context()->withSchool($school, fn () => app(StatutoryTdsDraftStatementExportService::class)->generate($run, $exporter));
    }
}
