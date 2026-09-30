<?php

namespace App\Domain\Payments\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * FEE.5 (ADR 0062 §20): one late fee was assessed -- a new late-fee charge
 * linked to its source charge -- raised exactly once, in the item
 * transaction that created it (a retry or a skipped duplicate raises
 * nothing). Payload is ids and currency only; no amount or Student.
 * Internal only: NOT registered in `App\Support\Webhooks\WebhookEventRegistry`
 * (rules 45/77).
 */
class LateFeeAssessed implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $lateFeeAssessmentId,
        public readonly string $sourceChargeId,
        public readonly string $chargeId,
        public readonly string $lateFeeRuleId,
        public readonly string $lateFeeRunId,
        public readonly string $currency,
    ) {}

    public function eventType(): string
    {
        return 'late_fee.assessed.v1';
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
            'lateFeeAssessmentId' => $this->lateFeeAssessmentId,
            'sourceChargeId' => $this->sourceChargeId,
            'chargeId' => $this->chargeId,
            'lateFeeRuleId' => $this->lateFeeRuleId,
            'lateFeeRunId' => $this->lateFeeRunId,
            'currency' => $this->currency,
        ];
    }
}
