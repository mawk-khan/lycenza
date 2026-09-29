<?php

namespace App\Domain\Fees\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * FEE.1 (ADR 0062 §7.3): one row of a line's authoritative billing
 * schedule. `billing_period_key` is the FEE.2 idempotency period (§11).
 * Writable only while the structure is a draft (database-enforced).
 *
 * @property string $id
 * @property string $school_id
 * @property string $fee_structure_line_id
 * @property int $sequence
 * @property string $label
 * @property string $billing_period_key
 * @property Carbon $period_starts_on
 * @property Carbon $period_ends_on
 * @property Carbon $due_date
 * @property string|null $academic_term_id
 * @property string $amount
 * @property string $currency
 */
class FeeStructureInstallment extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'fee_structure_installments';

    protected $fillable = [
        'school_id', 'fee_structure_line_id', 'sequence', 'label', 'billing_period_key',
        'period_starts_on', 'period_ends_on', 'due_date', 'academic_term_id', 'amount', 'currency',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'period_starts_on' => 'date',
            'period_ends_on' => 'date',
            'due_date' => 'date',
        ];
    }
}
