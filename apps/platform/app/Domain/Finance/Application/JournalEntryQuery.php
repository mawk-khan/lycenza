<?php

namespace App\Domain\Finance\Application;

/**
 * Phase 0G.3 -- explicit, allow-listed input for
 * `LedgerReadService::listJournalEntries()`. Mirrors
 * `App\Domain\HR\Application\EmployeeDirectoryQuery`'s established
 * shape: never an arbitrary array/query DSL, only fixed typed
 * properties.
 *
 * `postedFrom`/`postedTo` filter on `journal_entries.posted_at` (the
 * actual posting timestamp) -- never `created_at` (there is no other
 * date field to confuse this with; 0G.2 does not add a caller-specified
 * effective/backdated date). Both are compared with plain `>=`/`<=`
 * against the timestamp column with no accounting-period or
 * end-of-day-inclusive adjustment -- a caller wanting an inclusive
 * calendar day passes a value that covers it (e.g. `'2026-08-31
 * 23:59:59'` for `postedTo`); this class does not invent accounting
 * period semantics (deferred, FINANCE.md 0G.3 as-built).
 *
 * `ledgerAccountId` is a trusted, already-resolved identifier -- this
 * class does not validate its format or existence (that is a future
 * controller's job, exactly like `EmployeeDirectoryQuery`'s
 * `campusId`/`departmentId`/`positionId`). A value belonging to a
 * different School simply matches no `journal_lines` rows (see
 * `LedgerReadService`'s own docblock for why that is safe without an
 * extra existence check).
 *
 * `reversedOnly`: `true` returns only entries that ARE a reversal of
 * something else (`reversal_of_journal_entry_id IS NOT NULL`); `false`
 * returns only original (non-reversal) entries; `null` (default)
 * applies no filter on this dimension. This is about "is this entry
 * itself a reversal", not "has this entry been reversed" -- the latter
 * is exposed per-row via `JournalEntrySummary::$reversedByJournalEntryId`,
 * not as a list-level filter (0G.3 does not add one).
 *
 * `perPage` is clamped to `[1, LedgerReadService::MAX_PER_PAGE]` here,
 * not just at the service call site, so an out-of-range value can
 * never reach the query layer at all.
 */
final class JournalEntryQuery
{
    public const int DEFAULT_PER_PAGE = 25;

    public readonly int $page;

    public readonly int $perPage;

    public function __construct(
        public readonly ?string $postedFrom = null,
        public readonly ?string $postedTo = null,
        public readonly ?string $ledgerAccountId = null,
        public readonly ?bool $reversedOnly = null,
        public readonly ?string $search = null,
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
    ) {
        $this->page = max(1, $page);
        $this->perPage = max(1, min($perPage, LedgerReadService::MAX_PER_PAGE));
    }
}
