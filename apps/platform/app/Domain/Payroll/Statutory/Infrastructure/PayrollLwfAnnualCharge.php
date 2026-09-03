<?php

namespace App\Domain\Payroll\Statutory\Infrastructure;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Checkpoint 9.6C (ADR 0035 correction addendum §1.7) -- the
 * `unique(school_id, employment_record_id, annual_cycle_year)`
 * constraint on this table IS the structural "once per cycle"
 * guarantee; `LwfCalculationService`'s `alreadyChargedThisCycle` input
 * is built by checking for an existing row here.
 */
class PayrollLwfAnnualCharge extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $fillable = ['school_id', 'employment_record_id', 'annual_cycle_year', 'payroll_run_result_id'];

    /** @return BelongsTo<EmploymentRecord, $this> */
    public function employmentRecord(): BelongsTo
    {
        return $this->belongsTo(EmploymentRecord::class);
    }
}
