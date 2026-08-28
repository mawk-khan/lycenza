<?php

namespace App\Domain\Finance\Application;

use Illuminate\Support\Carbon;

/**
 * Phase 0G.3 -- the full typed detail returned by
 * `LedgerReadService::getJournalEntryDetail()`: safe entry metadata
 * plus every one of its immutable `journal_lines` rows, each already
 * projected through `JournalLineDetail`. Never a raw `JournalEntry`/
 * `JournalLine` Eloquent model or a lazy-loaded relation -- `$lines` is
 * a plain, already-materialized array built inside the authorized
 * operation, so nothing about it can trigger an uncontrolled query
 * after authorization/TenantContext has ended.
 */
final class JournalEntryDetail
{
    /**
     * @param  list<JournalLineDetail>  $lines
     */
    public function __construct(
        public readonly string $journalEntryId,
        public readonly string $currency,
        public readonly string $description,
        public readonly Carbon $postedAt,
        public readonly ?string $reversalOfJournalEntryId,
        public readonly ?string $reversedByJournalEntryId,
        public readonly array $lines,
    ) {}
}
