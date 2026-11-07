<?php

namespace Tests\Feature\Payroll;

use App\Domain\Payroll\Application\CorrectionDeltaInput;
use App\Domain\Payroll\Application\Exceptions\CorrectionTargetAlreadyReversedException;
use App\Domain\Payroll\Application\Exceptions\PayrollRunAlreadyReversedException;
use App\Domain\Payroll\Application\Exceptions\SelfApprovalNotAllowedException;
use App\Domain\Payroll\Application\PayrollApprovalReviewService;
use App\Domain\Payroll\Application\PayrollPostingService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\SchoolAuditEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\Payroll\Concerns\BuildsPayrollCorrections;
use Tests\Feature\Payroll\Concerns\BuildsPayrollIndependenceScenarios;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * HR.R1-C8 (PAY-17): a correction needs a run that stands. Reproduced at c4ea0307: a
 * correction was approved and posted after its target was reversed, leaving 500.00 in
 * the ledger with nothing under it, and a raw INSERT on a reversed target was accepted.
 * A reversal itself is never blocked (ADR 0034): a correction already posted stands, is
 * named, and is left for an authorized person (owner, HR.R1-C8).
 */
class PayrollCorrectionTargetTest extends TestCase
{
    use BuildsPayrollCorrections, BuildsPayrollIndependenceScenarios, CreatesPayrollRetentionFixtures, ProvidesSensitiveActionMfa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function runtimeRefuses(callable $write, string $reason): void
    {
        try {
            DB::transaction(fn () => $this->inSchool($this->school, $write));
            $this->fail('the runtime role was allowed: '.$reason);
        } catch (QueryException $e) {
            $this->assertStringContainsString($reason, (string) $e->getPrevious()?->getMessage());
        }
    }

    #[Test]
    public function a_correction_of_a_standing_run_still_posts(): void
    {
        $target = $this->postedTarget();
        $correction = $this->calculatedCorrection($target);

        $this->approve($correction, $this->checker);
        $this->postRun($correction);

        $this->assertSame('posted', $this->runRow($correction)['status']);
        $this->assertSame(['original' => '50500.00'], array_map(fn ($v) => (string) $v, $this->ledger($target, $correction)));
    }

    #[Test]
    public function a_correction_of_a_reversed_run_is_never_approved(): void
    {
        $target = $this->postedTarget();
        $correction = $this->calculatedCorrection($target);
        $this->reverseRun($target);

        try {
            $this->approve($correction, $this->checker);
            $this->fail('approved a correction of a reversed run');
        } catch (CorrectionTargetAlreadyReversedException) {
            $this->assertSame('calculated', $this->runRow($correction)['status']);
            $this->assertNull($this->runRow($correction)['approved_by_user_id']);
        }
    }

    #[Test]
    public function an_approved_correction_never_posts_onto_a_reversed_run_and_the_ledger_nets_to_nothing(): void
    {
        $target = $this->postedTarget();
        $correction = $this->calculatedCorrection($target);
        $this->approve($correction, $this->checker);
        $this->reverseRun($target);

        try {
            $this->postRun($correction);
            $this->fail('posted a delta with no economic basis');
        } catch (CorrectionTargetAlreadyReversedException) {
            $this->assertSame('approved', $this->runRow($correction)['status'], 'the approval stands as evidence');
            $this->assertSame(0, $this->postings($correction));
        }
        $this->assertSame(['original' => '50000.00', 'reversal' => '50000.00'], array_map(fn ($v) => (string) $v, $this->ledger($target, $correction)));
    }

    #[Test]
    public function no_correction_is_created_on_a_reversed_run(): void
    {
        $target = $this->postedTarget();
        $this->reverseRun($target);

        $this->expectException(CorrectionTargetAlreadyReversedException::class);
        $this->draftCorrection($target);
    }

