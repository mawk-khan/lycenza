<?php

namespace App\Domain\Finance\Application\Exceptions;

/**
 * Phase 0O.11A (ADR 0031 implementation amendment section 2), widened by
 * ORG.8-P1 (ADR 0076 §57): a journal entry another domain owns -- a Charge,
 * fee adjustment, Payment, Payment correction, payroll posting, or the
 * cancellation/reversal of one -- is never reversed through the generic
 * ledger action, which would leave the owning record live against a
 * reversed entry. Raised by `LedgerReversalPolicy`, or mapped from the
 * journal_entries guards' SQLSTATE (`restrict_violation`, 23001). The
 * message never names the owning domain.
 */
class JournalEntryNotReversibleException extends FinanceException
{
    public function __construct(string $journalEntryId)
    {
        parent::__construct(409, 'JOURNAL_ENTRY_NOT_REVERSIBLE', "Journal entry '{$journalEntryId}' belongs to another record and is reversed only through that record.");
    }
}
