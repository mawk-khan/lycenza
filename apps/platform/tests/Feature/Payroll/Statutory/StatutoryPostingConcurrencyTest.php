<?php

namespace Tests\Feature\Payroll\Statutory;

use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollAccountingConfigurationService;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollPostingService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Application\SalaryComponentService;
use App\Domain\Payroll\Application\SalaryStructureService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Statutory\Application\StatutoryAccountingConfigurationService;
use App\Domain\Payroll\Statutory\Application\StatutoryPayrollCalculationService;
use App\Domain\Payroll\Statutory\Infrastructure\EmployeePfStatus;
use App\Domain\Payroll\Statutory\Infrastructure\EmployeeTaxProfile;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollSalaryComponentStatutoryClassification;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollStatutoryRunPosting;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Checkpoint 9.6H -- REQUIRED real-concurrency proof: two GENUINELY
 * separate OS processes race `StatutoryPayrollPostingService::post()`
 * against the same run over real PostgreSQL, mirroring
 * `Tests\Feature\Payroll\PayrollPostingConcurrencyTest`'s exact
 * pattern -- `payroll_statutory_run_postings_one_original_per_run`
 * (a partial unique index, Checkpoint 9.6F) is the real guarantee,
 * proven here under genuine concurrency rather than assumed from the
 * application-level pre-check alone.
 */
class StatutoryPostingConcurrencyTest extends TestCase
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

    private function makeStatutoryCalculatedAndPostedMainRun(): PayrollRun
    {
        $context = app(TenantContext::class);
        $preparer = $this->createUser();
        $poster = $this->createUser();

        return $context->withSchool($this->school, function () use ($preparer, $poster) {
            $salaryExpense = LedgerAccount::factory()->for($this->school, 'school')->type('expense')->create();
            $salaryPayable = LedgerAccount::factory()->for($this->school, 'school')->type('liability')->create();
            app(PayrollAccountingConfigurationService::class)->configure($this->school, $salaryExpense->id, $salaryPayable->id, $preparer);

            $liability = fn () => LedgerAccount::factory()->for($this->school, 'school')->type('liability')->create()->id;
            $expense = fn () => LedgerAccount::factory()->for($this->school, 'school')->type('expense')->create()->id;
            app(StatutoryAccountingConfigurationService::class)->configure($this->school, [
                'employee_pf_payable_ledger_account_id' => $liability(),
                'employer_eps_payable_ledger_account_id' => $liability(),
                'employer_epf_payable_ledger_account_id' => $liability(),
                'pf_admin_charge_payable_ledger_account_id' => $liability(),
                'edli_payable_ledger_account_id' => $liability(),
                'esi_payable_ledger_account_id' => $liability(),
                'tds_payable_ledger_account_id' => $liability(),
                'professional_tax_payable_ledger_account_id' => $liability(),
                'lwf_payable_ledger_account_id' => $liability(),
                'employer_pf_contribution_expense_ledger_account_id' => $expense(),
                'pf_admin_charge_expense_ledger_account_id' => $expense(),
                'edli_expense_ledger_account_id' => $expense(),
                'employer_esi_contribution_expense_ledger_account_id' => $expense(),
                'employer_lwf_contribution_expense_ledger_account_id' => $expense(),
            ], $preparer);

            $structureService = app(SalaryStructureService::class);
            $componentService = app(SalaryComponentService::class);
            $structure = $structureService->createDraft($this->school, 'GRADE-STAT-PC', 'Grade Statutory Posting Concurrency', $preparer);
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
            $run = $run->fresh();

            app(StatutoryPayrollCalculationService::class)->calculateForRun($run, $preparer);

            $approver = $this->createUser();
            $run = $runService->approve($run->fresh(), $approver);
            app(PayrollPostingService::class)->post($run, $poster);

            return $run->fresh();
        });
    }

    #[Test]
    public function two_real_processes_posting_the_same_run_leave_exactly_one_original_statutory_posting_and_no_orphan_journal_entry(): void
    {
        $this->school = $this->createSchool();
        $run = $this->makeStatutoryCalculatedAndPostedMainRun();
        $posterA = $this->createUser();
        $posterB = $this->createUser();

        $script = __DIR__.'/../../../Support/post-statutory-payroll-run.php';
        $processA = new Process(['php', $script, $this->school->id, $run->id, $posterA->id]);
        $processB = new Process(['php', $script, $this->school->id, $run->id, $posterB->id]);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];
        $postedCount = count(array_filter($outputs, fn ($o) => str_starts_with($o, 'posted:')));

        $this->assertSame(1, $postedCount, 'Exactly one of the two concurrent statutory postings must succeed, got: '.implode(', ', $outputs));
        $this->assertTrue(
            in_array('rejected:App\\Domain\\Payroll\\Statutory\\Application\\Exceptions\\StatutoryAlreadyPostedException', $outputs, true),
            'The loser must receive a clean already-posted rejection (never a duplicate posting), got: '.implode(', ', $outputs),
        );

        $context = app(TenantContext::class);
        $postingCount = $context->withSchool(
            $this->school,
            fn () => PayrollStatutoryRunPosting::query()->where('payroll_run_id', $run->id)->where('posting_kind', 'original')->count(),
        );
        $this->assertSame(1, $postingCount, 'Exactly one original PayrollStatutoryRunPosting row must exist -- no duplicate/corrupted state from the race.');

        // No orphan JournalEntry beyond the main payroll entry + one
        // statutory entry: exactly 2 journal entries exist for this
        // School -- the losing statutory-posting process must never
        // have reached LedgerService::post() at all.
        $journalEntryCount = $context->withSchool(
            $this->school,
            fn () => JournalEntry::query()->where('school_id', $this->school->id)->count(),
        );
        $this->assertSame(2, $journalEntryCount, 'Exactly the main payroll entry + one statutory entry must exist -- no orphan from the losing process.');
    }
}
