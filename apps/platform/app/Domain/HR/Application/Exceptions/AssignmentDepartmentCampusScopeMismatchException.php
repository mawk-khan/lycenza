<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * Thrown when App\Domain\HR\Application\EmployeeAssignmentService::create()
 * is asked to combine a Campus-scoped Department (`hr_departments.campus_id`
 * non-null) with an Assignment `campus_id` that doesn't match it --
 * e.g. a Department scoped to North Campus cannot be used for an
 * Assignment at South Campus, even though both belong to the same
 * School. A School-wide Department (`campus_id IS NULL`) is compatible
 * with any Assignment Campus, so this is never thrown for one. A plain
 * foreign key cannot express this -- it depends on comparing against a
 * property of a different, mutable row -- so it is validated here at
 * the application layer (docs/modules/HR.md's "Database constraints"
 * table only database-constrains the FK identity checks, not this
 * cross-row compatibility rule). Both ids compared here have already
 * been verified same-School by the caller before this exception can
 * fire, so -- unlike the *Mismatch exceptions -- there is no
 * cross-tenant oracle concern in surfacing them.
 */
class AssignmentDepartmentCampusScopeMismatchException extends HrException
{
    public function __construct(
        public readonly string $departmentId,
        public readonly string $departmentCampusId,
        public readonly ?string $assignmentCampusId,
    ) {
        $given = $assignmentCampusId ?? 'null (School-wide)';
        parent::__construct(422, 'HR_ASSIGNMENT_DEPARTMENT_CAMPUS_SCOPE_MISMATCH', "Department {$departmentId} is scoped to Campus {$departmentCampusId}, but the Assignment specifies Campus {$given}.");
    }
}
