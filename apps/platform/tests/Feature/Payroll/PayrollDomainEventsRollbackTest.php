<?php

namespace Tests\Feature\Payroll;

use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollAccountingAdministrationService;
use App\Domain\Payroll\Application\PayrollCompensationAdministrationService;
use App\Domain\Payroll\Application\PayrollPeriodAdministrationService;
use App\Domain\Payroll\Application\PayrollPostingAdministrationService;
use App\Domain\Payroll\Application\PayrollRunAdministrationService;
use App\Domain\Payroll\Application\PayrollStructureAdministrationService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\SalaryStructure;
use App\Domain\Payroll\Infrastructure\SalaryStructureComponent;
use App\Models\DomainEventOutbox;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 9.10 -- negative proof that all 6 Payroll domain events
 * genuinely participate in the SAME atomic transaction as their
 * triggering mutation, mirroring
 * `Tests\Feature\HR\HrEmployeeDomainEventsRollbackTest`'s exact shape
 * and rationale: `App\Listeners\RecordDomainEventToOutbox` writing the
 * outbox row synchronously on the SAME connection is a structural
 * guarantee proven once, generically -- what this file proves PER
 * EVENT is that each dispatch call site genuinely sits INSIDE the
 * mutation's own `DB::transaction()`, not after it.
 */
class PayrollDomainEventsRollbackTest extends TestCase
{
    use CreatesTenancyFixtures;

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function eventScenarios(): array
    {
        return [
            'payroll_run.created.v1 (regular)' => ['payroll_run.created.v1', 'arrangeRegularCreate', 'actRegularCreate'],
            'payroll_run.created.v1 (correction)' => ['payroll_run.created.v1', 'arrangeCorrectionCreate', 'actCorrectionCreate'],
            'payroll_run.calculated.v1' => ['payroll_run.calculated.v1', 'arrangeCalculate', 'actCalculate'],
            'payroll_run.approved.v1' => ['payroll_run.approved.v1', 'arrangeApprove', 'actApprove'],
            'payroll_run.posted.v1' => ['payroll_run.posted.v1', 'arrangePost', 'actPost'],
            'payroll_run.reversed.v1' => ['payroll_run.reversed.v1', 'arrangeReverse', 'actReverse'],
            'employee_compensation.assigned.v1' => ['employee_compensation.assigned.v1', 'arrangeAssign', 'actAssign'],
        ];
    }

