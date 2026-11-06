<?php

namespace Tests\Feature\Payroll;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\CorrectionDeltaInput;
use App\Domain\Payroll\Application\Exceptions\PayrollRunAlreadyReversedException;
use App\Domain\Payroll\Application\Exceptions\RunRecalculationRequiredException;
use App\Domain\Payroll\Application\Exceptions\SelfApprovalNotAllowedException;
use App\Domain\Payroll\Application\Exceptions\StaleRunReviewException;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollApprovalReviewService;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollPostingService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Infrastructure\EmployeeCompensationAssignment;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * HR.R1-C3 (PAY-13): reversal must not undermine first-paid attribution. A
 * run's status stays `posted` forever (ADR 0034), so reversal is read from its
 * reversal posting. Synthetic data only; every date is in 2015.
 */
class PayrollReversalAttributionTest extends TestCase
{
    use CreatesPayrollRetentionFixtures;

    private School $school;

    /** @var array<string, mixed> */
    private array $setup;

    private EmploymentRecord $record;

    private User $preparer;

    private User $checker;

    private User $x;

    private User $finance;

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
        $this->x = $this->createUserWithCapabilities($this->school, ['payroll.compensation.sensitive.manage', 'payroll.compensation.sensitive.view', 'payroll.runs.approve', 'payroll.runs.view']);
        $this->finance = $this->createUserWithCapabilities($this->school, ['payroll.runs.post', 'payroll.runs.reverse', 'payroll.runs.view']);
    }

    private function calculatedRun(string $month): PayrollRun
    {
        $this->travelTo(Carbon::parse($month)->addDays(24)->setTime(6, 0));

        return $this->inSchool($this->school, function () use ($month): PayrollRun {
            $periods = app(PayrollPeriodService::class);
            $period = $periods->open($periods->createPeriod($this->school, Carbon::parse($month), null, $this->preparer), $this->preparer);
            $run = app(PayrollRunService::class)->createRun($period, $this->preparer);
            app(PayrollRunService::class)->calculate($run, $this->preparer);

            return $run->fresh();
        });
    }

    private function approve(PayrollRun $run, User $as): PayrollRun
    {
        return $this->inSchool($this->school, function () use ($run, $as): PayrollRun {
            $runs = app(PayrollRunService::class);
            $fresh = $run->fresh();

            return $runs->approve($fresh, $as, $this->freshMfaProofFor($as), $runs->latestCalculationId($fresh), $runs->latestAttributionVersion($fresh));
        });
    }

    private function postRun(PayrollRun $run): void
    {
        $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->post($run->fresh(), $this->finance, $this->freshMfaProofFor($this->finance)));
    }

    private function reverseRun(PayrollRun $run): void
    {
        $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->reverse($run->fresh(), $this->finance, $this->freshMfaProofFor($this->finance), 'test reversal'));
    }

    private function raise(string $effectiveFrom, string $amount, ?User $by = null): EmployeeCompensationAssignment
    {
        $by ??= $this->x;

        return $this->inSchool($this->school, fn () => app(CompensationService::class)->assign(
            $this->school, $this->record->fresh(), $this->setup['structure'], Carbon::parse($effectiveFrom),
            [new FixedComponentValueInput($this->setup['line'], $amount)], $by, $this->freshMfaProofFor($by),
        ));
    }

    /** @return array<string, mixed> */
    private function row(PayrollRun $run): array
    {
        return (array) $this->inSchool($this->school, fn () => DB::table('payroll_runs')->where('id', $run->id)->first(['status', 'approved_by_user_id', 'approved_at', 'approved_calculation_id']));
    }

    /** @return list<string> */
    private function compensationAuthors(PayrollRun $run): array
    {
        return $this->inSchool($this->school, fn () => app(PayrollRunService::class)->participants($run->fresh())->all()['compensation_author'] ?? []);
    }

    /** October pays X's raise, approved by the independent checker and posted. */
    private function postedOctoberPayingTheRaise(): PayrollRun
    {
        $this->travelTo(Carbon::parse('2015-09-28 06:00:00'));
        $this->raise('2015-10-01', '55000.00');
        $october = $this->calculatedRun('2015-10-01');
        $this->assertSame([$this->x->id], $this->compensationAuthors($october));
        $this->approve($october, $this->checker);
        $this->postRun($october);

        return $october;
    }

    #[Test]
    public function pay_13_the_author_cannot_approve_the_next_run_after_the_run_that_paid_the_change_is_reversed(): void
    {
        $october = $this->postedOctoberPayingTheRaise();
        $before = $this->row($october);

        $this->reverseRun($october);
        $november = $this->calculatedRun('2015-11-01');

        $this->assertSame([$this->x->id], $this->compensationAuthors($november), 'no standing run paid the change, so November pays it first');
        try {
            $this->approve($november, $this->x);
            $this->fail('the author approved the run that now first pays the change');
        } catch (SelfApprovalNotAllowedException) {
            $this->assertSame('calculated', $this->row($november)['status']);
        }

        $this->approve($november, $this->checker);
        $this->assertSame('approved', $this->row($november)['status']);
        $this->assertSame($before, $this->row($october), 'the reversed run keeps its status, approval and evidence');
    }

    private function refusesAuthor(PayrollRun $run): void
    {
        try {
            $this->approve($run, $this->x);
            $this->fail('the author approved a run that first pays the change');
        } catch (SelfApprovalNotAllowedException) {
            $this->assertSame('calculated', $this->row($run)['status']);
        }
    }

    /** @return array<string, mixed> */
    private function packet(PayrollRun $run, ?User $as = null): array
    {
        $as ??= $this->checker;

        return $this->inSchool($this->school, fn () => app(PayrollApprovalReviewService::class)->packet($this->school, $run->id, $as));
    }

    #[Test]
    public function a_standing_approved_or_posted_predecessor_keeps_discharging_the_attribution(): void
    {
        $this->travelTo(Carbon::parse('2015-09-28 06:00:00'));
        $this->raise('2015-10-01', '55000.00');
        $october = $this->calculatedRun('2015-10-01');
        $this->approve($october, $this->checker);

        $november = $this->calculatedRun('2015-11-01');
        $this->assertSame([], $this->compensationAuthors($november), 'approved, not yet posted: it stands');

        $this->postRun($october);
        $this->assertSame([], $this->compensationAuthors($november), 'posted: it stands');
        $this->approve($november, $this->x);
        $this->assertSame('approved', $this->row($november)['status']);
    }

    #[Test]
    public function a_reversal_after_the_later_calculation_reattributes_the_change_without_a_recalculation(): void
    {
        $october = $this->postedOctoberPayingTheRaise();
        $november = $this->calculatedRun('2015-11-01');
        $calculation = $this->inSchool($this->school, fn () => app(PayrollRunService::class)->latestCalculationId($november));
        $this->assertSame([], $this->compensationAuthors($november));

        $this->reverseRun($october);

        $this->assertSame([$this->x->id], $this->compensationAuthors($november));
        $this->refusesAuthor($november);
        $this->assertSame($calculation, $this->inSchool($this->school, fn () => app(PayrollRunService::class)->latestCalculationId($november)), 'the figures are unchanged: no recalculation');
        $this->approve($november, $this->checker);
        $this->assertSame($calculation, $this->row($november)['approved_calculation_id']);
    }

    #[Test]
    public function a_packet_opened_before_the_reversal_is_stale_for_an_independent_checker(): void
    {
        $october = $this->postedOctoberPayingTheRaise();
        $november = $this->calculatedRun('2015-11-01');
        $opened = $this->packet($november);
        $this->assertSame(0, $opened['compensation']->total(), 'the packet shows no change first paid');

        $this->reverseRun($october);

        try {
            $this->inSchool($this->school, fn () => app(PayrollRunService::class)->approve($november->fresh(), $this->checker, $this->freshMfaProofFor($this->checker), $opened['calculation']['id'], $opened['calculation']['attributionVersion']));
            $this->fail('approved on a packet that omits a change the run now first pays');
        } catch (StaleRunReviewException) {
            $this->assertSame('calculated', $this->row($november)['status']);
        }

        $reopened = $this->packet($november);
        $this->assertSame($opened['calculation']['id'], $reopened['calculation']['id'], 'the same calculation');
        $this->assertNotSame($opened['calculation']['attributionVersion'], $reopened['calculation']['attributionVersion']);
        $this->assertSame(1, $reopened['compensation']->total(), 'the reopened packet lists the change and its author');
        $this->inSchool($this->school, fn () => app(PayrollRunService::class)->approve($november->fresh(), $this->checker, $this->freshMfaProofFor($this->checker), $reopened['calculation']['id'], $reopened['calculation']['attributionVersion']));
        $this->assertSame('approved', $this->row($november)['status']);
    }

    #[Test]
    public function a_missing_or_forged_attribution_version_is_refused(): void
    {
        $this->postedOctoberPayingTheRaise();
        $november = $this->calculatedRun('2015-11-01');
        $calculation = $this->inSchool($this->school, fn () => app(PayrollRunService::class)->latestCalculationId($november));

        foreach ([null, str_repeat('0', 64)] as $version) {
            try {
                $this->inSchool($this->school, fn () => app(PayrollRunService::class)->approve($november->fresh(), $this->checker, $this->freshMfaProofFor($this->checker), $calculation, $version));
                $this->fail('approved without the reviewed attribution');
            } catch (StaleRunReviewException) {
                $this->assertSame('calculated', $this->row($november)['status']);
            }
        }
    }

    #[Test]
    public function a_reversal_after_a_decision_leaves_it_standing_and_the_authors_own_approval_never_stands_in(): void
    {
        $october = $this->postedOctoberPayingTheRaise();

        // October stands, so November is not the run that first pays the change, and X may approve it.
        $november = $this->calculatedRun('2015-11-01');
        $this->approve($november, $this->x);
        $decided = $this->row($november);

        $this->reverseRun($october);
        $this->assertSame($decided, $this->row($november), 'a completed decision is never rewritten');

        // Nothing independent stands now: November was approved by the author himself, so December first pays it.
        $december = $this->calculatedRun('2015-12-01');
        $this->assertSame([$this->x->id], $this->compensationAuthors($december));
        $this->refusesAuthor($december);
        $this->approve($december, $this->checker);

        // December's independent approval stands: X is not barred for ever.
        $january = $this->calculatedRun('2016-01-01');
        $this->assertSame([], $this->compensationAuthors($january));
        $this->approve($january, $this->x);
        $this->assertSame('approved', $this->row($january)['status']);
    }

    #[Test]
    public function a_later_independent_approval_discharges_the_attribution_after_a_reversal(): void
    {
        $october = $this->postedOctoberPayingTheRaise();
        $this->reverseRun($october);
        $november = $this->calculatedRun('2015-11-01');
        $this->approve($november, $this->checker);

        $december = $this->calculatedRun('2015-12-01');
        $this->assertSame([], $this->compensationAuthors($december));
    }

    #[Test]
    public function a_repeated_reversal_changes_nothing_and_opens_no_new_transition(): void
    {
        $october = $this->postedOctoberPayingTheRaise();
        $this->reverseRun($october);

        $this->expectException(PayrollRunAlreadyReversedException::class);
        try {
            $this->reverseRun($october);
        } finally {
            $this->assertSame(1, $this->inSchool($this->school, fn () => DB::table('payroll_run_postings')->where('payroll_run_id', $october->id)->where('posting_kind', 'reversal')->count()));
            $this->assertSame('posted', $this->row($october)['status']);
        }
    }

    #[Test]
    public function successive_versions_are_attributed_to_their_own_authors_only(): void
    {
        $y = $this->createUserWithCapabilities($this->school, ['payroll.compensation.sensitive.manage', 'payroll.compensation.sensitive.view', 'payroll.runs.approve', 'payroll.runs.view']);
        $october = $this->postedOctoberPayingTheRaise();

        $this->travelTo(Carbon::parse('2015-10-28 06:00:00'));
        $this->raise('2015-11-01', '57000.00', $y);
        $november = $this->calculatedRun('2015-11-01');
        $this->assertSame([$y->id], $this->compensationAuthors($november), 'October stands: only the new version is first paid');
        $this->approve($november, $this->checker);

        $this->reverseRun($october);

        // X's version ran only in reversed October and was superseded: no later run pays it, so nobody is attributed it.
        $december = $this->calculatedRun('2015-12-01');
        $this->assertSame([], $this->compensationAuthors($december));
        $this->approve($december, $this->x);
        $this->assertSame('approved', $this->row($december)['status']);
    }

    #[Test]
    public function a_same_amount_version_is_still_attributed_after_a_reversal(): void
    {
        $october = $this->postedOctoberPayingTheRaise();
        $this->reverseRun($october);
        $this->travelTo(Carbon::parse('2015-10-28 06:00:00'));
        $this->raise('2015-11-01', '55000.00');

        $november = $this->calculatedRun('2015-11-01');
        $this->assertSame([$this->x->id], $this->compensationAuthors($november));
        $this->refusesAuthor($november);
    }

    #[Test]
    public function a_future_effective_version_is_not_attributed_to_a_run_that_does_not_pay_it(): void
    {
        $october = $this->postedOctoberPayingTheRaise();
        $y = $this->createUserWithCapabilities($this->school, ['payroll.compensation.sensitive.manage']);
        $this->reverseRun($october);
        $this->travelTo(Carbon::parse('2015-10-28 06:00:00'));
        $this->raise('2016-02-01', '60000.00', $y);

        $november = $this->calculatedRun('2015-11-01');
        $this->assertSame([$this->x->id], $this->compensationAuthors($november), 'the version it pays, not the future one');
    }

    #[Test]
    public function correction_runs_are_never_predecessors_and_their_reversal_changes_no_attribution(): void
    {
        $october = $this->postedOctoberPayingTheRaise();
        $correction = $this->inSchool($this->school, function () use ($october): PayrollRun {
            $runs = app(PayrollRunService::class);
            $correction = $runs->createCorrectionRun($october->fresh(), $october->fresh()->period, $this->preparer);
            $basic = (string) DB::table('salary_components')->where('code', 'BASIC')->value('id');
            $runs->recordCorrectionDelta($correction, $this->record->fresh(), [new CorrectionDeltaInput($basic, '500.00', 'increase')], 'Missed allowance', $this->preparer);
            $runs->calculate($correction->fresh(), $this->preparer);

            return $correction->fresh();
        });
        $this->assertSame([], $this->compensationAuthors($correction), 'priced by deltas; its makers are their authors');
        $this->approve($correction, $this->checker);
        $this->postRun($correction);
        $this->reverseRun($correction);

        $november = $this->calculatedRun('2015-11-01');
        $this->assertSame([], $this->compensationAuthors($november), 'reversing a correction leaves October standing');

        // Reversing the original, which an existing correction does not prevent, is what re-attributes the change.
        $this->reverseRun($october);
        $this->assertSame([$this->x->id], $this->compensationAuthors($november), 'an approved correction never stands in for it');
    }

    #[Test]
    public function a_stale_calculation_stays_refused_after_a_reversal(): void
    {
        $october = $this->postedOctoberPayingTheRaise();
        $november = $this->calculatedRun('2015-11-01');
        $this->reverseRun($october);
        $this->travelTo(Carbon::parse('2015-11-26 06:00:00'));
        $this->raise('2015-11-01', '58000.00');

        $this->expectException(RunRecalculationRequiredException::class);
        $this->approve($november, $this->checker);
    }

    #[Test]
    public function the_comparison_baseline_stays_the_previous_run_and_says_it_was_reversed(): void
    {
        $october = $this->postedOctoberPayingTheRaise();
        $this->reverseRun($october);
        $november = $this->calculatedRun('2015-11-01');

        $packet = $this->packet($november);
        $this->assertSame($october->id, $packet['previousRun']['id'], 'PAY-1: which run is compared is unchanged');
        $this->assertContains('PAYROLL_PREVIOUS_RUN_REVERSED', array_column($packet['warnings'], 'code'));
        $this->assertSame(1, $packet['compensation']->total(), 'the change is listed even though the figures match the baseline');
        $this->assertFalse($this->packet($november, $this->x)['approvable'], 'never offered as ready to the author');
    }

    #[Test]
    public function the_runtime_role_cannot_approve_as_the_author_after_a_reversal(): void
    {
        $october = $this->postedOctoberPayingTheRaise();
        $this->reverseRun($october);
        $november = $this->calculatedRun('2015-11-01');
        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u);
        $calculation = $this->inSchool($this->school, fn () => app(PayrollRunService::class)->latestCalculationId($november));

        try {
            DB::transaction(fn () => $this->inSchool($this->school, fn () => DB::table('payroll_runs')->where('id', $november->id)->update(['status' => 'approved', 'approved_by_user_id' => $this->x->id, 'approved_at' => now(), 'approved_calculation_id' => $calculation])));
            $this->fail('the runtime role approved as the author');
        } catch (QueryException $e) {
            $this->assertStringContainsString('approved by the author of a compensation change it first pays', $e->getMessage());
        }
        $this->assertSame('calculated', $this->row($november)['status']);
    }

    #[Test]
    public function the_runtime_role_cannot_rewrite_what_the_attribution_and_pricing_rest_on(): void
    {
        $assignment = $this->inSchool($this->school, fn () => DB::table('employee_compensation_assignments')->whereNotNull('assigned_by_user_id')->first());
        $other = $this->createUser();
        foreach ([['assigned_by_user_id' => null], ['assigned_by_user_id' => $other->id], ['effective_from' => '2015-01-02'], ['salary_structure_id' => (string) DB::connection('pgsql_admin')->table('salary_structures')->where('school_id', '<>', $this->school->id)->value('id') ?: $assignment->salary_structure_id]] as $write) {
            if (array_key_exists('salary_structure_id', $write) && $write['salary_structure_id'] === $assignment->salary_structure_id) {
                continue;
            }
            try {
                DB::transaction(fn () => $this->inSchool($this->school, fn () => DB::table('employee_compensation_assignments')->where('id', $assignment->id)->update($write)));
                $this->fail('the runtime role rewrote '.array_key_first($write));
            } catch (QueryException $e) {
                $this->assertMatchesRegularExpression('/payroll_compensation_assignment_identity_guard|foreign key/', $e->getMessage());
            }
        }
        $this->inSchool($this->school, fn () => DB::table('employee_compensation_assignments')->where('id', $assignment->id)->update(['effective_to' => '2015-12-31']));
        $this->assertSame('2015-12-31', (string) $this->inSchool($this->school, fn () => DB::table('employee_compensation_assignments')->where('id', $assignment->id)->value('effective_to')), 'closing an assignment stays the ordinary write');
    }

    #[Test]
    public function another_schools_reversal_never_changes_this_schools_attribution(): void
    {
        $october = $this->postedOctoberPayingTheRaise();
        $other = $this->createSchool();
        $this->payrollSetup($other);
        $intruder = $this->createUserWithCapabilities($other, ['payroll.runs.reverse']);

        try {
            $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->reverse($october->fresh(), $intruder, $this->freshMfaProofFor($intruder), 'forged'));
            $this->fail('a user of another School reversed a run');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(AssertionFailedError::class, $e);
        }
        $november = $this->calculatedRun('2015-11-01');
        $this->assertSame([], $this->compensationAuthors($november), 'October still stands');
    }
}
