<?php

namespace App\Domain\Payroll\Statutory\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Checkpoint 9.6C -- PLATFORM reference data (no `school_id`, no RLS),
 * the same shape as `education_boards` (rule 69): PF is national law,
 * identical for every School.
 *
 * @property string $id
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property string $status
 */
class PayrollPfRuleVersion extends Model
{
    use GeneratesUuidV7;

    protected $fillable = [
        'effective_from', 'effective_to', 'status', 'legal_reference',
        'employee_contribution_rate', 'employer_contribution_rate', 'eps_rate',
        'edli_rate', 'admin_charge_rate', 'membership_wage_ceiling',
        'eps_wage_ceiling', 'edli_wage_ceiling', 'admin_charge_minimum',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }
}
