<?php

namespace Tests\Feature\Payments;

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Application\Exceptions\ChargeHasPaymentAllocationsException;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Finance\Application\LedgerBalanceReader;
use App\Domain\Payments\Application\Charges\ChargeStateReader;
use App\Domain\Payments\Application\Exceptions\PaymentCorrectionIdempotencyConflictException;
use App\Domain\Payments\Application\Exceptions\PaymentCorrectionRefusedException;
use App\Domain\Payments\Application\PaymentApplicationReader;
use App\Domain\Payments\Application\PaymentCorrectionData;
use App\Domain\Payments\Application\PaymentCorrectionService;
use App\Domain\Payments\Domain\ManualPaymentMethod;
use App\Domain\Payments\Infrastructure\Payment;
use App\Domain\Payments\Infrastructure\PaymentCorrection;
use App\Domain\Payments\Infrastructure\PaymentReceipt;
use App\Models\SchoolAuditEvent;
use App\Support\Money\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesReceiptFixtures;
use Tests\TestCase;

/**
 * UAT-C3 (UAT-FIN-05/24/27): payment corrections never edit or delete a
 * posted Payment; they add explicit, linked, balanced records. Every case
 * checks the before/after balances exactly (decimal strings, no float).
 */
class PaymentCorrectionServiceTest extends TestCase
{
    use CreatesReceiptFixtures;

    private function service(): PaymentCorrectionService
    {
        return app(PaymentCorrectionService::class);
    }

    private function correct(array $w, string $paymentId, string $kind, array $extra = [], $actor = null, ?string $key = null): array
    {
        $result = $this->service()->correct($w['school'], new PaymentCorrectionData(
            paymentId: $paymentId,
            kind: $kind,
            reason: $extra['reason'] ?? 'Entered against the wrong charge',
            idempotencyKey: $key ?? (string) Str::uuid(),
            chargeId: $extra['charge'] ?? null,
            toChargeId: $extra['to'] ?? null,
            amount: isset($extra['amount']) ? Money::of($extra['amount'], 'INR') : null,
            accountId: $extra['account'] ?? null,
            method: $extra['method'] ?? null,
            refundedOn: $extra['on'] ?? null,
            reference: $extra['reference'] ?? null,
        ), $actor ?? $w['recorder']);
        // Each test runs in one never-committed transaction: fire the deferred
        // balance check now so the database verifies every correction.
        $this->checkDeferred($w);

        return $result;
    }

    private function checkDeferred(array $w): void
    {
        // Inside the School's context, as a real commit is (the checks read under RLS).
        $this->inSchool($w['school'], function () {
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        });
    }

    /** @return array<string, string> */
    private function standing(array $w, string $paymentId): array
    {
        return $this->inSchool($w['school'], fn () => app(PaymentApplicationReader::class)->forPayment($w['school'], $paymentId));
    }

    private function owed(array $w, Charge $charge): string
    {
        return app(ChargeStateReader::class)->forCharges($w['school'], [$charge->id])[$charge->id]->outstanding()->amount();
    }

    private function secondCharge(array $w, string $amount = '800.00', $student = null): Charge
    {
        return $this->assessCharge($w['school'], $student ?? $w['student'], $w['year'], $w['receivable'], $w['revenue'], $amount, description: 'Second charge');
    }

    /** @return array{0: string, 1: string} debit, credit totals of one account */
    private function balance(array $w, string $accountId): array
    {
        $b = app(LedgerBalanceReader::class)->balances($w['school'])["{$accountId}|INR"] ?? ['debit' => '0.00', 'credit' => '0.00'];

        return [$b['debit'], $b['credit']];
    }

    private function cash(array $w): string
    {
        [$debit, $credit] = $this->balance($w, $w['settlement']->id);

        return bcsub($debit, $credit, 2);
    }

    // ------------------------------------------------------------ Reallocation

