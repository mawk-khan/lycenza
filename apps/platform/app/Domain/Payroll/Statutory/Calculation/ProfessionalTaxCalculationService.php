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
        foreach ($rule->slabs as $slab) {
            if ($slab['upTo'] === null) {
                return Money::of($slab['amount'], $monthlyWage->currency());
            }

            $upperBound = Money::of($slab['upTo'], $monthlyWage->currency());
            if (! $monthlyWage->add($upperBound->negated())->isPositive()) {
                return Money::of($slab['amount'], $monthlyWage->currency());
            }
        }

        // Unreachable while $rule->slabs' final row has upTo: null,
        // but PHP requires a return on every path.
        return Money::of('0.00', $monthlyWage->currency());
    }
}
