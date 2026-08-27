<?php

namespace App\Domain\Payments\Application;

use App\Domain\Payments\Infrastructure\Payment;
use Illuminate\Support\Carbon;

/**
 * Phase 0G.5: a safe, typed view of a `Payment` -- never the raw
 * Eloquent model, mirroring `App\Domain\Fees\Application\ChargeResult`'s
 * exact result-boundary discipline. Never exposes `posting_txid`-shaped
 * internals or anything provider-secret-shaped (rule 55).
 */
final class PaymentResult
{
    public function __construct(
        public readonly string $paymentId,
        public readonly string $schoolId,
        public readonly string $provider,
        public readonly string $providerPaymentReference,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $settlementLedgerAccountId,
        public readonly string $journalEntryId,
        public readonly Carbon $settledAt,
    ) {}

    public static function fromModel(Payment $payment): self
    {
        return new self(
            paymentId: $payment->id,
            schoolId: $payment->school_id,
            provider: $payment->provider,
            providerPaymentReference: $payment->provider_payment_reference,
            amount: $payment->amount,
            currency: $payment->currency,
            settlementLedgerAccountId: $payment->settlement_ledger_account_id,
            journalEntryId: $payment->journal_entry_id,
            settledAt: $payment->settled_at,
        );
    }
}
