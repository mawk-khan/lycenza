<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * Thrown when `SalaryStructureService::activate()` targets a revision
 * that is not (or is no longer) `draft` -- including the same-row race
 * a stale in-memory model can lose (someone else already activated or
 * superseded THIS exact row between read and write), mirroring
 * `AcademicYearService::activate()`'s conditional-UPDATE-affected-zero
 * handling.
 */
class InvalidStructureTransitionException extends PayrollException
{
    public function __construct(string $actualStatus, string $attemptedStatus)
    {
        parent::__construct(422, 'PAYROLL_INVALID_STRUCTURE_TRANSITION', "Cannot transition a salary structure from {$actualStatus} to {$attemptedStatus}.");
    }
}
