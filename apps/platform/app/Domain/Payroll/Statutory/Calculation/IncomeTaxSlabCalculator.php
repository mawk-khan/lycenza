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
        $currency = $taxableIncome->currency();
        $slabTax = $this->slabTax($taxableIncome, $rule->slabs, $currency);
        $taxAfterRebate = $this->applyRebateOrMarginalRelief($taxableIncome, $slabTax, $rule, $currency);
        $withSurcharge = $this->applySurcharge($taxableIncome, $taxAfterRebate, $rule, $currency);

        $cess = $withSurcharge->multiplyByRate($rule->cessRate, 2);
        $total = $withSurcharge->add($cess);

        return $total->multiplyByRate('1.00', 0)->multiplyByRate('1.00', 2);
    }

    /**
     * @param  list<array{upTo: ?string, rate: string}>  $slabs
     */
    private function slabTax(Money $taxableIncome, array $slabs, string $currency): Money
    {
        $tax = Money::of('0.00', $currency);
        $lowerBound = Money::of('0.00', $currency);

        foreach ($slabs as $slab) {
            $upperBound = $slab['upTo'] === null ? $taxableIncome : Money::of($slab['upTo'], $currency);
            $bandTop = $this->min($upperBound, $taxableIncome);
            $portion = $bandTop->add($lowerBound->negated());

            if ($portion->isPositive()) {
                $tax = $tax->add($portion->multiplyByRate($slab['rate'], 2));
            }

            $lowerBound = $upperBound;

            if (! $taxableIncome->add($upperBound->negated())->isPositive()) {
                break;
            }
        }

        return $tax;
    }

    private function applyRebateOrMarginalRelief(Money $taxableIncome, Money $slabTax, IncomeTaxRuleVersion $rule, string $currency): Money
    {
        $rebateThreshold = Money::of($rule->rebateQualifyingIncomeThreshold, $currency);

        if (! $taxableIncome->add($rebateThreshold->negated())->isPositive()) {
            $rebateMax = Money::of($rule->rebateMaximum, $currency);
            $rebate = $this->min($slabTax, $rebateMax);

            return $slabTax->add($rebate->negated());
        }

        $incomeExcess = $taxableIncome->add($rebateThreshold->negated());
        if ($slabTax->add($incomeExcess->negated())->isPositive()) {
            return $incomeExcess;
        }

        return $slabTax;
    }

    private function applySurcharge(Money $taxableIncome, Money $taxAfterRebate, IncomeTaxRuleVersion $rule, string $currency): Money
    {
        $matched = null;
        foreach ($rule->surchargeSlabs as $slab) {
            $threshold = Money::of($slab['threshold'], $currency);
            if ($taxableIncome->add($threshold->negated())->isPositive()) {
                $matched = $slab;
            }
        }

        if ($matched === null) {
            return $taxAfterRebate;
        }

        $threshold = Money::of($matched['threshold'], $currency);
        $rawSurcharge = $taxAfterRebate->multiplyByRate($matched['rate'], 2);
        $uncappedTotal = $taxAfterRebate->add($rawSurcharge);

        // Tax at exactly the matched threshold, computed with NO
        // surcharge (surcharge applies only once income strictly
        // exceeds a threshold) -- the marginal-relief baseline.
        $taxAtThreshold = $this->applyRebateOrMarginalRelief($threshold, $this->slabTax($threshold, $rule->slabs, $currency), $rule, $currency);

        $incomeIncrease = $taxableIncome->add($threshold->negated());
        $uncappedIncrease = $uncappedTotal->add($taxAtThreshold->negated());

        if ($uncappedIncrease->add($incomeIncrease->negated())->isPositive()) {
            // Marginal relief caps the total increase at the income
            // increase.
            return $taxAtThreshold->add($incomeIncrease);
        }

        return $uncappedTotal;
    }

    private function min(Money $a, Money $b): Money
    {
        return $a->add($b->negated())->isPositive() ? $b : $a;
    }
}
