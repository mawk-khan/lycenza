<?php

namespace App\Domain\Payroll\Statutory\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Model;

/** Checkpoint 9.6C -- state (jurisdiction) reference data, still PLATFORM-level (no `school_id`). */
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
