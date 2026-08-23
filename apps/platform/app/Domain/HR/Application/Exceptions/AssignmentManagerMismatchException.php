<?php

namespace App\Domain\HR\Application\Exceptions;

use RuntimeException;

/**
 * Thrown when App\Domain\HR\Application\ReportingHierarchyService::setManager()
 * is asked to use a manager Assignment that does not belong to the
 * same School as the subordinate Assignment. Same reasoning as
 * AssignmentCampusMismatchException, for the self-referencing
 * (manager_assignment_id, school_id) -> employee_assignments(id,
 * school_id) composite FK.
 */
class AssignmentManagerMismatchException extends RuntimeException
{
    public function __construct(
        public readonly string $managerAssignmentId,
        public readonly string $expectedSchoolId,
        public readonly string $actualSchoolId,
    ) {
        parent::__construct("Assignment {$managerAssignmentId} belongs to School {$actualSchoolId}, not the expected School {$expectedSchoolId}.");
    }
}
