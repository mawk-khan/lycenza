<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * Thrown when App\Domain\HR\Application\EmployeeAssignmentService::create()
 * is asked to use a Position that does not belong to the same School
 * as the owning EmploymentRecord. Same reasoning as
 * AssignmentCampusMismatchException, including the Phase 8A closure
 * correction's cross-tenant-oracle message redaction -- see that
 * class's docblock.
 */
class AssignmentPositionMismatchException extends HrException
{
    public function __construct(
        public readonly string $positionId,
        public readonly string $expectedSchoolId,
        public readonly string $actualSchoolId,
    ) {
        parent::__construct(422, 'HR_ASSIGNMENT_POSITION_MISMATCH', "Position {$positionId} does not belong to the expected School.");
    }
}
