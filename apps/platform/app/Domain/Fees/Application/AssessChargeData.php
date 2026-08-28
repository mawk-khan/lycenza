<?php

namespace App\Domain\Fees\Application;

use App\Support\Money\Money;

/**
 * Phase 0G.4: the typed input for `ChargeService::assess()` -- matches
 * App\Domain\Finance\Application\PostJournalEntryData's established
 * precedent (only fields this class actually declares can ever reach
 * the service). Deliberately does NOT carry `school_id` -- the
 * assessing School is always `ChargeService::assess()`'s own trusted
 * `School` parameter (rule 8/19).
 *
 * `studentId`/`academicYearId`/`receivableLedgerAccountId`/
 * `revenueLedgerAccountId` are trusted, already-resolved identifiers
 * -- this class does not validate their existence or School ownership
 * (that is `charges`' own composite foreign keys' job, structurally;
 * see the migration's docblock and
 * App\Domain\Fees\Application\Exceptions\StudentNotFoundException /
 * AcademicYearNotFoundException).
 *
 * `dueDate`, if given, is a plain `Y-m-d` calendar date string (rule
 * 20's "Academic year vs. financial period" -- a due date is an
 * operational Fees concern, never confused with `journal_entries.posted_at`,
 * which `LedgerService` sets to the real transaction time regardless).
 */
final class AssessChargeData
{
    public function __construct(
        public readonly string $studentId,
        public readonly string $academicYearId,
        public readonly string $description,
        public readonly Money $amount,
        public readonly string $receivableLedgerAccountId,
        public readonly string $revenueLedgerAccountId,
        public readonly ?string $dueDate = null,
    ) {}
}
