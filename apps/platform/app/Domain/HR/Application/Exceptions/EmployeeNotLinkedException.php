<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * TCH.1 (ADR 0063 section 6): EmployeeService::unlinkUser() on an Employee
 * with no User link (checked under the Employee row lock, so a second of
 * two concurrent unlinks is refused rather than audited twice).
 */
class EmployeeNotLinkedException extends HrException
{
    public function __construct(public readonly string $employeeId)
    {
        parent::__construct(409, 'HR_EMPLOYEE_NOT_LINKED', 'This Employee is not linked to a User.');
    }
}
