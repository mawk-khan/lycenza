<?php

namespace App\Domain\Payroll\Statutory\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollProfessionalTaxRuleSlab extends Model
{
    use GeneratesUuidV7;

    protected $fillable = ['rule_version_id', 'upper_bound', 'amount', 'sort_order'];

    /** @return BelongsTo<PayrollProfessionalTaxRuleVersion, $this> */
    public function ruleVersion(): BelongsTo
    {
        return $this->belongsTo(PayrollProfessionalTaxRuleVersion::class, 'rule_version_id');
    }
}
