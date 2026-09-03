<?php

namespace App\Domain\Payroll\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * Phase 9.10 -- raised exactly once by `PayrollPostingService::reverse()`,
 * from inside the same DB transaction as the row-locked reversal
 * check, `LedgerService::reverse()`, the new append-only reversal
 * `PayrollRunPosting` row, and (when a NEW idempotency claim is
 * present) `IdempotencyGuard::completeWithin()`. A replay never
 * re-enters `reverse()` at all, and a genuine second reversal attempt
 * throws `PayrollRunAlreadyReversedException` before this dispatch
 * site -- this event can never be emitted twice for the same run.
 */
class PayrollRunReversed implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $payrollRunId,
        public readonly string $originalPostingId,
        public readonly string $reversalJournalEntryId,
        public readonly string $reversedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'payroll_run.reversed.v1';
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
            'payrollRunId' => $this->payrollRunId,
            'originalPostingId' => $this->originalPostingId,
            'reversalJournalEntryId' => $this->reversalJournalEntryId,
            'reversedByUserId' => $this->reversedByUserId,
        ];
    }
}
