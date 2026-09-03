<?php

namespace App\Domain\Payroll\Statutory\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Checkpoint 9.6C -- state (jurisdiction) reference data, still PLATFORM-level (no `school_id`).
 *
 * @property string $id
 * @property string $jurisdiction
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property string $status
 * @property string $legal_reference
 * @property string $employee_amount
 * @property string $employer_amount
 */
class PayrollLwfRuleVersion extends Model
{
    use GeneratesUuidV7;

    protected $fillable = [
        'jurisdiction', 'effective_from', 'effective_to', 'status',
        'legal_reference', 'employee_amount', 'employer_amount',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }
}
