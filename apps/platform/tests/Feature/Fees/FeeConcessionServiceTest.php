<?php

namespace Tests\Feature\Fees;

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Application\Exceptions\AdjustmentExceedsOutstandingException;
use App\Domain\Fees\Application\Exceptions\ChargeFullyPaidException;
use App\Domain\Fees\Application\Exceptions\ChargeHasActiveAdjustmentsException;
use App\Domain\Fees\Application\Exceptions\ConcessionAccountNotConfiguredException;
use App\Domain\Fees\Application\Exceptions\FeeAdjustmentAlreadyCancelledException;
use App\Domain\Fees\Application\Exceptions\FeeConcessionIdempotencyConflictException;
use App\Domain\Fees\Application\Exceptions\FeeConcessionIllegalTransitionException;
use App\Domain\Fees\Application\Exceptions\FeeConcessionNotFoundException;
use App\Domain\Fees\Application\Exceptions\FeeConcessionNotRequesterException;
use App\Domain\Fees\Application\Exceptions\InvalidFeeConcessionException;
use App\Domain\Fees\Application\Exceptions\InvalidFeeSettingsException;
use App\Domain\Fees\Application\Exceptions\SelfApprovalNotAllowedException;
use App\Domain\Fees\Application\FeeAdjustmentService;
use App\Domain\Fees\Application\FeeConcessionReadService;
use App\Domain\Fees\Application\FeeSettingsService;
use App\Domain\Fees\Application\RequestFeeConcessionData;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Fees\Infrastructure\FeeConcession;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\Payments\Application\Exceptions\ChargeAllocationExceedsChargeAmountException;
use App\Domain\Payments\Application\ManualPaymentRecordingService;
use App\Models\DomainEventOutbox;
use App\Models\SchoolAuditEvent;
use App\Support\Money\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesFeeConcessionFixtures;
use Tests\TestCase;

/**
 * FEE.3 (ADR 0062 §14; owner decisions F, F2, G1, M) through the
 * Application services: the request/approval lifecycle and maker/checker,
 * the closed categories, the F2 concession account, the G1 outstanding cap
 * against Payments (both directions), adjustment cancellation, the charge
 * cancellation guard, idempotent requests, audit and outbox, and
 * authorization allow/deny.
 */
class FeeConcessionServiceTest extends TestCase
{
    use CreatesFeeConcessionFixtures;

    private function outboxCount(array $w, string $type): int
    {
        return $this->inSchool($w['school'], fn () => DomainEventOutbox::query()->where('event_type', $type)->count());
    }

    private function journalCount(array $w): int
    {
        return $this->inSchool($w['school'], fn () => JournalEntry::query()->count());
    }

    // --- Lifecycle and maker/checker ---------------------------------------

    #[Test]
    public function a_request_is_pending_and_posts_nothing(): void
    {
        $w = $this->concessionWorld();
        $before = $this->journalCount($w);

        $concession = $this->requestTargeted($w);

        $this->assertSame('pending', $concession->status);
        $this->assertSame($w['student']->id, $concession->student_id, 'Student and year come from the charge.');
        $this->assertSame($w['year']->id, $concession->academic_year_id);
        $this->assertSame($w['maker']->id, $concession->requested_by_user_id);
        $this->assertCount(0, $this->adjustmentsOf($w));
        $this->assertSame($before, $this->journalCount($w), 'A request is never a financial posting.');
        $this->assertSame(1, $this->auditCount($w['school'], 'fee_concession.requested'));
    }

    #[Test]
    public function a_second_person_approves_a_targeted_concession_and_its_adjustment_posts_in_the_same_transaction(): void
    {
        $w = $this->concessionWorld();
        $concession = $this->requestTargeted($w, '250.00', 'waiver');

        $approved = $this->concessions()->approve($w['school'], $concession->id, $w['checker']);

        $this->assertSame('approved', $approved->status);
        $this->assertSame($w['checker']->id, $approved->decided_by_user_id);
        $adjustment = $this->adjustmentsOf($w)->sole();
        $this->assertSame('250.00', $adjustment->amount);
        $this->assertSame('waiver', $adjustment->category);
        $this->assertSame($w['expense']->id, $adjustment->debit_ledger_account_id);
        $this->assertSame($w['receivable']->id, $adjustment->credit_ledger_account_id);
        $this->assertSame([$w['expense']->id => 'D:250.00', $w['receivable']->id => 'C:250.00'], $this->journalLines($w, $adjustment->journal_entry_id));
        $this->assertSame('1000.00', $this->inSchool($w['school'], fn () => Charge::query()->findOrFail($w['charge']->id)->amount), 'The charge amount never changes.');

        $this->assertSame(1, $this->auditCount($w['school'], 'fee_concession.approved'));
        $this->assertSame(1, $this->auditCount($w['school'], 'fee_adjustment.posted'));
        $this->assertSame(1, $this->outboxCount($w, 'fee_concession.approved.v1'));
        $this->assertSame(1, $this->outboxCount($w, 'fee_adjustment.posted.v1'));
    }

