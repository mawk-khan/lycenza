<?php

namespace Tests\Feature\Payroll;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\CorrectionDeltaInput;
use App\Domain\Payroll\Application\Exceptions\RunRecalculationRequiredException;
use App\Domain\Payroll\Application\Exceptions\SelfApprovalNotAllowedException;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollApprovalReviewService;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollPostingService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Infrastructure\EmployeeCompensationAssignment;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\SalaryComponent;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * HR.R1-C1 (HR.R1-0 finding PAY-2): a compensation change is attributed to
 * the run that FIRST PAYS it, from the calculation's recorded compensation
 * sources -- never from when the change was recorded relative to an approval
 * -- and a calculation that predates a compensation change of its period is
 * stale and cannot be approved.
 *
 * The original exploit (reproduced against 3254e1a0, where it FAILED at the
 * first assertion): October is calculated; X records a raise for E effective
 * 1 October; October is approved on the stale calculation (paying the old
 * salary); November then first pays the raise, and X -- holding
 * sensitive.manage + runs.approve + sensitive.view -- approved November,
 * because X's row was recorded before October's approval and took effect
 * before October ended, so it fell outside both branches of the old window.
 *
 * Synthetic data only; every date is in 2015.
 */
class PayrollCompensationIntegrityTest extends TestCase
{
    use CreatesPayrollRetentionFixtures;

    private School $school;

    /** @var array<string, mixed> */
    private array $setup;

    private EmploymentRecord $record;

    private User $preparer;

    private User $checker;

