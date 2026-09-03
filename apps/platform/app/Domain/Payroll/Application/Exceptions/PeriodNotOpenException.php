<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * Thrown when `PayrollRunService::createRun()` targets a
 * `payroll_periods` row that is not `open` (still `draft`, or already
 * `closed`) -- a regular payroll run may only be created for a period
 * a School has explicitly opened for processing.
 */
class PeriodNotOpenException extends PayrollException
{
    public function __construct(public readonly string $periodId, public readonly string $actualStatus)
    {
        parent::__construct(422, 'PAYROLL_PERIOD_NOT_OPEN', "Payroll period {$periodId} is {$actualStatus}, not open.");
    }
}
