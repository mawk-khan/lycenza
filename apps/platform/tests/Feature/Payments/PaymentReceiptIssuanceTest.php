<?php

namespace Tests\Feature\Payments;

use App\Domain\Fees\Application\Exceptions\InvalidFeeSettingsException;
use App\Domain\Fees\Application\Exceptions\ReceiptNumberingLockedException;
use App\Domain\Fees\Application\FeeSettingsService;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Payments\Application\Exceptions\ChargeAllocationExceedsChargeAmountException;
use App\Domain\Payments\Application\PaymentReadService;
use App\Domain\Payments\Application\ReceiptIssuer;
use App\Domain\Payments\Domain\ReceiptNumbering;
use App\Domain\Payments\Infrastructure\Payment;
use App\Domain\Payments\Infrastructure\PaymentReceipt;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Feature\Fees\Concerns\CreatesReceiptFixtures;
use Tests\TestCase;

/**
 * FEE.4 (ADR 0062 §17; owner decision I, corrected 2026-09-30): receipts
 * are issued with the settlement, in its transaction, for every ingress;
 * numbering is `<PREFIX>/<FY>/<000001>` per School x financial year of the
 * Payment's `settled_at` in the School's timezone; replays consume no
 * number; the prefix and start month lock as frozen.
 */
class PaymentReceiptIssuanceTest extends TestCase
{
    use CreatesReceiptFixtures;

    // --- Issuance -----------------------------------------------------------

    #[Test]
    public function a_recorded_payment_gets_exactly_one_receipt_in_the_same_transaction(): void
    {
        $w = $this->concessionWorld();

        $first = $this->pay($w, '100.00', '2026-04-01');
        $second = $this->pay($w, '50.00', '2026-04-02');

        $receipt = $this->receiptOf($w, $first->paymentId);
        $this->assertSame('RCPT/2026-27/000001', $receipt->receipt_number);
        $this->assertSame('2026-27', $receipt->series_key);
        $this->assertSame(1, $receipt->sequence_value);
        $this->assertSame($w['recorder']->id, $receipt->issued_by_user_id);
        $this->assertSame('RCPT/2026-27/000002', $this->receiptOf($w, $second->paymentId)->receipt_number);
        $this->assertSame(3, $this->counterNext($w, '2026-27'));

        $this->assertSame(2, $this->auditCount($w['school'], 'payment_receipt.issued'));
        $events = $this->outboxOf($w, 'payment_receipt.issued.v1');
        $this->assertCount(2, $events);
        $this->assertEqualsCanonicalizing(['paymentReceiptId', 'paymentId', 'seriesKey', 'issuance'], array_keys($events->first()->payload));
        $this->assertSame('settlement', $events->first()->payload['issuance']);

        $detail = app(PaymentReadService::class)->getPaymentDetail($w['school'], $first->paymentId, $w['recorder']);
        $this->assertSame('RCPT/2026-27/000001', $detail->receiptNumber);
    }

    #[Test]
    public function the_provider_ingress_issues_a_receipt_and_its_replay_issues_none(): void
    {
        $w = $this->concessionWorld();
        $eventId = (string) Str::uuid();
        $at = Carbon::parse('2026-05-10 06:00:00', 'UTC');

        $result = $this->recordSettlement($w['school'], $w['settlement']->id, [[$w['charge'], '200.00']], '200.00', providerEventId: $eventId, providerPaymentReference: 'PSP-1', occurredAt: $at);
        $this->recordSettlement($w['school'], $w['settlement']->id, [[$w['charge'], '200.00']], '200.00', providerEventId: $eventId, providerPaymentReference: 'PSP-1', occurredAt: $at);

        $this->assertSame('RCPT/2026-27/000001', $this->receiptOf($w, $result->paymentId)->receipt_number);
        $this->assertNull($this->receiptOf($w, $result->paymentId)->issued_by_user_id, 'A system ingress has no human issuer.');
        $this->assertSame(1, $this->receiptCount($w));
        $this->assertSame(2, $this->counterNext($w, '2026-27'));
    }

    #[Test]
    public function a_manual_replay_returns_the_same_payment_and_receipt_and_consumes_no_number(): void
    {
        $w = $this->concessionWorld();
        $key = (string) Str::uuid();

        $first = $this->pay($w, '100.00', '2026-04-01', key: $key);
        $replay = $this->pay($w, '100.00', '2026-04-01', key: $key);

        $this->assertSame($first->paymentId, $replay->paymentId);
        $this->assertSame(1, $this->receiptCount($w));
        $this->assertSame(2, $this->counterNext($w, '2026-27'));
        $this->assertSame(1, $this->auditCount($w['school'], 'payment_receipt.issued'));
        $this->assertCount(1, $this->outboxOf($w, 'payment_receipt.issued.v1'));
    }

