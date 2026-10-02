<?php

namespace App\Domain\Payments\Infrastructure;

use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * E21.3A (ADR 0064 §5): one charge's state as of a closed financial
 * period, written by `ChargePeriodStateParticipant` inside the close and
 * append-only afterwards (database-enforced). Payments owns it because
 * Payments owns the outstanding computation (allocations + Fees'
 * adjustments).
 *
 * @property string $id
 * @property string $school_id
 * @property string $financial_period_id
 * @property string $charge_id
 * @property string $currency
 * @property string $amount
 * @property string $allocated_total
 * @property string $live_adjusted_total
 * @property bool $cancelled
 */
class FinancialPeriodChargeState extends Model
{
    use BelongsToSchool;

    protected $table = 'financial_period_charge_states';

    protected $keyType = 'string';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['cancelled' => 'boolean'];
    }
}
