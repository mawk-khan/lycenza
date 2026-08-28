<?php

namespace App\Domain\Finance\Application\Exceptions;

/**
 * ADR 0030: at most one reversal per original journal entry
 * (`journal_entries_reversal_of_unique`, a partial unique index).
 * Raised both by `LedgerService::reverse()`'s own pre-check (the
 * common, sequential case -- a clean domain error without ever
 * attempting the insert) and by its mapping of the database's
 * `UniqueConstraintViolationException` (the genuine concurrent-race
 * case, mirroring
 * App\Domain\AcademicStructure\Application\Exceptions\ConcurrentActivationConflictException's
 * identical pattern) -- both paths raise this SAME exception, since
 * from the caller's perspective the outcome is identical: "this entry
 * is already reversed," never a distinction between "someone already
 * knew that" and "someone found out just now."
 */
class JournalEntryAlreadyReversedException extends FinanceException
{
    public function __construct(string $journalEntryId)
    {
        parent::__construct(409, 'JOURNAL_ENTRY_ALREADY_REVERSED', "Journal entry '{$journalEntryId}' has already been reversed.");
    }
}
