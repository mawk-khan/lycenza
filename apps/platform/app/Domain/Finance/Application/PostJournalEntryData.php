<?php

namespace App\Domain\Finance\Application;

/**
 * Phase 0G.2: the typed input for `LedgerService::post()` -- used
 * instead of an unbounded array, matching
 * `App\Domain\Documents\Application\CreateDocumentData`'s established
 * precedent (only fields this class actually declares can ever reach
 * the service). Deliberately does NOT carry `school_id` -- the posting
 * School is always `LedgerService::post()`'s own trusted `School`
 * parameter. Deliberately does NOT carry `posted_at`/an effective
 * date -- 0G.2 does not support caller-specified backdating;
 * `journal_entries.posted_at` is always the real database `now()` at
 * insert time (its existing `useCurrent()` default), matching
 * FINANCE.md's undecided "Semantic date vocabulary" `effective_date`
 * row, which 0G.1 left unimplemented and 0G.2 does not add.
 *
 * @param  list<JournalLineData>  $lines
 */
final class PostJournalEntryData
{
    public function __construct(
        public readonly string $currency,
        public readonly string $description,
        public readonly array $lines,
    ) {}
}
