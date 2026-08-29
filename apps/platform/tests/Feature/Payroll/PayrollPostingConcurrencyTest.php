<?php

namespace Tests\Feature\Payroll;

use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollAccountingConfigurationService;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Application\SalaryComponentService;
use App\Domain\Payroll\Application\SalaryStructureService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\PayrollRunPosting;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 9.5 -- REQUIRED real-concurrency proofs (ADR 0032 "Run kinds,
 * correction model, and posting" / "Reversal model"): two GENUINELY
 * separate OS processes race `PayrollPostingService::post()`/`reverse()`
 * against real PostgreSQL, mirroring `PayrollRunLifecycleConcurrencyTest`'s
 * exact pattern.
 *
 * Deliberately does NOT use DatabaseTransactions for the fixtures this
 * test creates (see $connectionsToTransact) -- the subprocesses are
 * separate PostgreSQL sessions and can never see this test process's
 * uncommitted rows.
 */
class PayrollPostingConcurrencyTest extends TestCase
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
                // Best-effort only, same rationale as
                // PayrollRunLifecycleConcurrencyTest::tearDown(): a
                // posted/reversed run's append-only, freeze-triggered
                // rows correctly reject the cascade delete this
                // teardown attempts. Leftover rows are harmless
                // test-database residue.
            }
        }

        parent::tearDown();
    }

    private function makeApprovedRun(): PayrollRun
    {
        $context = app(TenantContext::class);
        $preparer = $this->createUser();
        $structureService = app(SalaryStructureService::class);
        $componentService = app(SalaryComponentService::class);
        $periodService = app(PayrollPeriodService::class);
        $runService = app(PayrollRunService::class);
        $accountingConfig = app(PayrollAccountingConfigurationService::class);

        return $context->withSchool($this->school, function () use ($preparer, $structureService, $componentService, $periodService, $runService, $accountingConfig) {
            $expense = LedgerAccount::factory()->for($this->school, 'school')->type('expense')->create();
            $payable = LedgerAccount::factory()->for($this->school, 'school')->type('liability')->create();
            $accountingConfig->configure($this->school, $expense->id, $payable->id, $preparer);

            $structure = $structureService->createDraft($this->school, 'GRADE-POST-C', 'Grade Posting Concurrency', $preparer);
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

            $approver = $this->createUser();
            $run = $runService->approve($run->fresh(), $approver);

            return $run;
        });
    }

    #[Test]
    public function scenario_a_two_real_processes_posting_the_same_run_leave_exactly_one_original_posting_and_no_orphan_journal_entry(): void
    {
        $this->school = $this->createSchool();
        $run = $this->makeApprovedRun();
        $posterA = $this->createUser();
        $posterB = $this->createUser();

        $script = __DIR__.'/../../Support/post-payroll-run.php';
        $processA = new Process(['php', $script, $this->school->id, $run->id, $posterA->id]);
        $processB = new Process(['php', $script, $this->school->id, $run->id, $posterB->id]);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];
        $postedCount = count(array_filter($outputs, fn ($o) => str_starts_with($o, 'posted:')));

        $this->assertSame(1, $postedCount, 'Exactly one of the two concurrent postings must succeed, got: '.implode(', ', $outputs));
        $this->assertTrue(
            in_array('rejected:App\\Domain\\Payroll\\Application\\Exceptions\\InvalidRunTransitionException', $outputs, true),
            'The loser must receive a clean invalid-transition rejection (never a duplicate posting), got: '.implode(', ', $outputs),
        );

        $context = app(TenantContext::class);

        $postingCount = $context->withSchool(
            $this->school,
            fn () => PayrollRunPosting::query()->where('payroll_run_id', $run->id)->where('posting_kind', 'original')->count(),
        );
        $this->assertSame(1, $postingCount, 'Exactly one original PayrollRunPosting row must exist -- no duplicate/corrupted state from the race.');

        // No orphan JournalEntry: exactly one journal entry exists for
        // this School with the winning posting's id -- the losing
        // process must never have reached LedgerService::post() at all
        // (the row lock closes that window before it could).
        $winningJournalEntryId = explode(':', $outputs[array_search(true, array_map(fn ($o) => str_starts_with($o, 'posted:'), $outputs), true)])[1];
        $journalEntryCount = $context->withSchool(
            $this->school,
            fn () => JournalEntry::query()->where('school_id', $this->school->id)->count(),
        );
        $this->assertSame(1, $journalEntryCount, 'Exactly one JournalEntry must exist for this School -- the losing process must never have posted an orphan entry.');

        $storedJournalEntryId = $context->withSchool(
            $this->school,
            fn () => PayrollRunPosting::query()->where('payroll_run_id', $run->id)->where('posting_kind', 'original')->value('journal_entry_id'),
        );
        $this->assertSame($winningJournalEntryId, $storedJournalEntryId);
    }

    #[Test]
    public function scenario_b_two_real_processes_reversing_the_same_posted_run_leave_exactly_one_reversal(): void
    {
        $this->school = $this->createSchool();
        $run = $this->makeApprovedRun();
        $poster = $this->createUser();

        $postScript = __DIR__.'/../../Support/post-payroll-run.php';
        $postProcess = new Process(['php', $postScript, $this->school->id, $run->id, $poster->id]);
        $postProcess->run();
        $this->assertStringStartsWith('posted:', $postProcess->getOutput(), 'Setup precondition failed: run must be posted before racing reversal.');

        $reverserA = $this->createUser();
        $reverserB = $this->createUser();

        $script = __DIR__.'/../../Support/reverse-payroll-run.php';
        $processA = new Process(['php', $script, $this->school->id, $run->id, $reverserA->id]);
        $processB = new Process(['php', $script, $this->school->id, $run->id, $reverserB->id]);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];
        $reversedCount = count(array_filter($outputs, fn ($o) => str_starts_with($o, 'reversed:')));

        $this->assertSame(1, $reversedCount, 'Exactly one of the two concurrent reversals must succeed, got: '.implode(', ', $outputs));
        $this->assertTrue(
            in_array('rejected:App\\Domain\\Payroll\\Application\\Exceptions\\PayrollRunAlreadyReversedException', $outputs, true),
            'The loser must receive a domain-specific already-reversed rejection, got: '.implode(', ', $outputs),
        );

        $context = app(TenantContext::class);
        $reversalCount = $context->withSchool(
            $this->school,
            fn () => PayrollRunPosting::query()->where('payroll_run_id', $run->id)->where('posting_kind', 'reversal')->count(),
        );
        $this->assertSame(1, $reversalCount, 'Exactly one reversal PayrollRunPosting row must exist -- no duplicate/corrupted state from the race.');

        $journalEntryCount = $context->withSchool(
            $this->school,
            fn () => JournalEntry::query()->where('school_id', $this->school->id)->count(),
        );
        $this->assertSame(2, $journalEntryCount, 'Exactly the original + one reversal JournalEntry must exist -- no orphan from the losing process.');

        $finalStatus = $context->withSchool($this->school, fn () => $run->fresh()->status);
        $this->assertSame('posted', $finalStatus, 'A run stays posted forever -- reversal never mutates payroll_runs.status.');
    }
}
