<?php

namespace App\Domain\Payroll\Statutory\Calculation;

/**
 * Checkpoint 9.6B/9.6A (ADR 0036 correction addendum §1.1/§1.2) --
 * effective-dated PF rule constants, injected into
 * `PfCalculationService` rather than hardcoded there. A future legal
 * rate change becomes a new `PfRuleVersion` instance (persisted as a
 * `payroll_statutory_rule_versions` row, Checkpoint 9.6C) with a later
 * `effectiveFrom`, never an edit to this class or to history.
 *
 * All rates are plain decimal-string fractions (e.g. "0.1200" for
 * 12%), matching `Money::multiplyByRate()`'s own contract -- never a
 * float.
 */
final class PfRuleVersion
{
    public function __construct(
        public readonly string $effectiveFrom,
        public readonly string $employeeContributionRate,
        public readonly string $employerContributionRate,
        public readonly string $epsRate,
        public readonly string $edliRate,
        public readonly string $adminChargeRate,
        public readonly string $membershipWageCeiling,
        public readonly string $epsWageCeiling,
        public readonly string $edliWageCeiling,
        public readonly string $adminChargeMinimum,
    ) {}

    /**
     * The rule version in force from 1 April 2026 under
     * SCH/PAY/REG/2026-9.6 -- standard EPF/EPS/EDLI statutory rates,
     * unaffected by the correction addendum (which corrects the
     * MEMBERSHIP/wage-classification logic around these rates, not
     * the rates themselves).
     */
    public static function effectiveApril2026(): self
    {
        return new self(
            effectiveFrom: '2026-04-01',
            employeeContributionRate: '0.1200',
            employerContributionRate: '0.1200',
            epsRate: '0.0833',
            edliRate: '0.0050',
            adminChargeRate: '0.0050',
            membershipWageCeiling: '15000.00',
            epsWageCeiling: '15000.00',
            edliWageCeiling: '15000.00',
            adminChargeMinimum: '500.00',
        );
    }
}
