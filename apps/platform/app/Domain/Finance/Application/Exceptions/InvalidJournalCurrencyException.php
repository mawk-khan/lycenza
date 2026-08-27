<?php

namespace App\Domain\Finance\Application\Exceptions;

/**
 * Covers both: (1) a posting command naming a currency Phase 0G does
 * not support (anything other than `INR` -- the database's own
 * `..._currency_inr_only_check` CHECK constraints are the authoritative
 * defense, this is the clean pre-check), and (2) a journal line whose
 * Money amount's currency does not match the entry's declared
 * currency (the composite foreign keys remain the authoritative
 * defense for this case too).
 */
class InvalidJournalCurrencyException extends FinanceException
{
    public function __construct(string $reason)
    {
        parent::__construct(422, 'INVALID_JOURNAL_CURRENCY', $reason);
    }
}
