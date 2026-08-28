<?php

namespace App\Domain\Finance\Application\Exceptions;

/**
 * Covers every basic shape/validation failure of a posting command
 * that is cheaper and safer to reject before ever reaching PostgreSQL
 * (fewer than two lines, a zero or negative line amount, an amount
 * with more than NUMERIC(14,2)'s 2 decimal places, an empty or
 * over-length description) -- one stable exception type rather than a
 * separate class per tiny validation detail, since the database's own
 * CHECK constraints/column limits remain the authoritative defense
 * regardless (docs/modules/FINANCE.md "0G.1 as-built"). The message
 * names the specific reason.
 */
class InvalidJournalEntryException extends FinanceException
{
    public function __construct(string $reason)
    {
        parent::__construct(422, 'INVALID_JOURNAL_ENTRY', $reason);
    }
}
