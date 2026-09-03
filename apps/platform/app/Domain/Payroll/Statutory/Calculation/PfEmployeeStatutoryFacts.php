<?php

namespace App\Domain\Payroll\Statutory\Calculation;

/**
 * Checkpoint 9.6B (ADR 0036 correction addendum §1.1) -- the four
 * independent PF facts an EmploymentRecord carries, never inferred
 * from one another. Persisted as `employee_pf_status` in Checkpoint
 * 9.6C; this DTO is the pure-calculation-layer shape
 * `PfCalculationService` actually consumes.
 */
final class PfEmployeeStatutoryFacts
{
    public function __construct(
        /**
         * True if this EmploymentRecord already holds PF membership
         * (from this or a prior employment) before this calculation.
         * Never inferred from `hasUan` -- an employee can hold a UAN
         * from a prior employer without ever having contributed.
         */
        public readonly bool $hasExistingPfMembership,
        public readonly bool $hasUan,
        /**
         * An explicit, separately-approved Para 26(6)/higher-wage
         * participation decision -- never implied by `hasUan` or by
         * `hasExistingPfMembership` alone.
         */
        public readonly bool $hasApprovedHigherWageContribution,
        /**
         * EPS eligibility -- false for an employee who joined EPF
         * after the EPS membership cutoff at a wage that never
         * qualified them for EPS, or one who separately holds
         * higher-pension status making standard-EPS math inapplicable.
         * Never inferred from `hasUan` or from PF membership alone.
         */
        public readonly bool $isEpsEligible,
    ) {}
}
