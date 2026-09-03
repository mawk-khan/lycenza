<?php

namespace App\Domain\Payroll\Statutory\Calculation;

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
        throw new \LogicException('EsiContributionCalculationService::calculate() is implemented in Checkpoint 9.6D.');
    }
}
