<?php

namespace App\Domain\Finance\Application;

use App\Domain\Finance\Infrastructure\JournalEntry;
use Illuminate\Support\Carbon;

/**
 * Phase 0G.2 closure correction: the public return value of
 * `LedgerService::post()`/`reverse()` -- deliberately NOT the raw
 * `JournalEntry` Eloquent model. `posting_txid` is internal PostgreSQL
 * transaction-identity metadata (docs/modules/FINANCE.md "Posting_txid:
 * internal only, never caller-controlled") -- it is not Finance
 * business data, not caller-controlled, and not an API/domain
 * identifier, so it has no place in the Application service's public
 * result contract even though it is a harmless, ordinary attribute on
 * the model internally. Returning the bare model would make that
 * distinction easy for a future caller (an HTTP resource, a queued
 * job) to blur by accident -- this class makes it structurally
 * impossible instead.
 *
 * Deliberately minimal -- only the fields a caller of `post()`/
 * `reverse()` needs to know what just happened. This is NOT the start
 * of a Finance read model (0G.3's job); it exists solely as this
 * mutation operation's own result boundary.
 */
final class JournalEntryResult
{
    public function __construct(
        public readonly string $journalEntryId,
        public readonly string $schoolId,
        public readonly string $currency,
        public readonly string $description,
        public readonly Carbon $postedAt,
        public readonly ?string $reversalOfJournalEntryId,
        public readonly int $lineCount,
    ) {}

    public static function fromModel(JournalEntry $entry, int $lineCount): self
    {
        return new self(
            journalEntryId: $entry->id,
            schoolId: $entry->school_id,
            currency: $entry->currency,
            description: $entry->description,
            postedAt: $entry->posted_at,
            reversalOfJournalEntryId: $entry->reversal_of_journal_entry_id,
            lineCount: $lineCount,
        );
    }
}
