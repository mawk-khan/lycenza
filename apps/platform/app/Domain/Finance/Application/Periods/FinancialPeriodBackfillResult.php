<?php

namespace App\Domain\Finance\Application\Periods;

/**
 * E21.3A: one backfill pass over one School. Counts only, never amounts.
 * - mapped: assigned its period (or, in a dry run, would be);
 * - ambiguous: the period cannot be proven, so the entry stays unmapped
 *   and retained, and blocks closing any period it might belong to;
 * - blocked: it would fall in or before a closed period (cannot happen
 *   while the close refuses unmapped entries; counted, never forced);
 * - error: an unexpected failure for that entry, which stays unmapped.
 */
final class FinancialPeriodBackfillResult
{
    public function __construct(
        public readonly bool $dryRun,
        public readonly int $mapped,
        public readonly int $ambiguous,
        public readonly int $blocked,
        public readonly int $errors,
        public readonly int $remainingUnmapped,
    ) {}

    /** @return array{mapped: int, ambiguous: int, blocked: int, error: int, remaining_unmapped: int} */
    public function counts(): array
    {
        return ['mapped' => $this->mapped, 'ambiguous' => $this->ambiguous, 'blocked' => $this->blocked, 'error' => $this->errors, 'remaining_unmapped' => $this->remainingUnmapped];
    }
}
