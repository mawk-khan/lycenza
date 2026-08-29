<?php

namespace Tests\Feature\Payroll;

use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\CorrectionDeltaInput;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollAccountingAdministrationService;
use App\Domain\Payroll\Application\PayrollCompensationAdministrationService;
use App\Domain\Payroll\Application\PayrollPeriodAdministrationService;
use App\Domain\Payroll\Application\PayrollPostingAdministrationService;
use App\Domain\Payroll\Application\PayrollRunAdministrationService;
use App\Domain\Payroll\Application\PayrollStructureAdministrationService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\SalaryComponent;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 9.7 -- authorized/unauthorized functional tests for every
 * Payroll Administration wrapper service (rule 13: a protected
 * operation needs both an authorized and an unauthorized test). The
 * underlying trusted core services (`SalaryComponentService`,
 * `CompensationService`, `PayrollPeriodService`, `PayrollRunService`,
 * `PayrollPostingService`, `PayrollAccountingConfigurationService`)
 * remain deliberately capability-check-free, exactly like
 * `LedgerService`/`ChargeService` -- these Administration classes are
 * the ONLY place a capability denial can occur.
 */
class PayrollAdministrationAuthorizationTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    #[Test]
    public function structure_administration_authorized_flow_succeeds(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, ['payroll.structures.manage']);
        $service = app(PayrollStructureAdministrationService::class);

        $this->context()->withSchool($school, function () use ($school, $actor, $service) {
            $component = $service->createComponent($school, 'BASIC', 'Basic', 'earning', null, $actor);
            $structure = $service->createDraftStructure($school, 'GRADE-AUTH', 'Grade Authorization', $actor);
            $sc = $service->addStructureComponent($structure, new AddStructureComponentData($component->id, 'fixed_amount', null, null, 1), $actor);
            $structure = $service->activateStructure($structure, $actor);

            $this->assertSame('active', $structure->status);
            $this->assertNotNull($sc->id);

            $deactivated = $service->deactivateComponent($component, $actor);
            $this->assertSame('inactive', $deactivated->status);
        });
    }

    #[Test]
    public function structure_administration_denies_component_creation_without_capability(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, []);

        $this->expectException(AuthorizationException::class);

        $this->context()->withSchool($school, fn () => app(PayrollStructureAdministrationService::class)->createComponent($school, 'BASIC', 'Basic', 'earning', null, $actor));
    }

    #[Test]
    public function structure_administration_denies_activation_without_capability(): void
    {
        $school = $this->createSchool();
        $manager = $this->createUserWithCapabilities($school, ['payroll.structures.manage']);
        $service = app(PayrollStructureAdministrationService::class);

        $structure = $this->context()->withSchool($school, fn () => $service->createDraftStructure($school, 'GRADE-DENY', 'Grade Deny', $manager));

        $viewer = $this->createUserWithCapabilities($school, []);

        $this->expectException(AuthorizationException::class);

        $this->context()->withSchool($school, fn () => $service->activateStructure($structure, $viewer));
    }

    #[Test]
    public function compensation_administration_authorized_actor_can_assign(): void
    {
        $school = $this->createSchool();
        $structureManager = $this->createUserWithCapabilities($school, ['payroll.structures.manage']);
        $structureService = app(PayrollStructureAdministrationService::class);

        [$structure, $sc] = $this->context()->withSchool($school, function () use ($school, $structureManager, $structureService) {
            $component = $structureService->createComponent($school, 'BASIC', 'Basic', 'earning', null, $structureManager);
            $structure = $structureService->createDraftStructure($school, 'GRADE-COMP-AUTH', 'Grade Comp Auth', $structureManager);
            $sc = $structureService->addStructureComponent($structure, new AddStructureComponentData($component->id, 'fixed_amount', null, null, 1), $structureManager);
            $structure = $structureService->activateStructure($structure, $structureManager);

            return [$structure, $sc];
        });

        $employmentRecord = $this->createEmploymentRecord($this->createEmployee($school), ['starts_on' => '2025-01-01']);
        $actor = $this->createUserWithCapabilities($school, ['payroll.compensation.sensitive.manage']);

        $assignment = $this->context()->withSchool($school, fn () => app(PayrollCompensationAdministrationService::class)->assign(
            $school, $employmentRecord, $structure, Carbon::parse('2025-01-01'),
            [new FixedComponentValueInput($sc->id, '50000.00')], $actor,
        ));

        $this->assertNotNull($assignment->id);
    }

    #[Test]
    public function compensation_administration_denies_assignment_without_capability(): void
    {
        $school = $this->createSchool();
        $structureManager = $this->createUserWithCapabilities($school, ['payroll.structures.manage']);
        $structureService = app(PayrollStructureAdministrationService::class);

        [$structure, $sc] = $this->context()->withSchool($school, function () use ($school, $structureManager, $structureService) {
            $component = $structureService->createComponent($school, 'BASIC', 'Basic', 'earning', null, $structureManager);
            $structure = $structureService->createDraftStructure($school, 'GRADE-COMP-DENY', 'Grade Comp Deny', $structureManager);
            $sc = $structureService->addStructureComponent($structure, new AddStructureComponentData($component->id, 'fixed_amount', null, null, 1), $structureManager);
            $structure = $structureService->activateStructure($structure, $structureManager);

            return [$structure, $sc];
        });

        $employmentRecord = $this->createEmploymentRecord($this->createEmployee($school), ['starts_on' => '2025-01-01']);
        $actor = $this->createUserWithCapabilities($school, []);

        $this->expectException(AuthorizationException::class);

        $this->context()->withSchool($school, fn () => app(PayrollCompensationAdministrationService::class)->assign(
            $school, $employmentRecord, $structure, Carbon::parse('2025-01-01'),
            [new FixedComponentValueInput($sc->id, '50000.00')], $actor,
        ));
    }

    #[Test]
    public function period_administration_authorized_flow_succeeds(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, ['payroll.periods.manage']);
        $service = app(PayrollPeriodAdministrationService::class);

        $period = $this->context()->withSchool($school, function () use ($school, $actor, $service) {
            $period = $service->createPeriod($school, Carbon::parse('2026-09-01'), null, $actor);
            $period = $service->open($period, $actor);

            return $service->close($period, $actor);
        });

        $this->assertSame('closed', $period->status);
    }

    #[Test]
    public function period_administration_denies_creation_without_capability(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, []);

        $this->expectException(AuthorizationException::class);

        $this->context()->withSchool($school, fn () => app(PayrollPeriodAdministrationService::class)->createPeriod($school, Carbon::parse('2026-09-01'), null, $actor));
    }

    /**
     * @return array{school: School, structureManager: User, expense: LedgerAccount, payable: LedgerAccount}
     */
    private function baseFixtures(): array
    {
        $school = $this->createSchool();
        $structureManager = $this->createUserWithCapabilities($school, [
            'payroll.structures.manage', 'payroll.accounting.manage', 'payroll.compensation.sensitive.manage',
        ]);

        [$expense, $payable] = $this->context()->withSchool($school, function () use ($school, $structureManager) {
            $expense = LedgerAccount::factory()->for($school, 'school')->type('expense')->create();
            $payable = LedgerAccount::factory()->for($school, 'school')->type('liability')->create();
            app(PayrollAccountingAdministrationService::class)->configure($school, $expense->id, $payable->id, $structureManager);

            return [$expense, $payable];
        });

        return compact('school', 'structureManager', 'expense', 'payable');
    }

    #[Test]
    public function accounting_administration_denies_configuration_without_capability(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, []);

        $expense = $this->context()->withSchool($school, fn () => LedgerAccount::factory()->for($school, 'school')->type('expense')->create());
        $payable = $this->context()->withSchool($school, fn () => LedgerAccount::factory()->for($school, 'school')->type('liability')->create());

        $this->expectException(AuthorizationException::class);

        $this->context()->withSchool($school, fn () => app(PayrollAccountingAdministrationService::class)->configure($school, $expense->id, $payable->id, $actor));
    }

    #[Test]
    public function run_administration_authorized_flow_succeeds_through_calculate_and_approve(): void
    {
        $f = $this->baseFixtures();
        $runManager = $this->createUserWithCapabilities($f['school'], ['payroll.runs.manage', 'payroll.periods.manage']);
        $structureService = app(PayrollStructureAdministrationService::class);
        $runService = app(PayrollRunAdministrationService::class);

        $run = $this->context()->withSchool($f['school'], function () use ($f, $runManager, $structureService, $runService) {
            $component = $structureService->createComponent($f['school'], 'BASIC', 'Basic', 'earning', null, $f['structureManager']);
            $structure = $structureService->createDraftStructure($f['school'], 'GRADE-RUN-AUTH', 'Grade Run Auth', $f['structureManager']);
            $sc = $structureService->addStructureComponent($structure, new AddStructureComponentData($component->id, 'fixed_amount', null, null, 1), $f['structureManager']);
            $structure = $structureService->activateStructure($structure, $f['structureManager']);

            $employmentRecord = $this->createEmploymentRecord($this->createEmployee($f['school']), ['starts_on' => '2025-01-01']);
            app(PayrollCompensationAdministrationService::class)->assign(
                $f['school'], $employmentRecord, $structure, Carbon::parse('2025-01-01'),
                [new FixedComponentValueInput($sc->id, '50000.00')], $f['structureManager'],
            );

            $period = app(PayrollPeriodAdministrationService::class);
            $p = $period->open($period->createPeriod($f['school'], Carbon::parse('2026-09-01'), null, $runManager), $runManager);

            $run = $runService->createRun($p, $runManager);
            $runService->calculate($run, $runManager);
            $run = $run->fresh();

            $approver = $this->createUserWithCapabilities($f['school'], ['payroll.runs.manage', 'payroll.periods.manage']);

            return $runService->approve($run, $approver);
        });

        $this->assertSame('approved', $run->status);
    }

    #[Test]
    public function run_administration_denies_run_creation_without_capability(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, []);
        $periodManager = $this->createUserWithCapabilities($school, ['payroll.periods.manage']);

        $period = $this->context()->withSchool($school, fn () => app(PayrollPeriodAdministrationService::class)->open(
            app(PayrollPeriodAdministrationService::class)->createPeriod($school, Carbon::parse('2026-09-01'), null, $periodManager), $periodManager,
        ));

        $this->expectException(AuthorizationException::class);

        $this->context()->withSchool($school, fn () => app(PayrollRunAdministrationService::class)->createRun($period, $actor));
    }

    #[Test]
    public function run_administration_denies_approval_without_capability(): void
    {
        $f = $this->baseFixtures();
        $runManager = $this->createUserWithCapabilities($f['school'], ['payroll.runs.manage', 'payroll.periods.manage']);
        $structureService = app(PayrollStructureAdministrationService::class);
        $runService = app(PayrollRunAdministrationService::class);

        $run = $this->context()->withSchool($f['school'], function () use ($f, $runManager, $structureService, $runService) {
            $component = $structureService->createComponent($f['school'], 'BASIC', 'Basic', 'earning', null, $f['structureManager']);
            $structure = $structureService->createDraftStructure($f['school'], 'GRADE-RUN-DENY', 'Grade Run Deny', $f['structureManager']);
            $sc = $structureService->addStructureComponent($structure, new AddStructureComponentData($component->id, 'fixed_amount', null, null, 1), $f['structureManager']);
            $structure = $structureService->activateStructure($structure, $f['structureManager']);

            $employmentRecord = $this->createEmploymentRecord($this->createEmployee($f['school']), ['starts_on' => '2025-01-01']);
            app(PayrollCompensationAdministrationService::class)->assign(
                $f['school'], $employmentRecord, $structure, Carbon::parse('2025-01-01'),
                [new FixedComponentValueInput($sc->id, '50000.00')], $f['structureManager'],
            );

            $period = app(PayrollPeriodAdministrationService::class);
            $p = $period->open($period->createPeriod($f['school'], Carbon::parse('2026-09-01'), null, $runManager), $runManager);

            $run = $runService->createRun($p, $runManager);
            $runService->calculate($run, $runManager);

            return $run->fresh();
        });

        $viewer = $this->createUserWithCapabilities($f['school'], []);

        $this->expectException(AuthorizationException::class);

        $this->context()->withSchool($f['school'], fn () => $runService->approve($run, $viewer));
    }

    /**
     * @return array{school: School, run: PayrollRun, poster: User}
     */
    private function makeApprovedRunForPosting(): array
    {
        $f = $this->baseFixtures();
        $runManager = $this->createUserWithCapabilities($f['school'], ['payroll.runs.manage', 'payroll.periods.manage']);
        $structureService = app(PayrollStructureAdministrationService::class);
        $runService = app(PayrollRunAdministrationService::class);

        $run = $this->context()->withSchool($f['school'], function () use ($f, $runManager, $structureService, $runService) {
            $component = $structureService->createComponent($f['school'], 'BASIC', 'Basic', 'earning', null, $f['structureManager']);
            $structure = $structureService->createDraftStructure($f['school'], 'GRADE-POST-AUTH', 'Grade Post Auth', $f['structureManager']);
            $sc = $structureService->addStructureComponent($structure, new AddStructureComponentData($component->id, 'fixed_amount', null, null, 1), $f['structureManager']);
            $structure = $structureService->activateStructure($structure, $f['structureManager']);

            $employmentRecord = $this->createEmploymentRecord($this->createEmployee($f['school']), ['starts_on' => '2025-01-01']);
            app(PayrollCompensationAdministrationService::class)->assign(
                $f['school'], $employmentRecord, $structure, Carbon::parse('2025-01-01'),
                [new FixedComponentValueInput($sc->id, '50000.00')], $f['structureManager'],
            );

            $period = app(PayrollPeriodAdministrationService::class);
            $p = $period->open($period->createPeriod($f['school'], Carbon::parse('2026-09-01'), null, $runManager), $runManager);

            $run = $runService->createRun($p, $runManager);
            $runService->calculate($run, $runManager);
            $run = $run->fresh();

            $approver = $this->createUserWithCapabilities($f['school'], ['payroll.runs.manage', 'payroll.periods.manage']);

            return $runService->approve($run, $approver);
        });

        $poster = $this->createUserWithCapabilities($f['school'], ['payroll.runs.post', 'payroll.runs.reverse']);

        return ['school' => $f['school'], 'run' => $run, 'poster' => $poster];
    }

    #[Test]
    public function posting_administration_authorized_actor_can_post_and_reverse(): void
    {
        $f = $this->makeApprovedRunForPosting();

        $posting = $this->context()->withSchool($f['school'], fn () => app(PayrollPostingAdministrationService::class)->post($f['run'], $f['poster']));
        $this->assertSame('original', $posting->posting_kind);

        $reversal = $this->context()->withSchool($f['school'], fn () => app(PayrollPostingAdministrationService::class)->reverse($f['run'], $f['poster']));
        $this->assertSame('reversal', $reversal->posting_kind);
    }

    #[Test]
    public function posting_administration_denies_posting_without_the_post_capability(): void
    {
        $f = $this->makeApprovedRunForPosting();
        $viewer = $this->createUserWithCapabilities($f['school'], []);

        $this->expectException(AuthorizationException::class);

        $this->context()->withSchool($f['school'], fn () => app(PayrollPostingAdministrationService::class)->post($f['run'], $viewer));
    }

    #[Test]
    public function posting_administration_denies_reversal_to_an_actor_holding_only_the_post_capability(): void
    {
        $f = $this->makeApprovedRunForPosting();
        $postOnlyActor = $this->createUserWithCapabilities($f['school'], ['payroll.runs.post']);

        $this->context()->withSchool($f['school'], fn () => app(PayrollPostingAdministrationService::class)->post($f['run'], $postOnlyActor));

        $this->expectException(AuthorizationException::class);

        $this->context()->withSchool($f['school'], fn () => app(PayrollPostingAdministrationService::class)->reverse($f['run'], $postOnlyActor));
    }

    #[Test]
    public function correction_run_administration_authorized_actor_can_create_and_calculate(): void
    {
        $f = $this->makeApprovedRunForPosting();
        $this->context()->withSchool($f['school'], fn () => app(PayrollPostingAdministrationService::class)->post($f['run'], $f['poster']));

        $runManager = $this->createUserWithCapabilities($f['school'], ['payroll.runs.manage', 'payroll.periods.manage']);
        $runService = app(PayrollRunAdministrationService::class);

        $outcome = $this->context()->withSchool($f['school'], function () use ($f, $runManager, $runService) {
            $period = app(PayrollPeriodAdministrationService::class);
            $correctionPeriod = $period->open($period->createPeriod($f['school'], Carbon::parse('2026-10-01'), null, $runManager), $runManager);

            $correction = $runService->createCorrectionRun($f['run']->fresh(), $correctionPeriod, $runManager);

            $employmentRecord = EmploymentRecord::query()->first();
            $basic = SalaryComponent::query()->where('school_id', $f['school']->id)->where('code', 'BASIC')->firstOrFail();

            $runService->recordCorrectionDelta(
                $correction, $employmentRecord,
                [new CorrectionDeltaInput($basic->id, '250.00', 'increase')],
                'authorized correction test', $runManager,
            );

            return $runService->calculate($correction, $runManager);
        });

        $this->assertTrue($outcome->transitionedToCalculated);
    }
}
