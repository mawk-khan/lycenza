<?php

namespace App\Domain\Payroll\Statutory\Infrastructure;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Checkpoint 9.6C (ADR 0036 correction addendum §1.1) -- the four
 * independent PF facts. Never derive one from another anywhere this
 * model is consumed.
 *
 * @property string $id
 * @property string $school_id
 * @property string $employment_record_id
 * @property bool $has_existing_pf_membership
 * @property bool $has_uan
 * @property bool $has_approved_higher_wage_contribution
 * @property string|null $higher_wage_approval_reference
 * @property Carbon|null $higher_wage_approval_effective_from
 * @property bool $is_eps_eligible
 * @property bool $has_higher_pension_status
 */
class EmployeePfStatus extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'employee_pf_status';

    protected $fillable = [
        'school_id', 'employment_record_id', 'has_existing_pf_membership',
        'has_uan', 'has_approved_higher_wage_contribution',
        'higher_wage_approval_reference', 'higher_wage_approval_effective_from',
        'is_eps_eligible', 'has_higher_pension_status',
    ];

    protected function casts(): array
    {
        return [
            'has_existing_pf_membership' => 'boolean',
            'has_uan' => 'boolean',
            'has_approved_higher_wage_contribution' => 'boolean',
            'higher_wage_approval_effective_from' => 'date',
            'is_eps_eligible' => 'boolean',
            'has_higher_pension_status' => 'boolean',
        ];
    }

    /** @return BelongsTo<EmploymentRecord, $this> */
    public function employmentRecord(): BelongsTo
    {
        return $this->belongsTo(EmploymentRecord::class);
    }
}
