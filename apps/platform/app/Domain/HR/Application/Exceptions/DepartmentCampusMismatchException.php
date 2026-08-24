<?php

namespace App\Domain\HR\Application\Exceptions;

use RuntimeException;

/**
 * Thrown when App\Domain\HR\Application\DepartmentService is asked to
 * scope a Department to a Campus that does not belong to the same
 * School -- mirrors App\Support\Tenancy\CampusSchoolMismatchException's
 * exact reasoning, applied here so the caller gets a clean domain
 * exception before the composite FK (campus_id, school_id) ->
 * campuses(id, school_id) would otherwise reject the write with a raw
 * QueryException.
 */
class DepartmentCampusMismatchException extends RuntimeException
{
    public function __construct(
        public readonly string $campusId,
        public readonly string $expectedSchoolId,
        public readonly string $actualSchoolId,
    ) {
        parent::__construct("Campus {$campusId} belongs to School {$actualSchoolId}, not the expected School {$expectedSchoolId}.");
    }
}
