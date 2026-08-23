<?php

namespace App\Domain\HR\Application\Exceptions;

use RuntimeException;

/**
 * Thrown when App\Domain\HR\Application\DepartmentService is asked to
 * parent a Department under a parent Department that does not belong
 * to the same School -- same reasoning as
 * DepartmentCampusMismatchException, for the self-referencing
 * (parent_department_id, school_id) -> hr_departments(id, school_id)
 * composite FK.
 */
class DepartmentParentMismatchException extends RuntimeException
{
    public function __construct(
        public readonly string $parentDepartmentId,
        public readonly string $expectedSchoolId,
        public readonly string $actualSchoolId,
    ) {
        parent::__construct("Department {$parentDepartmentId} belongs to School {$actualSchoolId}, not the expected School {$expectedSchoolId}.");
    }
}
