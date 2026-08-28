<?php

namespace App\Domain\Finance\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Money\Money;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\JournalLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 0G.1 (ADR 0030): one debit or credit posting. Append-only, same
 * rationale as JournalEntry (see that model's docblock) -- REVOKE
 * UPDATE, DELETE at the database-privilege level, `UPDATED_AT = null`.
 *
 * Deliberately NOT casting `debit_amount`/`credit_amount` with
 * Laravel's built-in `decimal:2` cast -- that cast internally routes
 * the value through `sprintf('%.2F', ...)`, which coerces its argument
 * through PHP float, exactly the precision risk `ARCHITECTURE.md` §10
 * rule 1 (and CLAUDE.md rule 9) forbid for money. Left uncast, these
 * attributes are the raw PDO string PostgreSQL's `pgsql` driver already
 * returns for a NUMERIC column (never a float) -- `debit()`/`credit()`
 * below wrap that exact string into the committed Money value object
 * explicitly, with no cast magic (section 22) obscuring the
 * conversion.
 *
 * @property string $id
 * @property string $school_id
 * @property string $journal_entry_id
 * @property string $ledger_account_id
 * @property string $currency
 * @property string|null $debit_amount
 * @property string|null $credit_amount
 */
class JournalLine extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'journal_lines';

    const UPDATED_AT = null;

    protected $fillable = [
        'school_id', 'journal_entry_id', 'ledger_account_id', 'currency', 'debit_amount', 'credit_amount',
    ];

    protected static function newFactory(): JournalLineFactory
    {
        return JournalLineFactory::new();
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    public function isDebit(): bool
    {
        return $this->debit_amount !== null;
    }

    public function isCredit(): bool
    {
        return $this->credit_amount !== null;
    }

    public function debit(): ?Money
    {
        return $this->debit_amount !== null ? Money::of($this->debit_amount, $this->currency) : null;
    }

    public function credit(): ?Money
    {
        return $this->credit_amount !== null ? Money::of($this->credit_amount, $this->currency) : null;
    }
}
