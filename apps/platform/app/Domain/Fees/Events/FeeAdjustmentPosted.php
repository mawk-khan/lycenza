<?php

namespace App\Domain\Fees\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * FEE.3 (ADR 0062 §20): an approved concession was posted against a charge (Dr concession account / Cr receivable). Internal only: NOT
 * registered in `App\Support\Webhooks\WebhookEventRegistry` (rules 45/77).
 * Payload is ids plus currency only -- no amounts, names or reasons.
 */
class FeeAdjustmentPosted implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $feeAdjustmentId,
        public readonly string $feeConcessionId,
        public readonly string $chargeId,
        public readonly string $journalEntryId,
        public readonly string $currency,
    ) {}

    public function eventType(): string
    {
        return 'fee_adjustment.posted.v1';
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
            'feeConcessionId' => $this->feeConcessionId,
            'chargeId' => $this->chargeId,
            'journalEntryId' => $this->journalEntryId,
            'currency' => $this->currency,
        ];
    }
}
