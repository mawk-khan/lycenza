<?php

namespace App\Domain\Payments\Application;

/**
 * Phase 0G.5: the public return value of
 * `PaymentProviderEventService::recordSettlement()` -- a safe typed
 * result, never a raw `Payment`/`PaymentProviderEvent` Eloquent model
 * (rule 60).
 */
final class PaymentProviderEventResult
{
    public function __construct(
        public readonly PaymentProviderEventOutcome $outcome,
        public readonly string $paymentId,
        public readonly string $journalEntryId,
    ) {}
}
