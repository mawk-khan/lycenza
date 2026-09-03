<?php

namespace Tests\Feature\Payroll;

use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\Exceptions\PayrollRunNotFoundException;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollCompensationAdministrationService;
use App\Domain\Payroll\Application\PayrollPeriodAdministrationService;
use App\Domain\Payroll\Application\PayrollRunAdministrationService;
use App\Domain\Payroll\Application\PayrollRunReadService;
use App\Domain\Payroll\Application\PayrollStructureAdministrationService;
use App\Domain\Payroll\Infrastructure\PayrollPeriod;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 9.7 -- proves `PayrollRunReadService` (`payroll.runs.view`)
 * returns only non-sensitive run metadata and correctly denies/no-oracles,
 * mirroring `PayrollRunResultReadServiceTest`'s exact shape.
 */
class PayrollRunReadServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    /**
     * @return array{school: School, periodId: string, runId: string}
     */
    private function makeDraftRun(): array
    {
        $school = $this->createSchool();
        $context = $this->context();
        $structureManager = $this->createUserWithCapabilities($school, ['payroll.structures.manage', 'payroll.compensation.sensitive.manage']);
        $runManager = $this->createUserWithCapabilities($school, ['payroll.runs.prepare', 'payroll.periods.manage']);

        [$periodId, $runId] = $context->withSchool($school, function () use ($school, $structureManager, $runManager) {
            $structureService = app(PayrollStructureAdministrationService::class);
            $component = $structureService->createComponent($school, 'BASIC', 'Basic', 'earning', null, $structureManager);
            $structure = $structureService->createDraftStructure($school, 'GRADE-RUN-READ', 'Grade Run Read', $structureManager);
            $sc = $structureService->addStructureComponent($structure, new AddStructureComponentData($component->id, 'fixed_amount', null, null, 1), $structureManager);
            $structure = $structureService->activateStructure($structure, $structureManager);

            $employmentRecord = $this->createEmploymentRecord($this->createEmployee($school), ['starts_on' => '2025-01-01']);
            app(PayrollCompensationAdministrationService::class)->assign(
                $school, $employmentRecord, $structure, Carbon::parse('2025-01-01'),
                [new FixedComponentValueInput($sc->id, '50000.00')], $structureManager,
            );

            $periodService = app(PayrollPeriodAdministrationService::class);
            $period = $periodService->open($periodService->createPeriod($school, Carbon::parse('2026-09-01'), null, $runManager), $runManager);

            $run = app(PayrollRunAdministrationService::class)->createRun($period, $runManager);

            return [$period->id, $run->id];
        });

        return ['school' => $school, 'periodId' => $periodId, 'runId' => $runId];
    }

    #[Test]
    public function an_authorized_actor_can_list_and_get_a_run_summary(): void
    {
        $f = $this->makeDraftRun();
        $actor = $this->createUserWithCapabilities($f['school'], ['payroll.runs.view']);
        $service = app(PayrollRunReadService::class);

        $period = $this->context()->withSchool($f['school'], fn () => PayrollPeriod::query()->findOrFail($f['periodId']));
        $summaries = $service->listRuns($f['school'], $period, $actor);
        $this->assertCount(1, $summaries);
        $this->assertSame($f['runId'], $summaries[0]->id);
        $this->assertSame('draft', $summaries[0]->status);
        $this->assertSame('regular', $summaries[0]->runKind);

        $detail = $service->getRun($f['school'], $f['runId'], $actor);
        $this->assertSame($f['runId'], $detail->id);
    }

    #[Test]
    public function run_reads_are_denied_without_the_view_capability(): void
    {
        $f = $this->makeDraftRun();
        $actor = $this->createUserWithCapabilities($f['school'], []);

        $this->expectException(AuthorizationException::class);

        app(PayrollRunReadService::class)->getRun($f['school'], $f['runId'], $actor);
    }

    #[Test]
    public function a_cross_school_run_id_raises_the_same_not_found_error_as_a_nonexistent_one(): void
    {
        $f = $this->makeDraftRun();
        $otherSchool = $this->createSchool();
        $actor = $this->createUserWithCapabilities($otherSchool, ['payroll.runs.view']);

        $this->expectException(PayrollRunNotFoundException::class);

        app(PayrollRunReadService::class)->getRun($otherSchool, $f['runId'], $actor);
    }
}
