<?php

namespace App\Domain\HR\Application\Exceptions;

use RuntimeException;

/**
 * Thrown when App\Domain\HR\Application\EmployeeAssignmentService::create()
 * is asked to scope an Assignment to a Department that does not belong
 * to the same School as the owning EmploymentRecord. Same reasoning as
 * AssignmentCampusMismatchException.
 */
class AssignmentDepartmentMismatchException extends RuntimeException
{
    public function __construct(
        public readonly string $departmentId,
        public readonly string $expectedSchoolId,
        public readonly string $actualSchoolId,
    ) {
        parent::__construct("Department {$departmentId} belongs to School {$actualSchoolId}, not the expected School {$expectedSchoolId}.");
    }
}
