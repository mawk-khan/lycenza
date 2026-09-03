<?php

namespace App\Domain\Payroll\Statutory\Application\Exceptions;

/**
 * Checkpoint 9.6F -- raised when `StatutoryPayrollPostingService::post()`
 * is asked to post a run with no `payroll_statutory_calculation_results`
 * rows at all -- statutory calculation must run first.
 */
class StatutoryCalculationMissingException extends StatutoryPayrollException
{
    public function __construct(string $runId)
    {
        parent::__construct(422, 'STATUTORY_CALCULATION_MISSING', "Payroll run '{$runId}' has no statutory calculation results -- run statutory calculation before posting.");
    }
}
