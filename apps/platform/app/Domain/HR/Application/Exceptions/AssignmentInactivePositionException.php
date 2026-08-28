<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * Thrown when App\Domain\HR\Application\EmployeeAssignmentService::create()
 * is asked to create a NEW Assignment against a Position whose
 * `status` is not `active`. Same "creation-time only" reasoning as
 * AssignmentInactiveDepartmentException.
 */
class AssignmentInactivePositionException extends HrException
{
    public function __construct(public readonly string $positionId)
    {
        parent::__construct(422, 'HR_ASSIGNMENT_INACTIVE_POSITION', "Position {$positionId} is not active and cannot be used for a new Assignment.");
    }
}
