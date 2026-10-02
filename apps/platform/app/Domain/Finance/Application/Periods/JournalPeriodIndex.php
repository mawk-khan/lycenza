<?php

namespace App\Domain\Finance\Application\Periods;

/**
 * E21.3A: which financial period each of a School's journal entries
 * belongs to, and the School's latest closed period. A subledger (e.g.
 * Payments' charge states) uses it to place its own records in time by
 * their journal entry, without ever reading `journal_entries` itself
 * (CLAUDE.md rule 4).
 *
 * Periods are ordered by `starts_on` ('Y-m-d' strings compare correctly).
 * An entry with no period (`null`, not yet backfilled) is treated as LATER
 * than every closed period: a close refuses while any unmapped entry is
 * dated inside the period being closed, so an unmapped entry can only be
 * later detail.
 */
final class JournalPeriodIndex
{
    /**
     * @param  array<string, string|null>  $entryPeriodStarts  journal entry id => its period's starts_on
     */
    public function __construct(
        private readonly array $entryPeriodStarts,
        public readonly ?FinancialPeriodSummary $latestClosed,
    ) {}

    /** @return list<string> every journal entry of the School */
    public function entryIds(): array
    {
        return array_map('strval', array_keys($this->entryPeriodStarts));
    }

    public function has(string $entryId): bool
    {
        return array_key_exists($entryId, $this->entryPeriodStarts);
    }

    /** True when the entry belongs to exactly $period. */
    public function isIn(?string $entryId, FinancialPeriodSummary $period): bool
    {
        return $entryId !== null && ($this->entryPeriodStarts[$entryId] ?? null) === $period->startsOn;
    }

    /** True when the entry belongs to $period or an earlier period. */
    public function isThrough(?string $entryId, FinancialPeriodSummary $period): bool
    {
        if ($entryId === null) {
            return false;
        }
        $start = $this->entryPeriodStarts[$entryId] ?? null;

        return $start !== null && $start <= $period->startsOn;
    }

    /** True when the entry belongs to the latest closed period or earlier. */
    public function isClosedHistory(?string $entryId): bool
    {
        return $this->latestClosed !== null && $this->isThrough($entryId, $this->latestClosed);
    }

    public function withLatestClosed(FinancialPeriodSummary $period): self
    {
        return new self($this->entryPeriodStarts, $period);
    }
}
