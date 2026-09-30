<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * TCH.1 (ADR 0063 section 6): EmployeeService::linkUser() on an Employee
 * that already carries a User link (checked under the Employee row lock).
 * Changing a link is never one step: unlink, then link, so each change is
 * its own audited event and no identity is silently re-pointed.
 */
class EmployeeAlreadyLinkedException extends HrException
{
    public function __construct(public readonly string $employeeId)
    {
        parent::__construct(409, 'HR_EMPLOYEE_ALREADY_LINKED', 'This Employee is already linked to a User. Unlink it first.');
    }
}
