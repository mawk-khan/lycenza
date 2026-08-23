<?php

namespace App\Domain\HR\Application\Exceptions;

use RuntimeException;

/**
 * Thrown when App\Domain\HR\Application\EmployeeAssignmentService::create()
 * is asked to scope an Assignment to a Campus that does not belong to
 * the same School as the owning EmploymentRecord -- mirrors
 * App\Domain\HR\Application\Exceptions\DepartmentCampusMismatchException's
 * exact reasoning, applied here before the composite FK (campus_id,
 * school_id) -> campuses(id, school_id) would otherwise reject the
 * write with a raw QueryException.
 */
class AssignmentCampusMismatchException extends RuntimeException
{
    public function __construct(
        public readonly string $campusId,
        public readonly string $expectedSchoolId,
        public readonly string $actualSchoolId,
    ) {
        parent::__construct("Campus {$campusId} belongs to School {$actualSchoolId}, not the expected School {$expectedSchoolId}.");
    }
}
