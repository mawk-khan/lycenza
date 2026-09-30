<?php

namespace Tests\Feature\Payments;

use App\Domain\Payments\Application\ReceiptBackfillService;
use App\Domain\Payments\Infrastructure\Payment;
use App\Support\Tenancy\SchoolNotOperationalException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesReceiptFixtures;
use Tests\TestCase;

/**
 * FEE.4 (ADR 0062 §17.4; owner decision I2, correction 2026-09-30): the
 * explicit, one-School, idempotent receipt backfill for Payments settled
 * before FEE.4 -- `(settled_at, id)` order, the historical FY, the real
 * issue time, the live counter (never renumbered or reset), audited
 * `payment_receipt.backfilled`, refused for a non-active School.
 */
class PaymentReceiptBackfillTest extends TestCase
{
    use CreatesReceiptFixtures;

    #[Test]
    public function legacy_payments_are_backfilled_in_settlement_order_after_live_receipts(): void
    {
        $w = $this->concessionWorld();
        $march = $this->legacyPayment($w, '10.00', '2026-03-10');
        $aprilFifth = $this->legacyPayment($w, '10.00', '2026-04-05');
        $aprilSecond = $this->legacyPayment($w, '10.00', '2026-04-02');
        $live = $this->pay($w, '10.00', '2026-05-01');
        $this->assertSame(1, $this->receiptCount($w), 'Only the live Payment has a receipt before the backfill.');

        Carbon::setTestNow('2026-09-30 10:15:00');
        try {
            $this->artisan('finance:receipts-backfill', ['school' => $w['school']->id])
                ->expectsOutputToContain('Backfilled 3 receipt(s); 0 Payment(s) already had one.')
                ->assertSuccessful();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame('RCPT/2026-27/000001', $this->receiptOf($w, $live->paymentId)->receipt_number, 'Live receipts are never renumbered.');
        $this->assertSame('RCPT/2025-26/000001', $this->receiptOf($w, $march->paymentId)->receipt_number, 'The historical settlement decides the FY.');
        $this->assertSame('RCPT/2026-27/000002', $this->receiptOf($w, $aprilSecond->paymentId)->receipt_number, '(settled_at, id) order.');
        $this->assertSame('RCPT/2026-27/000003', $this->receiptOf($w, $aprilFifth->paymentId)->receipt_number, 'An older Payment backfilled later follows the live receipt.');

        $receipt = $this->receiptOf($w, $march->paymentId);
        $this->assertSame('2026-09-30 10:15:00', $receipt->issued_at->format('Y-m-d H:i:s'), 'issued_at is the real backfill time, never backdated.');
        $this->assertNull($receipt->issued_by_user_id);
        $this->assertSame(3, $this->auditCount($w['school'], 'payment_receipt.backfilled'));
        $this->assertSame(1, $this->auditCount($w['school'], 'payment_receipt.issued'));
        $this->assertSame(['settlement', 'backfill', 'backfill', 'backfill'], $this->outboxOf($w, 'payment_receipt.issued.v1')->pluck('payload.issuance')->all());
    }

    #[Test]
    public function a_rerun_issues_nothing_and_changes_nothing(): void
    {
        $w = $this->concessionWorld();
        $legacy = $this->legacyPayment($w, '10.00', '2026-04-02');
        $this->artisan('finance:receipts-backfill', ['school' => $w['school']->slug])->assertSuccessful();
        $number = $this->receiptOf($w, $legacy->paymentId)->receipt_number;
        $paymentBefore = $this->inSchool($w['school'], fn () => (array) DB::table('payments')->where('id', $legacy->paymentId)->first());

        $this->artisan('finance:receipts-backfill', ['school' => $w['school']->slug])
            ->expectsOutputToContain('Backfilled 0 receipt(s)')
            ->assertSuccessful();

        $this->assertSame(1, $this->receiptCount($w));
        $this->assertSame($number, $this->receiptOf($w, $legacy->paymentId)->receipt_number);
        $this->assertSame(2, $this->counterNext($w, '2026-27'));
        $this->assertSame(1, $this->auditCount($w['school'], 'payment_receipt.backfilled'));
        $this->assertEquals($paymentBefore, $this->inSchool($w['school'], fn () => (array) DB::table('payments')->where('id', $legacy->paymentId)->first()), 'The Payment row is never edited.');
    }

    #[Test]
    public function only_the_named_school_is_backfilled(): void
    {
        $a = $this->concessionWorld();
        $b = $this->concessionWorld();
        $this->legacyPayment($a, '10.00', '2026-04-02');
        $other = $this->legacyPayment($b, '10.00', '2026-04-02');

        $this->assertSame(['issued' => 1, 'skipped' => 0], app(ReceiptBackfillService::class)->backfill($a['school']));

        $this->assertNull($this->receiptOf($b, $other->paymentId));
        $this->artisan('finance:receipts-backfill', ['school' => 'no-such-school'])->assertFailed();
    }

    #[Test]
    public function a_non_active_school_is_refused_and_nothing_is_issued(): void
    {
        $w = $this->concessionWorld();
        $legacy = $this->legacyPayment($w, '10.00', '2026-04-02');
        DB::table('schools')->where('id', $w['school']->id)->update(['status' => 'suspended']);

        $this->artisan('finance:receipts-backfill', ['school' => $w['school']->id])
            ->expectsOutputToContain('Refused: the School is not active')
            ->assertFailed();
        $this->assertNull($this->receiptOf($w, $legacy->paymentId));

        try {
            app(ReceiptBackfillService::class)->backfill($w['school']->refresh());
            $this->fail('The service refuses too.');
        } catch (SchoolNotOperationalException) {
            $this->addToAssertionCount(1);
        }

        DB::table('schools')->where('id', $w['school']->id)->update(['status' => 'active']);
        $this->assertSame(['issued' => 1, 'skipped' => 0], app(ReceiptBackfillService::class)->backfill($w['school']->refresh()), 'After resuming, the operator runs it again.');
    }

    #[Test]
    public function the_backfill_orders_candidates_by_settlement_time(): void
    {
        $w = $this->concessionWorld();
        $ids = [];
        foreach (['2026-04-03', '2026-04-01', '2026-04-02'] as $day) {
            $ids[$day] = $this->legacyPayment($w, '1.00', $day)->paymentId;
        }

        app(ReceiptBackfillService::class)->backfill($w['school']);

        $this->assertSame(
            ['RCPT/2026-27/000001', 'RCPT/2026-27/000002', 'RCPT/2026-27/000003'],
            [$this->receiptOf($w, $ids['2026-04-01'])->receipt_number, $this->receiptOf($w, $ids['2026-04-02'])->receipt_number, $this->receiptOf($w, $ids['2026-04-03'])->receipt_number],
        );
        $this->assertSame(3, $this->inSchool($w['school'], fn () => Payment::query()->count()));
    }
}
