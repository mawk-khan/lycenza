<?php

namespace App\Domain\Payroll\Statutory\Calculation;

use App\Support\Money\Money;

/**
 * Checkpoint 9.6D implements this against Checkpoint 9.6B's ESI-03/
 * ESI-06/ESI-07/ESI-08/ESI-09/ESI-10/ESI-11 golden fixtures.
 */
class EsiContributionCalculationService
{
    /**
     * ADR 0035 correction addendum §1.8 -- the algorithm:
     *   1. if NOT coveredForThisPeriod: employee = employer = 0, done.
     *   2. employerContribution = ceil_to_next_rupee(statutoryWage * employerContributionRate)
     *      -- ALWAYS payable once covered, never exempted by the
     *      average-daily-wage rule.
     *   3. employeeContribution = (averageDailyWage !== null AND averageDailyWage <= averageDailyWageExemptionThreshold)
     *        ? 0
     *        : ceil_to_next_rupee(statutoryWage * employeeContributionRate)
     *   Rounding is UPWARD to the next whole rupee (ceiling), never
     *   half-up -- this is the one place statutory rounding in this
     *   checkpoint deliberately differs from PF's half-up whole-INR
     *   rule (ADR 0035 correction addendum §1.8's own "upward
     *   rounding" language), and must not reuse
     *   `Money::multiplyByRate()`'s half-up behavior unmodified.
     */
    public function calculate(EsiContributionInput $input, EsiRuleVersion $rule): EsiContributionResult
    {
        $currency = $input->statutoryWage->currency();
        $zero = Money::of('0.00', $currency);

        if (! $input->coveredForThisPeriod) {
            return new EsiContributionResult(isCovered: false, employeeContribution: $zero, employerContribution: $zero);
        }

        $employerContribution = $this->ceilToRupee($input->statutoryWage->multiplyByRate($rule->employerContributionRate, 6));

        $exemptionThreshold = Money::of($rule->averageDailyWageExemptionThreshold, $currency);
        $isExempt = $input->averageDailyWage !== null
            && ! $input->averageDailyWage->add($exemptionThreshold->negated())->isPositive();

        $employeeContribution = $isExempt
            ? $zero
            : $this->ceilToRupee($input->statutoryWage->multiplyByRate($rule->employeeContributionRate, 6));

        return new EsiContributionResult(isCovered: true, employeeContribution: $employeeContribution, employerContribution: $employerContribution);
    }

    /**
     * ADR 0035 correction addendum §1.8's "upward rounding" -- ceiling
     * to the next whole rupee, deliberately never
     * `Money::multiplyByRate()`'s own half-up behavior (see this
     * class's own docblock). `$raw` is expected at a fine decimal
     * scale (6) so the fractional-remainder check below is never
     * itself distorted by premature rounding.
     */
    private function ceilToRupee(Money $raw): Money
    {
        // bcadd(..., 0) TRUNCATES (never rounds) -- the floor, for
        // every non-negative statutory amount this class ever
        // computes. Deliberately NOT `multiplyByRate('1.00', 0)`,
        // which rounds half-up and would silently turn e.g. 142.60
        // into 143 before this method ever adds its own +1, double-
        // counting the fraction.
        $floor = bcadd($raw->amount(), '0', 0);
        $hasFraction = bccomp($raw->amount(), $floor, 10) !== 0;

        $wholeRupees = $hasFraction ? bcadd($floor, '1', 0) : $floor;

        return Money::of($wholeRupees.'.00', $raw->currency());
    }
}
