<?php

namespace App\Domain\Payments\Application;

use App\Domain\Payments\Infrastructure\Payment;
use Illuminate\Support\Carbon;

/**
 * Phase 0G.5: one row of `PaymentReadService::listPayments()` -- never
 * the raw `Payment` Eloquent model, never a provider payload/secret
 * (rule 55).
 *
 * Phase 0O.11A: `source` distinguishes provider-derived from manually
 * recorded Payments; `settledAt` is when the money was received
 * (occurred-at), `recordedAt` when Lycenza committed the record. The
 * manual idempotency key is never exposed.
 */
final class PaymentSummary
{
    public function __construct(
        public readonly string $paymentId,
        public readonly string $source,
        public readonly ?string $provider,
        public readonly ?string $method,
        public readonly string $amount,
        public readonly string $currency,
        public readonly Carbon $settledAt,
        public readonly Carbon $recordedAt,
    ) {}

    public static function fromModel(Payment $payment): self
    {
        return new self(
            paymentId: $payment->id,
            source: $payment->source,
            provider: $payment->provider,
            method: $payment->method,
            amount: $payment->amount,
            currency: $payment->currency,
            settledAt: $payment->settled_at,
            recordedAt: $payment->created_at,
        );
    }
}
