<?php

namespace Tests\Feature\Payments;

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Application\Exceptions\ChargeHasLiveLateFeeException;
use App\Domain\Fees\Application\Exceptions\ChargeHasPaymentAllocationsException;
use App\Domain\Fees\Application\Exceptions\ChargeIsLateFeeException;
use App\Domain\Fees\Application\Exceptions\FeeLateFeeRuleNotEditableException;
use App\Domain\Fees\Application\Exceptions\InvalidFeeLateFeeRuleException;
use App\Domain\Fees\Application\FeeAssessmentService;
use App\Domain\Fees\Infrastructure\FeeAssessment;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Payments\Application\Exceptions\InvalidLateFeeRunException;
use App\Domain\Payments\Application\Exceptions\LateFeeAssessmentAlreadyVoidedException;
use App\Domain\Payments\Application\Exceptions\LateFeeRunOpenConflictException;
use App\Domain\Payments\Application\Exceptions\LateFeeRunStalePreviewException;
use App\Domain\Payments\Application\LateFeeAssessmentService;
use App\Domain\Payments\Application\LateFeeItemExecutor;
use App\Domain\Payments\Application\LateFeeReadService;
use App\Domain\Payments\Domain\LateFeeCalculation;
use App\Domain\Payments\Infrastructure\LateFeeRun;
use App\Domain\Payments\Infrastructure\LateFeeRunItem;
use App\Jobs\ExecuteLateFeeRunJob;
use App\Support\Money\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesLateFeeFixtures;
use Tests\TestCase;

/**
 * FEE.5 (ADR 0062 §16; owner decision H) through the Application services:
 * rule validation, the frozen grace boundary, fixed / percentage-of-current-
 * outstanding / cap arithmetic, preview vs. execution (preview is never
 * authority), idempotency, no late fee on a late fee, void and one
 * deliberate re-assessment, accounting, audit/outbox and authorization.
 * Legal status: DEVELOPMENT AUTHORISED — PROD LEGAL SIGN-OFF REQUIRED.
 * The source charge is T1 5000.00, due 2026-06-10.
 */
class LateFeeServiceTest extends TestCase
{
    use CreatesLateFeeFixtures;

    // --- Rules --------------------------------------------------------------

    #[Test]
    public function rules_are_validated_to_the_h_model(): void
    {
        $w = $this->lateFeeWorld();
        $base = ['name' => 'R', 'fee_structure_id' => $w['structure']->id, 'late_fee_head_id' => $w['lateHead']->id, 'grace_days' => 0, 'kind' => 'fixed', 'fixed_amount' => '100'];

        $fixed = $this->lateRules()->create($w['school'], $base, $w['actor']);
        $this->assertSame(['inactive', '100.00', null, 0], [$fixed->status, $fixed->fixed_amount, $fixed->percentage, $fixed->grace_days]);
        $pct = $this->lateRules()->create($w['school'], [...$base, 'kind' => 'percentage', 'fixed_amount' => null, 'percentage' => '2.5', 'max_amount' => '500', 'fee_head_id' => $w['head']->id], $w['actor']);
        $this->assertSame(['2.50', '500.00', null], [$pct->percentage, $pct->max_amount, $pct->fixed_amount]);

        $cases = [
            [['kind' => 'tiered'], 'kind'],
            [['fixed_amount' => '0'], 'fixed_amount'],
            [['fixed_amount' => '-5'], 'fixed_amount'],
            [['fixed_amount' => '1.005'], 'fixed_amount'],
            [['kind' => 'percentage', 'fixed_amount' => null, 'percentage' => '0'], 'percentage'],
            [['kind' => 'percentage', 'fixed_amount' => null, 'percentage' => '100.01'], 'percentage'],
            [['grace_days' => -1], 'grace_days'],
            [['grace_days' => '1.5'], 'grace_days'],
            [['max_amount' => '0'], 'max_amount'],
            [['fee_head_id' => $w['lateHead']->id], 'fee_head_id'],
            [['late_fee_head_id' => (string) Str::uuid()], 'late_fee_head_id'],
            [['fee_structure_id' => (string) Str::uuid()], 'fee_structure_id'],
        ];
        foreach ($cases as [$override, $field]) {
            try {
                $this->lateRules()->create($w['school'], [...$base, ...$override], $w['actor']);
                $this->fail('Refused: '.json_encode($override));
            } catch (InvalidFeeLateFeeRuleException $e) {
                $this->assertSame($field, $e->field(), json_encode($override));
            }
        }

        // A percentage rule never also carries a fixed amount (normalized away).
        $this->assertNull($this->lateRules()->create($w['school'], [...$base, 'kind' => 'percentage', 'percentage' => '1', 'fixed_amount' => '9'], $w['actor'])->fixed_amount);
    }

