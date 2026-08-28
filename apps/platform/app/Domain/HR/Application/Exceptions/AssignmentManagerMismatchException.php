<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * Thrown when App\Domain\HR\Application\ReportingHierarchyService::setManager()
 * is asked to use a manager Assignment that does not belong to the
 * same School as the subordinate Assignment. Same reasoning as
 * AssignmentCampusMismatchException, for the self-referencing
 * (manager_assignment_id, school_id) -> employee_assignments(id,
 * school_id) composite FK, including the Phase 8A closure correction's
 * cross-tenant-oracle message redaction -- see that class's docblock.
 */
class AssignmentManagerMismatchException extends HrException
{
    public function __construct(
        public readonly string $managerAssignmentId,
        public readonly string $expectedSchoolId,
        public readonly string $actualSchoolId,
    ) {
        parent::__construct(422, 'HR_ASSIGNMENT_MANAGER_MISMATCH', "Assignment {$managerAssignmentId} does not belong to the expected School.");
    }
}
