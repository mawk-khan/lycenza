<?php

namespace App\Domain\Payroll\Statutory\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollIncomeTaxRuleSlab extends Model
{
    use GeneratesUuidV7;

    protected $fillable = ['rule_version_id', 'slab_kind', 'upper_bound', 'rate', 'sort_order'];

    /** @return BelongsTo<PayrollIncomeTaxRuleVersion, $this> */
    public function ruleVersion(): BelongsTo
    {
        return $this->belongsTo(PayrollIncomeTaxRuleVersion::class, 'rule_version_id');
    }
}
