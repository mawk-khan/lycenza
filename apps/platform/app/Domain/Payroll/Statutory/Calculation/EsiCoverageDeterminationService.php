<?php

namespace App\Domain\Payroll\Statutory\Calculation;

use App\Support\Money\Money;

/**
 * Checkpoint 9.6D implements this against Checkpoint 9.6B's ESI-01/
 * ESI-02/ESI-04/ESI-05 golden fixtures. Determines coverage ONCE, at
 * contribution-period start, from the wage in force at that moment --
 * never re-evaluated mid-period (ADR 0035 correction addendum §1.8's
 * continuity rule; `EsiContributionCalculationService` below consumes
 * this decision as an already-resolved fact, it never re-derives it).
 */
class EsiCoverageDeterminationService
{
    /**
     * Covered iff wageAtPeriodStart <= wageThreshold (inclusive --
     * ESI-01's exact-₹21,000 case is covered).
     */
    public function determineCoverageAtPeriodStart(Money $wageAtPeriodStart, EsiRuleVersion $rule): bool
    {
        throw new \LogicException('EsiCoverageDeterminationService::determineCoverageAtPeriodStart() is implemented in Checkpoint 9.6D.');
    }
}
