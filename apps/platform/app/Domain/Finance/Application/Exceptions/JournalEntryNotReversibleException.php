<?php

namespace App\Domain\Finance\Application\Exceptions;

/**
 * Phase 0O.11A (ADR 0031 implementation amendment section 2): a journal
 * entry owned by a subledger record that forbids direct reversal -- today
 * a Payment's settlement entry, refused by the Payments-owned
 * `journal_entries_payment_reversal_guard` trigger. Reversing it through
 * the generic ledger action would leave the owning record posted against
 * a reversed entry. Finance never reads the owning table; it recognizes
 * the guard only by its SQLSTATE (`restrict_violation`, 23001).
 */
class JournalEntryNotReversibleException extends FinanceException
{
    public function __construct(string $journalEntryId)
    {
        parent::__construct(409, 'JOURNAL_ENTRY_NOT_REVERSIBLE', "Journal entry '{$journalEntryId}' belongs to a recorded payment and cannot be reversed directly.");
    }
}
