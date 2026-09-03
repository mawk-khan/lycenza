<?php

namespace Tests\Feature\Payroll;

use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\Exceptions\InvalidRunTransitionException;
use App\Domain\Payroll\Application\Exceptions\RunNotEditableException;
use App\Domain\Payroll\Application\Exceptions\SelfApprovalNotAllowedException;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Application\SalaryComponentService;
use App\Domain\Payroll\Application\SalaryStructureService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 9.4 functional tests for `PayrollRunService::approve()`
 * (ADR 0034 "Separation of duties" -- the calculated -> approved
 * transition and its immutability boundary).
 */
class PayrollRunApprovalTest extends TestCase
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
     * @return array{0: PayrollRun, 1: User} [calculated run, preparer]
     */
    private function makeCalculatedRun(School $school): array
    {
        $preparer = $this->createUser();
        $structureService = app(SalaryStructureService::class);
        $componentService = app(SalaryComponentService::class);

        $structure = $structureService->createDraft($school, 'GRADE1', 'Grade I', $preparer);
        $basic = $componentService->create($school, 'BASIC', 'Basic', 'earning', null, $preparer);
        $basicStructureComponent = $structureService->addComponent(
            $structure, new AddStructureComponentData($basic->id, 'fixed_amount', null, null, 1), $preparer,
        );
        $structure = $structureService->activate($structure, $preparer);

        $employmentRecord = $this->createEmploymentRecord($this->createEmployee($school), ['starts_on' => '2025-01-01']);

        app(CompensationService::class)->assign(
            $school, $employmentRecord, $structure, Carbon::parse('2025-01-01'),
            [new FixedComponentValueInput($basicStructureComponent->id, '50000.00')], $preparer,
        );

        $period = $this->periodService()->open($this->periodService()->createPeriod($school, Carbon::parse('2026-09-01'), null, $preparer), $preparer);
        $run = $this->runService()->createRun($period, $preparer);
        $this->runService()->calculate($run, $preparer);

        return [$run->fresh(), $preparer];
    }

    #[Test]
    public function a_calculated_run_can_be_approved_by_a_different_user(): void
    {
        $school = $this->createSchool();
        $context = app(TenantContext::class);
        [$run, $preparer] = $context->withSchool($school, fn () => $this->makeCalculatedRun($school));
        $approver = $this->createUser();

        $approved = $context->withSchool($school, fn () => $this->runService()->approve($run, $approver));

        $this->assertSame('approved', $approved->status);
        $this->assertSame($approver->id, $approved->approved_by_user_id);
        $this->assertNotNull($approved->approved_at);
    }

    #[Test]
    public function a_draft_run_cannot_be_approved(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $context = app(TenantContext::class);

        $period = $context->withSchool($school, fn () => $this->periodService()->open($this->periodService()->createPeriod($school, Carbon::parse('2026-09-01'), null, $actor), $actor));
        $run = $context->withSchool($school, fn () => $this->runService()->createRun($period, $actor));

        $this->expectException(InvalidRunTransitionException::class);

        $context->withSchool($school, fn () => $this->runService()->approve($run, $this->createUser()));
    }

    #[Test]
    public function the_preparer_cannot_approve_their_own_run(): void
    {
        $school = $this->createSchool();
        $context = app(TenantContext::class);
        [$run, $preparer] = $context->withSchool($school, fn () => $this->makeCalculatedRun($school));

        $this->expectException(SelfApprovalNotAllowedException::class);

        $context->withSchool($school, fn () => $this->runService()->approve($run, $preparer));
    }

    #[Test]
    public function an_already_approved_run_cannot_be_approved_again(): void
    {
        $school = $this->createSchool();
        $context = app(TenantContext::class);
        [$run] = $context->withSchool($school, fn () => $this->makeCalculatedRun($school));
        $approver = $this->createUser();

        $context->withSchool($school, fn () => $this->runService()->approve($run, $approver));

        $this->expectException(InvalidRunTransitionException::class);

        $context->withSchool($school, fn () => $this->runService()->approve($run->fresh(), $this->createUser()));
    }

    #[Test]
    public function calculate_is_rejected_once_the_run_is_approved(): void
    {
        $school = $this->createSchool();
        $context = app(TenantContext::class);
        [$run] = $context->withSchool($school, fn () => $this->makeCalculatedRun($school));
        $approver = $this->createUser();

        $context->withSchool($school, fn () => $this->runService()->approve($run, $approver));

        $this->expectException(RunNotEditableException::class);

        $context->withSchool($school, fn () => $this->runService()->calculate($run->fresh(), $approver));
    }

    #[Test]
    public function approval_is_audited(): void
    {
        $school = $this->createSchool();
        $context = app(TenantContext::class);
        [$run] = $context->withSchool($school, fn () => $this->makeCalculatedRun($school));
        $approver = $this->createUser();

        $context->withSchool($school, fn () => $this->runService()->approve($run, $approver));

        $event = $context->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'payroll.run.approved')->where('subject_id', $run->id)->first(),
        );

        $this->assertNotNull($event);
        $this->assertSame($approver->id, $event->actor_user_id);
    }
}