    #[Test]
    public function a_full_reallocation_moves_the_money_and_leaves_cash_received_unchanged(): void
    {
        $w = $this->concessionWorld();
        $b = $this->secondCharge($w);
        $paymentId = $this->pay($w, '500.00')->paymentId;
        $cashBefore = $this->cash($w);

        $result = $this->correct($w, $paymentId, PaymentCorrection::REALLOCATION, ['charge' => $w['charge']->id, 'to' => $b->id, 'amount' => '500.00']);

        $this->assertFalse($result['replayed']);
        $this->assertSame('1000.00', $this->owed($w, $w['charge']), 'before 500.00 owed + 500.00 released');
        $this->assertSame('300.00', $this->owed($w, $b), 'before 800.00 owed - 500.00 applied');
        $this->assertSame($cashBefore, $this->cash($w), 'Cash received does not change.');
        $payment = $this->inSchool($w['school'], fn () => Payment::query()->with('allocations')->findOrFail($paymentId));
        $this->assertSame('500.00', $payment->amount);
        $this->assertSame([$w['charge']->id], $payment->allocations->pluck('charge_id')->all(), 'The original allocation row is untouched.');
        $this->assertSame([$b->id => '500.00'], $this->standing($w, $paymentId));
        $this->assertSame(1, $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', 'payment.reallocated')->where('subject_id', $paymentId)->count()));
    }

    #[Test]
    public function a_partial_reallocation_leaves_the_rest_where_it_was(): void
    {
        $w = $this->concessionWorld();
        $b = $this->secondCharge($w);
        $paymentId = $this->pay($w, '500.00')->paymentId;

        $this->correct($w, $paymentId, PaymentCorrection::REALLOCATION, ['charge' => $w['charge']->id, 'to' => $b->id, 'amount' => '200.00']);

        $this->assertSame('700.00', $this->owed($w, $w['charge']));
        $this->assertSame('600.00', $this->owed($w, $b));
        $this->assertEqualsCanonicalizing([$w['charge']->id => '300.00', $b->id => '200.00'], $this->standing($w, $paymentId));
    }

    #[Test]
    public function reallocation_refuses_more_than_the_payment_has_and_invalid_destinations_writing_nothing(): void
    {
        $w = $this->concessionWorld();
        $b = $this->secondCharge($w, '300.00');
        $otherStudent = $this->secondCharge($w, '800.00', $this->createStudent($w['school']));
        $foreign = $this->concessionWorld();
        $paymentId = $this->pay($w, '500.00')->paymentId;
        $cancelled = $this->secondCharge($w, '100.00');
        app(ChargeService::class)->cancel($w['school'], $cancelled->id);

        $cases = [
            'exceeds' => [['charge' => $w['charge']->id, 'to' => $b->id, 'amount' => '600.00'], 'PAYMENT_CORRECTION_EXCEEDS_PAYMENT'],
            'same charge' => [['charge' => $w['charge']->id, 'to' => $w['charge']->id, 'amount' => '100.00'], 'PAYMENT_CORRECTION_DESTINATION_INVALID'],
            'other Student' => [['charge' => $w['charge']->id, 'to' => $otherStudent->id, 'amount' => '100.00'], 'PAYMENT_CORRECTION_DESTINATION_INVALID'],
            'cancelled' => [['charge' => $w['charge']->id, 'to' => $cancelled->id, 'amount' => '100.00'], 'PAYMENT_CORRECTION_DESTINATION_INVALID'],
            'other School' => [['charge' => $w['charge']->id, 'to' => $foreign['charge']->id, 'amount' => '100.00'], 'PAYMENT_CORRECTION_DESTINATION_INVALID'],
            'destination full' => [['charge' => $w['charge']->id, 'to' => $b->id, 'amount' => '400.00'], 'PAYMENT_CORRECTION_DESTINATION_FULL'],
        ];
        foreach ($cases as $name => [$extra, $code]) {
            try {
                $this->correct($w, $paymentId, PaymentCorrection::REALLOCATION, $extra);
                $this->fail("{$name}: expected a refusal");
            } catch (PaymentCorrectionRefusedException $e) {
                $this->assertSame($code, $e->errorCode(), $name);
            }
        }

        $this->assertSame(0, $this->inSchool($w['school'], fn () => PaymentCorrection::query()->count()));
        $this->assertSame('500.00', $this->owed($w, $w['charge']));
    }

    // --------------------------------------------------------------- Reversal

    #[Test]
    public function a_reversal_unwinds_every_charge_keeps_the_payment_and_receipt_and_cannot_repeat(): void
    {
        $w = $this->concessionWorld();
        $b = $this->secondCharge($w);
        $cashBefore = $this->cash($w);
        $paymentId = $this->recordManualPayment($w['school'], $w['recorder'], $w['settlement']->id, [[$w['charge'], '300.00'], [$b, '200.00']], '500.00')->paymentId;
        $this->assertSame(bcadd($cashBefore, '500.00', 2), $this->cash($w));

        $this->correct($w, $paymentId, PaymentCorrection::REVERSAL, ['reason' => 'Entered twice by mistake']);

        $this->assertSame('1000.00', $this->owed($w, $w['charge']));
        $this->assertSame('800.00', $this->owed($w, $b));
        $this->assertSame($cashBefore, $this->cash($w), 'The cash recorded is taken back out exactly.');
        $this->assertNotNull($this->inSchool($w['school'], fn () => Payment::query()->find($paymentId)), 'The original payment remains.');
        $this->assertNotNull($this->inSchool($w['school'], fn () => PaymentReceipt::query()->where('payment_id', $paymentId)->first()), 'Its receipt remains.');
        $reversal = $this->inSchool($w['school'], fn () => PaymentCorrection::query()->where('payment_id', $paymentId)->sole());
        $this->assertSame(['reversal', '500.00', $w['settlement']->id], [$reversal->kind, $reversal->amount, $reversal->settlement_ledger_account_id]);

        foreach ([PaymentCorrection::REVERSAL => [], PaymentCorrection::REALLOCATION => ['charge' => $b->id, 'to' => $w['charge']->id, 'amount' => '1.00']] as $kind => $extra) {
            try {
                $this->correct($w, $paymentId, $kind, $extra);
                $this->fail("{$kind} after a reversal must be refused");
            } catch (PaymentCorrectionRefusedException $e) {
                $this->assertSame('PAYMENT_ALREADY_REVERSED', $e->errorCode());
            }
        }

        // A replacement is an ordinary new payment.
        $this->pay($w, '450.00');
        $this->assertSame('550.00', $this->owed($w, $w['charge']));
    }

    // ----------------------------------------------------------------- Refund

    #[Test]
    public function a_refund_pays_money_out_of_the_chosen_account_and_the_charge_is_owed_again(): void
    {
        $w = $this->concessionWorld();
        $paymentId = $this->pay($w, '500.00', now($w['school']->timezone)->subDay()->toDateString())->paymentId;
        $cashBefore = $this->cash($w);
        $today = now($w['school']->timezone)->toDateString();

        $this->correct($w, $paymentId, PaymentCorrection::REFUND, ['charge' => $w['charge']->id, 'amount' => '200.00', 'account' => $w['settlement']->id, 'method' => ManualPaymentMethod::Cash, 'on' => $today, 'reason' => 'Student withdrew']);

        $this->assertSame('700.00', $this->owed($w, $w['charge']));
        $this->assertSame(bcsub($cashBefore, '200.00', 2), $this->cash($w));
        $foreign = $this->concessionWorld();
        $this->checkDeferred($foreign); // its own pending ledger checks, under its own RLS context
        $unpaid = $this->secondCharge($w);

        $refusals = [
            'more than is left' => [['charge' => $w['charge']->id, 'amount' => '301.00', 'account' => $w['settlement']->id, 'method' => ManualPaymentMethod::Cash, 'on' => $today], 'PAYMENT_CORRECTION_EXCEEDS_PAYMENT'],
            'future date' => [['charge' => $w['charge']->id, 'amount' => '10.00', 'account' => $w['settlement']->id, 'method' => ManualPaymentMethod::Cash, 'on' => now($w['school']->timezone)->addDay()->toDateString()], 'PAYMENT_CORRECTION_DATE_INVALID'],
            'not an asset account' => [['charge' => $w['charge']->id, 'amount' => '10.00', 'account' => $w['revenue']->id, 'method' => ManualPaymentMethod::Cash, 'on' => $today], 'PAYMENT_CORRECTION_ACCOUNT_INVALID'],
            'no method' => [['charge' => $w['charge']->id, 'amount' => '10.00', 'account' => $w['settlement']->id, 'on' => $today], 'PAYMENT_CORRECTION_METHOD_INVALID'],
            "another School's account" => [['charge' => $w['charge']->id, 'amount' => '10.00', 'account' => $foreign['settlement']->id, 'method' => ManualPaymentMethod::Cash, 'on' => $today], 'PAYMENT_CORRECTION_ACCOUNT_INVALID'],
            'a charge this payment is not on' => [['charge' => $unpaid->id, 'amount' => '10.00', 'account' => $w['settlement']->id, 'method' => ManualPaymentMethod::Cash, 'on' => $today], 'PAYMENT_CORRECTION_EXCEEDS_PAYMENT'],
        ];
        foreach ($refusals as $name => [$extra, $code]) {
            try {
                $this->correct($w, $paymentId, PaymentCorrection::REFUND, $extra);
                $this->fail("{$name}: expected a refusal");
            } catch (PaymentCorrectionRefusedException $e) {
                $this->assertSame($code, $e->errorCode(), $name);
            }
        }
        $this->assertSame(1, $this->inSchool($w['school'], fn () => PaymentCorrection::query()->count()));

        // A second refund of what is left is its own correction; nothing remains after it.
        $this->correct($w, $paymentId, PaymentCorrection::REFUND, ['charge' => $w['charge']->id, 'amount' => '300.00', 'account' => $w['settlement']->id, 'method' => ManualPaymentMethod::Cheque, 'on' => $today]);
        $this->assertSame('1000.00', $this->owed($w, $w['charge']));
        $this->assertSame([], $this->standing($w, $paymentId));
        try {
            $this->correct($w, $paymentId, PaymentCorrection::REFUND, ['charge' => $w['charge']->id, 'amount' => '0.01', 'account' => $w['settlement']->id, 'method' => ManualPaymentMethod::Cash, 'on' => $today]);
            $this->fail('a third refund of nothing must be refused');
        } catch (PaymentCorrectionRefusedException $e) {
            $this->assertSame('PAYMENT_CORRECTION_EXCEEDS_PAYMENT', $e->errorCode());
        }
    }

    // ------------------------------------------- The Local UAT case (FIN-24/27)

    #[Test]
    public function the_uat_duplicate_part_paid_charge_is_repaired_and_cancelled_without_a_concession(): void
    {
        $w = $this->concessionWorld();
        $real = $w['charge'];                                    // 1000.00 legitimate charge
        $duplicate = $this->secondCharge($w, '600.00');          // created by mistake
        $paymentId = $this->pay($w, '250.00', null, $duplicate)->paymentId;
        $before = bcadd($this->owed($w, $real), $this->owed($w, $duplicate), 2); // 1000 + 350 = 1350

        // 1. Move the money onto the real charge; 2. cancel the duplicate.
        $this->correct($w, $paymentId, PaymentCorrection::REALLOCATION, ['charge' => $duplicate->id, 'to' => $real->id, 'amount' => '250.00', 'reason' => 'Duplicate charge; money belongs on the real one']);
        app(ChargeService::class)->cancel($w['school'], $duplicate->id, $w['actor'], 'Duplicate charge');

        $this->assertSame('750.00', $this->owed($w, $real));
        $this->assertSame('0.00', $this->owed($w, $duplicate));
        $this->assertSame('1350.00', $before);
        $this->assertSame('750.00', bcsub(bcsub($before, '600.00', 2), '0.00', 2), 'before - cancelled duplicate (600) = after');
        $this->assertSame(0, $this->inSchool($w['school'], fn () => DB::table('fee_concessions')->count()), 'No concession was used.');
    }

    #[Test]
    public function a_charge_with_money_still_on_it_cannot_be_cancelled_but_can_once_it_is_moved(): void
    {
        $w = $this->concessionWorld();
        $b = $this->secondCharge($w);
        $paymentId = $this->pay($w, '100.00', null, $b)->paymentId;

        try {
            app(ChargeService::class)->cancel($w['school'], $b->id);
            $this->fail('Cancelling a charge with money on it must be refused.');
        } catch (ChargeHasPaymentAllocationsException) {
        }

        $this->correct($w, $paymentId, PaymentCorrection::REALLOCATION, ['charge' => $b->id, 'to' => $w['charge']->id, 'amount' => '60.00']);
        try {
            app(ChargeService::class)->cancel($w['school'], $b->id);
            $this->fail('Still 40.00 on it.');
        } catch (ChargeHasPaymentAllocationsException) {
        }

        $this->correct($w, $paymentId, PaymentCorrection::REALLOCATION, ['charge' => $b->id, 'to' => $w['charge']->id, 'amount' => '40.00']);
        app(ChargeService::class)->cancel($w['school'], $b->id);
        $this->assertSame('900.00', $this->owed($w, $w['charge']));
    }

    // ------------------------------------------------------- Idempotency/authz

    #[Test]
    public function the_same_form_replays_and_a_different_request_with_its_key_is_refused(): void
    {
        $w = $this->concessionWorld();
        $b = $this->secondCharge($w);
        $paymentId = $this->pay($w, '500.00')->paymentId;
        $key = (string) Str::uuid();
        $request = ['charge' => $w['charge']->id, 'to' => $b->id, 'amount' => '100.00'];

        $first = $this->correct($w, $paymentId, PaymentCorrection::REALLOCATION, $request, key: $key);
        $again = $this->correct($w, $paymentId, PaymentCorrection::REALLOCATION, $request, key: $key);

        $this->assertSame([$first['correctionId'], true], [$again['correctionId'], $again['replayed']]);
        $this->assertSame('600.00', $this->owed($w, $w['charge']), 'Moved once (600.00), not twice (700.00).');

        // Same key, changed request: refused, nothing written.
        try {
            $this->correct($w, $paymentId, PaymentCorrection::REALLOCATION, [...$request, 'amount' => '50.00'], key: $key);
            $this->fail('a changed request under a used key must be refused');
        } catch (PaymentCorrectionIdempotencyConflictException) {
        }
        // Same key, identical request, ANOTHER User: refused -- never handed someone else's result.
        $colleague = $this->createPaymentRecorder($w['school']);
        try {
            $this->correct($w, $paymentId, PaymentCorrection::REALLOCATION, $request, $colleague, $key);
            $this->fail("another User must never replay someone else's correction");
        } catch (PaymentCorrectionIdempotencyConflictException) {
        }
        $this->assertSame(1, $this->inSchool($w['school'], fn () => PaymentCorrection::query()->count()));
        $this->assertSame('600.00', $this->owed($w, $w['charge']));
    }

    #[Test]
    public function each_correction_needs_its_own_capabilities(): void
    {
        $w = $this->concessionWorld();
        $b = $this->secondCharge($w);
        $paymentId = $this->pay($w, '500.00')->paymentId;
        $cashier = $this->createUserWithCapabilities($w['school'], ['finance.payments.view', 'finance.payments.record']);
        $accountant = $this->createUserWithCapabilities($w['school'], ['finance.payments.view', 'finance.payments.record', 'finance.charges.manage']);

        foreach ([[$cashier, PaymentCorrection::REALLOCATION, ['charge' => $w['charge']->id, 'to' => $b->id, 'amount' => '1.00']], [$accountant, PaymentCorrection::REVERSAL, []]] as [$user, $kind, $extra]) {
            try {
                $this->correct($w, $paymentId, $kind, $extra, $user);
                $this->fail("{$kind} must be forbidden");
            } catch (AuthorizationException) {
            }
        }

        $this->correct($w, $paymentId, PaymentCorrection::REALLOCATION, ['charge' => $w['charge']->id, 'to' => $b->id, 'amount' => '1.00'], $accountant);
        $this->assertSame(1, $this->inSchool($w['school'], fn () => PaymentCorrection::query()->count()));
    }

    #[Test]
    public function a_blank_reason_is_refused_before_anything_runs(): void
    {
        $w = $this->concessionWorld();
        $paymentId = $this->pay($w, '500.00')->paymentId;

        $this->expectException(PaymentCorrectionRefusedException::class);
        $this->correct($w, $paymentId, PaymentCorrection::REVERSAL, ['reason' => '   ']);
    }

    // -------------------------------------------------------- Database backstop

    #[Test]
    public function the_database_refuses_unbalanced_or_over_releasing_corrections_whatever_the_caller(): void
    {
        $w = $this->concessionWorld();
        $b = $this->secondCharge($w);
        $paymentId = $this->pay($w, '500.00')->paymentId;
        $entry = $this->postBalancedJournalEntry($w['school'], $w['receivable'], $w['receivable'], '1.00');

        $insert = function (array $lines, string $amount = '600.00', string $kind = 'reallocation') use ($w, $paymentId, $entry) {
            $this->inSchool($w['school'], fn () => DB::transaction(function () use ($w, $paymentId, $entry, $lines, $amount, $kind) {
                $id = (string) Str::uuid();
                DB::table('payment_corrections')->insert(['id' => $id, 'school_id' => $w['school']->id, 'payment_id' => $paymentId, 'kind' => $kind,
                    'amount' => $amount, 'currency' => 'INR', 'journal_entry_id' => $entry->id, 'reason' => 'Direct write test',
                    'corrected_by_user_id' => $w['recorder']->id, 'idempotency_key' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
                foreach ($lines as [$chargeId, $direction, $lineAmount]) {
                    DB::table('payment_correction_lines')->insert(['id' => (string) Str::uuid(), 'school_id' => $w['school']->id, 'payment_correction_id' => $id,
                        'payment_id' => $paymentId, 'charge_id' => $chargeId, 'direction' => $direction, 'amount' => $lineAmount, 'currency' => 'INR', 'created_at' => now(), 'updated_at' => now()]);
                }
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            }));
        };

        foreach ([
            'over-release' => [[[$w['charge']->id, 'release', '600.00'], [$b->id, 'apply', '600.00']]],
            'unbalanced' => [[[$w['charge']->id, 'release', '100.00'], [$b->id, 'apply', '90.00']], '100.00'],
        ] as $name => $args) {
            try {
                $insert(...$args);
                $this->fail("{$name} must be refused by the database");
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode(), "{$name}: refused as a check violation");
            }
        }
        $this->assertSame(0, $this->inSchool($w['school'], fn () => PaymentCorrection::query()->count()));
        $this->assertSame('500.00', $this->owed($w, $w['charge']));
    }
}
