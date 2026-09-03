<?php

namespace App\Domain\Payroll\Statutory\Application\Exceptions;

/**
 * Checkpoint 9.6F -- statutory calculation must run BEFORE the parent
 * `payroll_runs` row reaches `approved` (the same instant
 * `trg_payroll_statutory_results_freeze` starts rejecting writes) --
 * mirrors `InvalidRunTransitionException`'s role, applied to the
 * statutory calculation entry point specifically.
 */
class StatutoryRunNotEligibleForCalculationException extends StatutoryPayrollException
{
    public function __construct(string $runId, string $actualStatus)
    {
        parent::__construct(
            422,
            'STATUTORY_RUN_NOT_ELIGIBLE_FOR_CALCULATION',
            "Payroll run '{$runId}' is '{$actualStatus}' -- statutory calculation requires the run to be 'draft' or 'calculated' (results are frozen once the run reaches 'approved').",
        );
    }
}
