<?php

namespace App\Domain\Payroll\Statutory\Calculation;

use App\Support\Money\Money;

/**
 * Checkpoint 9.6D implements this against Checkpoint 9.6B's TDS-01..
 * TDS-12/TDS-20 golden fixtures. Pure annual-tax-on-taxable-income
 * calculator -- no projection/spreading logic (that is
 * `TdsMonthlyDeductionService`'s job).
 */
class IncomeTaxSlabCalculator
{
    /**
     * ADR 0035 correction addendum §1.3/§1.5 -- the algorithm:
     *   1. slabTax = sum, across `rule.slabs`, of (portion of taxableIncome within that band) * band.rate.
     *   2. if taxableIncome <= rule.rebateQualifyingIncomeThreshold:
     *        rebate = min(slabTax, rule.rebateMaximum); taxAfterRebate = slabTax - rebate.
     *      else if slabTax > (taxableIncome - rule.rebateQualifyingIncomeThreshold):
     *        marginal relief: taxAfterRebate = taxableIncome - rule.rebateQualifyingIncomeThreshold.
     *      else:
     *        taxAfterRebate = slabTax (no rebate, no relief needed).
     *   3. surcharge: resolve the highest `rule.surchargeSlabs` row whose
     *      `threshold` < taxableIncome (none matching -> surcharge 0).
     *      rawSurcharge = taxAfterRebate * matchedSlab.rate.
     *      Marginal relief on surcharge: the INCREASE in
     *      (taxAfterRebate + surcharge) over (tax at exactly the
     *      matched threshold, computed with NO surcharge) must never
     *      exceed the increase in taxableIncome over that threshold --
     *      cap surcharge so this holds.
     *   4. cess = (taxAfterRebate + surcharge) * rule.cessRate.
     *   5. total = taxAfterRebate + surcharge + cess.
     *   Whole-rupee rounding (half-up) applies only at the FINAL total,
     *   never at intermediate slab/rebate/surcharge steps.
     */
    public function calculateAnnualTax(Money $taxableIncome, IncomeTaxRuleVersion $rule): Money
    {
        throw new \LogicException('IncomeTaxSlabCalculator::calculateAnnualTax() is implemented in Checkpoint 9.6D.');
    }
}
