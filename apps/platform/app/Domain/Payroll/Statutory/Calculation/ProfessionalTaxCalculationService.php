<?php

namespace App\Domain\Payroll\Statutory\Calculation;

use App\Support\Money\Money;

/**
 * Checkpoint 9.6D implements this against Checkpoint 9.6B's PT
 * fixtures. Pure slab lookup over `ProfessionalTaxRuleVersion::$slabs`
 * -- monthly wage <= slab's `upTo` (first matching row, ascending) ->
 * that row's `amount`; the final `upTo: null` row is the catch-all
 * above every prior boundary.
 */
class ProfessionalTaxCalculationService
{
    public function calculate(Money $monthlyWage, ProfessionalTaxRuleVersion $rule): Money
    {
        throw new \LogicException('ProfessionalTaxCalculationService::calculate() is implemented in Checkpoint 9.6D.');
    }
}
