<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * TCH.1: EmployeeService::linkUser() refuses an archived Employee -- an
 * archived record never gains a User link (and could never resolve as an
 * ActingEmployee anyway). Unlinking an archived Employee stays allowed:
 * removing an identity link is always the safe direction.
 */
class EmployeeNotActiveException extends HrException
{
    public function __construct(public readonly string $employeeId)
    {
        parent::__construct(409, 'HR_EMPLOYEE_NOT_ACTIVE', 'An archived Employee cannot be linked to a User.');
    }
}
