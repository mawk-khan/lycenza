<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * Thrown when a genuine concurrent race loses against
 * `payroll_runs_one_regular_per_period`'s partial unique index -- a
 * second regular run for the same period. Mirrors
 * ConcurrentStructureActivationConflictException's identical role.
 */
class DuplicateRegularRunException extends PayrollException
{
    public function __construct(public readonly string $periodId)
    {
        parent::__construct(409, 'PAYROLL_DUPLICATE_REGULAR_RUN', "Payroll period {$periodId} already has a regular run.");
    }
}
