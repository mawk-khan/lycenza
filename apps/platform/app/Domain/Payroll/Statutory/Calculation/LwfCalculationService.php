<?php

namespace App\Domain\Payroll\Statutory\Calculation;

use App\Support\Money\Money;

/**
 * Checkpoint 9.6D implements this against Checkpoint 9.6B's LWF
 * fixtures.
 */
class LwfCalculationService
{
    /**
     * shouldCharge = isEligibleCategory AND NOT alreadyChargedThisCycle.
     * `alreadyChargedThisCycle` is an already-resolved fact (from a
     * per-School, per-annual-cycle charge record Checkpoint 9.6C
     * adds) -- this service never charges a second time in the same
     * cycle and never charges a legally-excluded employee category.
     */
    public function calculate(bool $isEligibleCategory, bool $alreadyChargedThisCycle, LabourWelfareFundRuleVersion $rule): LwfCalculationResult
    {
        $shouldCharge = $isEligibleCategory && ! $alreadyChargedThisCycle;

        return new LwfCalculationResult(
            shouldCharge: $shouldCharge,
            employeeAmount: Money::of($shouldCharge ? $rule->employeeAmount : '0.00', 'INR'),
            employerAmount: Money::of($shouldCharge ? $rule->employerAmount : '0.00', 'INR'),
        );
    }
}
