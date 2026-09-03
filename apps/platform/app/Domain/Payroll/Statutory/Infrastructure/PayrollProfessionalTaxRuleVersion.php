<?php

namespace App\Domain\Payroll\Statutory\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Checkpoint 9.6C -- state reference data; slabs are the child `PayrollProfessionalTaxRuleSlab` rows. */
class PayrollProfessionalTaxRuleVersion extends Model
{
    use GeneratesUuidV7;

    protected $fillable = ['jurisdiction', 'effective_from', 'effective_to', 'status', 'legal_reference'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /** @return HasMany<PayrollProfessionalTaxRuleSlab, $this> */
    public function slabs(): HasMany
    {
        return $this->hasMany(PayrollProfessionalTaxRuleSlab::class, 'rule_version_id')->orderBy('sort_order');
    }
}
