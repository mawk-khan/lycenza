<?php

namespace App\Domain\Payroll\Statutory\Calculation;

/**
 * Checkpoint 9.6B/9.6A (ADR 0036 correction addendum §1.8) --
 * effective-dated ESI rule constants. `wageThreshold` gates coverage
 * determination at contribution-period start (never mid-period, per
 * the continuity rule -- see `EsiCoverageDeterminationService`);
 * `averageDailyWageExemptionThreshold` gates the EMPLOYEE contribution
 * only -- the employer share is never exempted by it.
 */
final class EsiRuleVersion
{
    public function __construct(
        public readonly string $effectiveFrom,
        public readonly string $employeeContributionRate,
        public readonly string $employerContributionRate,
        public readonly string $wageThreshold,
        public readonly string $averageDailyWageExemptionThreshold,
    ) {}

    public static function effectiveApril2026(): self
    {
        return new self(
            effectiveFrom: '2026-04-01',
            employeeContributionRate: '0.0075',
            employerContributionRate: '0.0325',
            wageThreshold: '21000.00',
            averageDailyWageExemptionThreshold: '176.00',
        );
    }
}
