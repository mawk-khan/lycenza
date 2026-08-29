<?php

namespace Tests\Feature\Payroll;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Application\SalaryComponentService;
use App\Domain\Payroll\Application\SalaryStructureService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\PayrollRunResult;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 9.4 -- REQUIRED real-concurrency proofs (ADR 0032 "Separation
 * of duties" / run lifecycle): two GENUINELY separate OS processes
 * race `PayrollRunService::calculate()`/`approve()` against real
 * PostgreSQL, mirroring `CompensationConcurrencyTest`'s exact pattern.
 *
 * Deliberately does NOT use DatabaseTransactions for the fixtures this
 * test creates (see $connectionsToTransact) -- the subprocesses are
 * separate PostgreSQL sessions and can never see this test process's
 * uncommitted rows.
 */
class PayrollRunLifecycleConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            try {
                $this->school->delete();
            } catch (\Throwable) {
                // Best-effort only: once a scenario reaches `approved`,
                // the freeze triggers on payroll_run_results/_lines
                // correctly reject the cascade delete too -- exactly
                // the same guarantee that protects a run's history from
                // an in-product recalculation attempt (ADR 0032) also,
                // deliberately, makes a School containing an approved
                // run non-trivial to hard-delete. Leftover rows here
                // are harmless test-database residue, cleaned up by the
                // repository's own platform:test-db-reset between real
                // sessions, not by per-test teardown.
            }
        }

        parent::tearDown();
    }

    private function makeCalculatedRun(): PayrollRun
    {
        $context = app(TenantContext::class);
        $preparer = $this->createUser();
        $structureService = app(SalaryStructureService::class);
        $componentService = app(SalaryComponentService::class);
        $periodService = app(PayrollPeriodService::class);
        $runService = app(PayrollRunService::class);

        return $context->withSchool($this->school, function () use ($preparer, $structureService, $componentService, $periodService, $runService) {
            $structure = $structureService->createDraft($this->school, 'GRADE-LC', 'Grade Lifecycle', $preparer);
            $basic = $componentService->create($this->school, 'BASIC', 'Basic', 'earning', null, $preparer);
            $basicStructureComponent = $structureService->addComponent(
                $structure, new AddStructureComponentData($basic->id, 'fixed_amount', null, null, 1), $preparer,
            );
            $structure = $structureService->activate($structure, $preparer);

            $employee = Employee::factory()->for($this->school, 'school')->create();
            $employmentRecord = EmploymentRecord::factory()->create([
                'school_id' => $this->school->id, 'employee_id' => $employee->id, 'starts_on' => '2025-01-01',
            ]);

            app(CompensationService::class)->assign(
                $this->school, $employmentRecord, $structure, Carbon::parse('2025-01-01'),
                [new FixedComponentValueInput($basicStructureComponent->id, '50000.00')], $preparer,
            );

            $period = $periodService->open($periodService->createPeriod($this->school, Carbon::parse('2026-09-01'), null, $preparer), $preparer);
            $run = $runService->createRun($period, $preparer);
            $runService->calculate($run, $preparer);

            return $run->fresh();
        });
    }

    #[Test]
    public function scenario_a_two_real_processes_recalculating_the_same_run_never_corrupt_the_result_set(): void
    {
        $this->school = $this->createSchool();
        $run = $this->makeCalculatedRun();
        $actor = $this->createUser();

        $script = __DIR__.'/../../Support/calculate-payroll-run.php';
        $processA = new Process(['php', $script, $this->school->id, $run->id, $actor->id]);
        $processB = new Process(['php', $script, $this->school->id, $run->id, $actor->id]);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];

        foreach ($outputs as $output) {
            $this->assertSame('calculated:transitioned', $output, "Both concurrent recalculations of the same run should succeed cleanly, got: {$output}");
        }

        $context = app(TenantContext::class);
        $resultCount = $context->withSchool(
            $this->school,
            fn () => PayrollRunResult::query()->where('payroll_run_id', $run->id)->count(),
        );
        $this->assertSame(1, $resultCount, 'Exactly one result row must exist -- no duplicate/corrupted state from the race.');
    }

    #[Test]
    public function scenario_b_calculate_versus_approve_never_mutates_an_approved_run(): void
    {
        $this->school = $this->createSchool();
        $run = $this->makeCalculatedRun();
        $preparer = User::query()->find($run->prepared_by_user_id);
        $approver = $this->createUser();

        $calculateScript = __DIR__.'/../../Support/calculate-payroll-run.php';
        $approveScript = __DIR__.'/../../Support/approve-payroll-run.php';
        $processCalculate = new Process(['php', $calculateScript, $this->school->id, $run->id, $preparer->id]);
        $processApprove = new Process(['php', $approveScript, $this->school->id, $run->id, $approver->id]);
        $processCalculate->start();
        $processApprove->start();
        $processCalculate->wait();
        $processApprove->wait();

        // Approve always eventually succeeds in this scenario (nothing
        // else contends for it); calculate() either completed fully
        // BEFORE approval (in which case it also succeeds) or is
        // correctly rejected once it observes the now-approved status
        // after acquiring its row lock -- never a corrupted mix.
        $this->assertSame('approved', $processApprove->getOutput());
        $this->assertContains($processCalculate->getOutput(), ['calculated:transitioned', 'rejected:App\\Domain\\Payroll\\Application\\Exceptions\\RunNotEditableException']);

        $context = app(TenantContext::class);
        $finalStatus = $context->withSchool($this->school, fn () => $run->fresh()->status);
        $this->assertSame('approved', $finalStatus);
    }

    #[Test]
    public function scenario_c_two_real_processes_approving_the_same_run_leave_exactly_one_approver(): void
    {
        $this->school = $this->createSchool();
        $run = $this->makeCalculatedRun();
        $approverA = $this->createUser();
        $approverB = $this->createUser();

        $script = __DIR__.'/../../Support/approve-payroll-run.php';
        $processA = new Process(['php', $script, $this->school->id, $run->id, $approverA->id]);
        $processB = new Process(['php', $script, $this->school->id, $run->id, $approverB->id]);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];
        $approvedCount = count(array_filter($outputs, fn ($o) => $o === 'approved'));

        $this->assertSame(1, $approvedCount, 'Exactly one of the two concurrent approvals must succeed.');
        $this->assertTrue(
            in_array('rejected:App\\Domain\\Payroll\\Application\\Exceptions\\ConcurrentRunApprovalConflictException', $outputs, true),
            'The loser must receive a domain-specific concurrency exception, got: '.implode(', ', $outputs),
        );

        $context = app(TenantContext::class);
        $fresh = $context->withSchool($this->school, fn () => $run->fresh());
        $this->assertSame('approved', $fresh->status);
        $this->assertContains($fresh->approved_by_user_id, [$approverA->id, $approverB->id]);
    }
}
