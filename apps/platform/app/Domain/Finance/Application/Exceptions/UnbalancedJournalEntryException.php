<?php

namespace App\Domain\Finance\Application\Exceptions;

/**
 * ADR 0030: a journal entry's lines must sum to zero (total debits ==
 * total credits) before it may be posted. This is the
 * Application-layer pre-check using exact Money arithmetic
 * (App\Domain\Finance\Application\LedgerService::assertBalanced()) --
 * a clean, named domain error raised before any database write is
 * attempted. The PostgreSQL deferred constraint trigger
 * (`journal_entries_balanced_check`) remains the authoritative,
 * mandatory defense regardless of this pre-check ever running
 * correctly.
 */
class UnbalancedJournalEntryException extends FinanceException
{
    public function __construct(string $totalDebit, string $totalCredit, string $currency)
    {
        parent::__construct(
            422,
            'UNBALANCED_JOURNAL_ENTRY',
            "Journal entry is unbalanced: total debits {$totalDebit} {$currency} != total credits {$totalCredit} {$currency}.",
        );
    }
}
