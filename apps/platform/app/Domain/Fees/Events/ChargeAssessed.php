<?php

namespace App\Domain\Fees\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * Phase 0G.4: raised exactly once by `ChargeService::assess()`, from
 * inside the same DB transaction that persists the charge and its
 * ledger posting (ADR 0025's transactional outbox). Payload carries
 * minimal, safe facts only -- never the full description text, never
 * another School's data. Internal-only: NOT registered in
 * App\Support\Webhooks\WebhookEventRegistry (rule 45), so it is not
 * externally webhook-subscribable merely by existing in the outbox --
 * that is a deliberate, separate, future decision.
 */
class ChargeAssessed implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $chargeId,
        public readonly string $studentId,
        public readonly string $journalEntryId,
        public readonly string $currency,
    ) {}

    public function eventType(): string
    {
        return 'charge.assessed.v1';
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
            'chargeId' => $this->chargeId,
            'studentId' => $this->studentId,
            'journalEntryId' => $this->journalEntryId,
            'currency' => $this->currency,
        ];
    }
}
