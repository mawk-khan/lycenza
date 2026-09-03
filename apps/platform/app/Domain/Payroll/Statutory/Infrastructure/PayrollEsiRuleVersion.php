<?php

namespace App\Domain\Payroll\Statutory\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Checkpoint 9.6C -- PLATFORM reference data, mirroring `PayrollPfRuleVersion`.
 *
 * @property string $id
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property string $status
 * @property string $legal_reference
 * @property string $employee_contribution_rate
 * @property string $employer_contribution_rate
 * @property string $wage_threshold
 * @property string $average_daily_wage_exemption_threshold
 */
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
