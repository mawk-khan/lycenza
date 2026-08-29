<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * Phase 9.7 -- raised uniformly whether a caller-supplied compensation
 * assignment id genuinely does not exist OR exists only in a different
 * School, mirroring `PayrollRunNotFoundException`'s identical "no
 * oracle" precedent. `PayrollCompensationReadService::getAssignmentValues()`
 * always resolves the id through a School-scoped query.
 */
class CompensationAssignmentNotFoundException extends PayrollException
{
    public function __construct(public readonly string $assignmentId)
    {
        parent::__construct(404, 'PAYROLL_COMPENSATION_ASSIGNMENT_NOT_FOUND', "No compensation assignment with id '{$assignmentId}' was found in this School.");
    }
}
