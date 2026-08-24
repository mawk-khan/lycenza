<?php

namespace App\Domain\HR\Application\Exceptions;

use RuntimeException;

/**
 * Thrown when App\Domain\HR\Application\EmployeeAssignmentService::create()
 * is asked to use a Position that does not belong to the same School
 * as the owning EmploymentRecord. Same reasoning as
 * AssignmentCampusMismatchException.
 */
class AssignmentPositionMismatchException extends RuntimeException
{
    public function __construct(
        public readonly string $positionId,
        public readonly string $expectedSchoolId,
        public readonly string $actualSchoolId,
    ) {
        parent::__construct("Position {$positionId} belongs to School {$actualSchoolId}, not the expected School {$expectedSchoolId}.");
    }
}