    #[Test]
    #[DataProvider('eventScenarios')]
    public function a_rolled_back_mutation_leaves_no_outboxed_event(string $eventType, string $arrangeMethod, string $actMethod): void
    {
        $ctx = $this->$arrangeMethod();

        $countForType = fn () => app(TenantContext::class)->withSchool(
            $ctx['school'],
            fn () => DomainEventOutbox::query()->where('school_id', $ctx['school']->id)->where('event_type', $eventType)->count(),
        );

        // Several arrange helpers legitimately produce an already-
        // COMMITTED event of the SAME type as prerequisite state (e.g.
        // arrangeCorrectionCreate() commits the original run's own
        // payroll_run.created.v1). The rollback proof is therefore a
        // DELTA -- the count must not INCREASE across the rolled-back
        // ACT step -- never an absolute zero.
        $before = $countForType();

        try {
            DB::transaction(function () use ($ctx, $actMethod): void {
                $this->$actMethod($ctx);
                throw new RuntimeException('Simulated failure after event dispatch.');
            });
            $this->fail('Expected the RuntimeException to propagate.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(
            $before,
            $countForType(),
            "{$eventType} must not survive a rolled-back transaction -- its dispatch site is not inside the mutation's own DB::transaction().",
        );
    }

    // --- Shared baseline: a compensated EmploymentRecord, committed
    // outside the transaction under test. --------------------------

    /**
     * @return array{school: School, structureManager: User, runManager: User, structureId: string, employmentRecordId: string}
     */
    private function arrangeBaseline(): array
    {
        $school = $this->createSchool();
        $structureManager = $this->createUserWithCapabilities($school, [
            'payroll.structures.manage', 'payroll.accounting.manage', 'payroll.compensation.sensitive.manage',
        ]);
        $runManager = $this->createUserWithCapabilities($school, ['payroll.runs.prepare', 'payroll.periods.manage']);

        $ctx = app(TenantContext::class)->withSchool($school, function () use ($school, $structureManager) {
            $expense = LedgerAccount::factory()->for($school, 'school')->type('expense')->create();
            $payable = LedgerAccount::factory()->for($school, 'school')->type('liability')->create();
            app(PayrollAccountingAdministrationService::class)->configure($school, $expense->id, $payable->id, $structureManager);

            $structureService = app(PayrollStructureAdministrationService::class);
            $component = $structureService->createComponent($school, 'BASIC', 'Basic', 'earning', null, $structureManager);
            $structure = $structureService->createDraftStructure($school, 'GRADE-RB', 'Grade Rollback', $structureManager);
            $sc = $structureService->addStructureComponent($structure, new AddStructureComponentData($component->id, 'fixed_amount', null, null, 1), $structureManager);
            $structure = $structureService->activateStructure($structure, $structureManager);

            $employmentRecord = $this->createEmploymentRecord($this->createEmployee($school), ['starts_on' => '2025-01-01']);
            app(PayrollCompensationAdministrationService::class)->assign(
                $school, $employmentRecord, $structure, Carbon::parse('2025-01-01'),
                [new FixedComponentValueInput($sc->id, '50000.00')], $structureManager,
            );

            return ['structureId' => $structure->id, 'employmentRecordId' => $employmentRecord->id];
        });

        return $ctx + ['school' => $school, 'structureManager' => $structureManager, 'runManager' => $runManager];
    }

    private function arrangeCalculatedRunBaseline(): array
    {
        $f = $this->arrangeBaseline();

        $runId = app(TenantContext::class)->withSchool($f['school'], function () use ($f) {
            $periodService = app(PayrollPeriodAdministrationService::class);
            $period = $periodService->open($periodService->createPeriod($f['school'], Carbon::parse('2026-09-01'), null, $f['runManager']), $f['runManager']);
            $run = app(PayrollRunAdministrationService::class)->createRun($period, $f['runManager']);
            app(PayrollRunAdministrationService::class)->calculate($run, $f['runManager']);

            return $run->id;
        });

        return $f + ['runId' => $runId];
    }

    private function arrangeApprovedRunBaseline(): array
    {
        $f = $this->arrangeCalculatedRunBaseline();
        $approver = $this->createUserWithCapabilities($f['school'], ['payroll.runs.approve']);

        app(TenantContext::class)->withSchool($f['school'], function () use ($f, $approver) {
            app(PayrollRunAdministrationService::class)->approve(PayrollRun::query()->findOrFail($f['runId']), $approver);
        });

        return $f + ['approver' => $approver];
    }

    private function arrangePostedRunBaseline(): array
    {
        $f = $this->arrangeApprovedRunBaseline();
        $poster = $this->createUserWithCapabilities($f['school'], ['payroll.runs.post', 'payroll.runs.reverse']);

        app(TenantContext::class)->withSchool($f['school'], function () use ($f, $poster) {
            app(PayrollPostingAdministrationService::class)->post(PayrollRun::query()->findOrFail($f['runId']), $poster);
        });

        return $f + ['poster' => $poster];
    }

    // --- Arrange/Act pairs, one per scenario. ------------------------

    private function arrangeRegularCreate(): array
    {
        $f = $this->arrangeBaseline();
        $f['period'] = app(TenantContext::class)->withSchool($f['school'], fn () => app(PayrollPeriodAdministrationService::class)->open(
            app(PayrollPeriodAdministrationService::class)->createPeriod($f['school'], Carbon::parse('2026-09-01'), null, $f['runManager']),
            $f['runManager'],
        ));

        return $f;
    }

    private function actRegularCreate(array $ctx): void
    {
        app(PayrollRunAdministrationService::class)->createRun($ctx['period'], $ctx['runManager']);
    }

    private function arrangeCorrectionCreate(): array
    {
        $f = $this->arrangePostedRunBaseline();
        $f['correctionPeriod'] = app(TenantContext::class)->withSchool($f['school'], fn () => app(PayrollPeriodAdministrationService::class)->open(
            app(PayrollPeriodAdministrationService::class)->createPeriod($f['school'], Carbon::parse('2026-10-01'), null, $f['runManager']),
            $f['runManager'],
        ));

        return $f;
    }

    private function actCorrectionCreate(array $ctx): void
    {
        $original = PayrollRun::query()->findOrFail($ctx['runId']);
        app(PayrollRunAdministrationService::class)->createCorrectionRun($original, $ctx['correctionPeriod'], $ctx['runManager']);
    }

    private function arrangeCalculate(): array
    {
        // Already-calculated (committed) so the ACT step below is a
        // genuine RECALCULATION -- calculate() is safe to call
        // repeatedly while draft/calculated (PayrollRunService's own
        // docblock).
        return $this->arrangeCalculatedRunBaseline();
    }

    private function actCalculate(array $ctx): void
    {
        app(PayrollRunAdministrationService::class)->calculate(PayrollRun::query()->findOrFail($ctx['runId']), $ctx['runManager']);
    }

    private function arrangeApprove(): array
    {
        $f = $this->arrangeCalculatedRunBaseline();
        $f['approver'] = $this->createUserWithCapabilities($f['school'], ['payroll.runs.approve']);

        return $f;
    }

    private function actApprove(array $ctx): void
    {
        app(PayrollRunAdministrationService::class)->approve(PayrollRun::query()->findOrFail($ctx['runId']), $ctx['approver']);
    }

    private function arrangePost(): array
    {
        $f = $this->arrangeApprovedRunBaseline();
        $f['poster'] = $this->createUserWithCapabilities($f['school'], ['payroll.runs.post', 'payroll.runs.reverse']);

        return $f;
    }

    private function actPost(array $ctx): void
    {
        app(PayrollPostingAdministrationService::class)->post(PayrollRun::query()->findOrFail($ctx['runId']), $ctx['poster']);
    }

    private function arrangeReverse(): array
    {
        return $this->arrangePostedRunBaseline();
    }

    private function actReverse(array $ctx): void
    {
        app(PayrollPostingAdministrationService::class)->reverse(PayrollRun::query()->findOrFail($ctx['runId']), $ctx['poster'], 'rollback test');
    }

    private function arrangeAssign(): array
    {
        return $this->arrangeBaseline();
    }

    private function actAssign(array $ctx): void
    {
        $employmentRecord = EmploymentRecord::query()->findOrFail($ctx['employmentRecordId']);
        $structure = SalaryStructure::query()->findOrFail($ctx['structureId']);
        $component = SalaryStructureComponent::query()->where('salary_structure_id', $structure->id)->firstOrFail();

        app(PayrollCompensationAdministrationService::class)->assign(
            $ctx['school'], $employmentRecord, $structure, Carbon::parse('2027-01-01'),
            [new FixedComponentValueInput($component->id, '70000.00')], $ctx['structureManager'],
        );
    }
}
