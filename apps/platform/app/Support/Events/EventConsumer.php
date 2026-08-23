<?php

namespace App\Support\Events;

use App\Models\DomainEventOutbox;

/**
 * A registered reaction to one or more event types. Implementations
 * must be safe to call multiple times for the same event (the actual
 * duplicate-suppression is handled by IdempotentConsumerGuard, which
 * wraps every call -- an EventConsumer's handle() should assume it
 * genuinely needs to run, not re-check duplication itself).
 */
interface EventConsumer
{
    /**
     * Stable identity used as the idempotency-receipt key
     * (event_consumer_receipts.consumer_name) -- changing this string
     * for an existing consumer effectively resets its dedup history,
     * so treat it as seriously as a database column rename.
     */
    public function name(): string;

    public function handles(string $eventType): bool;

    public function handle(DomainEventOutbox $event): void;
}
