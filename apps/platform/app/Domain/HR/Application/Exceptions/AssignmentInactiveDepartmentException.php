<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * Thrown when App\Domain\HR\Application\EmployeeAssignmentService::create()
 * is asked to create a NEW Assignment against a Department whose
 * `status` is not `active`. Only applies at creation time -- an
 * existing historical Assignment continues referencing its Department
 * unaffected if that Department is later archived (docs/modules/HR.md
 * rule 73's reference-entity deactivation pattern: archive is not
 * delete, and archiving must never retroactively invalidate history).
 */
class AssignmentInactiveDepartmentException extends HrException
{
    public function __construct(public readonly string $departmentId)
    {
        parent::__construct(422, 'HR_ASSIGNMENT_INACTIVE_DEPARTMENT', "Department {$departmentId} is not active and cannot be used for a new Assignment.");
    }
}
