<?php

namespace App\Domain\Payroll\Infrastructure;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\User;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\PayrollAdjustmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 9.1 (ADR 0034 "Partial-period policy" / "Run kinds, correction
 * model, and posting") -- explicit, human-entered amounts in exactly
 * two distinguished modes: `manual_override` (the authoritative
 * absolute amount for a partial-period EmploymentRecord in a REGULAR
 * run) and `correction_delta` (a signed delta, `effect` carries
 * direction, belonging to a CORRECTION run). `mode` must match the
 * parent run's `run_kind` (database trigger). Append-only
 * (`TenantRls::makeAppendOnly()`).
 *
 * @property string $id
 * @property string $school_id
 * @property string $payroll_run_id
 * @property string $employment_record_id
 * @property string $salary_component_id
 * @property string $mode manual_override|correction_delta
 * @property string $amount
 * @property string|null $effect increase|decrease
 * @property string $reason
 * @property string $actor_user_id
 */
class PayrollAdjustment extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $fillable = [
        'school_id',
        'payroll_run_id',
        'employment_record_id',
        'salary_component_id',
        'mode',
        'amount',
        'effect',
        'reason',
        'actor_user_id',
    ];

    protected static function newFactory(): PayrollAdjustmentFactory
    {
        return PayrollAdjustmentFactory::new();
    }

    public function isManualOverride(): bool
    {
        return $this->mode === 'manual_override';
    }

    public function isCorrectionDelta(): bool
    {
        return $this->mode === 'correction_delta';
    }

    /** @return BelongsTo<PayrollRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /** @return BelongsTo<EmploymentRecord, $this> */
    public function employmentRecord(): BelongsTo
    {
        return $this->belongsTo(EmploymentRecord::class);
    }

    /** @return BelongsTo<SalaryComponent, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
