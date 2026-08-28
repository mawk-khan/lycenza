<?php

namespace App\Domain\Payments\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * Phase 0G.5: raised exactly once by
 * `PaymentProviderEventService::recordSettlement()`, from inside the
 * same DB transaction that persists the provider event, the payment,
 * its allocations, and the settlement ledger posting (ADR 0025's
 * transactional outbox) -- never raised again on a duplicate-replay
 * call (rule 59). Payload carries minimal, safe facts only -- never a
 * provider payload, never another School's data. Internal-only: NOT
 * registered in `App\Support\Webhooks\WebhookEventRegistry` (rule 45,
 * rule 58) -- not externally webhook-subscribable merely by existing in
 * the outbox.
 */
class PaymentSettled implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $paymentId,
        public readonly string $journalEntryId,
        public readonly string $currency,
        public readonly int $allocationCount,
    ) {}

    public function eventType(): string
    {
        return 'payment.settled.v1';
    }

    public function eventVersion(): int
    {
        return 1;
    }

    public function schoolId(): ?string
    {
        return $this->schoolId;
    }

    public function payload(): array
    {
        return [
            'paymentId' => $this->paymentId,
            'journalEntryId' => $this->journalEntryId,
            'currency' => $this->currency,
            'allocationCount' => $this->allocationCount,
        ];
    }
}
