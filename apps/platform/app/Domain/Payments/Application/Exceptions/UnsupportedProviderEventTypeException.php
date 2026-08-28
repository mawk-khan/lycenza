<?php

namespace App\Domain\Payments\Application\Exceptions;

/**
 * Phase 0G.5 scope decision (see `create_payment_provider_events_table`
 * migration's docblock): `PaymentProviderEventService::recordSettlement()`
 * accepts exactly ONE normalized `eventType` (the settlement/success
 * type) -- every other value is rejected outright, never silently
 * recorded as a no-op and never produces a Payment/ledger effect.
 * Non-settlement provider callback types (pending/authorized/failed) are
 * not modeled in 0G.5 at all (deferred alongside Refunds).
 */
class UnsupportedProviderEventTypeException extends PaymentsException
{
    public function __construct(string $eventType)
    {
        parent::__construct(
            422,
            'UNSUPPORTED_PROVIDER_EVENT_TYPE',
            "Provider event type '{$eventType}' is not a supported settlement event in this checkpoint."
        );
    }
}
