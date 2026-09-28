<?php

namespace App\Domain\Finance\Application;

use App\Domain\Finance\Application\Exceptions\InvalidJournalCurrencyException;
use App\Domain\Finance\Application\Exceptions\InvalidJournalEntryException;
use App\Domain\Finance\Application\Exceptions\JournalEntryAlreadyReversedException;
use App\Domain\Finance\Application\Exceptions\JournalEntryNotFoundException;
use App\Domain\Finance\Application\Exceptions\JournalEntryNotReversibleException;
use App\Domain\Finance\Application\Exceptions\LedgerAccountNotFoundException;
use App\Domain\Finance\Application\Exceptions\UnbalancedJournalEntryException;
use App\Domain\Finance\Domain\JournalSide;
use App\Domain\Finance\Events\JournalEntryPosted;
use App\Domain\Finance\Events\JournalEntryReversed;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0G.2: the only sanctioned write path for posting and reversing
 * journal entries -- never write JournalEntry/JournalLine directly from
 * anywhere else, mirroring
 * App\Domain\AcademicStructure\Application\AcademicYearService's exact
 * established pattern (validate -> write state -> audit -> emit domain
 * event, inside one transaction, ADR 0025).
 *
 * NOT an authorization boundary (rule 7 of this checkpoint's brief):
 * `$actor` is passed through to AuditRecorder purely for WHO-did-this
 * provenance in the audit trail -- this service performs no
 * capability/permission check of any kind. Whether a given actor is
 * ALLOWED to call `post()`/`reverse()` at all is entirely the calling
 * layer's responsibility until 0G.3 (Finance Authorization &
 * Administrative Read Model) exists. Actor provenance != authorization.
 *
 * Every public method requires a trusted School (either the explicit
 * `School $school` parameter of `post()`, or the already-resolved
 * `JournalEntry $original`'s own `school` relation for `reverse()`) --
 * never a caller-supplied `school_id` string on any input DTO/line
 * (rule 8). `TenantContext::withSchool()` wraps the entire
 * validate-through-commit sequence so the RLS session GUC stays
 * correctly set through the real PostgreSQL COMMIT that both the
 * deferred balance trigger and the post-commit line-set-immutability
 * trigger depend on (docs/modules/FINANCE.md "TenantContext-through-
 * commit requirement") -- this class must never clear/switch/restore
 * TenantContext before `DB::transaction()`'s closure returns.
 */
class LedgerService
{
    private const SUPPORTED_CURRENCY = 'INR';

    private const MAX_DESCRIPTION_LENGTH = 255;

    private const SUBLEDGER_REVERSAL_GUARD_SQLSTATE = '23001';

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * Posts a new, balanced journal entry atomically: header + every
     * line + audit event + outbox event all commit together, or none
     * of them do (proven directly by real failure-injection tests, not
     * merely an outer-transaction-wrap simulation -- see
     * docs/modules/FINANCE.md "Atomicity: real failure-injection
     * proofs"). Validated with exact Money arithmetic BEFORE any
     * database write is attempted; PostgreSQL's own CHECK constraints
     * and the deferred balance-check constraint trigger remain the
     * authoritative defense regardless of this pre-check (rule 15).
     * Actual write order inside the transaction: header -> lines ->
     * audit -> domain event/outbox -- documented here because it IS
     * observable (e.g. which write a failure-injection trigger catches
     * first), but the ordering itself is never what provides
     * atomicity; the enclosing `DB::transaction()` is the sole
     * consistency boundary, and a failure at any one of these four
     * steps rolls back all of them, regardless of order.
     *
     * `journal_entries.posting_txid` is never set here -- it is
     * entirely database-computed (an unconditional BEFORE INSERT
     * trigger, docs/modules/FINANCE.md "Posting_txid is database-
     * authoritative") and is never read back or exposed by this
     * method's return value at all -- `JournalEntryResult` has no
     * `posting_txid` field, structurally, unlike the raw `JournalEntry`
     * model this method used to return directly.
     */
    public function post(School $school, PostJournalEntryData $data, ?User $actor = null): JournalEntryResult
    {
        $this->assertValidShape($data);
        $this->assertBalanced($data);

        return $this->context->withSchool($school, function () use ($school, $data, $actor) {
            return DB::transaction(function () use ($school, $data, $actor) {
                $accounts = $this->resolveAccounts($data->lines);

                $entry = JournalEntry::query()->create([
                    'school_id' => $school->id,
                    'currency' => $data->currency,
                    'description' => $data->description,
                ]);

                foreach ($data->lines as $line) {
                    $account = $accounts->get($line->ledgerAccountId);

                    $entry->lines()->create([
                        'school_id' => $school->id,
                        'ledger_account_id' => $account->id,
                        'currency' => $data->currency,
                        'debit_amount' => $line->side === JournalSide::Debit ? $line->amount->amount() : null,
                        'credit_amount' => $line->side === JournalSide::Credit ? $line->amount->amount() : null,
                    ]);
                }

                $this->audit->school($school, 'journal_entry.posted', actor: $actor, subject: $entry, metadata: [
                    'currency' => $data->currency,
                    'lineCount' => count($data->lines),
                ]);

                event(new JournalEntryPosted($school->id, $entry->id, $data->currency, count($data->lines)));

                return JournalEntryResult::fromModel($entry->refresh(), count($data->lines));
            });
        });
    }

    /**
     * Semantically reverses a posted journal entry by creating a NEW
     * journal entry whose lines exactly invert the original's (ADR
     * 0030 "Reversal linkage direction") -- the original's own rows
     * are never written to again. `$reason`, if given, becomes the
     * reversal's `description` (journal_entries has no dedicated
     * reversal-reason column -- FINANCE.md does not require one, and
     * 0G.2 does not add one); otherwise a description is derived
     * automatically. Whether reversal entries may themselves be
     * reversed: rule 26 of this checkpoint's brief asked this to be
     * settled from committed architecture, not guessed. ADR 0030 and
     * FINANCE.md never state a restriction, and the 0G.1 schema itself
     * applies the SAME uniform rule (one full reversal per entry,
     * `journal_entries_reversal_of_unique`) to every `journal_entries`
     * row without distinguishing "is this row itself a reversal" --
     * the database's own committed design does not special-case it.
     * Adding a NEW restriction here that neither the schema nor the
     * prose requires would itself be inventing policy (the exact thing
     * rule 26 warns against), and reversing a reversal is ordinary,
     * legitimate double-entry practice (e.g. correcting an erroneous
     * reversal). This method therefore does not check `$original->
     * isReversal()` at all -- any posted entry, reversal or not, may
     * be reversed, exactly once, uniformly.
     */
    public function reverse(JournalEntry $original, ?User $actor = null, ?string $reason = null): JournalEntryResult
    {
        if ($reason !== null && (trim($reason) === '' || mb_strlen($reason) > self::MAX_DESCRIPTION_LENGTH)) {
            throw new InvalidJournalEntryException(
                'Reversal reason must be non-empty and at most '.self::MAX_DESCRIPTION_LENGTH.' characters.'
            );
        }

        return $this->context->withSchool($original->school, function () use ($original, $actor, $reason) {
            $original->loadMissing('lines');

            // Sequential pre-check: a clean domain error without ever
            // attempting the insert, for the common (non-racing) case.
            if ($original->reversedBy()->exists()) {
                throw new JournalEntryAlreadyReversedException($original->id);
            }

            try {
                return DB::transaction(function () use ($original, $actor, $reason) {
                    $reversal = JournalEntry::query()->create([
                        'school_id' => $original->school_id,
                        'currency' => $original->currency,
                        'description' => $reason ?? "Reversal of journal entry {$original->id}.",
                        'reversal_of_journal_entry_id' => $original->id,
                    ]);

                    foreach ($original->lines as $line) {
                        $reversal->lines()->create([
                            'school_id' => $original->school_id,
                            'ledger_account_id' => $line->ledger_account_id,
                            'currency' => $line->currency,
                            'debit_amount' => $line->credit_amount,
                            'credit_amount' => $line->debit_amount,
                        ]);
                    }

                    $this->audit->school($original->school, 'journal_entry.reversed', actor: $actor, subject: $reversal, metadata: [
                        'originalJournalEntryId' => $original->id,
                        'lineCount' => $original->lines->count(),
                    ]);

                    event(new JournalEntryReversed($original->school_id, $reversal->id, $original->id));

                    return JournalEntryResult::fromModel($reversal->refresh(), $original->lines->count());
                });
            } catch (QueryException $e) {
                // Phase 0O.11A: a subledger guard trigger (today
                // journal_entries_payment_reversal_guard) refuses the
                // reversal of an entry it owns with restrict_violation.
                // Finance recognizes only the SQLSTATE, never the owner.
                if ($e->getCode() === self::SUBLEDGER_REVERSAL_GUARD_SQLSTATE) {
                    throw new JournalEntryNotReversibleException($original->id);
                }

                if (! $e instanceof UniqueConstraintViolationException) {
                    throw $e;
                }

                // Genuine concurrent race: the sequential pre-check
                // above passed for two racing callers, but the
                // database's own partial unique index
                // (journal_entries_reversal_of_unique) allows only one
                // of them to actually commit. The loser is told the
                // same thing the sequential pre-check would have told
                // it -- from the caller's perspective the outcome is
                // identical either way.
                throw new JournalEntryAlreadyReversedException($original->id);
            }
        });
    }

    /**
     * Phase 0G.4: resolves a trusted internal caller's plain journal
     * entry id to the model `reverse()` requires, entirely inside
     * Finance's own module boundary -- added specifically so a trusted
     * internal domain caller from a DIFFERENT module (e.g.
     * `App\Domain\Fees\Application\ChargeService::cancel()`, the first
     * such caller) never needs to read `JournalEntry` (Finance's own
     * Eloquent model) directly to obtain one (CLAUDE.md rule 4 -- "a
     * module calls another module's Application-layer service ...
     * never reads another module's Eloquent models or tables
     * directly"). Mirrors `App\Domain\Finance\Application\LedgerAdministrationService::reverse()`'s
     * exact resolution shape (School-scoped `find()`, uniform
     * not-found error for both "does not exist" and "exists in another
     * School"), but WITHOUT that class's `finance.ledger.reverse`
     * capability check -- this method is for the SAME kind of trusted,
     * no-human-capability-impersonation internal caller `LedgerService::post()`/
     * `reverse()` themselves already serve; the calling module owns
     * its OWN authorization (e.g. `finance.charges.manage`) before
     * ever reaching here. Delegates to `reverse()` for every actual
     * reversal invariant -- no duplicated logic.
     */
    public function reverseById(School $school, string $journalEntryId, ?User $actor = null, ?string $reason = null): JournalEntryResult
    {
        $original = $this->context->withSchool(
            $school,
            fn () => JournalEntry::query()->where('school_id', $school->id)->find($journalEntryId),
        );

        if ($original === null) {
            throw new JournalEntryNotFoundException($journalEntryId);
        }

        return $this->reverse($original, $actor, $reason);
    }

    /**
     * Phase 0O.11A: a trusted lookup (no capability check -- the caller
     * authorizes, exactly like `post()`) of the School's ACTIVE ledger
     * accounts of one ADR 0030 type, ordered by code -- e.g. the `asset`
     * accounts a manually recorded payment may be received into.
     *
     * @return Collection<int, LedgerAccountSummary>
     */
    public function activeAccountsOfType(School $school, string $type): Collection
    {
        return $this->context->withSchool($school, fn () => LedgerAccount::query()
            ->where('school_id', $school->id)
            ->where('type', $type)
            ->where('status', 'active')
            ->where('currency', self::SUPPORTED_CURRENCY)
            ->orderBy('code')
            ->orderBy('id')
            ->get()
            ->map(fn (LedgerAccount $account) => LedgerAccountSummary::fromModel($account))
            ->values());
    }

    /**
     * @return Collection<string, LedgerAccount>
     */
    private function resolveAccounts(array $lines): Collection
    {
        $ids = collect($lines)->pluck('ledgerAccountId')->unique()->values();

        // One bounded query regardless of line count -- SchoolScope
        // (via the already-active TenantContext) already restricts
        // this to the trusted School's own accounts, so a cross-School
        // id is simply absent from the result, identical to a
        // nonexistent one (rule 38's "no oracle" requirement).
        $accounts = LedgerAccount::query()->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $id) {
            if (! $accounts->has($id)) {
                throw new LedgerAccountNotFoundException($id);
            }
        }

        return $accounts;
    }

    private function assertValidShape(PostJournalEntryData $data): void
    {
        if (count($data->lines) < 2) {
            throw new InvalidJournalEntryException('A journal entry must have at least two lines.');
        }

        if (trim($data->description) === '' || mb_strlen($data->description) > self::MAX_DESCRIPTION_LENGTH) {
            throw new InvalidJournalEntryException(
                'Journal entry description must be non-empty and at most '.self::MAX_DESCRIPTION_LENGTH.' characters.'
            );
        }

        // Mirrors journal_entries_currency_inr_only_check /
        // ledger_accounts_currency_inr_only_check directly -- Phase 0G
        // is INR-only (docs/modules/FINANCE.md "Currency scope"), not
        // merely single-currency-per-entry. A clean Application error
        // here, the database CHECK remains authoritative regardless.
        if ($data->currency !== self::SUPPORTED_CURRENCY) {
            throw new InvalidJournalCurrencyException(
                "Unsupported currency '{$data->currency}': Phase 0G supports only ".self::SUPPORTED_CURRENCY.'.'
            );
        }

        foreach ($data->lines as $line) {
            if ($line->amount->currency() !== $data->currency) {
                throw new InvalidJournalCurrencyException(
                    "Journal line currency '{$line->amount->currency()}' does not match the entry's currency '{$data->currency}'."
                );
            }

            $this->assertLineAmountFitsLedgerSchema($line->amount);
        }
    }

    /**
     * journal_lines.debit_amount/credit_amount are NUMERIC(14,2) --
     * catching an amount that would silently be ROUNDED by PostgreSQL
     * (more than 2 decimal places) or would overflow the 12-digit
     * integer part, as a clean Application error, before any write is
     * attempted. Amounts must also be strictly positive: zero is
     * rejected (journal_lines_exactly_one_side_check already requires
     * this at the database level; this is the earlier, cleaner error),
     * negative is rejected (a negative amount would be a sign-based
     * side encoding, exactly what ADR 0030's debit_amount/credit_amount
     * shape was chosen to eliminate).
     */
    private function assertLineAmountFitsLedgerSchema(Money $amount): void
    {
        if (! $amount->isPositive()) {
            throw new InvalidJournalEntryException(
                "Journal line amount must be strictly positive, got '{$amount->amount()}'."
            );
        }

        if (! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $amount->amount())) {
            throw new InvalidJournalEntryException(
                "Journal line amount '{$amount->amount()}' does not fit journal_lines' NUMERIC(14,2) column ".
                '(at most 12 integer digits and 2 decimal places).'
            );
        }
    }

    /**
     * Exact Money arithmetic (bcmath, never float) -- total debit must
     * equal total credit. "Total > 0" (rule 15) is not checked
     * separately: it is already structurally implied by "at least two
     * lines" (assertValidShape) plus "every line amount is strictly
     * positive" (assertLineAmountFitsLedgerSchema) plus this equality
     * check -- if debits == credits and at least one line exists on
     * either side with a positive amount, both totals are necessarily
     * positive, never zero. Adding a redundant explicit isZero() check
     * here would be unreachable dead code given those three
     * preconditions, so it is deliberately omitted.
     */
    private function assertBalanced(PostJournalEntryData $data): void
    {
        $currency = $data->currency;
        $totalDebit = Money::of('0', $currency);
        $totalCredit = Money::of('0', $currency);

        foreach ($data->lines as $line) {
            if ($line->side === JournalSide::Debit) {
                $totalDebit = $totalDebit->add($line->amount);
            } else {
                $totalCredit = $totalCredit->add($line->amount);
            }
        }

        if (! $totalDebit->equals($totalCredit)) {
            throw new UnbalancedJournalEntryException($totalDebit->amount(), $totalCredit->amount(), $currency);
        }
    }
}
