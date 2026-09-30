<?php

namespace App\Domain\Fees\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * FEE.3 (ADR 0062 §20): a fee adjustment was cancelled through a Finance reversal. Internal only: NOT
 * registered in `App\Support\Webhooks\WebhookEventRegistry` (rules 45/77).
 * Payload is ids plus currency only -- no amounts, names or reasons.
 */
class FeeAdjustmentCancelled implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $feeAdjustmentId,
        public readonly string $chargeId,
        public readonly string $cancellationJournalEntryId,
        public readonly string $originalJournalEntryId,
        public readonly string $currency,
    ) {}

    public function eventType(): string
    {
        return 'fee_adjustment.cancelled.v1';
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
            'feeAdjustmentId' => $this->feeAdjustmentId,
            'chargeId' => $this->chargeId,
            'cancellationJournalEntryId' => $this->cancellationJournalEntryId,
            'originalJournalEntryId' => $this->originalJournalEntryId,
            'currency' => $this->currency,
        ];
    }
}