    #[Test]
    public function an_active_rule_is_not_editable_and_every_change_is_versioned_and_audited(): void
    {
        $w = $this->lateFeeWorld();
        $rule = $this->activeRule($w);
        $this->assertSame(2, $rule->configuration_version);

        try {
            $this->lateRules()->update($w['school'], $rule->id, ['name' => 'X', 'late_fee_head_id' => $w['lateHead']->id, 'grace_days' => 1, 'kind' => 'fixed', 'fixed_amount' => '5'], $w['actor']);
            $this->fail('Deactivate before editing.');
        } catch (FeeLateFeeRuleNotEditableException) {
            $this->addToAssertionCount(1);
        }

        $this->lateRules()->deactivate($w['school'], $rule->id, $w['actor']);
        $edited = $this->lateRules()->update($w['school'], $rule->id, ['name' => 'X', 'late_fee_head_id' => $w['lateHead']->id, 'grace_days' => 1, 'kind' => 'fixed', 'fixed_amount' => '5'], $w['actor']);
        $this->assertSame(4, $edited->configuration_version);

        $this->assertSame(1, $this->auditCount($w['school'], 'late_fee_rule.created'));
        $this->assertSame(1, $this->auditCount($w['school'], 'late_fee_rule.updated'));
        $this->assertSame(2, $this->auditCount($w['school'], 'late_fee_rule.status_changed'));
    }

    // --- Grace boundary -----------------------------------------------------

    #[Test]
    public function the_grace_boundary_is_evaluation_after_due_plus_grace(): void
    {
        $this->assertFalse(LateFeeCalculation::graceElapsed('2026-06-10', 0, '2026-06-10'), 'Never on the due date.');
        $this->assertTrue(LateFeeCalculation::graceElapsed('2026-06-10', 0, '2026-06-11'));
        $this->assertFalse(LateFeeCalculation::graceElapsed('2026-06-10', 5, '2026-06-15'), 'Never on the final grace date.');
        $this->assertTrue(LateFeeCalculation::graceElapsed('2026-06-10', 5, '2026-06-16'));
        $this->assertSame('2026-06-15', LateFeeCalculation::finalGraceDate('2026-06-10', 5));
        $this->assertSame('2026-03-01', LateFeeCalculation::finalGraceDate('2026-02-27', 2), 'Calendar arithmetic, not 30-day months.');

        $w = $this->lateFeeWorld();
        $zero = $this->activeRule($w);
        $this->assertSame('grace_not_elapsed', $this->lateItems($w, $this->previewedLateRun($w, $zero, '2026-06-10'))->sole()->reason);
        $this->lateRuns()->cancel($w['school'], $this->inSchool($w['school'], fn () => LateFeeRun::query()->sole()->id), $w['actor']);
        $this->assertSame('ready', $this->lateItems($w, $this->previewedLateRun($w, $zero, '2026-06-11'))->sole()->preview_result);

        $five = $this->activeRule($w, ['name' => 'Five', 'grace_days' => 5]);
        $item = $this->lateItems($w, $run = $this->previewedLateRun($w, $five, '2026-06-15'))->sole();
        $this->assertSame(['not_eligible', 'grace_not_elapsed', '2026-06-15'], [$item->preview_result, $item->reason, $item->final_grace_date->toDateString()]);
        $this->lateRuns()->cancel($w['school'], $run->id, $w['actor']);
        $this->assertSame('ready', $this->lateItems($w, $this->previewedLateRun($w, $five, '2026-06-16'))->sole()->preview_result);
    }

    #[Test]
    public function the_default_evaluation_date_is_today_in_the_schools_timezone_and_never_the_future(): void
    {
        $w = $this->lateFeeWorld();
        $rule = $this->activeRule($w);

        Carbon::setTestNow('2026-06-10 20:00:00'); // 2026-06-11 01:30 in Asia/Kolkata
        try {
            $run = $this->lateRuns()->create($w['school'], $rule->id, null, $w['actor']);
            $this->assertSame('2026-06-11', $run->evaluation_date->toDateString(), 'The School calendar, not the server\'s UTC date.');
        } finally {
            Carbon::setTestNow();
        }
        $this->lateRuns()->cancel($w['school'], $run->id, $w['actor']);

        $this->expectException(InvalidLateFeeRunException::class);
        $this->lateRuns()->create($w['school'], $rule->id, Carbon::now($w['school']->timezone)->addDay()->toDateString(), $w['actor']);
    }

