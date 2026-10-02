<?php

namespace App\Domain\Finance\Infrastructure;

use App\Models\School;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\JournalEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Phase 0G.1 (ADR 0030): one posted financial fact's header. Append-
 * only from its first migration onward -- REVOKE UPDATE, DELETE at the
 * database-privilege level (TenantRls::makeAppendOnly(), see the
 * migration), not just application-code discipline. `UPDATED_AT =
 * null` because there is genuinely no legitimate mutation path for
 * this row after creation (rule 33) -- Laravel still manages
 * `created_at` automatically.
 *
 * No `status`/draft field -- existence means posted (ADR 0030
 * Decision #1). No posting/reversal BEHAVIOR here -- 0G.2's Posting &
 * Reversal Application Services own the transactional "insert header
 * + balanced lines" operation; this model only exposes the structural
 * relationships the schema already guarantees.
 *
 * @property string $id
 * @property string $school_id
 * @property string $currency
 * @property string $description
 * @property Carbon $posted_at
 * @property string|null $reversal_of_journal_entry_id
 * @property string|null $financial_period_id E21.3A: assigned by the database on INSERT (ADR 0064); NULL only for entries posted before it, until backfilled
 * @property string $posting_txid Internal PostgreSQL transaction-identity
 *                                metadata, NOT a Finance business attribute. Unconditionally assigned/
 *                                overwritten by a BEFORE INSERT database trigger
 *                                (finance_set_journal_entry_posting_txid(), see the posting-invariants
 *                                migration) on every insert -- never trustworthy from a caller-supplied
 *                                value, and never application-set. Backs the post-commit line-set
 *                                immutability trigger on journal_lines. Deliberately excluded from
 *                                $fillable and from any future Finance DTO/API contract.
 * @property-read string|null $reversed_by_journal_entry_id NOT a persisted column -- only
 *                                populated when a query explicitly projects it (Phase 0G.3's
 *                                `App\Domain\Finance\Application\LedgerReadService`'s correlated
 *                                scalar subquery). Absent/undefined on a plain `JournalEntry::find()`.
 */
class JournalEntry extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'journal_entries';

    const UPDATED_AT = null;

    protected $fillable = ['school_id', 'currency', 'description', 'posted_at', 'reversal_of_journal_entry_id'];

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
        ];
    }

    /**
     * E21.3A (ADR 0064): every entry is dated by the application clock, and
     * its financial period exists before the INSERT. The database trigger
     * then assigns and checks the period; it never creates one.
     */
    protected static function booted(): void
    {
        static::creating(function (JournalEntry $entry): void {
            if ($entry->getAttribute('posted_at') === null) {
                $entry->posted_at = now();
            }
            $school = School::query()->find($entry->school_id);
            if ($school !== null) {
                FinancialPeriod::ensureContaining($school->id, $school->timezone ?: 'UTC', $entry->posted_at);
            }
        });
    }

    protected static function newFactory(): JournalEntryFactory
    {
        return JournalEntryFactory::new();
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /** The original entry this one reverses, if any. */
    /** @return BelongsTo<JournalEntry, $this> */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_journal_entry_id');
    }

    /** The entry that reverses this one, if any (ADR 0030: at most one). */
    /** @return HasOne<JournalEntry, $this> */
    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_journal_entry_id');
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_journal_entry_id !== null;
    }
}