    #[Test]
    public function a_failed_settlement_leaves_no_receipt_and_no_consumed_number(): void
    {
        $w = $this->concessionWorld();
        $this->pay($w, '100.00', '2026-04-01');

        try {
            $this->pay($w, '1000.00', '2026-04-02');
            $this->fail('Over-allocation is refused.');
        } catch (ChargeAllocationExceedsChargeAmountException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, $this->receiptCount($w));
        $this->assertSame(2, $this->counterNext($w, '2026-27'));
        $this->assertSame('RCPT/2026-27/000002', $this->receiptOf($w, $this->pay($w, '10.00', '2026-04-03')->paymentId)->receipt_number, 'No gap.');
    }

    #[Test]
    public function a_failed_receipt_rolls_the_whole_settlement_back(): void
    {
        $w = $this->concessionWorld();
        $journals = $this->inSchool($w['school'], fn () => JournalEntry::query()->count());
        $issuer = Mockery::mock(ReceiptIssuer::class);
        $issuer->shouldReceive('issue')->andThrow(new RuntimeException('receipt failed'));
        app()->instance(ReceiptIssuer::class, $issuer);

        try {
            $this->pay($w, '100.00', '2026-04-01');
            $this->fail('A settlement never commits without its receipt.');
        } catch (RuntimeException $e) {
            $this->assertSame('receipt failed', $e->getMessage());
        } finally {
            app()->forgetInstance(ReceiptIssuer::class);
        }

        $this->assertSame(0, $this->inSchool($w['school'], fn () => Payment::query()->count()));
        $this->assertSame($journals, $this->inSchool($w['school'], fn () => JournalEntry::query()->count()));
        $this->assertSame(0, $this->auditCount($w['school'], 'payment.recorded_manually'));
    }

    #[Test]
    public function the_issuer_is_idempotent_per_payment(): void
    {
        $w = $this->concessionWorld();
        $payment = $this->inSchool($w['school'], fn () => Payment::query()->findOrFail($this->pay($w, '100.00', '2026-04-01')->paymentId));

        $again = $this->inSchool($w['school'], fn () => DB::transaction(fn () => app(ReceiptIssuer::class)->issue($w['school'], $payment, ReceiptIssuer::ISSUANCE_BACKFILL)));

        $this->assertFalse($again['issued']);
        $this->assertSame('RCPT/2026-27/000001', $again['receipt']->receipt_number);
        $this->assertSame(0, $this->auditCount($w['school'], 'payment_receipt.backfilled'));
        $this->assertSame(2, $this->counterNext($w, '2026-27'));
    }

    // --- Numbering, financial year, timezone --------------------------------

    #[Test]
    public function the_series_label_is_the_same_rule_for_every_start_month(): void
    {
        $at = fn (string $local) => CarbonImmutable::parse($local, 'Asia/Kolkata');

        $this->assertSame('2026-27', ReceiptNumbering::seriesKey($at('2026-04-01 00:00'), 'Asia/Kolkata', 4));
        $this->assertSame('2025-26', ReceiptNumbering::seriesKey($at('2026-03-31 23:59'), 'Asia/Kolkata', 4));
        $this->assertSame('2026-27', ReceiptNumbering::seriesKey($at('2026-01-15 10:00'), 'Asia/Kolkata', 1), 'January start is labelled YYYY-YY too.');
        $this->assertSame('2025-26', ReceiptNumbering::seriesKey($at('2025-12-31 23:59'), 'Asia/Kolkata', 1));
        $this->assertSame('2026-27', ReceiptNumbering::seriesKey($at('2026-07-01 00:00'), 'Asia/Kolkata', 7));
        $this->assertSame('2025-26', ReceiptNumbering::seriesKey($at('2026-06-30 23:59'), 'Asia/Kolkata', 7));
        $this->assertSame('2099-00', ReceiptNumbering::seriesKey($at('2099-05-01'), 'Asia/Kolkata', 4), 'Century rollover.');

        // A UTC instant belongs to the School's LOCAL financial year.
        $instant = CarbonImmutable::parse('2026-03-31 19:00:00', 'UTC');
        $this->assertSame('2026-27', ReceiptNumbering::seriesKey($instant, 'Asia/Kolkata', 4));
        $this->assertSame('2025-26', ReceiptNumbering::seriesKey($instant, 'UTC', 4));

        foreach ([['2026-01-15 10:00', 1, '2026-27'], ['2025-12-31 23:59', 1, '2025-26'], ['2026-07-01 00:00', 7, '2026-27'], ['2026-03-31 23:59', 4, '2025-26']] as [$local, $month, $expected]) {
            $this->assertSame($expected, DB::selectOne('select payments_fy_series_key(?::timestamp, ?) as k', [$local, $month])->k, 'SQL agrees with PHP.');
        }
    }

    #[Test]
    public function the_sequence_is_padded_to_six_digits_and_never_wraps(): void
    {
        foreach ([1 => 'RCPT/2026-27/000001', 42 => 'RCPT/2026-27/000042', 999999 => 'RCPT/2026-27/999999', 1000000 => 'RCPT/2026-27/1000000'] as $n => $expected) {
            $this->assertSame($expected, ReceiptNumbering::format('RCPT', '2026-27', $n));
            $this->assertSame($expected, DB::selectOne('select payments_receipt_number(?, ?, ?) as n', ['RCPT', '2026-27', $n])->n, 'SQL agrees with PHP.');
        }
    }

