<?php

namespace Tests\Feature\Payroll;

use App\Domain\Finance\Infrastructure\JournalLine;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\CorrectionDeltaInput;
use App\Domain\Payroll\Application\Exceptions\CorrectionTargetAlreadyReversedException;
use App\Domain\Payroll\Application\Exceptions\InvalidCorrectionTargetException;
use App\Domain\Payroll\Application\Exceptions\ZeroEffectCorrectionException;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollAccountingConfigurationService;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollPostingService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Application\SalaryComponentService;
use App\Domain\Payroll\Application\SalaryStructureService;
use App\Domain\Payroll\Infrastructure\PayrollPeriod;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\SalaryComponent;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 9.5 correction -- functional tests for correction-run creation,
 * signed-effect calculation, and posting (ADR 0032 "Run kinds,
 * correction model, and posting"). Proves all four signed posting
 * directions, positive/negative net correction, the zero-effect
 * fail-closed gate, and the correction-target eligibility guards.
 */
class PayrollCorrectionRunTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }

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

    /**
     * @return array{
     *     school: School, preparer: User, poster: User,
     *     basic: SalaryComponent, pf: SalaryComponent,
     *     expense: LedgerAccount, payable: LedgerAccount, pfLedger: LedgerAccount,
     *     employmentRecord: EmploymentRecord, postedRun: PayrollRun,
     * }
     */
    private function makePostedRegularRun(): array
    {
        $school = $this->createSchool();
        $context = $this->context();
        $preparer = $this->createUser();
        $poster = $this->createUser();

        return $context->withSchool($school, function () use ($school, $preparer, $poster) {
            $expense = LedgerAccount::factory()->for($school, 'school')->type('expense')->create();
            $payable = LedgerAccount::factory()->for($school, 'school')->type('liability')->create();
            $pfLedger = LedgerAccount::factory()->for($school, 'school')->type('liability')->create();
            app(PayrollAccountingConfigurationService::class)->configure($school, $expense->id, $payable->id, $preparer);

            $structureService = app(SalaryStructureService::class);
            $componentService = app(SalaryComponentService::class);

            $structure = $structureService->createDraft($school, 'GRADE-CORR', 'Grade Correction', $preparer);
            $basic = $componentService->create($school, 'BASIC', 'Basic', 'earning', null, $preparer);
            $pf = $componentService->create($school, 'PF', 'Provident Fund', 'deduction', $pfLedger->id, $preparer);

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
            $run = $this->runService()->approve($run->fresh(), $this->createUser());
            $postedRun = $this->postingService()->post($run, $poster);
            $run = $run->fresh();

            return compact('school', 'preparer', 'poster', 'basic', 'pf', 'expense', 'payable', 'pfLedger', 'employmentRecord') + ['postedRun' => $run];
        });
    }

    private function openCorrectionPeriod(School $school, User $actor): PayrollPeriod
    {
        return $this->periodService()->open($this->periodService()->createPeriod($school, Carbon::parse('2026-10-01'), null, $actor), $actor);
    }

    #[Test]
    public function it_creates_a_correction_run_against_a_posted_regular_run(): void
    {
        $f = $this->makePostedRegularRun();
        $context = $this->context();

        $correction = $context->withSchool($f['school'], function () use ($f) {
            $period = $this->openCorrectionPeriod($f['school'], $f['preparer']);

            return $this->runService()->createCorrectionRun($f['postedRun'], $period, $f['preparer']);
        });

        $this->assertSame('correction', $correction->run_kind);
        $this->assertSame($f['postedRun']->id, $correction->corrects_payroll_run_id);
        $this->assertSame('draft', $correction->status);
    }

    #[Test]
    public function correction_target_must_be_a_posted_run_not_a_draft_or_approved_one(): void
    {
        $school = $this->createSchool();
        $context = $this->context();
        $actor = $this->createUser();

        $draftRun = $context->withSchool($school, function () use ($school, $actor) {
            $period = $this->periodService()->open($this->periodService()->createPeriod($school, Carbon::parse('2026-09-01'), null, $actor), $actor);

            return $this->runService()->createRun($period, $actor);
        });

        $this->expectException(InvalidCorrectionTargetException::class);

        $context->withSchool($school, function () use ($school, $draftRun, $actor) {
            $period = $this->openCorrectionPeriod($school, $actor);

            return $this->runService()->createCorrectionRun($draftRun, $period, $actor);
        });
    }

    #[Test]
    public function correction_target_must_be_a_regular_run_not_another_correction(): void
    {
        $f = $this->makePostedRegularRun();
        $context = $this->context();

        $firstCorrection = $context->withSchool($f['school'], function () use ($f) {
            $period = $this->openCorrectionPeriod($f['school'], $f['preparer']);
            $correction = $this->runService()->createCorrectionRun($f['postedRun'], $period, $f['preparer']);
            $this->runService()->recordCorrectionDelta(
                $correction, $f['employmentRecord'],
                [new CorrectionDeltaInput($f['basic']->id, '500.00', 'increase')],
                'test correction', $f['preparer'],
            );
            $this->runService()->calculate($correction, $f['preparer']);
            $correction = $this->runService()->approve($correction->fresh(), $this->createUser());

            return $this->postingService()->post($correction, $f['poster']);
        });

        $this->expectException(InvalidCorrectionTargetException::class);

        $context->withSchool($f['school'], function () use ($f, $firstCorrection) {
            $correctionRun = PayrollRun::query()->findOrFail($firstCorrection->payroll_run_id);
            $period = $this->periodService()->open($this->periodService()->createPeriod($f['school'], Carbon::parse('2026-11-01'), null, $f['preparer']), $f['preparer']);

            return $this->runService()->createCorrectionRun($correctionRun, $period, $f['preparer']);
        });
    }

    #[Test]
    public function correction_cannot_target_an_already_reversed_run(): void
    {
        $f = $this->makePostedRegularRun();
        $context = $this->context();

        $context->withSchool($f['school'], fn () => $this->postingService()->reverse($f['postedRun'], $this->createUser()));

        $this->expectException(CorrectionTargetAlreadyReversedException::class);

        $context->withSchool($f['school'], function () use ($f) {
            $period = $this->openCorrectionPeriod($f['school'], $f['preparer']);

            return $this->runService()->createCorrectionRun($f['postedRun'], $period, $f['preparer']);
        });
    }

    #[Test]
    public function reversing_an_original_with_an_existing_correction_is_still_allowed(): void
    {
        $f = $this->makePostedRegularRun();
        $context = $this->context();

        $context->withSchool($f['school'], function () use ($f) {
            $period = $this->openCorrectionPeriod($f['school'], $f['preparer']);

            return $this->runService()->createCorrectionRun($f['postedRun'], $period, $f['preparer']);
        });

        $reversal = $context->withSchool($f['school'], fn () => $this->postingService()->reverse($f['postedRun'], $this->createUser()));

        $this->assertSame('reversal', $reversal->posting_kind);
    }

    #[Test]
    public function an_increased_earning_correction_debits_expense_and_credits_payable(): void
    {
        $f = $this->makePostedRegularRun();
        $context = $this->context();

        $posting = $context->withSchool($f['school'], function () use ($f) {
            $period = $this->openCorrectionPeriod($f['school'], $f['preparer']);
            $correction = $this->runService()->createCorrectionRun($f['postedRun'], $period, $f['preparer']);
            $this->runService()->recordCorrectionDelta(
                $correction, $f['employmentRecord'],
                [new CorrectionDeltaInput($f['basic']->id, '500.00', 'increase')],
                'basic pay was underpaid', $f['preparer'],
            );
            $outcome = $this->runService()->calculate($correction, $f['preparer']);
            $this->assertTrue($outcome->transitionedToCalculated);
            $correction = $this->runService()->approve($correction->fresh(), $this->createUser());

            return $this->postingService()->post($correction, $f['poster']);
        });

        $lines = $context->withSchool($f['school'], fn () => JournalLine::query()->where('journal_entry_id', $posting->journal_entry_id)->get());
        $this->assertSame(2, $lines->count());
        $expenseLine = $lines->firstWhere('ledger_account_id', $f['expense']->id);
        $payableLine = $lines->firstWhere('ledger_account_id', $f['payable']->id);
        $this->assertSame('500.00', $expenseLine->debit_amount, 'increased earning must DEBIT salary expense');
        $this->assertSame('500.00', $payableLine->credit_amount, 'increased earning must CREDIT salary payable');
    }

    #[Test]
    public function a_decreased_earning_correction_credits_expense_and_debits_payable(): void
    {
        $f = $this->makePostedRegularRun();
        $context = $this->context();

        $posting = $context->withSchool($f['school'], function () use ($f) {
            $period = $this->openCorrectionPeriod($f['school'], $f['preparer']);
            $correction = $this->runService()->createCorrectionRun($f['postedRun'], $period, $f['preparer']);
            $this->runService()->recordCorrectionDelta(
                $correction, $f['employmentRecord'],
                [new CorrectionDeltaInput($f['basic']->id, '500.00', 'decrease')],
                'basic pay was overpaid', $f['preparer'],
            );
            $this->runService()->calculate($correction, $f['preparer']);
            $correction = $this->runService()->approve($correction->fresh(), $this->createUser());

            return $this->postingService()->post($correction, $f['poster']);
        });

        $lines = $context->withSchool($f['school'], fn () => JournalLine::query()->where('journal_entry_id', $posting->journal_entry_id)->get());
        $this->assertSame(2, $lines->count());
        $expenseLine = $lines->firstWhere('ledger_account_id', $f['expense']->id);
        $payableLine = $lines->firstWhere('ledger_account_id', $f['payable']->id);
        $this->assertSame('500.00', $expenseLine->credit_amount, 'decreased earning must CREDIT salary expense');
        $this->assertSame('500.00', $payableLine->debit_amount, 'decreased earning must DEBIT salary payable');
    }

    #[Test]
    public function an_increased_deduction_correction_credits_liability_and_debits_payable(): void
    {
        $f = $this->makePostedRegularRun();
        $context = $this->context();

        $posting = $context->withSchool($f['school'], function () use ($f) {
            $period = $this->openCorrectionPeriod($f['school'], $f['preparer']);
            $correction = $this->runService()->createCorrectionRun($f['postedRun'], $period, $f['preparer']);
            $this->runService()->recordCorrectionDelta(
                $correction, $f['employmentRecord'],
                [new CorrectionDeltaInput($f['pf']->id, '100.00', 'increase')],
                'PF was underwithheld', $f['preparer'],
            );
            $this->runService()->calculate($correction, $f['preparer']);
            $correction = $this->runService()->approve($correction->fresh(), $this->createUser());

            return $this->postingService()->post($correction, $f['poster']);
        });

        $lines = $context->withSchool($f['school'], fn () => JournalLine::query()->where('journal_entry_id', $posting->journal_entry_id)->get());
        $this->assertSame(2, $lines->count());
        $pfLine = $lines->firstWhere('ledger_account_id', $f['pfLedger']->id);
        $payableLine = $lines->firstWhere('ledger_account_id', $f['payable']->id);
        $this->assertSame('100.00', $pfLine->credit_amount, 'increased deduction must CREDIT the liability account');
        $this->assertSame('100.00', $payableLine->debit_amount, 'increased deduction must DEBIT salary payable (net pay falls)');
    }

    #[Test]
    public function a_decreased_deduction_correction_debits_liability_and_credits_payable(): void
    {
        $f = $this->makePostedRegularRun();
        $context = $this->context();

        $posting = $context->withSchool($f['school'], function () use ($f) {
            $period = $this->openCorrectionPeriod($f['school'], $f['preparer']);
            $correction = $this->runService()->createCorrectionRun($f['postedRun'], $period, $f['preparer']);
            $this->runService()->recordCorrectionDelta(
                $correction, $f['employmentRecord'],
                [new CorrectionDeltaInput($f['pf']->id, '100.00', 'decrease')],
                'PF was overwithheld', $f['preparer'],
            );
            $this->runService()->calculate($correction, $f['preparer']);
            $correction = $this->runService()->approve($correction->fresh(), $this->createUser());

            return $this->postingService()->post($correction, $f['poster']);
        });

        $lines = $context->withSchool($f['school'], fn () => JournalLine::query()->where('journal_entry_id', $posting->journal_entry_id)->get());
        $this->assertSame(2, $lines->count());
        $pfLine = $lines->firstWhere('ledger_account_id', $f['pfLedger']->id);
        $payableLine = $lines->firstWhere('ledger_account_id', $f['payable']->id);
        $this->assertSame('100.00', $pfLine->debit_amount, 'decreased deduction must DEBIT the liability account');
        $this->assertSame('100.00', $payableLine->credit_amount, 'decreased deduction must CREDIT salary payable (net pay rises)');
    }

    #[Test]
    public function a_zero_net_correction_is_rejected(): void
    {
        $f = $this->makePostedRegularRun();
        $context = $this->context();

        $this->expectException(ZeroEffectCorrectionException::class);

        $context->withSchool($f['school'], function () use ($f) {
            $period = $this->openCorrectionPeriod($f['school'], $f['preparer']);
            $correction = $this->runService()->createCorrectionRun($f['postedRun'], $period, $f['preparer']);
            $this->runService()->recordCorrectionDelta(
                $correction, $f['employmentRecord'],
                [
                    new CorrectionDeltaInput($f['basic']->id, '100.00', 'increase'),
                    new CorrectionDeltaInput($f['pf']->id, '100.00', 'increase'),
                ],
                'offsetting delta', $f['preparer'],
            );

            $this->runService()->calculate($correction, $f['preparer']);
        });
    }

    #[Test]
    public function a_correction_combining_earning_and_deduction_deltas_produces_a_balanced_journal(): void
    {
        $f = $this->makePostedRegularRun();
        $context = $this->context();

        $posting = $context->withSchool($f['school'], function () use ($f) {
            $period = $this->openCorrectionPeriod($f['school'], $f['preparer']);
            $correction = $this->runService()->createCorrectionRun($f['postedRun'], $period, $f['preparer']);
            $this->runService()->recordCorrectionDelta(
                $correction, $f['employmentRecord'],
                [
                    new CorrectionDeltaInput($f['basic']->id, '500.00', 'increase'),
                    new CorrectionDeltaInput($f['pf']->id, '50.00', 'increase'),
                ],
                'basic increased, PF adjusted accordingly', $f['preparer'],
            );
            $this->runService()->calculate($correction, $f['preparer']);
            $correction = $this->runService()->approve($correction->fresh(), $this->createUser());

            return $this->postingService()->post($correction, $f['poster']);
        });

        $lines = $context->withSchool($f['school'], fn () => JournalLine::query()->where('journal_entry_id', $posting->journal_entry_id)->get());
        $this->assertSame(3, $lines->count());

        $totalDebits = $lines->reduce(fn ($carry, $l) => bcadd($carry, $l->debit_amount ?? '0.00', 2), '0.00');
        $totalCredits = $lines->reduce(fn ($carry, $l) => bcadd($carry, $l->credit_amount ?? '0.00', 2), '0.00');
        $this->assertSame($totalDebits, $totalCredits, 'a correction journal entry must balance exactly like any other');

        $expenseLine = $lines->firstWhere('ledger_account_id', $f['expense']->id);
        $pfLine = $lines->firstWhere('ledger_account_id', $f['pfLedger']->id);
        $payableLine = $lines->firstWhere('ledger_account_id', $f['payable']->id);
        $this->assertSame('500.00', $expenseLine->debit_amount);
        $this->assertSame('50.00', $pfLine->credit_amount);
        $this->assertSame('450.00', $payableLine->credit_amount);
    }
}
