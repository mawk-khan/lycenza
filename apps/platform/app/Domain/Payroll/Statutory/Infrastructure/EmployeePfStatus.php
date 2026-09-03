<?php

namespace App\Domain\Payroll\Statutory\Infrastructure;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Checkpoint 9.6C (ADR 0035 correction addendum §1.1) -- the four
 * independent PF facts. Never derive one from another anywhere this
 * model is consumed.
 */
class EmployeePfStatus extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $fillable = [
        'school_id', 'employment_record_id', 'has_existing_pf_membership',
        'has_uan', 'has_approved_higher_wage_contribution',
        'higher_wage_approval_reference', 'is_eps_eligible',
    ];

    protected function casts(): array
    {
        return [
            'has_existing_pf_membership' => 'boolean',
            'has_uan' => 'boolean',
            'has_approved_higher_wage_contribution' => 'boolean',
            'is_eps_eligible' => 'boolean',
        ];
    }

    /** @return BelongsTo<EmploymentRecord, $this> */
    public function employmentRecord(): BelongsTo
    {
        return $this->belongsTo(EmploymentRecord::class);
    }
}
