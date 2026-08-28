<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * Thrown when App\Domain\HR\Application\DepartmentService is asked to
 * parent a Department under a parent Department that does not belong
 * to the same School -- same reasoning as
 * DepartmentCampusMismatchException, for the self-referencing
 * (parent_department_id, school_id) -> hr_departments(id, school_id)
 * composite FK, including the Phase 8A closure correction's
 * cross-tenant-oracle message redaction -- see that class's docblock.
 */
class DepartmentParentMismatchException extends HrException
{
    public function __construct(
        public readonly string $parentDepartmentId,
        public readonly string $expectedSchoolId,
        public readonly string $actualSchoolId,
    ) {
        parent::__construct(422, 'HR_DEPARTMENT_PARENT_MISMATCH', "Department {$parentDepartmentId} does not belong to the expected School.");
    }
}
