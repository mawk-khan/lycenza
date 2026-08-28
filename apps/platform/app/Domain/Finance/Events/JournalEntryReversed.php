<?php

namespace App\Domain\Finance\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * Phase 0G.2: raised exactly once by `LedgerService::reverse()`, from
 * inside the same DB transaction that persists the reversal entry and
 * its inverted lines. `LedgerService::reverse()` does NOT call
 * `LedgerService::post()` internally (they share private helpers, not
 * the public posting method) specifically so this event is never
 * accidentally emitted alongside a JournalEntryPosted for the same
 * operation -- one service call, one event, by construction, not by
 * incidental suppression.
 */
class JournalEntryReversed implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $reversalJournalEntryId,
        public readonly string $originalJournalEntryId,
    ) {}

    public function eventType(): string
    {
        return 'journal_entry.reversed.v1';
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
            'reversalJournalEntryId' => $this->reversalJournalEntryId,
            'originalJournalEntryId' => $this->originalJournalEntryId,
        ];
    }
}
