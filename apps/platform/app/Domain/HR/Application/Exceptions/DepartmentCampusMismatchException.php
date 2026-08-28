<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * Thrown when App\Domain\HR\Application\DepartmentService is asked to
 * scope a Department to a Campus that does not belong to the same
 * School -- mirrors App\Support\Tenancy\CampusSchoolMismatchException's
 * exact reasoning, applied here so the caller gets a clean domain
 * exception before the composite FK (campus_id, school_id) ->
 * campuses(id, school_id) would otherwise reject the write with a raw
 * QueryException.
 *
 * Phase 8A closure correction (item 3): message redacted of
 * `$actualSchoolId` for the same cross-tenant-existence-oracle reason
 * as AssignmentCampusMismatchException -- see that class's docblock.
 */
class DepartmentCampusMismatchException extends HrException
{
    public function __construct(
        public readonly string $campusId,
        public readonly string $expectedSchoolId,
        public readonly string $actualSchoolId,
    ) {
        parent::__construct(422, 'HR_DEPARTMENT_CAMPUS_MISMATCH', "Campus {$campusId} does not belong to the expected School.");
    }
}