    #[Test]
    public function the_local_financial_year_decides_the_series_not_the_utc_date(): void
    {
        $w = $this->concessionWorld();

        // 2026-04-01 in Asia/Kolkata is stored as 2026-03-31 18:30 UTC.
        $april = $this->pay($w, '10.00', '2026-04-01');
        $march = $this->pay($w, '10.00', '2026-03-31');

        $this->assertSame('2026-03-31 18:30:00', $this->inSchool($w['school'], fn () => Payment::query()->findOrFail($april->paymentId)->settled_at->format('Y-m-d H:i:s')));
        $this->assertSame('RCPT/2026-27/000001', $this->receiptOf($w, $april->paymentId)->receipt_number);
        $this->assertSame('RCPT/2025-26/000001', $this->receiptOf($w, $march->paymentId)->receipt_number, 'Each FY is its own series starting at 1.');
    }

    #[Test]
    public function a_configured_start_month_and_prefix_shape_the_number_and_academic_years_do_not(): void
    {
        $w = $this->concessionWorld(startMonth: 1);
        $this->setNumbering($w, ' sch-a ', 1);

        // The AcademicYear runs 2026-06-01..2027-05-31; it has no effect.
        $this->assertSame('SCH-A/2026-27/000001', $this->receiptOf($w, $this->pay($w, '10.00', '2026-01-15')->paymentId)->receipt_number);
        $this->assertSame('SCH-A/2025-26/000001', $this->receiptOf($w, $this->pay($w, '10.00', '2025-12-31')->paymentId)->receipt_number);

        $july = $this->concessionWorld(startMonth: 7);
        $this->assertSame('RCPT/2025-26/000001', $this->receiptOf($july, $this->pay($july, '10.00', '2026-06-30')->paymentId)->receipt_number);
        $this->assertSame('RCPT/2026-27/000001', $this->receiptOf($july, $this->pay($july, '10.00', '2026-07-01')->paymentId)->receipt_number);
    }

    // --- Settings locks -----------------------------------------------------

    #[Test]
    public function the_prefix_is_validated_and_locks_once_the_current_series_has_receipts(): void
    {
        $w = $this->concessionWorld();
        $settings = app(FeeSettingsService::class);

        foreach (['A/B', '', '-LEAD', str_repeat('X', 17)] as $bad) {
            try {
                $this->setNumbering($w, $bad, 4);
                $this->fail("Prefix '{$bad}' is refused.");
            } catch (InvalidFeeSettingsException $e) {
                $this->assertSame('receipt_prefix', $e->field());
            }
        }

        $this->setNumbering($w, 'OLD', 4);
        $past = $this->pay($w, '10.00', $this->lastFinancialYear($w));
        $pastSeries = $this->receiptOf($w, $past->paymentId)->series_key;

        $this->setNumbering($w, 'NEW', 4);
        $this->assertSame('NEW', $settings->receiptNumbering($w['school'])->prefix, 'Only a past series has receipts, so the prefix may change.');

        $current = $this->pay($w, '10.00', $this->today($w));
        $this->assertStringStartsWith('NEW/', $this->receiptOf($w, $current->paymentId)->receipt_number);

        // A later receipt in the PAST series keeps that series' own prefix.
        $older = $this->pay($w, '10.00', $this->lastFinancialYear($w));
        $this->assertSame("OLD/{$pastSeries}/000002", $this->receiptOf($w, $older->paymentId)->receipt_number, 'One format per series.');

        try {
            $this->setNumbering($w, 'OTHER', 4);
            $this->fail('The current series has receipts: the prefix is locked.');
        } catch (ReceiptNumberingLockedException) {
            $this->addToAssertionCount(1);
        }

        try {
            $this->setNumbering($w, 'NEW', 7);
            $this->fail('Receipts exist: the start month is locked.');
        } catch (ReceiptNumberingLockedException) {
            $this->addToAssertionCount(1);
        }

        $this->setNumbering($w, 'new', 4);
        $this->assertSame(['prefix' => 'NEW', 'month' => 4], ['prefix' => $settings->receiptNumbering($w['school'])->prefix, 'month' => $settings->receiptNumbering($w['school'])->financialYearStartMonth], 'An unchanged value is not a change.');

        $this->expectException(AuthorizationException::class);
        $settings->setReceiptNumbering($w['school'], 'X', 4, $w['maker']);
    }

    #[Test]
    public function receipts_are_never_edited_through_eloquent(): void
    {
        $w = $this->concessionWorld();
        $receipt = $this->receiptOf($w, $this->pay($w, '10.00', '2026-04-01')->paymentId);

        $this->expectExceptionMessage('permission denied');
        $this->inSchool($w['school'], fn () => PaymentReceipt::query()->whereKey($receipt->id)->update(['receipt_number' => 'RCPT/2026-27/999999']));
    }
}
