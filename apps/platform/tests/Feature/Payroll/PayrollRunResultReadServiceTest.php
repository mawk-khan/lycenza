<?php

namespace Tests\Feature\Payroll;

use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\Exceptions\PayrollRunNotFoundException;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollAccountingAdministrationService;
use App\Domain\Payroll\Application\PayrollCompensationAdministrationService;
use App\Domain\Payroll\Application\PayrollPeriodAdministrationService;
use App\Domain\Payroll\Application\PayrollRunAdministrationService;
use App\Domain\Payroll\Application\PayrollRunResultReadService;
use App\Domain\Payroll\Application\PayrollStructureAdministrationService;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 9.7 -- proves `PayrollRunResultReadService`'s authorization/
 * disclosure/audit discipline mirrors
 * `Tests\Feature\Payments\PaymentReadServiceTest`'s exact shape:
 * authorized access, capability denial, and the "no oracle" cross-
 * School not-found uniformity.
 */
class PayrollRunResultReadServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    /**
     * @return array{schoolId: string, runId: string}
     */
    private function makeCalculatedRun(): array
    {
        $school = $this->createSchool();
        $context = $this->context();
        $structureManager = $this->createUserWithCapabilities($school, [
            'payroll.structures.manage', 'payroll.accounting.manage', 'payroll.compensation.sensitive.manage',
        ]);
        $runManager = $this->createUserWithCapabilities($school, ['payroll.runs.prepare', 'payroll.periods.manage']);

        $runId = $context->withSchool($school, function () use ($school, $structureManager, $runManager) {
            $expense = LedgerAccount::factory()->for($school, 'school')->type('expense')->create();
            $payable = LedgerAccount::factory()->for($school, 'school')->type('liability')->create();
            app(PayrollAccountingAdministrationService::class)->configure($school, $expense->id, $payable->id, $structureManager);

            $structureService = app(PayrollStructureAdministrationService::class);
            $component = $structureService->createComponent($school, 'BASIC', 'Basic', 'earning', null, $structureManager);
            $structure = $structureService->createDraftStructure($school, 'GRADE-READ', 'Grade Read', $structureManager);
            $sc = $structureService->addStructureComponent($structure, new AddStructureComponentData($component->id, 'fixed_amount', null, null, 1), $structureManager);
            $structure = $structureService->activateStructure($structure, $structureManager);

            $employmentRecord = $this->createEmploymentRecord($this->createEmployee($school), ['starts_on' => '2025-01-01']);
            app(PayrollCompensationAdministrationService::class)->assign(
                $school, $employmentRecord, $structure, Carbon::parse('2025-01-01'),
                [new FixedComponentValueInput($sc->id, '50000.00')], $structureManager,
            );

            $periodService = app(PayrollPeriodAdministrationService::class);
            $period = $periodService->open($periodService->createPeriod($school, Carbon::parse('2026-09-01'), null, $runManager), $runManager);

            $runService = app(PayrollRunAdministrationService::class);
            $run = $runService->createRun($period, $runManager);
            $runService->calculate($run, $runManager);

            return $run->id;
        });

        return ['schoolId' => $school->id, 'runId' => $runId];
    }

    #[Test]
    public function an_authorized_actor_can_list_results_and_the_read_is_audited(): void
    {
        $f = $this->makeCalculatedRun();
        $school = School::query()->findOrFail($f['schoolId']);
        $actor = $this->createUserWithCapabilities($school, ['payroll.compensation.sensitive.view']);

        $results = app(PayrollRunResultReadService::class)->listResults($school, $f['runId'], $actor);

        $this->assertCount(1, $results);
        $this->assertSame('50000.00', $results[0]->grossAmount);
        $this->assertSame('0.00', $results[0]->totalDeductions);
        $this->assertSame('50000.00', $results[0]->netAmount);
        $this->assertCount(1, $results[0]->lines);

        $event = $this->context()->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'payroll.run.results_viewed')->where('subject_id', $f['runId'])->first(),
        );
        $this->assertNotNull($event);
        $this->assertSame($actor->id, $event->actor_user_id);
        // The fact is audited, never the value.
        $this->assertArrayNotHasKey('grossAmount', (array) $event->metadata);
        $this->assertArrayNotHasKey('netAmount', (array) $event->metadata);
    }

    #[Test]
    public function an_actor_without_the_capability_is_denied(): void
    {
        $f = $this->makeCalculatedRun();
        $school = School::query()->findOrFail($f['schoolId']);
        $actor = $this->createUserWithCapabilities($school, []);

        $this->expectException(AuthorizationException::class);

        app(PayrollRunResultReadService::class)->listResults($school, $f['runId'], $actor);
    }

    #[Test]
    public function a_cross_school_run_id_raises_the_same_not_found_error_as_a_nonexistent_one(): void
    {
        $f = $this->makeCalculatedRun();
        $otherSchool = $this->createSchool();
        $actor = $this->createUserWithCapabilities($otherSchool, ['payroll.compensation.sensitive.view']);

        $this->expectException(PayrollRunNotFoundException::class);

        app(PayrollRunResultReadService::class)->listResults($otherSchool, $f['runId'], $actor);
    }
}
