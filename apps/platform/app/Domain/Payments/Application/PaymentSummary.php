<?php

namespace App\Domain\Payments\Application;

use App\Domain\Payments\Infrastructure\Payment;
use Illuminate\Support\Carbon;

/**
 * Phase 0G.5: one row of `PaymentReadService::listPayments()` -- never
 * the raw `Payment` Eloquent model, never a provider payload/secret
 * (rule 55).
 */
final class PaymentSummary
{
    public function __construct(
        public readonly string $paymentId,
        public readonly string $provider,
        public readonly string $amount,
        public readonly string $currency,
        public readonly Carbon $settledAt,
    ) {}

    public static function fromModel(Payment $payment): self
    {
        return new self(
            paymentId: $payment->id,
            provider: $payment->provider,
            amount: $payment->amount,
            currency: $payment->currency,
            settledAt: $payment->settled_at,
        );
    }
}
