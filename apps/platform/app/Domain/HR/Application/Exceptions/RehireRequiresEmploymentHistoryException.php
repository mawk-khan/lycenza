<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * Thrown by App\Domain\HR\Application\EmployeeLifecycleService::rehire()
 * when the target Employee has no prior EmploymentRecord at all
 * (checkpoint 8A.13 section 26: "If there is no prior Employment
 * history, use ordinary first Employment creation rather than falsely
 * calling it rehire"). `rehire()` is an explicit lifecycle COMMAND, not
 * a synonym for `EmploymentService::create()` -- a first hire continues
 * to go through `EmploymentService::create()` directly, unchanged from
 * 8A.4.
 */
class RehireRequiresEmploymentHistoryException extends HrException
{
    public function __construct(public readonly string $employeeId)
    {
        parent::__construct(422, 'HR_REHIRE_REQUIRES_EMPLOYMENT_HISTORY', "Employee {$employeeId} has no prior EmploymentRecord -- use EmploymentService::create() directly for a first hire, not rehire().");
    }
}
