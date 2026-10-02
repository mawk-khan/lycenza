<?php

namespace App\Domain\Fees\Application;

/**
 * E21.3A (ADR 0064 §5): one fee adjustment as the financial-period close
 * sees it: amount, the charge it reduces, the journal entry that posted it
 * and, if voided, the journal entry that voided it.
 */
final class FeeAdjustmentLedgerFact
{
    public function __construct(
        public readonly string $id,
        public readonly string $chargeId,
        public readonly string $amount,
        public readonly string $journalEntryId,
        public readonly bool $cancelled,
        public readonly ?string $cancellationJournalEntryId,
    ) {}
}
