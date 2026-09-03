<?php

namespace App\Domain\Payroll\Statutory\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Checkpoint 9.6C -- national reference data (Income-tax Act, 2025).
 *
 * @property string $id
 * @property string $regime old|new
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property string $status
 * @property string $legal_reference
 * @property string $standard_deduction
 * @property string $rebate_qualifying_income_threshold
 * @property string $rebate_maximum
 * @property string $cess_rate
 */
class PayrollIncomeTaxRuleVersion extends Model
{
    use GeneratesUuidV7;

    protected $fillable = [
        'regime', 'effective_from', 'effective_to', 'status', 'legal_reference',
        'standard_deduction', 'rebate_qualifying_income_threshold', 'rebate_maximum', 'cess_rate',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /** @return HasMany<PayrollIncomeTaxRuleSlab, $this> */
    public function incomeSlabs(): HasMany
    {
        return $this->hasMany(PayrollIncomeTaxRuleSlab::class, 'rule_version_id')->where('slab_kind', 'income')->orderBy('sort_order');
    }

    /** @return HasMany<PayrollIncomeTaxRuleSlab, $this> */
    public function surchargeSlabs(): HasMany
    {
        return $this->hasMany(PayrollIncomeTaxRuleSlab::class, 'rule_version_id')->where('slab_kind', 'surcharge')->orderBy('sort_order');
    }
}