    #[Test]
    public function the_requester_can_never_decide_their_own_request_whatever_they_hold(): void
    {
        $w = $this->concessionWorld();
        $both = $this->createUserWithCapabilities($w['school'], ['finance.fee_concessions.view', 'finance.fee_concessions.request', 'finance.fee_concessions.approve']);
        $concession = $this->requestTargeted($w, actor: $both);

        foreach (['approve', 'reject'] as $action) {
            try {
                $this->concessions()->{$action}($w['school'], $concession->id, $both);
                $this->fail("Self-{$action} must be refused.");
            } catch (SelfApprovalNotAllowedException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('pending', $this->inSchool($w['school'], fn () => $concession->refresh()->status));
        $this->assertCount(0, $this->adjustmentsOf($w));
    }

    #[Test]
    public function rejection_leaves_no_adjustment_and_is_final(): void
    {
        $w = $this->concessionWorld();
        $concession = $this->requestTargeted($w);

        $this->assertSame('rejected', $this->concessions()->reject($w['school'], $concession->id, $w['checker'])->status);
        $this->assertCount(0, $this->adjustmentsOf($w));
        $this->assertSame(1, $this->outboxCount($w, 'fee_concession.rejected.v1'));

        $this->expectException(FeeConcessionIllegalTransitionException::class);
        $this->concessions()->approve($w['school'], $concession->id, $w['checker']);
    }

    #[Test]
    public function only_the_requester_withdraws_and_only_while_pending(): void
    {
        $w = $this->concessionWorld();
        $other = $this->createUserWithCapabilities($w['school'], ['finance.fee_concessions.request']);
        $concession = $this->requestTargeted($w);

        try {
            $this->concessions()->withdraw($w['school'], $concession->id, $other);
            $this->fail('Another requester cannot withdraw it.');
        } catch (FeeConcessionNotRequesterException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('withdrawn', $this->concessions()->withdraw($w['school'], $concession->id, $w['maker'])->status);
        $this->assertSame(1, $this->auditCount($w['school'], 'fee_concession.withdrawn'));

        $approved = $this->requestTargeted($w, '10.00');
        $this->concessions()->approve($w['school'], $approved->id, $w['checker']);
        $this->expectException(FeeConcessionIllegalTransitionException::class);
        $this->concessions()->withdraw($w['school'], $approved->id, $w['maker']);
    }

    #[Test]
    public function only_an_approved_standing_concession_is_revoked_and_posted_adjustments_stay(): void
    {
        $w = $this->concessionWorld();
        $targeted = $this->requestTargeted($w);
        $this->concessions()->approve($w['school'], $targeted->id, $w['checker']);

        try {
            $this->concessions()->revoke($w['school'], $targeted->id, $w['checker']);
            $this->fail('A targeted concession is never revoked; its adjustment is cancelled instead.');
        } catch (FeeConcessionIllegalTransitionException) {
            $this->addToAssertionCount(1);
        }

        $enrollment = $this->enroll($w);
        $standing = $this->approvedStanding($w, $enrollment->student_id, FeeConcession::KIND_PERCENTAGE, '10.00');
        $this->executedRun($w);
        $this->assertCount(2, $this->adjustmentsOf($w));

        $revoked = $this->concessions()->revoke($w['school'], $standing->id, $w['checker']);
        $this->assertSame('revoked', $revoked->status);
        $this->assertSame($w['checker']->id, $revoked->revoked_by_user_id);
        $this->assertCount(2, $this->adjustmentsOf($w)->whereNull('cancelled_at'), 'Revocation stops future application only.');
    }

    // --- Categories and input (M, N) ----------------------------------------

    #[Test]
    public function the_category_catalogue_is_closed_and_a_targeted_concession_is_fixed_only(): void
    {
        $w = $this->concessionWorld();

        foreach (FeeConcession::CATEGORIES as $category) {
            $this->assertSame($category, $this->requestTargeted($w, '1.00', $category)->category);
        }

        $cases = [
            ['category' => 'sibling', 'field' => 'category'],
            ['kind' => FeeConcession::KIND_PERCENTAGE, 'percentage' => '10', 'fixedAmount' => null, 'field' => 'kind'],
            ['fixedAmount' => '10.005', 'field' => 'fixed_amount'],
            ['fixedAmount' => '0.00', 'field' => 'fixed_amount'],
            ['fixedAmount' => '1000.01', 'field' => 'fixed_amount'],
        ];
        foreach ($cases as $case) {
            try {
                $this->concessions()->request($w['school'], new RequestFeeConcessionData(
                    idempotencyKey: (string) Str::uuid(),
                    scope: FeeConcession::SCOPE_TARGETED,
                    category: $case['category'] ?? 'concession',
                    kind: $case['kind'] ?? FeeConcession::KIND_FIXED,
                    fixedAmount: array_key_exists('fixedAmount', $case) ? $case['fixedAmount'] : '10.00',
                    percentage: $case['percentage'] ?? null,
                    chargeId: $w['charge']->id,
                ), $w['maker']);
                $this->fail('Refused: '.json_encode($case));
            } catch (InvalidFeeConcessionException $e) {
                $this->assertSame($case['field'], $e->field());
            }
        }
    }

    #[Test]
    public function a_standing_request_is_validated_and_its_window_must_lie_inside_the_year(): void
    {
        $w = $this->concessionWorld();

        foreach ([['101', 'percentage'], ['0', 'percentage'], ['12.345', 'percentage']] as [$value, $field]) {
            try {
                $this->requestStanding($w, $w['student']->id, FeeConcession::KIND_PERCENTAGE, $value);
                $this->fail("{$value}% is refused.");
            } catch (InvalidFeeConcessionException $e) {
                $this->assertSame($field, $e->field());
            }
        }

        try {
            $this->requestStanding($w, $w['student']->id, FeeConcession::KIND_FIXED, '100.00', null, '2026-06-01', '2027-06-30');
            $this->fail('A window past the year end is refused.');
        } catch (InvalidFeeConcessionException $e) {
            $this->assertSame('valid_to', $e->field());
        }

        $ok = $this->requestStanding($w, $w['student']->id, FeeConcession::KIND_PERCENTAGE, '100', $w['head']->id);
        $this->assertSame('100.00', $ok->percentage);
        $this->assertSame($w['head']->id, $ok->fee_head_id);
    }

    #[Test]
    public function percentage_values_round_half_away_from_zero_at_two_decimals(): void
    {
        $service = app(FeeAdjustmentService::class);
        $concession = new FeeConcession(['kind' => FeeConcession::KIND_PERCENTAGE, 'percentage' => '15.00']);

        $this->assertSame('1.52', $service->valueOf($concession, Money::of('10.10', 'INR'))->amount(), '1.515 -> 1.52');
        $this->assertSame('1666.50', $service->valueOf(new FeeConcession(['kind' => 'percentage', 'percentage' => '33.33']), Money::of('5000.00', 'INR'))->amount());
        $this->assertSame('5000.00', $service->valueOf(new FeeConcession(['kind' => 'percentage', 'percentage' => '100.00']), Money::of('5000.00', 'INR'))->amount());
        $this->assertSame('0.01', $service->valueOf(new FeeConcession(['kind' => 'percentage', 'percentage' => '0.05']), Money::of('10.00', 'INR'))->amount(), '0.005 -> 0.01');
    }

    // --- Idempotent requests -------------------------------------------------

    #[Test]
    public function the_same_key_and_content_replays_and_anything_else_fails_closed(): void
    {
        $w = $this->concessionWorld();
        $key = (string) Str::uuid();
        $first = $this->requestTargeted($w, '200.00', key: $key);

        $again = $this->concessions()->request($w['school'], new RequestFeeConcessionData(
            idempotencyKey: $key, scope: 'targeted', category: 'concession', kind: 'fixed', fixedAmount: '200', chargeId: $w['charge']->id,
        ), $w['maker']);
        $this->assertTrue($again['replayed']);
        $this->assertSame($first->id, $again['concession']->id);
        $this->assertSame(1, $this->auditCount($w['school'], 'fee_concession.requested'));

        foreach ([['200.00', $w['maker'], 'scholarship'], ['300.00', $w['maker'], 'concession']] as [$amount, $actor, $category]) {
            try {
                $this->requestTargeted($w, $amount, $category, actor: $actor, key: $key);
                $this->fail('Different content with the same key is refused.');
            } catch (FeeConcessionIdempotencyConflictException) {
                $this->addToAssertionCount(1);
            }
        }

        $colleague = $this->createUserWithCapabilities($w['school'], ['finance.fee_concessions.request']);
        $this->expectException(FeeConcessionIdempotencyConflictException::class);
        $this->requestTargeted($w, '200.00', actor: $colleague, key: $key);
    }

    #[Test]
    public function the_same_key_in_two_schools_is_two_independent_requests(): void
    {
        $a = $this->concessionWorld();
        $b = $this->concessionWorld();
        $key = (string) Str::uuid();

        $this->assertNotSame($this->requestTargeted($a, key: $key)->id, $this->requestTargeted($b, key: $key)->id);
    }

    // --- F2: the concession account -----------------------------------------

    #[Test]
    public function without_a_configured_account_approval_fails_closed_and_stays_pending(): void
    {
        $w = $this->concessionWorld(configureAccount: false);
        $concession = $this->requestTargeted($w);
        $before = $this->journalCount($w);

        try {
            $this->concessions()->approve($w['school'], $concession->id, $w['checker']);
            $this->fail('No concession account, no posting.');
        } catch (ConcessionAccountNotConfiguredException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('pending', $this->inSchool($w['school'], fn () => $concession->refresh()->status));
        $this->assertSame($before, $this->journalCount($w));
        $this->assertSame(0, $this->auditCount($w['school'], 'fee_concession.approved'));
    }

    #[Test]
    public function an_inactive_concession_account_refuses_the_posting(): void
    {
        $w = $this->concessionWorld();
        $this->inSchool($w['school'], fn () => LedgerAccount::query()->whereKey($w['expense']->id)->update(['status' => 'inactive']));
        $concession = $this->requestTargeted($w);

        $this->expectException(ConcessionAccountNotConfiguredException::class);
        $this->concessions()->approve($w['school'], $concession->id, $w['checker']);
    }

    #[Test]
    public function only_an_active_expense_account_of_the_school_can_be_configured(): void
    {
        $w = $this->concessionWorld();
        $other = $this->concessionWorld();
        $settings = app(FeeSettingsService::class);

        foreach ([$w['revenue']->id, $w['receivable']->id, $other['expense']->id, (string) Str::uuid()] as $id) {
            try {
                $settings->setConcessionAccount($w['school'], $id, $w['actor']);
                $this->fail('Refused: not an active expense account of this School.');
            } catch (InvalidFeeSettingsException $e) {
                $this->assertSame('concession_ledger_account_id', $e->field());
            }
        }

        $this->expectException(AuthorizationException::class);
        $settings->setConcessionAccount($w['school'], $w['expense']->id, $w['maker']);
    }

    #[Test]
    public function changing_the_configured_account_never_moves_posted_history(): void
    {
        $w = $this->concessionWorld();
        $first = $this->requestTargeted($w, '100.00');
        $this->concessions()->approve($w['school'], $first->id, $w['checker']);

        $newAccount = $this->createLedgerAccount($w['school'], ['code' => 'EXP-SCHOL', 'type' => 'expense']);
        app(FeeSettingsService::class)->setConcessionAccount($w['school'], $newAccount->id, $w['actor']);
        $this->assertSame(2, $this->auditCount($w['school'], 'fee_settings.concession_account_changed'));

        $second = $this->requestTargeted($w, '50.00');
        $this->concessions()->approve($w['school'], $second->id, $w['checker']);

        $this->assertSame([$w['expense']->id, $newAccount->id], $this->adjustmentsOf($w)->pluck('debit_ledger_account_id')->all());
    }

    // --- G1: current outstanding, against Payments --------------------------

    #[Test]
    public function a_concession_beyond_the_current_outstanding_is_refused_never_reduced(): void
    {
        $w = $this->concessionWorld();
        $this->recordManualPayment($w['school'], $w['recorder'], $w['settlement']->id, [[$w['charge'], '900.00']], '900.00');
        $concession = $this->requestTargeted($w, '200.00');

        try {
            $this->concessions()->approve($w['school'], $concession->id, $w['checker']);
            $this->fail('200.00 exceeds the 100.00 outstanding.');
        } catch (AdjustmentExceedsOutstandingException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('pending', $this->inSchool($w['school'], fn () => $concession->refresh()->status));
        $this->assertCount(0, $this->adjustmentsOf($w));

        $exact = $this->requestTargeted($w, '100.00');
        $this->concessions()->approve($w['school'], $exact->id, $w['checker']);
        $this->assertSame('100.00', $this->adjustmentsOf($w)->sole()->amount, 'Exactly the outstanding is allowed.');

        $more = $this->requestTargeted($w, '0.01');
        $this->expectException(ChargeFullyPaidException::class);
        $this->concessions()->approve($w['school'], $more->id, $w['checker']);
    }

    #[Test]
    public function a_payment_beyond_the_net_outstanding_is_refused_and_the_outstanding_shown_is_net(): void
    {
        $w = $this->concessionWorld();
        $concession = $this->requestTargeted($w, '300.00');
        $this->concessions()->approve($w['school'], $concession->id, $w['checker']);

        $open = app(ManualPaymentRecordingService::class)->outstandingChargesForStudent($w['school'], $w['student']->id, $w['recorder']);
        $this->assertSame('700.00', $open[0]->outstanding);
        $this->assertSame('300.00', $open[0]->adjusted);

        try {
            $this->recordManualPayment($w['school'], $w['recorder'], $w['settlement']->id, [[$w['charge'], '800.00']], '800.00');
            $this->fail('800.00 exceeds the 700.00 net outstanding.');
        } catch (ChargeAllocationExceedsChargeAmountException) {
            $this->addToAssertionCount(1);
        }

        $this->recordManualPayment($w['school'], $w['recorder'], $w['settlement']->id, [[$w['charge'], '700.00']], '700.00');
        $this->assertSame('0.00', app(ManualPaymentRecordingService::class)->outstandingChargesForStudent($w['school'], $w['student']->id, $w['recorder'])[0]->outstanding);
    }

    #[Test]
    public function the_payment_snapshot_carries_the_live_adjustment_total(): void
    {
        $w = $this->concessionWorld();
        $concession = $this->requestTargeted($w, '125.50');
        $this->concessions()->approve($w['school'], $concession->id, $w['checker']);

        $snapshot = $this->inSchool($w['school'], fn () => app(ChargeService::class)->lockChargeForAllocation($w['school'], $w['charge']->id));
        $this->assertSame('125.50', $snapshot->adjustedTotal->amount());
        $this->assertSame('874.50', $snapshot->netAmount()->amount());
    }

    // --- Cancellation --------------------------------------------------------

    #[Test]
    public function cancelling_an_adjustment_reverses_it_and_frees_its_capacity(): void
    {
        $w = $this->concessionWorld();
        $concession = $this->requestTargeted($w, '400.00');
        $this->concessions()->approve($w['school'], $concession->id, $w['checker']);
        $adjustment = $this->adjustmentsOf($w)->sole();

        $cancelled = $this->concessions()->cancelAdjustment($w['school'], $adjustment->id, $w['checker'], 'entered twice');

        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame($w['checker']->id, $cancelled->cancelled_by_user_id);
        $reversal = $this->inSchool($w['school'], fn () => JournalEntry::query()->findOrFail($cancelled->cancellation_journal_entry_id));
        $this->assertSame($adjustment->journal_entry_id, $reversal->reversal_of_journal_entry_id);
        $this->assertSame(1, $this->auditCount($w['school'], 'fee_adjustment.cancelled'));
        $this->assertSame(1, $this->outboxCount($w, 'fee_adjustment.cancelled.v1'));

        $this->recordManualPayment($w['school'], $w['recorder'], $w['settlement']->id, [[$w['charge'], '1000.00']], '1000.00');

        $this->expectException(FeeAdjustmentAlreadyCancelledException::class);
        $this->concessions()->cancelAdjustment($w['school'], $adjustment->id, $w['checker']);
    }

    #[Test]
    public function a_charge_with_a_live_adjustment_cannot_be_cancelled_until_the_adjustment_is(): void
    {
        $w = $this->concessionWorld();
        $concession = $this->requestTargeted($w, '100.00');
        $this->concessions()->approve($w['school'], $concession->id, $w['checker']);

        try {
            app(ChargeService::class)->cancel($w['school'], $w['charge']->id, $w['actor']);
            $this->fail('Live adjustments block charge cancellation.');
        } catch (ChargeHasActiveAdjustmentsException) {
            $this->addToAssertionCount(1);
        }

        $this->concessions()->cancelAdjustment($w['school'], $this->adjustmentsOf($w)->sole()->id, $w['checker']);
        app(ChargeService::class)->cancel($w['school'], $w['charge']->id, $w['actor']);
        $this->assertNotNull($this->inSchool($w['school'], fn () => Charge::query()->findOrFail($w['charge']->id)->cancelled_at));
    }

    // --- Authorization -------------------------------------------------------

    #[Test]
    public function every_action_needs_its_capability(): void
    {
        $w = $this->concessionWorld();
        $viewer = $this->createUserWithCapabilities($w['school'], ['finance.fee_concessions.view']);
        $nobody = $this->createUserWithCapabilities($w['school'], ['finance.charges.view']);
        $concession = $this->requestTargeted($w);

        $denied = [
            fn () => $this->requestTargeted($w, actor: $viewer),
            fn () => $this->requestTargeted($w, actor: $w['checker']),
            fn () => $this->concessions()->withdraw($w['school'], $concession->id, $w['checker']),
            fn () => $this->concessions()->approve($w['school'], $concession->id, $w['maker']),
            fn () => $this->concessions()->reject($w['school'], $concession->id, $viewer),
            fn () => $this->concessions()->revoke($w['school'], $concession->id, $w['maker']),
            fn () => $this->concessions()->cancelAdjustment($w['school'], (string) Str::uuid(), $w['maker']),
            fn () => app(FeeConcessionReadService::class)->list($w['school'], [], 1, $nobody),
            fn () => app(FeeConcessionReadService::class)->get($w['school'], $concession->id, $nobody),
            fn () => app(FeeConcessionReadService::class)->listAdjustments($w['school'], [], $nobody),
        ];
        foreach ($denied as $i => $operation) {
            try {
                $operation();
                $this->fail("Operation {$i} must be denied.");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('pending', $this->inSchool($w['school'], fn () => $concession->refresh()->status));
    }

    #[Test]
    public function reads_are_audited_once_per_call_and_another_schools_concession_is_not_found(): void
    {
        $a = $this->concessionWorld();
        $b = $this->concessionWorld();
        $foreign = $this->requestTargeted($b);
        $this->requestTargeted($a);
        $reads = app(FeeConcessionReadService::class);

        $this->assertSame(1, $reads->list($a['school'], [], 1, $a['checker'])->total());
        $this->assertSame(1, $this->auditCount($a['school'], 'fee_concession.list_viewed'));

        foreach ([$foreign->id, 'not-a-uuid'] as $id) {
            try {
                $reads->get($a['school'], $id, $a['checker']);
                $this->fail('Not found.');
            } catch (FeeConcessionNotFoundException) {
                $this->addToAssertionCount(1);
            }
            try {
                $this->concessions()->approve($a['school'], $id, $a['checker']);
                $this->fail('Not found.');
            } catch (FeeConcessionNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('pending', $this->inSchool($b['school'], fn () => $foreign->refresh()->status));
    }

    #[Test]
    public function audit_and_event_metadata_carry_ids_and_closed_codes_only(): void
    {
        $w = $this->concessionWorld();
        $concession = $this->requestTargeted($w, '321.00', 'scholarship');
        $this->concessions()->approve($w['school'], $concession->id, $w['checker']);

        $events = $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()->whereIn('event_type', ['fee_concession.requested', 'fee_concession.approved', 'fee_adjustment.posted'])->get());
        $this->assertCount(3, $events);
        foreach ($events as $event) {
            $this->assertStringNotContainsString('321', json_encode($event->metadata), 'No amounts in audit metadata.');
        }

        $payloads = $this->inSchool($w['school'], fn () => DomainEventOutbox::query()->where('event_type', 'like', 'fee_%')->pluck('payload'));
        foreach ($payloads as $payload) {
            $this->assertStringNotContainsString('321', json_encode($payload), 'No amounts in event payloads.');
        }
    }
}
