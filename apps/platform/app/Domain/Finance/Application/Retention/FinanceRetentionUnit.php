<?php

namespace App\Domain\Finance\Application\Retention;

/**
 * E21.3A2 (ADR 0064 §17): one dependency-safe Finance retention unit --
 * charges (with everything that depends on them) and/or standalone journal
 * entries, expired together or not at all. A blocked unit carries the
 * reason it is retained. Identifiers stay inside the maintenance run; they
 * are never logged or reported.
 */
final class FinanceRetentionUnit
{
    /**
     * @param  list<string>  $chargeIds
     * @param  list<string>  $entryIds  standalone journal entries (charge-linked entries are derived by the database)
     * @param  list<string>  $studentIds  Students whose dues the unit could affect
     */
    public function __construct(
        public readonly string $participant,
        public readonly array $chargeIds,
        public readonly array $entryIds,
        public readonly array $studentIds = [],
        public readonly ?string $blockedReason = null,
    ) {}

    public function isBlocked(): bool
    {
        return $this->blockedReason !== null;
    }
}
