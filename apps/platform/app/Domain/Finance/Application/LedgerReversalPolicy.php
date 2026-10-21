<?php

namespace App\Domain\Finance\Application;

use App\Domain\Finance\Application\Exceptions\JournalEntryNotReversibleException;
use App\Domain\Finance\Infrastructure\JournalEntry;
use Illuminate\Support\Facades\DB;

/**
 * ORG.8-P1 (ADR 0076 §57): may this journal entry be reversed through the
 * GENERIC ledger reversal? Never whether the actor may reverse at all --
 * `finance.ledger.reverse`, fresh MFA and tenancy are checked before this.
 *
 * The answer is the database's one structural classification,
 * `journal_entry_subledger_owner()`: an entry another domain owns (a Charge,
 * fee adjustment, Payment, Payment correction, payroll posting, or the
 * cancellation/reversal of one) is reversed only through that domain, which
 * keeps its own record and the ledger in step. Only a ledger-native entry
 * (a manual posting, or a reversal this generic path created) answers yes.
 * Finance never reads the owning tables itself; the same function backs the
 * journal_entries guards, so raw SQL is refused too.
 *
 * Ownership is fixed in the transaction that creates the entry, so this
 * answer cannot change between the check and the reversal. The refusal is
 * one generic answer that never names the owning domain.
 */
class LedgerReversalPolicy
{
    /** Call inside the entry's School context (RLS scopes the owner lookup). */
    public function assertGenericallyReversible(JournalEntry $entry): void
    {
        $owner = DB::selectOne('select public.journal_entry_subledger_owner(?, ?) as owner', [$entry->id, $entry->school_id])->owner;

        if ($owner !== null) {
            throw new JournalEntryNotReversibleException($entry->id);
        }
    }
}