    private User $x;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2015-07-01 06:00:00'));
        $this->school = $this->createSchool();
        $this->setup = $this->payrollSetup($this->school);
        $employee = $this->paidEmployee($this->school, $this->setup);
        $this->record = $this->inSchool($this->school, fn () => EmploymentRecord::query()->where('employee_id', $employee->id)->firstOrFail());
        $this->preparer = $this->createUserWithCapabilities($this->school, ['payroll.runs.prepare', 'payroll.runs.view', 'payroll.periods.manage']);
        $this->checker = $this->createUserWithCapabilities($this->school, ['payroll.runs.approve', 'payroll.runs.view', 'payroll.compensation.sensitive.view']);
        // The audit's dangerous combination: X may legitimately manage pay AND approve runs.
        $this->x = $this->createUserWithCapabilities($this->school, ['payroll.compensation.sensitive.manage', 'payroll.compensation.sensitive.view', 'payroll.runs.approve', 'payroll.runs.view']);
    }

    private function calculatedRun(string $month): PayrollRun
    {
        return $this->inSchool($this->school, function () use ($month): PayrollRun {
            $periods = app(PayrollPeriodService::class);
            $period = $periods->open($periods->createPeriod($this->school, Carbon::parse($month), null, $this->preparer), $this->preparer);
            $run = app(PayrollRunService::class)->createRun($period, $this->preparer);
            app(PayrollRunService::class)->calculate($run, $this->preparer);

            return $run->fresh();
        });
    }

    private function recalculate(PayrollRun $run): void
    {
        $this->inSchool($this->school, fn () => app(PayrollRunService::class)->calculate($run->fresh(), $this->preparer));
    }

    private function approve(PayrollRun $run, User $as): PayrollRun
    {
        return $this->inSchool($this->school, function () use ($run, $as): PayrollRun {
            $runs = app(PayrollRunService::class);
            $fresh = $run->fresh();

            return $runs->approve($fresh, $as, $this->freshMfaProofFor($as), $runs->latestCalculationId($fresh));
        });
    }

    private function raise(string $effectiveFrom, string $amount, ?User $by = null): EmployeeCompensationAssignment
    {
        $by ??= $this->x;

        return $this->inSchool($this->school, fn () => app(CompensationService::class)->assign(
            $this->school, $this->record->fresh(), $this->setup['structure'], Carbon::parse($effectiveFrom),
            [new FixedComponentValueInput($this->setup['line'], $amount)], $by, $this->freshMfaProofFor($by),
        ));
    }

    private function runStatus(PayrollRun $run): string
    {
        return $this->inSchool($this->school, fn () => (string) DB::table('payroll_runs')->where('id', $run->id)->value('status'));
    }

    #[Test]
    public function pay_2_the_stale_earlier_run_is_refused_and_the_author_is_a_maker_of_the_run_that_first_pays_the_change(): void
    {
        $this->travelTo(Carbon::parse('2015-10-25 06:00:00'));
        $october = $this->calculatedRun('2015-10-01');

        // After October was calculated, X records a raise effective 1 October.
        $this->travelTo(Carbon::parse('2015-10-26 06:00:00'));
        $this->raise('2015-10-01', '55000.00');

        // 1. October's calculation no longer reflects October's compensation: approval is refused.
        $this->travelTo(Carbon::parse('2015-10-27 06:00:00'));
        try {
            $this->approve($october, $this->checker);
            $this->fail('a calculation that predates a compensation change of its period was approved');
        } catch (RunRecalculationRequiredException) {
            $this->assertSame('calculated', $this->runStatus($october));
        }

        // 2. Recalculated, October first pays the raise: X is one of its makers, even holding every key.
        $this->recalculate($october);
        $this->assertSame('55000.00', $this->inSchool($this->school, fn () => (string) DB::table('payroll_run_results')->where('payroll_run_id', $october->id)->value('net_amount')));
        try {
            $this->approve($october, $this->x);
            $this->fail('the author of a compensation change approved the run that first pays it');
        } catch (SelfApprovalNotAllowedException) {
            $this->assertSame('calculated', $this->runStatus($october));
        }
        $this->approve($october, $this->checker);
        $this->assertSame('approved', $this->runStatus($october));

        // 3. November pays the same, already independently approved change: X is not re-attributed indefinitely.
        $this->travelTo(Carbon::parse('2015-11-25 06:00:00'));
        $november = $this->calculatedRun('2015-11-01');
        $this->approve($november, $this->x);
        $this->assertSame('approved', $this->runStatus($november));
    }

    #[Test]
    public function a_change_recorded_before_the_previous_approval_but_first_paid_later_keeps_its_author_out(): void
    {
        $this->travelTo(Carbon::parse('2015-10-25 06:00:00'));
        $october = $this->calculatedRun('2015-10-01');
        // Recorded in October, effective in November: October does not pay it.
        $this->raise('2015-11-01', '56000.00');
        $this->approve($october, $this->checker);

        $this->travelTo(Carbon::parse('2015-11-25 06:00:00'));
        $november = $this->calculatedRun('2015-11-01');
        $this->expectException(SelfApprovalNotAllowedException::class);
        $this->approve($november, $this->x);
    }

    #[Test]
    public function a_future_effective_change_is_not_attributed_to_a_run_that_does_not_pay_it(): void
    {
        $this->travelTo(Carbon::parse('2015-10-20 06:00:00'));
        $this->raise('2015-12-01', '57000.00');
        $this->travelTo(Carbon::parse('2015-10-25 06:00:00'));
        $october = $this->calculatedRun('2015-10-01');

        // October pays only the original assignment: X may approve it.
        $this->approve($october, $this->x);
        $this->assertSame('approved', $this->runStatus($october));
    }

    #[Test]
    public function successive_changes_by_different_authors_are_attributed_only_to_the_run_that_first_pays_each(): void
    {
        $y = $this->createUserWithCapabilities($this->school, ['payroll.compensation.sensitive.manage', 'payroll.compensation.sensitive.view', 'payroll.runs.approve', 'payroll.runs.view']);
        // B by X from October, C by Y from November, D by X again from December.
        $this->travelTo(Carbon::parse('2015-09-20 06:00:00'));
        $this->raise('2015-10-01', '51000.00');
        $this->raise('2015-11-01', '52000.00', $y);
        $this->raise('2015-12-01', '53000.00');

        $this->travelTo(Carbon::parse('2015-10-25 06:00:00'));
        $october = $this->calculatedRun('2015-10-01');
        $this->assertSame([$this->x->id], $this->compensationAuthors($october), 'October first pays B only');
        $this->approve($october, $y);

        $this->travelTo(Carbon::parse('2015-11-25 06:00:00'));
        $november = $this->calculatedRun('2015-11-01');
        $this->assertSame([$y->id], $this->compensationAuthors($november), 'November first pays C only; B was approved with October');
        $this->approve($november, $this->x);

        $this->travelTo(Carbon::parse('2015-12-25 06:00:00'));
        $december = $this->calculatedRun('2015-12-01');
        $this->assertSame([$this->x->id], $this->compensationAuthors($december));
        try {
            $this->approve($december, $this->x);
            $this->fail('X approved the run that first pays X\'s second change');
        } catch (SelfApprovalNotAllowedException) {
            $this->approve($december, $y);
        }
        $this->assertSame('approved', $this->runStatus($december));
    }

    #[Test]
    public function a_change_with_the_same_amount_is_still_a_change_its_author_cannot_approve(): void
    {
        $this->travelTo(Carbon::parse('2015-09-20 06:00:00'));
        // Same 50,000.00 as the original assignment: a new source all the same.
        $this->raise('2015-10-01', '50000.00');
        $this->travelTo(Carbon::parse('2015-10-25 06:00:00'));
        $october = $this->calculatedRun('2015-10-01');
        $this->expectException(SelfApprovalNotAllowedException::class);
        $this->approve($october, $this->x);
    }

    #[Test]
    public function the_review_packet_lists_exactly_the_changes_whose_authors_the_decision_refuses(): void
    {
        $this->travelTo(Carbon::parse('2015-09-20 06:00:00'));
        $this->raise('2015-10-16', '58000.00');
        $this->travelTo(Carbon::parse('2015-10-25 06:00:00'));
        $october = $this->calculatedRun('2015-10-01');
        // A mid-period change: the run needs an override before it is complete.
        $this->inSchool($this->school, function () use ($october): void {
            $basic = SalaryComponent::query()->where('code', 'BASIC')->firstOrFail();
            app(PayrollRunService::class)->recordManualOverride($october->fresh(), $this->record->fresh(), [$basic->id => '54000.00'], 'Raise from 16 October', $this->preparer);
            app(PayrollRunService::class)->calculate($october->fresh(), $this->preparer);
        });

        $packet = app(PayrollApprovalReviewService::class)->packet($this->school, $october->id, $this->checker);
        $listed = collect($packet['compensation']->items());
        // October is this School's first approved run, so it also pays the original assignment (Oct 1-15) first.
        $authors = [$this->setup['actor'], $this->x];
        $this->assertSame(array_map(fn (User $u) => $u->name, $authors), $listed->pluck('author')->values()->all(), 'the raise is listed with its author');
        $this->assertEqualsCanonicalizing(array_map(fn (User $u) => $u->id, $authors), $this->compensationAuthors($october), 'and exactly those authors are makers');
        $this->assertTrue($packet['approvable']);
        $this->assertTrue($packet['totals']['reconciles']);
        $sources = $this->inSchool($this->school, fn () => DB::table('payroll_run_calculation_sources')->where('payroll_run_calculation_id', $packet['calculation']['id'])->orderBy('effective_from')->pluck('basis')->all());
        $this->assertSame(['period_override', 'period_override'], $sources, 'both October assignments, priced by the override');
    }

    #[Test]
    public function pay_1_a_regular_run_is_compared_with_the_previous_regular_run_never_a_correction(): void
    {
        $this->travelTo(Carbon::parse('2015-10-25 06:00:00'));
        $october = $this->calculatedRun('2015-10-01');
        $this->approve($october, $this->checker);
        $poster = $this->createUserWithCapabilities($this->school, ['payroll.runs.post']);
        $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->post($october->fresh(), $poster, $this->freshMfaProofFor($poster)));

        // A correction of October, approved later than October itself.
        $this->travelTo(Carbon::parse('2015-11-05 06:00:00'));
        $correction = $this->inSchool($this->school, function () use ($october): PayrollRun {
            $runs = app(PayrollRunService::class);
            // Recorded in October's own period (a correction may sit in any open period), approved after October.
            $correction = $runs->createCorrectionRun($october->fresh(), $october->fresh()->period, $this->preparer);
            $basic = SalaryComponent::query()->where('code', 'BASIC')->firstOrFail();
            $runs->recordCorrectionDelta($correction, $this->record->fresh(), [new CorrectionDeltaInput($basic->id, '500.00', 'increase')], 'Missed allowance', $this->preparer);
            $runs->calculate($correction->fresh(), $this->preparer);

            return $correction->fresh();
        });
        // Correction runs are priced by deltas: no compensation sources, and X (who changed no delta) is not a maker.
        $this->assertSame([], $this->compensationAuthors($correction));
        $this->approve($correction, $this->checker);

        $this->travelTo(Carbon::parse('2015-11-25 06:00:00'));
        $november = $this->calculatedRun('2015-11-01');
        $review = app(PayrollApprovalReviewService::class)->packet($this->school, $november->id, $this->checker);
        $this->assertSame($october->id, $review['previousRun']['id'], 'compared with the October REGULAR run, not its later correction');
        $this->assertSame(0, $review['changes']->total(), 'so an unchanged salary is not listed as new');
    }

    #[Test]
    public function a_run_paying_no_new_change_has_no_compensation_maker(): void
    {
        $this->travelTo(Carbon::parse('2015-10-25 06:00:00'));
        $october = $this->calculatedRun('2015-10-01');
        $this->approve($october, $this->checker);
        $this->travelTo(Carbon::parse('2015-11-25 06:00:00'));
        $november = $this->calculatedRun('2015-11-01');
        $this->assertSame([], $this->compensationAuthors($november));
    }

    /** @return list<string> */
    private function compensationAuthors(PayrollRun $run): array
    {
        return $this->inSchool($this->school, fn () => app(PayrollRunService::class)->participants($run->fresh())->all()['compensation_author'] ?? []);
    }

    #[Test]
    public function a_change_first_priced_by_an_override_keeps_its_author_out_of_the_run_that_first_prices_it_from_the_assignment(): void
    {
        // Security review 2026-11-05: X records a raise from 31 October, October needs an override (which X enters at the
        // old salary), an independent checker approves October -- but nobody has yet approved the raise's own values.
        $this->travelTo(Carbon::parse('2015-10-25 06:00:00'));
        $this->raise('2015-10-31', '90000.00');
        $october = $this->calculatedRun('2015-10-01');
        $this->inSchool($this->school, function () use ($october): void {
            $basic = SalaryComponent::query()->where('code', 'BASIC')->firstOrFail();
            app(PayrollRunService::class)->recordManualOverride($october->fresh(), $this->record->fresh(), [$basic->id => '50000.00'], 'Raise from 31 October', $this->x);
            app(PayrollRunService::class)->calculate($october->fresh(), $this->preparer);
        });
        $this->approve($october, $this->checker);

        // November is the first run priced FROM the raise: X is still its maker.
        $this->travelTo(Carbon::parse('2015-11-25 06:00:00'));
        $november = $this->calculatedRun('2015-11-01');
        $this->assertSame('90000.00', $this->inSchool($this->school, fn () => (string) DB::table('payroll_run_results')->where('payroll_run_id', $november->id)->value('net_amount')));
        $this->assertContains($this->x->id, $this->compensationAuthors($november));
        $this->expectException(SelfApprovalNotAllowedException::class);
        $this->approve($november, $this->x);
    }
}
