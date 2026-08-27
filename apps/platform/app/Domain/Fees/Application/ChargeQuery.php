<?php

namespace App\Domain\Fees\Application;

/**
 * Phase 0G.4 -- explicit, allow-listed input for
 * `ChargeReadService::listCharges()`. Mirrors
 * App\Domain\Finance\Application\JournalEntryQuery's established shape
 * exactly: never an arbitrary array/query DSL, only fixed typed
 * properties, `perPage` clamped here so an out-of-range value can
 * never reach the query layer.
 *
 * `studentId`/`academicYearId` are trusted, already-resolved
 * identifiers -- a value belonging to a different School simply
 * matches no `charges` rows (same "no oracle" reasoning as
 * `JournalEntryQuery::$ledgerAccountId`).
 *
 * `includeCancelled`: `false` (default) returns only non-cancelled
 * charges; `true` returns every charge regardless of cancellation
 * state. There is no "cancelled only" mode in 0G.4 -- not requested by
 * any known caller yet, easy to add later without a breaking change.
 */
final class ChargeQuery
{
    public const int DEFAULT_PER_PAGE = 25;

    public readonly int $page;

    public readonly int $perPage;

    public function __construct(
        public readonly ?string $studentId = null,
        public readonly ?string $academicYearId = null,
        public readonly bool $includeCancelled = false,
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
    ) {
        $this->page = max(1, $page);
        $this->perPage = max(1, min($perPage, ChargeReadService::MAX_PER_PAGE));
    }
}
