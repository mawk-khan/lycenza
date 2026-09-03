<?php

namespace App\Domain\Payroll\Infrastructure;

use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Models\User;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\PayrollRunPostingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Phase 9.1 (ADR 0034 "Run kinds, correction model, and posting" /
 * "Reversal model") -- append-only link between a `PayrollRun` and the
 * Finance `JournalEntry` `LedgerService::post()`/`reverse()` produced
 * for it. `posting_kind` is `original`|`reversal`; at most one
 * `original` per run and one `reversal` per posting (partial unique
 * indexes). A run's own `status` never becomes `reversed` -- check
 * `PayrollRun::postings()->where('posting_kind', 'reversal')->exists()`
 * for the derived financial status instead.
 *
 * @property string $id
 * @property string $school_id
 * @property string $payroll_run_id
 * @property string $journal_entry_id
 * @property string $currency
 * @property string $posting_kind original|reversal
 * @property string|null $reversal_of_payroll_run_posting_id
 * @property string $actor_user_id
 * @property string|null $reason
 */
class PayrollRunPosting extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $fillable = [
        'school_id',
        'payroll_run_id',
        'journal_entry_id',
        'currency',
        'posting_kind',
        'reversal_of_payroll_run_posting_id',
        'actor_user_id',
        'reason',
    ];

    protected static function newFactory(): PayrollRunPostingFactory
    {
        return PayrollRunPostingFactory::new();
    }

    public function isOriginal(): bool
    {
        return $this->posting_kind === 'original';
    }

    public function isReversal(): bool
    {
        return $this->posting_kind === 'reversal';
    }

    /** @return BelongsTo<PayrollRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return BelongsTo<self, $this> */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_payroll_run_posting_id');
    }

    /** @return HasOne<self, $this> */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_payroll_run_posting_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
