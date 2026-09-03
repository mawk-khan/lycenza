<?php

namespace App\Domain\Payroll\Statutory\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Model;

/** Checkpoint 9.6C -- PLATFORM reference data, mirroring `PayrollPfRuleVersion`. */
class PayrollEsiRuleVersion extends Model
{
    use GeneratesUuidV7;

    protected $fillable = [
        'effective_from', 'effective_to', 'status', 'legal_reference',
        'employee_contribution_rate', 'employer_contribution_rate',
        'wage_threshold', 'average_daily_wage_exemption_threshold',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }
}