    // --- Amounts ------------------------------------------------------------

    #[Test]
    public function fixed_and_percentage_amounts_with_the_cap(): void
    {
        $owed = Money::of('4000.00', 'INR');
        $this->assertSame(['100.00', '100.00', false], $this->calc('fixed', '100.00', null, null, $owed));
        $this->assertSame(['100.00', '60.00', true], $this->calc('fixed', '100.00', null, '60.00', $owed), 'Cap below the fixed amount reduces it.');
        $this->assertSame(['100.00', '100.00', false], $this->calc('fixed', '100.00', null, '150.00', $owed));
        $this->assertSame(['80.00', '80.00', false], $this->calc('percentage', null, '2.00', null, $owed));
        $this->assertSame(['80.00', '50.00', true], $this->calc('percentage', null, '2.00', '50.00', $owed));
        $this->assertSame(['0.01', '0.01', false], $this->calc('percentage', null, '0.50', null, Money::of('1.00', 'INR')), '0.005 -> 0.01 (N).');
        $this->assertSame(['0.00', '0.00', false], $this->calc('percentage', null, '0.01', null, Money::of('0.10', 'INR')), 'A zero result is no late fee.');
    }

    /** @return array{0: string, 1: string, 2: bool} */
    private function calc(string $kind, ?string $fixed, ?string $pct, ?string $cap, Money $owed): array
    {
        $r = LateFeeCalculation::amount($kind, $fixed, $pct, $cap, $owed);

        return [$r['calculated']->amount(), $r['final']->amount(), $r['capApplied']];
    }

    #[Test]
    public function a_percentage_late_fee_uses_the_current_outstanding_after_payments_and_concessions(): void
    {
        $w = $this->lateFeeWorld();
        $this->pay($w, '1000.00', '2026-06-05', $w['source']);
        $concession = $this->requestTargeted($w, '1500.00', charge: $w['source']);
        $this->concessions()->approve($w['school'], $concession->id, $w['checker']);
        $rule = $this->activeRule($w, ['kind' => 'percentage', 'fixed_amount' => null, 'percentage' => '10.00']);

        $run = $this->executedLateRun($w, $rule);

        $item = $this->lateItems($w, $run)->sole();
        $this->assertSame(['2500.00', '250.00', '250.00'], [$item->outstanding_amount, $item->final_amount, $item->executed_amount], '10% of 5000 - 1000 - 1500, never of 5000.');
        $fee = $this->chargeOf($w, $this->lateFees($w)->sole()->charge_id);
        $this->assertSame('250.00', $fee->amount);
    }

    #[Test]
    public function a_payment_or_concession_after_preview_changes_the_execution_amount(): void
    {
        $w = $this->lateFeeWorld();
        $rule = $this->activeRule($w, ['kind' => 'percentage', 'fixed_amount' => null, 'percentage' => '10.00']);
        $run = $this->previewedLateRun($w, $rule);
        $this->assertSame('500.00', $this->lateItems($w, $run)->sole()->final_amount, 'Preview: 10% of 5000.');

        $this->pay($w, '2000.00', '2026-06-20', $w['source']);
        $concession = $this->requestTargeted($w, '1000.00', charge: $w['source']);
        $this->concessions()->approve($w['school'], $concession->id, $w['checker']);
        $this->lateRuns()->execute($w['school'], $run->id, $w['actor']);

        $item = $this->lateItems($w, $run)->sole();
        $this->assertSame(['500.00', '2000.00', '200.00'], [$item->final_amount, $item->executed_outstanding_amount, $item->executed_amount], 'Execution recomputes from the new outstanding.');
        $this->assertSame('200.00', $this->chargeOf($w, $this->lateFees($w)->sole()->charge_id)->amount, 'No stale preview amount is posted.');
    }

