<?php

namespace App\Domain\Fees\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * Phase 0G.4: raised exactly once by `ChargeService::cancel()`, from
 * inside the same DB transaction that persists the cancellation
 * marker and the reversing ledger entry.
 */
class ChargeCancelled implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $chargeId,
        public readonly string $cancellationJournalEntryId,
        public readonly string $originalJournalEntryId,
    ) {}

    public function eventType(): string
    {
        return 'charge.cancelled.v1';
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
            'cancellationJournalEntryId' => $this->cancellationJournalEntryId,
            'originalJournalEntryId' => $this->originalJournalEntryId,
        ];
    }
}
