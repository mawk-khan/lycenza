<?php

namespace App\Domain\Fees\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * FEE.3 (ADR 0062 §20): a fee concession request was approved by a second person. Internal only: NOT
 * registered in `App\Support\Webhooks\WebhookEventRegistry` (rules 45/77).
 * Payload is ids plus the closed scope and category only -- no amounts, names or reasons.
 */
class FeeConcessionApproved implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $feeConcessionId,
        public readonly string $studentId,
        public readonly string $scope,
        public readonly string $category,
    ) {}

    public function eventType(): string
    {
        return 'fee_concession.approved.v1';
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
            'feeConcessionId' => $this->feeConcessionId,
            'studentId' => $this->studentId,
            'scope' => $this->scope,
            'category' => $this->category,
        ];
    }
}