    #[Test]
    public function a_fully_settled_charge_gets_no_late_fee_in_preview_or_execution(): void
    {
        $w = $this->lateFeeWorld();
        $rule = $this->activeRule($w);
        $run = $this->previewedLateRun($w, $rule);
        $this->pay($w, '5000.00', '2026-06-20', $w['source']);

        $this->lateRuns()->execute($w['school'], $run->id, $w['actor']);

        $item = $this->lateItems($w, $run)->sole();
        $this->assertSame(['failed', 'fully_settled', '0.00'], [$item->execution_status, $item->failure_reason, $item->executed_outstanding_amount]);
        $this->assertCount(0, $this->lateFees($w));
        $this->assertCount(0, $this->outboxOf($w, 'late_fee.assessed.v1'), 'A failed item emits no financial event.');
        $this->assertSame('completed_with_errors', $this->inSchool($w['school'], fn () => $run->refresh()->status));

        $again = $this->previewedLateRun($w, $rule, '2026-06-21');
        $this->assertSame(['not_eligible', 'fully_settled'], [$this->lateItems($w, $again)->sole()->preview_result, $this->lateItems($w, $again)->sole()->reason]);
    }

    // --- Posting, idempotency, non-recurrence -------------------------------

    #[Test]
    public function a_late_fee_is_a_new_linked_charge_posted_once(): void
    {
        $w = $this->lateFeeWorld();
        $rule = $this->activeRule($w, ['max_amount' => '60.00']);
        $journals = $this->inSchool($w['school'], fn () => JournalEntry::query()->count());

        $run = $this->executedLateRun($w, $rule);

        $link = $this->lateFees($w)->sole();
        $fee = $this->chargeOf($w, $link->charge_id);
        $this->assertSame([$w['source']->id, $rule->id, $run->id], [$link->source_charge_id, $link->fee_late_fee_rule_id, $link->late_fee_run_id]);
        $this->assertSame(['60.00', $w['source']->student_id, $w['source']->academic_year_id, '2026-06-11'], [$fee->amount, $fee->student_id, $fee->academic_year_id, $fee->due_date->toDateString()], 'Capped fixed fee, same Student and year, due on the evaluation date.');
        $this->assertStringStartsWith('Late fee: ', $fee->description);
        $this->assertSame([$w['lateHead']->receivable_ledger_account_id => 'D:60.00', $w['lateIncome']->id => 'C:60.00'], $this->journalLines($w, $fee->journal_entry_id));
        $this->assertSame($journals + 1, $this->inSchool($w['school'], fn () => JournalEntry::query()->count()));
        $this->assertSame('5000.00', $this->chargeOf($w, $w['source']->id)->amount, 'The source charge never changes.');
        $this->assertSame(['completed', 1, '60.00'], [$run->status, $run->succeeded_count, $run->assessed_amount]);

        $this->assertSame(1, $this->auditCount($w['school'], 'late_fee.assessed'));
        $this->assertCount(1, $this->outboxOf($w, 'late_fee.assessed.v1'));
        $this->assertEqualsCanonicalizing(['lateFeeAssessmentId', 'sourceChargeId', 'chargeId', 'lateFeeRuleId', 'lateFeeRunId', 'currency'], array_keys($this->outboxOf($w, 'late_fee.assessed.v1')->first()->payload));
        foreach (['created', 'previewed', 'execution_started', 'completed'] as $event) {
            $this->assertSame(1, $this->auditCount($w['school'], "late_fee_run.{$event}"), $event);
        }
    }

    #[Test]
    public function re_executing_resuming_and_later_runs_never_create_a_second_live_late_fee(): void
    {
        $w = $this->lateFeeWorld();
        $rule = $this->activeRule($w);
        $run = $this->executedLateRun($w, $rule);
        $item = $this->lateItems($w, $run)->sole();

        // The same item again (a retried worker) and the job again.
        $this->assertSame('not_executing', app(LateFeeItemExecutor::class)->executeItem($w['school'], $run->id, $item->id));
        $this->assertSame(['processed' => 0, 'paused' => false, 'pendingRemaining' => false], app(LateFeeItemExecutor::class)->executeBatch($w['school'], $run->id));

        // A later run of the same rule: preview says already assessed; nothing is posted.
        $later = $this->executedLateRun($w, $rule, '2026-07-01');
        $this->assertSame('already_assessed', $this->lateItems($w, $later)->sole()->preview_result);
        $this->assertCount(1, $this->lateFees($w));
        $this->assertCount(1, $this->outboxOf($w, 'late_fee.assessed.v1'), 'No duplicate event.');

        // One open run per rule.
        $this->lateRuns()->create($w['school'], $rule->id, '2026-07-02', $w['actor']);
        $this->expectException(LateFeeRunOpenConflictException::class);
        $this->lateRuns()->create($w['school'], $rule->id, '2026-07-03', $w['actor']);
    }