    #[Test]
    public function the_database_refuses_a_correction_of_a_reversed_run_whichever_code_writes(): void
    {
        $target = $this->postedTarget();
        $calculated = $this->calculatedCorrection($target);
        $period = $this->openPeriod('2015-10-01');
        $approved = $this->calculatedCorrection($target, '2015-11-01');
        $this->approve($approved, $this->checker);
        $this->reverseRun($target);

        $this->runtimeRefuses(fn () => DB::table('payroll_runs')->insert(['id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'payroll_period_id' => $period->id, 'run_kind' => 'correction', 'corrects_payroll_run_id' => $target->id, 'status' => 'draft', 'prepared_by_user_id' => $this->preparer->id, 'created_at' => now(), 'updated_at' => now()]), 'a correction needs a target that stands');
        $this->runtimeRefuses(fn () => DB::table('payroll_runs')->where('id', $calculated->id)->update(['status' => 'approved', 'approved_by_user_id' => $this->checker->id, 'approved_at' => now(), 'approved_calculation_id' => DB::table('payroll_run_calculations')->where('payroll_run_id', $calculated->id)->value('id')]), 'a correction needs a target that stands');
        $this->runtimeRefuses(fn () => DB::table('payroll_runs')->where('id', $approved->id)->update(['status' => 'posted', 'posted_by_user_id' => $this->finance->id, 'posted_at' => now()]), 'a correction needs a target that stands');

        $this->assertSame('calculated', $this->runRow($calculated)['status']);
        $this->assertSame('approved', $this->runRow($approved)['status']);
    }

    #[Test]
    public function a_correction_of_a_reversed_run_is_a_dead_record_nobody_can_edit(): void
    {
        $target = $this->postedTarget();
        $correction = $this->calculatedCorrection($target);
        $this->reverseRun($target);
        $results = $this->inSchool($this->school, fn () => DB::table('payroll_run_calculations')->where('payroll_run_id', $correction->id)->count());

        try {
            $this->inSchool($this->school, fn () => app(PayrollRunService::class)->calculate($correction->fresh(), $this->preparer));
            $this->fail('recalculated a dead correction');
        } catch (CorrectionTargetAlreadyReversedException) {
            $this->assertSame($results, $this->inSchool($this->school, fn () => DB::table('payroll_run_calculations')->where('payroll_run_id', $correction->id)->count()));
        }

        $this->expectException(CorrectionTargetAlreadyReversedException::class);
        $this->inSchool($this->school, function () use ($correction): void {
            $component = DB::table('salary_structure_components')->where('id', $this->setup['line'])->value('salary_component_id');
            app(PayrollRunService::class)->recordCorrectionDelta($correction->fresh(), $this->record->fresh(), [new CorrectionDeltaInput($component, '100.00', 'increase')], 'late', $this->preparer);
        });
    }

    #[Test]
    public function the_review_packet_and_the_run_page_say_so_before_anyone_tries(): void
    {
        $target = $this->postedTarget();
        $correction = $this->calculatedCorrection($target);
        $packet = fn () => $this->inSchool($this->school, fn () => app(PayrollApprovalReviewService::class)->packet($this->school, $correction->id, $this->checker));
        $this->assertNotContains('PAYROLL_CORRECTION_TARGET_ALREADY_REVERSED', array_column($packet()['blocking'], 'code'), 'control');

        $this->reverseRun($target);

        $this->assertContains('PAYROLL_CORRECTION_TARGET_ALREADY_REVERSED', array_column($packet()['blocking'], 'code'));
    }

    #[Test]
    public function the_web_and_api_routes_refuse_with_the_closed_message(): void
    {
        $target = $this->postedTarget();
        $correction = $this->calculatedCorrection($target);
        $this->reverseRun($target);
        $this->mfaEnrolled($this->checker);
        [$calculation, $attribution] = $this->inSchool($this->school, function () use ($correction): array {
            $runs = app(PayrollRunService::class);

            return [$runs->latestCalculationId($correction->fresh()), $runs->latestAttributionVersion($correction->fresh())];
        });

        $this->actingAs($this->checker)->post("/app/schools/{$this->school->id}/activate");
        $this->post("/app/payroll/runs/{$correction->id}/approve", ['calculation_id' => $calculation, 'attribution_version' => $attribution, 'mfa_code' => $this->freshMfaCode($this->checker)])
            ->assertSessionHasErrors('payroll');
        $this->assertSame('calculated', $this->runRow($correction)['status']);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$this->mfaToken($this->checker))->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/schools/{$this->school->id}/payroll-runs/{$correction->id}/approve", ['calculation_id' => $calculation, 'attribution_version' => $attribution, 'mfa_code' => $this->freshMfaCode($this->checker)])
            ->assertStatus(422)->assertJsonPath('error.code', 'PAYROLL_CORRECTION_TARGET_ALREADY_REVERSED');
        $this->assertSame('calculated', $this->runRow($correction)['status']);
    }

    #[Test]
    public function a_reversal_is_never_blocked_and_a_posted_correction_is_named_and_left_standing(): void
    {
        $target = $this->postedTarget();
        $correction = $this->calculatedCorrection($target);
        $this->approve($correction, $this->checker);
        $this->postRun($correction);

        $this->reverseRun($target);

        $this->assertSame([$correction->id], $this->audit('payroll.run.reversed', $target)[0]['standingCorrectionRunIds']);
        $this->assertSame('posted', $this->runRow($correction)['status']);
        $this->assertSame(1, $this->postings($correction), 'nothing reverses it automatically');
        $this->assertSame(['original' => '50500.00', 'reversal' => '50000.00'], array_map(fn ($v) => (string) $v, $this->ledger($target, $correction)));
        $this->assertSame([$correction->id], $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->standingCorrections($this->school->id, $target->id)));

        // An authorized person then decides, through the ordinary reversal.
        $this->reverseRun($correction);

        $this->assertSame([], $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->standingCorrections($this->school->id, $target->id)));
        $this->assertSame(['original' => '50500.00', 'reversal' => '50500.00'], array_map(fn ($v) => (string) $v, $this->ledger($target, $correction)));
    }

