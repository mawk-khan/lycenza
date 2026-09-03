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
use App\Domain\Payroll\Statutory\Infrastructure\EmployeePfStatus;
use App\Domain\Payroll\Statutory\Infrastructure\EmployeeTaxProfile;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollSalaryComponentStatutoryClassification;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollStatutoryCalculationResult;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Checkpoint 9.6H -- REQUIRED real-concurrency proof: two GENUINELY
 * separate OS processes race `StatutoryPayrollCalculationService::calculateForRun()`
 * against the same run over real PostgreSQL, mirroring
 * `Tests\Feature\Payroll\PayrollPostingConcurrencyTest`'s exact
 * pattern. The `payroll_runs` row lock serializes the two calls, but
 * `calculateForRun()` never mutates `payroll_runs.status` -- so the
 * SECOND (unblocked) caller would otherwise attempt a duplicate set of
 * `payroll_statutory_calculation_results` inserts. That table's
 * `payroll_run_result_id` UNIQUE constraint is the real guarantee;
 * this proves it holds under genuine concurrency, not just that the
 * application code happens to check first.
 */
class StatutoryCalculationConcurrencyTest extends TestCase
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
                // Best-effort only -- same rationale as
                // PayrollPostingConcurrencyTest::tearDown().
            }
        }

        parent::tearDown();
    }

    private function makeCalculatedRun(): PayrollRun
    {
        $context = app(TenantContext::class);
        $preparer = $this->createUser();

        return $context->withSchool($this->school, function () use ($preparer) {
            $structureService = app(SalaryStructureService::class);
            $componentService = app(SalaryComponentService::class);

            $structure = $structureService->createDraft($this->school, 'GRADE-STAT-C', 'Grade Statutory Concurrency', $preparer);
            $basic = $componentService->create($this->school, 'BASIC', 'Basic', 'earning', null, $preparer);

            PayrollSalaryComponentStatutoryClassification::query()->create([
                'school_id' => $this->school->id,
                'salary_component_id' => $basic->id,
                'pf_classification' => 'core_wage',
                'esi_wage_included' => true,
                'income_tax_treatment' => 'taxable',
                'effective_from' => '2026-04-01',
            ]);

            $basicSc = $structureService->addComponent($structure, new AddStructureComponentData($basic->id, 'fixed_amount', null, null, 1), $preparer);
            $structure = $structureService->activate($structure, $preparer);

            $employmentRecord = $this->createEmploymentRecord($this->createEmployee($this->school), ['starts_on' => '2025-01-01']);

            EmployeePfStatus::query()->create([
                'school_id' => $this->school->id,
                'employment_record_id' => $employmentRecord->id,
                'has_existing_pf_membership' => true,
                'has_uan' => true,
                'has_approved_higher_wage_contribution' => false,
                'is_eps_eligible' => true,
            ]);

            EmployeeTaxProfile::query()->create([
                'school_id' => $this->school->id,
                'employment_record_id' => $employmentRecord->id,
                'fiscal_year_start' => '2026-04-01',
                'regime' => 'new',
            ]);

            app(CompensationService::class)->assign(
                $this->school, $employmentRecord, $structure, Carbon::parse('2025-01-01'),
                [new FixedComponentValueInput($basicSc->id, '10000.00')], $preparer,
            );

            $periodService = app(PayrollPeriodService::class);
            $runService = app(PayrollRunService::class);
            $period = $periodService->open($periodService->createPeriod($this->school, Carbon::parse('2026-09-01'), null, $preparer), $preparer);
            $run = $runService->createRun($period, $preparer);
            $runService->calculate($run, $preparer);

            return $run->fresh();
        });
    }

    #[Test]
    public function two_real_processes_calculating_the_same_run_leave_exactly_one_statutory_result_set(): void
    {
        $this->school = $this->createSchool();
        $run = $this->makeCalculatedRun();
        $actorA = $this->createUser();
        $actorB = $this->createUser();

        $script = __DIR__.'/../../../Support/calculate-statutory-payroll-run.php';
        $processA = new Process(['php', $script, $this->school->id, $run->id, $actorA->id]);
        $processB = new Process(['php', $script, $this->school->id, $run->id, $actorB->id]);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];
        $successCount = count(array_filter($outputs, fn ($o) => str_starts_with($o, 'calculated:')));

        $this->assertSame(1, $successCount, 'Exactly one of the two concurrent calculations must succeed, got: '.implode(', ', $outputs));
        $this->assertTrue(
            in_array('rejected:App\\Domain\\Payroll\\Statutory\\Application\\Exceptions\\StatutoryCalculationAlreadyPerformedException', $outputs, true),
            'The loser must receive a clean already-performed rejection (never a duplicate result set), got: '.implode(', ', $outputs),
        );

        $context = app(TenantContext::class);
        $resultCount = $context->withSchool(
            $this->school,
            fn () => PayrollStatutoryCalculationResult::query()
                ->whereHas('payrollRunResult', fn ($q) => $q->where('payroll_run_id', $run->id))
                ->count(),
        );
        $this->assertSame(1, $resultCount, 'Exactly one statutory result row must exist -- no duplicate/corrupted state from the race.');
    }
}
