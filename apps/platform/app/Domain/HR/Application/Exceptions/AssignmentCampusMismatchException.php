<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * Thrown when App\Domain\HR\Application\EmployeeAssignmentService::create()
 * is asked to scope an Assignment to a Campus that does not belong to
 * the same School as the owning EmploymentRecord -- mirrors
 * App\Domain\HR\Application\Exceptions\DepartmentCampusMismatchException's
 * exact reasoning, applied here before the composite FK (campus_id,
 * school_id) -> campuses(id, school_id) would otherwise reject the
 * write with a raw QueryException.
 *
 * Phase 8A closure correction (item 3): this exception's message
 * deliberately does NOT include `$actualSchoolId` -- now that mutation
 * endpoints exist and a caller-supplied `campus_id` in a request body
 * can reference an id belonging to ANY School, echoing back which
 * School genuinely owns it would be a cross-tenant existence oracle
 * (the exact thing `EmployeeCategoryNotFoundException`/
 * `EmployeeProfileController`'s identical-404 pattern/rule 28 already
 * guard against everywhere else). `actualSchoolId` remains available as
 * a public property for tests/internal diagnostics, just never rendered
 * into the HTTP-facing message.
 */
class AssignmentCampusMismatchException extends HrException
{
    public function __construct(
        public readonly string $campusId,
        public readonly string $expectedSchoolId,
        public readonly string $actualSchoolId,
    ) {
        parent::__construct(422, 'HR_ASSIGNMENT_CAMPUS_MISMATCH', "Campus {$campusId} does not belong to the expected School.");
    }
}
