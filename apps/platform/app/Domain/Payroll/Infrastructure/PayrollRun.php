<?php

namespace App\Domain\Payroll\Infrastructure;

use App\Models\User;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\PayrollRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Phase 9.1 (ADR 0034 "Run kinds, correction model, and posting" /
 * "Separation of duties") -- one payroll calculation execution.
 * `approved` is the sole immutability boundary (no `finalized` state).
 * There is no `reversed` status -- reversal is derived from
 * `PayrollRunPosting` records. Never call `save()` with an ad-hoc
 * status change from outside `App\Domain\Payroll\Application` --
 * Checkpoint 9.4's `PayrollRunService` owns every transition, backed
 * by the database transition/SoD triggers as the real guarantee.
 *
 * @property string $id
 * @property string $school_id
 * @property string $payroll_period_id
 * @property string $run_kind regular|correction
 * @property string|null $corrects_payroll_run_id
 * @property string $status draft|calculated|approved|posted
 * @property string $prepared_by_user_id
 * @property string|null $approved_by_user_id
 * @property string|null $posted_by_user_id
 * @property Carbon|null $approved_at
 * @property Carbon|null $posted_at
 */
class PayrollRun extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $fillable = [
        'school_id',
        'payroll_period_id',
        'run_kind',
        'corrects_payroll_run_id',
        'status',
        'prepared_by_user_id',
        'approved_by_user_id',
        'posted_by_user_id',
        'approved_at',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    protected static function newFactory(): PayrollRunFactory
    {
        return PayrollRunFactory::new();
    }

    public function isRegular(): bool
    {
        return $this->run_kind === 'regular';
    }

    public function isCorrection(): bool
    {
        return $this->run_kind === 'correction';
    }

    public function isApprovedOrLater(): bool
    {
        return in_array($this->status, ['approved', 'posted'], true);
    }

    /** @return BelongsTo<PayrollPeriod, $this> */
    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    /** @return BelongsTo<self, $this> */
    public function correctsRun(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_payroll_run_id');
    }

    /** @return HasMany<self, $this> */
    public function corrections(): HasMany
    {
        return $this->hasMany(self::class, 'corrects_payroll_run_id');
    }

    /** @return BelongsTo<User, $this> */
    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    /** @return HasMany<PayrollRunResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(PayrollRunResult::class);
    }

    /** @return HasMany<PayrollAdjustment, $this> */
    public function adjustments(): HasMany
    {
        return $this->hasMany(PayrollAdjustment::class);
    }

    /** @return HasMany<PayrollRunPosting, $this> */
    public function postings(): HasMany
    {
        return $this->hasMany(PayrollRunPosting::class);
    }
}
