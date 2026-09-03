<?php

namespace App\Domain\Payroll\Statutory\Calculation;

/**
 * Checkpoint 9.6B (ADR 0035 correction addendum §1.2) -- the
 * classification a Payroll salary component carries for the PF 50%
 * test, replacing any hardcoded "Basic + DA + HRA + Special +
 * Transport" field list. `PfCalculationInput` groups a run's
 * component amounts under these buckets; `PfCalculationService` never
 * inspects a component's NAME, only its classification.
 */
enum PfComponentClassification: string
{
    /**
     * Basic, DA, and Retaining Allowance where applicable -- always
     * counted in PF wage, never subject to the 50% test.
     */
    case CoreWage = 'core_wage';

    /**
     * A remuneration component (HRA, conveyance, special allowance,
     * etc.) subject to the 50% test: if the SUM of every
     * TestedRemuneration component exceeds 50% of total remuneration
     * (core + tested), the excess is added back into statutory PF
     * wage.
     */
    case TestedRemuneration = 'tested_remuneration';

    /**
     * Statutorily excluded from PF wage entirely -- e.g. overtime
     * allowance, a bonus, or a reimbursement. Never counted in PF
     * wage and never part of the 50%-test denominator, unlike
     * TestedRemuneration.
     */
    case ExcludedNonRemuneration = 'excluded_non_remuneration';
}