    #[Test]
    public function a_second_rule_is_keyed_separately_and_a_late_fee_never_attracts_one(): void
    {
        $w = $this->lateFeeWorld();
        $first = $this->activeRule($w);
        $this->executedLateRun($w, $first);
        $second = $this->activeRule($w, ['name' => 'Second', 'fixed_amount' => '25.00']);
        $this->executedLateRun($w, $second);

        $this->assertCount(2, $this->lateFees($w), 'One per source charge per rule.');
        $candidates = app(ChargeService::class)->lateFeeCandidates($w['school'], $w['structure']->id, null);
        $this->assertSame([$w['source']->id], array_map(fn ($c) => $c->chargeId, $candidates), 'Late-fee charges are never candidates.');
    }

    #[Test]
    public function a_rule_changed_after_preview_makes_the_run_stale(): void
    {
        $w = $this->lateFeeWorld();
        $rule = $this->activeRule($w);
        $run = $this->previewedLateRun($w, $rule);
        $this->lateRules()->deactivate($w['school'], $rule->id, $w['actor']);
        $this->lateRules()->activate($w['school'], $rule->id, $w['actor']);

        $this->expectException(LateFeeRunStalePreviewException::class);
        $this->lateRuns()->execute($w['school'], $run->id, $w['actor']);
    }

    // --- Void and re-assessment ---------------------------------------------

    #[Test]
    public function voiding_reverses_once_keeps_history_and_allows_one_deliberate_reassessment(): void
    {
        $w = $this->lateFeeWorld();
        $rule = $this->activeRule($w);
        $this->executedLateRun($w, $rule);
        $link = $this->lateFees($w)->sole();
        $voids = app(LateFeeAssessmentService::class);

        $voided = $voids->void($w['school'], $link->id, $w['actor'], 'waived at the counter');
        $this->assertNotNull($voided->voided_at);
        $fee = $this->chargeOf($w, $link->charge_id);
        $this->assertNotNull($fee->cancelled_at);
        $this->assertSame($fee->journal_entry_id, $this->inSchool($w['school'], fn () => JournalEntry::query()->findOrFail($fee->cancellation_journal_entry_id)->reversal_of_journal_entry_id));
        $this->assertSame(1, $this->auditCount($w['school'], 'late_fee_assessment.voided'));

        try {
            $voids->void($w['school'], $link->id, $w['actor']);
            $this->fail('A void happens once.');
        } catch (LateFeeAssessmentAlreadyVoidedException) {
            $this->addToAssertionCount(1);
        }

        $this->executedLateRun($w, $rule, '2026-07-01');
        $this->assertCount(2, $this->lateFees($w), 'The freed key allows one deliberate later assessment.');
        $this->assertCount(1, $this->lateFees($w)->whereNull('voided_at'));
    }

    #[Test]
    public function charge_cancellation_guards_protect_both_ends_of_the_link(): void
    {
        $w = $this->lateFeeWorld();
        $this->executedLateRun($w, $this->activeRule($w));
        $link = $this->lateFees($w)->sole();

        try {
            app(ChargeService::class)->cancel($w['school'], $link->charge_id, $w['actor']);
            $this->fail('A late fee is cancelled only by its void.');
        } catch (ChargeIsLateFeeException) {
            $this->addToAssertionCount(1);
        }

        $assessment = $this->inSchool($w['school'], fn () => FeeAssessment::query()->sole());
        try {
            app(FeeAssessmentService::class)->void($w['school'], $assessment->id, $w['actor']);
            $this->fail('The source waits until its late fee is voided.');
        } catch (ChargeHasLiveLateFeeException) {
            $this->addToAssertionCount(1);
        }
        $this->assertNull($this->inSchool($w['school'], fn () => $assessment->refresh()->voided_at));

        // A payment on the late fee blocks its void (no payment reversal exists).
        $this->pay($w, '10.00', '2026-06-20', $this->chargeOf($w, $link->charge_id));
        $this->expectException(ChargeHasPaymentAllocationsException::class);
        app(LateFeeAssessmentService::class)->void($w['school'], $link->id, $w['actor']);
    }

    // --- Authorization ------------------------------------------------------

