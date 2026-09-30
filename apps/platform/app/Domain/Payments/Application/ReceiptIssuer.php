<?php

namespace App\Domain\Payments\Application;

use App\Domain\Fees\Application\FeeSettingsService;
use App\Domain\Payments\Domain\ReceiptNumbering;
use App\Domain\Payments\Events\PaymentReceiptIssued;
use App\Domain\Payments\Infrastructure\Payment;
use App\Domain\Payments\Infrastructure\PaymentReceipt;
use App\Domain\Payments\Infrastructure\PaymentReceiptCounter;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\Uid\UuidV7;

/**
 * FEE.4 (ADR 0062 §17; owner decisions I, I2): the TRUSTED issuer of
 * Payment receipts -- no capability check; it runs only inside
 * `SettledPaymentRecorder::record()` (every settlement ingress) and the
 * operator backfill (`ReceiptBackfillService`). There is no human "create
 * a receipt" path.
 *
 * In the caller's transaction, with the School's TenantContext set:
 * 1. the series is the School financial year of the Payment's
 *    `settled_at` in the School's timezone (`fee_settings` start month,
 *    default April; never an AcademicYear);
 * 2. the series counter row is created if missing (`insertOrIgnore`, the
 *    `EmployeeNumberAllocator` precedent -- a concurrent duplicate never
 *    aborts the transaction) with the current prefix snapshotted;
 * 3. the counter row is locked FOR UPDATE, so concurrent issuers in one
 *    series serialize; the Payment's receipt is then re-checked, so a
 *    replay or a racing backfill returns the existing receipt instead of
 *    consuming a number;
 * 4. the receipt is inserted with `next_value`, then the counter advances
 *    by one.
 *
 * A rolled-back transaction rolls back the receipt and the increment: no
 * committed gap. The database re-checks the series, sequence and format
 * of every insert and every counter move; `payment_receipts_sequence_unique`
 * is the backstop and is never retried silently.
 */
class ReceiptIssuer
{
    public const ISSUANCE_SETTLEMENT = 'settlement';

    public const ISSUANCE_BACKFILL = 'backfill';

    public function __construct(
        private readonly FeeSettingsService $settings,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return array{receipt: PaymentReceipt, issued: bool} */
    public function issue(School $school, Payment $payment, string $issuance, ?User $actor = null): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('ReceiptIssuer::issue() must run inside the caller\'s database transaction.');
        }

        $numbering = $this->settings->receiptNumbering($school);
        // Read settled_at exactly as stored (a UTC wall-clock value), the
        // same interpretation the database trigger re-checks.
        $settledAt = CarbonImmutable::parse($payment->settled_at->format('Y-m-d H:i:s'), 'UTC');
        $seriesKey = ReceiptNumbering::seriesKey($settledAt, $school->timezone, $numbering->financialYearStartMonth);

        PaymentReceiptCounter::query()->insertOrIgnore([
            'id' => (string) new UuidV7,
            'school_id' => $school->id,
            'series_key' => $seriesKey,
            'prefix' => $numbering->prefix,
            'next_value' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $counter = PaymentReceiptCounter::query()
            ->where('school_id', $school->id)
            ->where('series_key', $seriesKey)
            ->lockForUpdate()
            ->firstOrFail();

        $existing = PaymentReceipt::query()->where('school_id', $school->id)->where('payment_id', $payment->id)->first();
        if ($existing !== null) {
            return ['receipt' => $existing, 'issued' => false];
        }

        $sequence = $counter->next_value;

        $receipt = new PaymentReceipt;
        $receipt->forceFill([
            'school_id' => $school->id,
            'payment_id' => $payment->id,
            'receipt_number' => ReceiptNumbering::format($counter->prefix, $seriesKey, $sequence),
            'series_key' => $seriesKey,
            'sequence_value' => $sequence,
            'issued_at' => now(),
            'issued_by_user_id' => $actor?->id,
        ])->save();

        $counter->forceFill(['next_value' => $sequence + 1])->save();

        $this->audit->school($school, $issuance === self::ISSUANCE_BACKFILL ? 'payment_receipt.backfilled' : 'payment_receipt.issued', actor: $actor, subject: $receipt, metadata: [
            'paymentReceiptId' => $receipt->id,
            'paymentId' => $payment->id,
            'seriesKey' => $seriesKey,
        ]);

        event(new PaymentReceiptIssued($school->id, $receipt->id, $payment->id, $seriesKey, $issuance));

        return ['receipt' => $receipt, 'issued' => true];
    }
}
