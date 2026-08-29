<?php

namespace Tests\Feature\Payroll;

use App\Domain\Finance\Infrastructure\JournalLine;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\Exceptions\DeductionMissingLedgerMappingException;
use App\Domain\Payroll\Application\Exceptions\InvalidRunTransitionException;
use App\Domain\Payroll\Application\Exceptions\PayrollAccountingNotConfiguredException;
use App\Domain\Payroll\Application\Exceptions\PayrollRunAlreadyReversedException;
use App\Domain\Payroll\Application\Exceptions\PayrollRunNotPostedException;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollAccountingConfigurationService;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollPostingService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Application\SalaryComponentService;
use App\Domain\Payroll\Application\SalaryStructureService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\PayrollRunPosting;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 9.5 -- functional tests for `PayrollPostingService`
 * (ADR 0032 "Run kinds, correction model, and posting" / "Deduction
 * accounting" / "Reversal model").
 */
class PayrollPostingServiceTest extends TestCase
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

    private function postingService(): PayrollPostingService
    {
        return app(PayrollPostingService::class);
    }

    private function accountingConfig(): PayrollAccountingConfigurationService
    {
        return app(PayrollAccountingConfigurationService::class);
    }

    private function configureAccounting(School $school, User $actor): array
    {
        $expense = LedgerAccount::factory()->for($school, 'school')->type('expense')->create();
        $payable = LedgerAccount::factory()->for($school, 'school')->type('liability')->create();
        $this->accountingConfig()->configure($school, $expense->id, $payable->id, $actor);

        return [$expense, $payable];
    }

    /**
     * @return array{0: PayrollRun, 1: User, 2: LedgerAccount} [approved run, preparer, deduction liability account]
     */
    private function makeApprovedRun(School $school, bool $withDeductionMapping = true): array
    {
        $preparer = $this->createUser();
        $structureService = app(SalaryStructureService::class);
        $componentService = app(SalaryComponentService::class);

        $structure = $structureService->createDraft($school, 'GRADE-POST', 'Grade Posting', $preparer);
        $basic = $componentService->create($school, 'BASIC', 'Basic', 'earning', null, $preparer);

        $deductionLedgerAccount = LedgerAccount::factory()->for($school, 'school')->type('liability')->create();
        $pf = $componentService->create(
            $school, 'PF', 'Provident Fund', 'deduction',
            $withDeductionMapping ? $deductionLedgerAccount->id : null, $preparer,
        );

        $basicSc = $structureService->addComponent($structure, new AddStructureComponentData($basic->id, 'fixed_amount', null, null, 1), $preparer);
        $pfSc = $structureService->addComponent($structure, new AddStructureComponentData($pf->id, 'fixed_amount', null, null, 2), $preparer);
        $structure = $structureService->activate($structure, $preparer);

        $employmentRecord = $this->createEmploymentRecord($this->createEmployee($school), ['starts_on' => '2025-01-01']);

        app(CompensationService::class)->assign(
            $school, $employmentRecord, $structure, Carbon::parse('2025-01-01'),
            [
                new FixedComponentValueInput($basicSc->id, '50000.00'),
                new FixedComponentValueInput($pfSc->id, '1800.00'),
            ],
            $preparer,
        );

        $period = $this->periodService()->open($this->periodService()->createPeriod($school, Carbon::parse('2026-09-01'), null, $preparer), $preparer);
        $run = $this->runService()->createRun($period, $preparer);
        $this->runService()->calculate($run, $preparer);
        $run = $run->fresh();

        $approver = $this->createUser();
        $run = $this->runService()->approve($run, $approver);

        return [$run, $preparer, $deductionLedgerAccount];
    }

    #[Test]
    public function it_posts_an_approved_run_producing_a_balanced_journal_entry(): void
    {
        $school = $this->createSchool();
        $context = app(TenantContext::class);
        $poster = $this->createUser();

        [$expense, $payable] = $context->withSchool($school, fn () => $this->configureAccounting($school, $poster));
        [$run] = $context->withSchool($school, fn () => $this->makeApprovedRun($school));

        $posting = $context->withSchool($school, fn () => $this->postingService()->post($run, $poster));

        $freshRun = $context->withSchool($school, fn () => $run->fresh());
        $this->assertSame('original', $posting->posting_kind);
        $this->assertSame('posted', $freshRun->status);
        $this->assertSame($poster->id, $freshRun->posted_by_user_id);

        $lines = $context->withSchool($school, fn () => JournalLine::query()->where('journal_entry_id', $posting->journal_entry_id)->get());

        // Basic 50000 debit expense, PF 1800 credit liability, net 48200 credit payable.
        $this->assertSame(3, $lines->count());
        $expenseLine = $lines->firstWhere('ledger_account_id', $expense->id);
        $payableLine = $lines->firstWhere('ledger_account_id', $payable->id);
        $pfLine = $lines->first(fn ($l) => ! in_array($l->ledger_account_id, [$expense->id, $payable->id], true));

        $this->assertSame('50000.00', $expenseLine->debit_amount);
        $this->assertSame('48200.00', $payableLine->credit_amount);
        $this->assertSame('1800.00', $pfLine->credit_amount);
    }

    #[Test]
    public function posting_is_rejected_when_the_run_is_not_approved(): void
    {
        $school = $this->createSchool();
        $context = app(TenantContext::class);
        $poster = $this->createUser();
        $context->withSchool($school, fn () => $this->configureAccounting($school, $poster));

        $preparer = $this->createUser();
        $period = $context->withSchool($school, fn () => $this->periodService()->open($this->periodService()->createPeriod($school, Carbon::parse('2026-09-01'), null, $preparer), $preparer));
        $run = $context->withSchool($school, fn () => $this->runService()->createRun($period, $preparer));

        $this->expectException(InvalidRunTransitionException::class);

        $context->withSchool($school, fn () => $this->postingService()->post($run, $poster));
    }

    #[Test]
    public function posting_is_rejected_when_accounting_is_not_configured(): void
    {
        $school = $this->createSchool();
        $context = app(TenantContext::class);
        [$run] = $context->withSchool($school, fn () => $this->makeApprovedRun($school));
        $poster = $this->createUser();

        $this->expectException(PayrollAccountingNotConfiguredException::class);

        $context->withSchool($school, fn () => $this->postingService()->post($run, $poster));
    }

    #[Test]
    public function posting_is_blocked_when_a_nonzero_deduction_has_no_configured_liability_account(): void
    {
        $school = $this->createSchool();
        $context = app(TenantContext::class);
        $poster = $this->createUser();
        $context->withSchool($school, fn () => $this->configureAccounting($school, $poster));
        [$run] = $context->withSchool($school, fn () => $this->makeApprovedRun($school, withDeductionMapping: false));

        $this->expectException(DeductionMissingLedgerMappingException::class);

        $context->withSchool($school, fn () => $this->postingService()->post($run, $poster));
    }

    #[Test]
    public function posting_twice_is_rejected(): void
    {
        $school = $this->createSchool();
        $context = app(TenantContext::class);
        $poster = $this->createUser();
        $context->withSchool($school, fn () => $this->configureAccounting($school, $poster));
        [$run] = $context->withSchool($school, fn () => $this->makeApprovedRun($school));

        $context->withSchool($school, fn () => $this->postingService()->post($run, $poster));

        $this->expectException(InvalidRunTransitionException::class);

        $context->withSchool($school, fn () => $this->postingService()->post($run->fresh(), $poster));
    }

    #[Test]
    public function posting_is_audited(): void
    {
        $school = $this->createSchool();
        $context = app(TenantContext::class);
        $poster = $this->createUser();
        $context->withSchool($school, fn () => $this->configureAccounting($school, $poster));
        [$run] = $context->withSchool($school, fn () => $this->makeApprovedRun($school));

        $context->withSchool($school, fn () => $this->postingService()->post($run, $poster));

        $event = $context->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'payroll.run.posted')->where('subject_id', $run->id)->first(),
        );

        $this->assertNotNull($event);
        $this->assertSame($poster->id, $event->actor_user_id);
    }

    #[Test]
    public function it_reverses_a_posted_run_without_mutating_the_original_posting_or_run_status(): void
    {
        $school = $this->createSchool();
        $context = app(TenantContext::class);
        $poster = $this->createUser();
        $context->withSchool($school, fn () => $this->configureAccounting($school, $poster));
        [$run] = $context->withSchool($school, fn () => $this->makeApprovedRun($school));
        $original = $context->withSchool($school, fn () => $this->postingService()->post($run, $poster));

        $reverser = $this->createUser();
        $reversal = $context->withSchool($school, fn () => $this->postingService()->reverse($run, $reverser, 'payroll correction needed'));

        $this->assertSame('reversal', $reversal->posting_kind);
        $this->assertSame($original->id, $reversal->reversal_of_payroll_run_posting_id);
        $freshRun = $context->withSchool($school, fn () => $run->fresh());
        $this->assertSame('posted', $freshRun->status);

        $freshOriginal = $context->withSchool($school, fn () => PayrollRunPosting::query()->findOrFail($original->id));
        $this->assertSame('original', $freshOriginal->posting_kind);

        $reversalLines = $context->withSchool($school, fn () => JournalLine::query()->where('journal_entry_id', $reversal->journal_entry_id)->get());
        $originalLines = $context->withSchool($school, fn () => JournalLine::query()->where('journal_entry_id', $original->journal_entry_id)->get());
        $this->assertSame($originalLines->count(), $reversalLines->count());
    }

    #[Test]
    public function reversal_is_rejected_when_the_run_has_not_been_posted(): void
    {
        $school = $this->createSchool();
        $context = app(TenantContext::class);
        [$run] = $context->withSchool($school, fn () => $this->makeApprovedRun($school));
        $reverser = $this->createUser();

        $this->expectException(PayrollRunNotPostedException::class);

        $context->withSchool($school, fn () => $this->postingService()->reverse($run, $reverser));
    }

    #[Test]
    public function reversing_twice_is_rejected(): void
    {
        $school = $this->createSchool();
        $context = app(TenantContext::class);
        $poster = $this->createUser();
        $context->withSchool($school, fn () => $this->configureAccounting($school, $poster));
        [$run] = $context->withSchool($school, fn () => $this->makeApprovedRun($school));
        $context->withSchool($school, fn () => $this->postingService()->post($run, $poster));

        $reverser = $this->createUser();
        $context->withSchool($school, fn () => $this->postingService()->reverse($run, $reverser));

        $this->expectException(PayrollRunAlreadyReversedException::class);

        $context->withSchool($school, fn () => $this->postingService()->reverse($run, $reverser));
    }

    #[Test]
    public function reversal_is_audited(): void
    {
        $school = $this->createSchool();
        $context = app(TenantContext::class);
        $poster = $this->createUser();
        $context->withSchool($school, fn () => $this->configureAccounting($school, $poster));
        [$run] = $context->withSchool($school, fn () => $this->makeApprovedRun($school));
        $context->withSchool($school, fn () => $this->postingService()->post($run, $poster));

        $reverser = $this->createUser();
        $context->withSchool($school, fn () => $this->postingService()->reverse($run, $reverser));

        $event = $context->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'payroll.run.reversed')->where('subject_id', $run->id)->first(),
        );

        $this->assertNotNull($event);
        $this->assertSame($reverser->id, $event->actor_user_id);
    }

    /**
     * Phase 9.5 accounting-integrity correction: proves posting
     * resolves a deduction's liability account LIVE, from
     * `SalaryComponent::liability_ledger_account_id`, never from the
     * calculation-time `payroll_run_result_lines.resolved_ledger_account_id`
     * snapshot. The component's mapping is changed to a DIFFERENT
     * ledger account AFTER `calculate()` has already run (so the
     * snapshot captured the OLD account) but BEFORE `post()` -- the
     * posted journal entry must credit the NEW account, not the
     * stale snapshot.
     */
    #[Test]
    public function posting_uses_the_components_current_liability_mapping_not_the_stale_calculation_time_snapshot(): void
    {
        $school = $this->createSchool();
        $context = app(TenantContext::class);
        $poster = $this->createUser();
        $context->withSchool($school, fn () => $this->configureAccounting($school, $poster));

        $preparer = $this->createUser();
        $structureService = app(SalaryStructureService::class);
        $componentService = app(SalaryComponentService::class);

        $result = $context->withSchool($school, function () use ($school, $preparer, $structureService, $componentService) {
            $structure = $structureService->createDraft($school, 'GRADE-SNAP', 'Grade Snapshot', $preparer);
            $basic = $componentService->create($school, 'BASIC', 'Basic', 'earning', null, $preparer);

            $oldLedgerAccount = LedgerAccount::factory()->for($school, 'school')->type('liability')->create();
            $pf = $componentService->create($school, 'PF', 'Provident Fund', 'deduction', $oldLedgerAccount->id, $preparer);

            $basicSc = $structureService->addComponent($structure, new AddStructureComponentData($basic->id, 'fixed_amount', null, null, 1), $preparer);
            $pfSc = $structureService->addComponent($structure, new AddStructureComponentData($pf->id, 'fixed_amount', null, null, 2), $preparer);
            $structure = $structureService->activate($structure, $preparer);

            $employmentRecord = $this->createEmploymentRecord($this->createEmployee($school), ['starts_on' => '2025-01-01']);

            app(CompensationService::class)->assign(
                $school, $employmentRecord, $structure, Carbon::parse('2025-01-01'),
                [
                    new FixedComponentValueInput($basicSc->id, '50000.00'),
                    new FixedComponentValueInput($pfSc->id, '1800.00'),
                ],
                $preparer,
            );

            $period = $this->periodService()->open($this->periodService()->createPeriod($school, Carbon::parse('2026-09-01'), null, $preparer), $preparer);
            $run = $this->runService()->createRun($period, $preparer);
            $this->runService()->calculate($run, $preparer);
            $run = $run->fresh();

            $approver = $this->createUser();
            $run = $this->runService()->approve($run, $approver);

            // Reconfigure PF's liability account to a DIFFERENT one
            // AFTER calculation/approval -- the frozen
            // payroll_run_result_lines.resolved_ledger_account_id
            // snapshot still points at $oldLedgerAccount.
            $newLedgerAccount = LedgerAccount::factory()->for($school, 'school')->type('liability')->create();
            $pf->update(['liability_ledger_account_id' => $newLedgerAccount->id]);

            return compact('run', 'oldLedgerAccount', 'newLedgerAccount');
        });

        $posting = $context->withSchool($school, fn () => $this->postingService()->post($result['run'], $poster));

        $lines = $context->withSchool($school, fn () => JournalLine::query()->where('journal_entry_id', $posting->journal_entry_id)->get());

        $this->assertNull($lines->firstWhere('ledger_account_id', $result['oldLedgerAccount']->id), 'posting must NOT use the stale snapshot account');
        $newAccountLine = $lines->firstWhere('ledger_account_id', $result['newLedgerAccount']->id);
        $this->assertNotNull($newAccountLine, 'posting must use the CURRENT component mapping');
        $this->assertSame('1800.00', $newAccountLine->credit_amount);
    }

    #[Test]
    public function posting_is_blocked_when_a_previously_valid_liability_account_becomes_inactive_before_posting(): void
    {
        $school = $this->createSchool();
        $context = app(TenantContext::class);
        $poster = $this->createUser();
        $context->withSchool($school, fn () => $this->configureAccounting($school, $poster));

        [$run, , $deductionLedgerAccount] = $context->withSchool($school, fn () => $this->makeApprovedRun($school));

        $context->withSchool($school, fn () => $deductionLedgerAccount->update(['status' => 'inactive']));

        $this->expectException(DeductionMissingLedgerMappingException::class);

        $context->withSchool($school, fn () => $this->postingService()->post($run, $poster));
    }
}
