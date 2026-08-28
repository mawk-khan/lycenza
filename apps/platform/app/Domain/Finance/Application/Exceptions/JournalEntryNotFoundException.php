<?php

namespace App\Domain\Finance\Application\Exceptions;

/**
 * Phase 0G.3 -- raised uniformly whether a caller-supplied journal
 * entry id genuinely does not exist OR exists only in a different
 * School. `LedgerReadService`/`LedgerAdministrationService` always
 * resolve the id through a School-scoped query
 * (`where('school_id', $school->id)->find($id)`) run under the
 * trusted School's own TenantContext/RLS -- a cross-School id is
 * simply absent from the result, identical to a nonexistent one. The
 * message never distinguishes the two cases, so a caller cannot use
 * repeated read or reversal attempts to probe whether a journal entry
 * id exists in another School (the same "no oracle" principle
 * `LedgerAccountNotFoundException`/`App\Domain\Documents\Application\Exceptions\DocumentNotFoundException`
 * already establish).
 */
class JournalEntryNotFoundException extends FinanceException
{
    public function __construct(public readonly string $journalEntryId)
    {
        parent::__construct(404, 'JOURNAL_ENTRY_NOT_FOUND', "No journal entry with id '{$journalEntryId}' was found in this School.");
    }
}
