<?php

namespace App\Domain\Payroll\Statutory\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Checkpoint 9.6C -- national reference data (Income-tax Act, 2025). */
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
