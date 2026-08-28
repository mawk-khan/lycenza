<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * Thrown when App\Domain\HR\Application\EmployeeAssignmentService::create()
 * is asked to scope an Assignment to a Department that does not belong
 * to the same School as the owning EmploymentRecord. Same reasoning as
 * AssignmentCampusMismatchException, including the Phase 8A closure
 * correction's cross-tenant-oracle message redaction -- see that
 * class's docblock.
 */
class AssignmentDepartmentMismatchException extends HrException
{
    public function __construct(
        public readonly string $departmentId,
        public readonly string $expectedSchoolId,
        public readonly string $actualSchoolId,
    ) {
        parent::__construct(422, 'HR_ASSIGNMENT_DEPARTMENT_MISMATCH', "Department {$departmentId} does not belong to the expected School.");
    }
}
