<?php

namespace App\Domain\Payroll\Statutory\Infrastructure;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Checkpoint 9.6C -- one row per EmploymentRecord per fiscal year.
 *
 * @property string $id
 * @property string $school_id
 * @property string $employment_record_id
 * @property Carbon $fiscal_year_start
 * @property string $regime old|new
 * @property string|null $regime_switch_policy_reference
 * @property string $previous_employer_income
 * @property string $previous_employer_tds
 * @property string $declared_other_income
 * @property string $declared_deductions
 */
class EmployeeTaxProfile extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'employee_tax_profile';

    protected $fillable = [
        'school_id', 'employment_record_id', 'fiscal_year_start', 'regime',
        'regime_switch_policy_reference', 'previous_employer_income',
        'previous_employer_tds', 'declared_other_income', 'declared_deductions',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year_start' => 'date',
        ];
    }

    /** @return BelongsTo<EmploymentRecord, $this> */
    public function employmentRecord(): BelongsTo
    {
        return $this->belongsTo(EmploymentRecord::class);
    }
}
