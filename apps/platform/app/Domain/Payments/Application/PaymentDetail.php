<?php

namespace App\Domain\Payments\Application;

use App\Domain\Payments\Infrastructure\Payment;
use Illuminate\Support\Carbon;

/**
 * Phase 0G.5: `PaymentReadService::getPaymentDetail()`'s return type --
 * includes the allocation summary (rule 55: "allocation summary" is a
 * named safe field) but never a raw provider payload, provider secret,
 * or `posting_txid`-shaped internal.
 *
 * @param  list<array{chargeId: string, amount: string}>  $allocations
 */
final class PaymentDetail
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
        public readonly array $allocations,
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
            allocations: $payment->allocations->map(fn ($allocation) => [
                'chargeId' => $allocation->charge_id,
                'amount' => $allocation->amount,
            ])->all(),
        );
    }
}
