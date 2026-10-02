<?php

namespace App\Domain\Fees\Application;

/**
 * E21.3A (ADR 0064 §5): one charge as the financial-period close sees it:
 * its amount and the journal entries that assessed and (if so) cancelled
 * it. Amounts are exact decimal strings.
 */
final class ChargeLedgerFact
{
    public function __construct(
        public readonly string $id,
        public readonly string $studentId,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $journalEntryId,
        public readonly bool $cancelled,
        public readonly ?string $cancellationJournalEntryId,
    ) {}
}
