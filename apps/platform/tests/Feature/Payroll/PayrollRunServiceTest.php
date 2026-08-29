<?php

namespace Tests\Feature\Payroll;

use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\Exceptions\DuplicateRegularRunException;
use App\Domain\Payroll\Application\Exceptions\PeriodNotOpenException;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Application\SalaryComponentService;
use App\Domain\Payroll\Application\SalaryStructureService;
use App\Domain\Payroll\Infrastructure\PayrollPeriod;
use App\Domain\Payroll\Infrastructure\PayrollRunResult;
use App\Domain\Payroll\Infrastructure\SalaryStructure;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 9.3 functional tests for `PayrollPeriodService`/`PayrollRunService`
 * (ADR 0032 "Monthly period model" / "Partial-period policy").
 */
class PayrollRunServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function periodService(): PayrollPeriodService
    {
        return app(PayrollPeriodService::class);
    }

    private function runService(): PayrollRunService
    {
        return app(PayrollRunService::class);
    }

    /**
     * @return array{0: SalaryStructure, 1: string} [structure, basicComponentId]
     */
    private function makeSimpleActiveStructure(School $school): array
    {
        $actor = $this->createUser();
        $structureService = app(SalaryStructureService::class);
        $componentService = app(SalaryComponentService::class);

        $structure = $structureService->createDraft($school, 'GRADE1', 'Grade I', $actor);
        $basic = $componentService->create($school, 'BASIC', 'Basic', 'earning', null, $actor);
        $basicStructureComponent = $structureService->addComponent(
            $structure, new AddStructureComponentData($basic->id, 'fixed_amount', null, null, 1), $actor,
        );
        $activated = $structureService->activate($structure, $actor);

        return [$activated, $basicStructureComponent->id];
    }

    private function makeOpenPeriod(School $school, string $month = '2026-09-01'): PayrollPeriod
    {
        $actor = $this->createUser();
        $period = $this->periodService()->createPeriod($school, Carbon::parse($month), null, $actor);

        return $this->periodService()->open($period, $actor);
    }

    #[Test]
    public function it_creates_a_regular_run_for_an_open_period(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $context = app(TenantContext::class);

        $period = $context->withSchool($school, fn () => $this->makeOpenPeriod($school));
        $run = $context->withSchool($school, fn () => $this->runService()->createRun($period, $actor));

        $this->assertSame('regular', $run->run_kind);
        $this->assertSame('draft', $run->status);
        $this->assertSame($actor->id, $run->prepared_by_user_id);
    }

    #[Test]
    public function a_run_cannot_be_created_for_a_draft_period(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $context = app(TenantContext::class);

        $period = $context->withSchool($school, fn () => $this->periodService()->createPeriod($school, Carbon::parse('2026-09-01'), null, $actor));

        $this->expectException(PeriodNotOpenException::class);

        $context->withSchool($school, fn () => $this->runService()->createRun($period, $actor));
    }

    #[Test]
    public function a_second_regular_run_for_the_same_period_is_rejected(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $context = app(TenantContext::class);

        $period = $context->withSchool($school, fn () => $this->makeOpenPeriod($school));
        $context->withSchool($school, fn () => $this->runService()->createRun($period, $actor));

        $this->expectException(DuplicateRegularRunException::class);

        $context->withSchool($school, fn () => $this->runService()->createRun($period->fresh(), $actor));
    }

    #[Test]
    public function calculate_produces_a_result_for_a_fully_covered_employment_record(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $context = app(TenantContext::class);

        [$structure, $basicComponentId] = $context->withSchool($school, fn () => $this->makeSimpleActiveStructure($school));
        $employmentRecord = $context->withSchool($school, fn () => $this->createEmploymentRecord($this->createEmployee($school), ['starts_on' => '2025-01-01']));

        $context->withSchool($school, fn () => app(CompensationService::class)->assign(
            $school, $employmentRecord, $structure, Carbon::parse('2025-01-01'),
            [new FixedComponentValueInput($basicComponentId, '50000.00')], $actor,
        ));

        $period = $context->withSchool($school, fn () => $this->makeOpenPeriod($school));
        $run = $context->withSchool($school, fn () => $this->runService()->createRun($period, $actor));

        $outcome = $context->withSchool($school, fn () => $this->runService()->calculate($run, $actor));

        $this->assertSame([$employmentRecord->id], $outcome->resolvedEmploymentRecordIds);
        $this->assertEmpty($outcome->unresolvedEmploymentRecordIds);
        $this->assertTrue($outcome->transitionedToCalculated);
        $this->assertSame('calculated', $context->withSchool($school, fn () => $run->fresh())->status);
    }

    #[Test]
    public function calculate_handles_multiple_employees_with_different_compensation_under_the_same_structure(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $context = app(TenantContext::class);

        [$structure, $basicComponentId] = $context->withSchool($school, fn () => $this->makeSimpleActiveStructure($school));

        $er1 = $context->withSchool($school, fn () => $this->createEmploymentRecord($this->createEmployee($school), ['starts_on' => '2025-01-01']));
        $er2 = $context->withSchool($school, fn () => $this->createEmploymentRecord($this->createEmployee($school), ['starts_on' => '2025-01-01']));

        $context->withSchool($school, fn () => app(CompensationService::class)->assign(
            $school, $er1, $structure, Carbon::parse('2025-01-01'), [new FixedComponentValueInput($basicComponentId, '50000.00')], $actor,
        ));
        $context->withSchool($school, fn () => app(CompensationService::class)->assign(
            $school, $er2, $structure, Carbon::parse('2025-01-01'), [new FixedComponentValueInput($basicComponentId, '75000.00')], $actor,
        ));

        $period = $context->withSchool($school, fn () => $this->makeOpenPeriod($school));
        $run = $context->withSchool($school, fn () => $this->runService()->createRun($period, $actor));
        $outcome = $context->withSchool($school, fn () => $this->runService()->calculate($run, $actor));

        $this->assertCount(2, $outcome->resolvedEmploymentRecordIds);
        $results = $context->withSchool($school, fn () => PayrollRunResult::query()->where('payroll_run_id', $run->id)->get()->keyBy('employment_record_id'));
        $this->assertSame('50000.00', $results[$er1->id]->gross_amount);
        $this->assertSame('75000.00', $results[$er2->id]->gross_amount);
    }

    #[Test]
    public function a_mid_period_hire_requires_manual_override(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $context = app(TenantContext::class);

        [$structure, $basicComponentId] = $context->withSchool($school, fn () => $this->makeSimpleActiveStructure($school));
        $employmentRecord = $context->withSchool($school, fn () => $this->createEmploymentRecord($this->createEmployee($school), ['starts_on' => '2026-09-15']));

        $context->withSchool($school, fn () => app(CompensationService::class)->assign(
            $school, $employmentRecord, $structure, Carbon::parse('2026-09-15'), [new FixedComponentValueInput($basicComponentId, '50000.00')], $actor,
        ));

        $period = $context->withSchool($school, fn () => $this->makeOpenPeriod($school, '2026-09-01'));
        $run = $context->withSchool($school, fn () => $this->runService()->createRun($period, $actor));
        $outcome = $context->withSchool($school, fn () => $this->runService()->calculate($run, $actor));

        $this->assertEmpty($outcome->resolvedEmploymentRecordIds);
        $this->assertSame([$employmentRecord->id], $outcome->unresolvedEmploymentRecordIds);
        $this->assertFalse($outcome->transitionedToCalculated);
    }

    #[Test]
    public function a_mid_period_termination_requires_manual_override(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $context = app(TenantContext::class);

        [$structure, $basicComponentId] = $context->withSchool($school, fn () => $this->makeSimpleActiveStructure($school));
        $employmentRecord = $context->withSchool($school, fn () => $this->createEmploymentRecord($this->createEmployee($school), [
            'starts_on' => '2025-01-01', 'ends_on' => '2026-09-10', 'status' => 'terminated',
        ]));

        $context->withSchool($school, fn () => app(CompensationService::class)->assign(
            $school, $employmentRecord, $structure, Carbon::parse('2025-01-01'), [new FixedComponentValueInput($basicComponentId, '50000.00')], $actor,
        ));

        $period = $context->withSchool($school, fn () => $this->makeOpenPeriod($school, '2026-09-01'));
        $run = $context->withSchool($school, fn () => $this->runService()->createRun($period, $actor));
        $outcome = $context->withSchool($school, fn () => $this->runService()->calculate($run, $actor));

        $this->assertSame([$employmentRecord->id], $outcome->unresolvedEmploymentRecordIds);
    }

    #[Test]
    public function a_compensation_change_inside_the_period_requires_manual_override(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $context = app(TenantContext::class);

        [$structure, $basicComponentId] = $context->withSchool($school, fn () => $this->makeSimpleActiveStructure($school));
        $employmentRecord = $context->withSchool($school, fn () => $this->createEmploymentRecord($this->createEmployee($school), ['starts_on' => '2025-01-01']));

        $context->withSchool($school, function () use ($school, $employmentRecord, $structure, $basicComponentId, $actor) {
            $service = app(CompensationService::class);
            $service->assign($school, $employmentRecord, $structure, Carbon::parse('2025-01-01'), [new FixedComponentValueInput($basicComponentId, '50000.00')], $actor);
            // Raise effective mid-September -- compensation changes
            // INSIDE the period being calculated.
            $service->assign($school, $employmentRecord, $structure, Carbon::parse('2026-09-15'), [new FixedComponentValueInput($basicComponentId, '55000.00')], $actor);
        });

        $period = $context->withSchool($school, fn () => $this->makeOpenPeriod($school, '2026-09-01'));
        $run = $context->withSchool($school, fn () => $this->runService()->createRun($period, $actor));
        $outcome = $context->withSchool($school, fn () => $this->runService()->calculate($run, $actor));

        $this->assertSame([$employmentRecord->id], $outcome->unresolvedEmploymentRecordIds);
    }

    #[Test]
    public function a_complete_manual_override_produces_a_deterministic_result_and_completes_the_run(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $context = app(TenantContext::class);

        [$structure, $basicComponentId] = $context->withSchool($school, fn () => $this->makeSimpleActiveStructure($school));
        $basicSalaryComponentId = $context->withSchool($school, fn () => $structure->components->first()->salary_component_id);
        $employmentRecord = $context->withSchool($school, fn () => $this->createEmploymentRecord($this->createEmployee($school), ['starts_on' => '2026-09-15']));

        $context->withSchool($school, fn () => app(CompensationService::class)->assign(
            $school, $employmentRecord, $structure, Carbon::parse('2026-09-15'), [new FixedComponentValueInput($basicComponentId, '50000.00')], $actor,
        ));

        $period = $context->withSchool($school, fn () => $this->makeOpenPeriod($school, '2026-09-01'));
        $run = $context->withSchool($school, fn () => $this->runService()->createRun($period, $actor));

        // First pass: unresolved, exactly as the fail-closed rule requires.
        $firstOutcome = $context->withSchool($school, fn () => $this->runService()->calculate($run, $actor));
        $this->assertFalse($firstOutcome->transitionedToCalculated);

        // Admin supplies the complete, authoritative partial-period result.
        $context->withSchool($school, fn () => $this->runService()->recordManualOverride(
            $run, $employmentRecord, [$basicSalaryComponentId => '25000.00'], 'Half-month pro-rated by admin decision', $actor,
        ));

        $secondOutcome = $context->withSchool($school, fn () => $this->runService()->calculate($run->fresh(), $actor));

        $this->assertSame([$employmentRecord->id], $secondOutcome->resolvedEmploymentRecordIds);
        $this->assertTrue($secondOutcome->transitionedToCalculated);

        $result = $context->withSchool($school, fn () => PayrollRunResult::query()->where('payroll_run_id', $run->id)->first());
        $this->assertSame('25000.00', $result->gross_amount);
        $this->assertSame('25000.00', $result->net_amount);
    }
}