    #[Test]
    public function reversing_a_run_with_no_correction_records_an_empty_list(): void
    {
        $target = $this->postedTarget();

        $this->reverseRun($target);

        $this->assertSame([], $this->audit('payroll.run.reversed', $target)[0]['standingCorrectionRunIds']);
    }

    #[Test]
    public function a_reversed_correction_leaves_its_target_correctable_and_a_repeat_reversal_changes_nothing(): void
    {
        $target = $this->postedTarget();
        $correction = $this->calculatedCorrection($target);
        $this->approve($correction, $this->checker);
        $this->postRun($correction);
        $this->reverseRun($correction);

        try {
            $this->reverseRun($correction);
            $this->fail('reversed twice');
        } catch (PayrollRunAlreadyReversedException) {
            $this->assertSame(2, $this->postings($correction));
        }

        $second = $this->calculatedCorrection($target, '2015-10-01');
        $this->approve($second, $this->checker);
        $this->postRun($second);
        $this->assertSame('posted', $this->runRow($second)['status']);
    }

    #[Test]
    public function the_maker_of_a_correction_still_cannot_approve_it(): void
    {
        $correction = $this->calculatedCorrection($this->postedTarget());

        try {
            $this->approve($correction, $this->preparer);
            $this->fail('a maker approved');
        } catch (SelfApprovalNotAllowedException) {
            $this->assertSame('calculated', $this->runRow($correction)['status']);
        }
    }

    #[Test]
    public function a_correction_touches_no_open_obligation_and_no_first_paid_attribution(): void
    {
        [$october, $november] = $this->octoberAndPostedNovember();
        $this->reverseRun($october);
        $o = $this->obligation($november);
        $version = $this->reviewVersion($o);

        $correction = $this->calculatedCorrection($november, '2015-12-01');
        $this->approve($correction, $this->checker);
        $this->postRun($correction);

        $this->assertSame(1, $this->obligations($november));
        $this->assertSame(0, $this->reviews(), 'a correction is not a review');
        $this->assertSame($version, $this->reviewVersion($o), 'nor does it move what the review will see');
    }

    #[Test]
    public function a_period_stays_a_separate_rule(): void
    {
        $target = $this->postedTarget();
        $this->travelTo(Carbon::parse('2015-09-01 06:00:00'));

        $this->assertInstanceOf(PayrollRun::class, $this->draftCorrection($target));
        $this->assertSame(1, $this->inSchool($this->school, fn () => SchoolAuditEvent::query()->where('event_type', 'payroll.run.correction_created')->count()));
    }
}
