<?php

namespace App\Domain\Fees\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * FEE.2 (ADR 0062 §20): an assessment run reached a terminal execution
 * status (`completed` or `completed_with_errors`). Written once, in the
 * finalize transaction. Internal only: NOT registered in
 * `App\Support\Webhooks\WebhookEventRegistry` (rules 45/77). Payload is ids
 * and counts -- no Student, amount or name.
 */
class FeeAssessmentRunCompleted implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $runId,
        public readonly string $feeStructureId,
        public readonly string $billingPeriodKey,
        public readonly string $status,
        public readonly int $succeededCount,
        public readonly int $skippedCount,
        public readonly int $failedCount,
    ) {}

    public function eventType(): string
    {
        return 'fee_assessment_run.completed.v1';
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
            'runId' => $this->runId,
            'feeStructureId' => $this->feeStructureId,
            'billingPeriodKey' => $this->billingPeriodKey,
            'status' => $this->status,
            'succeededCount' => $this->succeededCount,
            'skippedCount' => $this->skippedCount,
            'failedCount' => $this->failedCount,
        ];
    }
}
