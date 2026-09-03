<?php

namespace App\Domain\Payroll\Statutory\Application\Exceptions;

/**
 * Checkpoint 9.6H -- `payroll_statutory_calculation_results.payroll_run_result_id`
 * carries a UNIQUE constraint (Checkpoint 9.6C); this is the clean,
 * typed rejection `StatutoryPayrollCalculationService::calculateForRun()`
 * raises when a concurrent caller already calculated this exact run
 * (the losing side of a real race, translated from
 * `Illuminate\Database\UniqueConstraintViolationException` exactly
 * like `ConcurrentActivationConflictException` does for AcademicYear
 * activation) -- never a raw database exception, and never a second,
 * duplicate set of statutory result rows.
 */
class StatutoryCalculationAlreadyPerformedException extends StatutoryPayrollException
{
    public function __construct(string $runId)
    {
        parent::__construct(409, 'STATUTORY_CALCULATION_ALREADY_PERFORMED', "Payroll run '{$runId}' has already been statutorily calculated by a concurrent request.");
    }
}