    #[Test]
    public function every_action_needs_its_existing_capability(): void
    {
        $w = $this->lateFeeWorld();
        $rule = $this->activeRule($w);
        $run = $this->previewedLateRun($w, $rule);
        $viewer = $this->createUserWithCapabilities($w['school'], ['finance.fee_structures.view', 'finance.charges.view']);
        $base = ['name' => 'R', 'fee_structure_id' => $w['structure']->id, 'late_fee_head_id' => $w['lateHead']->id, 'grace_days' => 0, 'kind' => 'fixed', 'fixed_amount' => '1'];

        $denied = [
            fn () => $this->lateRules()->create($w['school'], $base, $viewer),
            fn () => $this->lateRules()->deactivate($w['school'], $rule->id, $viewer),
            fn () => $this->lateRuns()->create($w['school'], $rule->id, '2026-06-11', $viewer),
            fn () => $this->lateRuns()->preview($w['school'], $run->id, $viewer),
            fn () => $this->lateRuns()->execute($w['school'], $run->id, $viewer),
            fn () => $this->lateRuns()->cancel($w['school'], $run->id, $viewer),
            fn () => app(LateFeeAssessmentService::class)->void($w['school'], (string) Str::uuid(), $viewer),
            fn () => $this->lateRules()->list($w['school'], $w['maker']),
            fn () => app(LateFeeReadService::class)->listRuns($w['school'], $w['maker']),
        ];
        foreach ($denied as $i => $op) {
            try {
                $op();
                $this->fail("Operation {$i} must be denied.");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertCount(1, $this->lateRules()->list($w['school'], $viewer));
        $this->assertCount(1, app(LateFeeReadService::class)->listRuns($w['school'], $viewer));
        $this->assertSame(LateFeeRunItem::RESULT_READY, $this->lateItems($w, $run)->sole()->preview_result);
    }

    #[Test]
    public function a_retried_or_resumed_execution_never_duplicates_and_a_failure_emits_nothing(): void
    {
        $w = $this->lateFeeWorld();
        $rule = $this->activeRule($w);
        Queue::fake();
        $run = $this->previewedLateRun($w, $rule);
        $this->lateRuns()->execute($w['school'], $run->id, $w['actor']);
        $item = $this->lateItems($w, $run)->sole();
        $executor = app(LateFeeItemExecutor::class);

        $this->assertSame('succeeded', $executor->executeItem($w['school'], $run->id, $item->id));
        $this->assertSame('not_pending', $executor->executeItem($w['school'], $run->id, $item->id), 'A retried item is a no-op.');

        // A resumed job after a crash: only pending items, then finalize.
        $this->lateRuns()->resume($w['school'], $run->id, $w['actor']);
        $this->inSchool($w['school'], fn () => new ExecuteLateFeeRunJob($run->id))->handle($executor, $this->lateRuns());
        $this->inSchool($w['school'], fn () => new ExecuteLateFeeRunJob($run->id))->handle($executor, $this->lateRuns());

        $this->assertSame('completed', $this->inSchool($w['school'], fn () => $run->refresh()->status));
        $this->assertCount(1, $this->lateFees($w));
        $this->assertCount(1, $this->outboxOf($w, 'late_fee.assessed.v1'));
        $this->assertSame(1, $this->auditCount($w['school'], 'late_fee_run.completed'));
    }

    #[Test]
    public function a_suspended_school_pauses_the_run_without_changing_anything(): void
    {
        $w = $this->lateFeeWorld();
        $rule = $this->activeRule($w);
        Queue::fake();
        $run = $this->previewedLateRun($w, $rule);
        $this->lateRuns()->execute($w['school'], $run->id, $w['actor']);
        DB::table('schools')->where('id', $w['school']->id)->update(['status' => 'suspended']);

        $this->inSchool($w['school'], fn () => new ExecuteLateFeeRunJob($run->id))->handle(app(LateFeeItemExecutor::class), $this->lateRuns());

        $this->assertSame('pending', $this->lateItems($w, $run)->sole()->execution_status);
        $this->assertCount(0, $this->lateFees($w));
        $this->assertSame(1, $this->auditCount($w['school'], 'late_fee_run.paused'));

        DB::table('schools')->where('id', $w['school']->id)->update(['status' => 'active']);
        $this->inSchool($w['school'], fn () => new ExecuteLateFeeRunJob($run->id))->handle(app(LateFeeItemExecutor::class), $this->lateRuns());
        $this->assertCount(1, $this->lateFees($w), 'Staff resume after reactivation.');
    }
}
