<?php

namespace App\Domain\Finance\Application;

use Illuminate\Support\Carbon;

/**
 * Phase 0G.3 -- one row of `LedgerReadService::listJournalEntries()`'s
 * journal history. Deliberately excludes `posting_txid` (internal
 * PostgreSQL transaction-identity metadata, never a Finance business
 * attribute -- see `JournalEntryResult`'s docblock) and any raw
 * Eloquent model reference. `reversedByJournalEntryId` is derived
 * read-only from immutable journal history (a correlated subquery in
 * `LedgerReadService`, never a persisted/mutable column) -- ADR 0030
 * already guarantees at most one reversing entry per original via
 * `journal_entries_reversal_of_unique`.
 *
 * Deliberately does NOT expose `totalDebit`/`totalCredit`: FINANCE.md's
 * 0G.3 as-built section documents this as an explicit deferral (no
 * accounting "balance" semantics have been architecturally settled
 * yet, and a per-entry total is redundant with the database's own
 * balanced-entry invariant) -- see the "Balance / totals" subsection.
 */
final class JournalEntrySummary
{
    public function __construct(
        public readonly string $journalEntryId,
        public readonly string $currency,
        public readonly string $description,
        public readonly Carbon $postedAt,
        public readonly ?string $reversalOfJournalEntryId,
        public readonly ?string $reversedByJournalEntryId,
        public readonly int $lineCount,
    ) {}
}
