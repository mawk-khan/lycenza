<?php

namespace App\Domain\Payroll\Statutory\Infrastructure;

use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\User;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Checkpoint 9.6F -- append-only link between a `PayrollRun` and the
 * SEPARATE statutory `JournalEntry` `StatutoryPayrollPostingService`
 * posts for it. Mirrors `App\Domain\Payroll\Infrastructure\PayrollRunPosting`
 * exactly.
 *
 * @property string $id
 * @property string $school_id
 * @property string $payroll_run_id
 * @property string $journal_entry_id
 * @property string $currency
 * @property string $posting_kind original|reversal
 * @property string|null $reversal_of_posting_id
 * @property string $actor_user_id
 * @property string|null $reason
 */
class PayrollStatutoryRunPosting extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $fillable = [
        'school_id',
        'payroll_run_id',
        'journal_entry_id',
        'currency',
        'posting_kind',
        'reversal_of_posting_id',
        'actor_user_id',
        'reason',
    ];

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
        return $this->belongsTo(self::class, 'reversal_of_posting_id');
    }

    /** @return HasOne<self, $this> */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_posting_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
