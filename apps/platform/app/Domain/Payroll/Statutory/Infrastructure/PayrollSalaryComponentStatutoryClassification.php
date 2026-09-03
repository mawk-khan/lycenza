<?php

namespace App\Domain\Payroll\Statutory\Infrastructure;

use App\Domain\Payroll\Infrastructure\SalaryComponent;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollSalaryComponentStatutoryClassification extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $fillable = [
        'school_id', 'salary_component_id', 'pf_classification',
        'esi_wage_included', 'income_tax_treatment', 'effective_from', 'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'esi_wage_included' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /** @return BelongsTo<SalaryComponent, $this> */
    public function salaryComponent(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class);
    }
}
