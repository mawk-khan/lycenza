<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * UAT-C1 (UAT-HR-20b): translated from `employees_school_id_employee_number_unique`.
 * EmployeeNumberAllocator never hands out a used number on its own (row lock
 * inside the create transaction), so this fires only when the School's counter
 * is behind numbers written some other way -- the demo seeding defect UAT-HR-20
 * found. Nothing is created; the user gets a plain refusal instead of a 500,
 * and EmployeeService still reports the underlying exception so the out-of-step
 * counter is visible to operators.
 */
class EmployeeNumberConflictException extends HrException
{
    public const string CONSTRAINT = 'employees_school_id_employee_number_unique';

    public function __construct(public readonly string $employeeNumber)
    {
        parent::__construct(409, 'HR_EMPLOYEE_NUMBER_CONFLICT', "Employee number {$employeeNumber} is already taken in this School, so no Employee was added. The School's employee numbering is out of step and needs an administrator to correct it.");
    }
}
