<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * Phase 8A closure correction ("EmployeeCategory"). Raised uniformly
 * whether a caller-supplied `employee_category_id` genuinely does not
 * exist OR exists only in a different School -- the composite foreign
 * key (`employment_records_employee_category_id_school_id_foreign`)
 * is the sole structural source of truth;
 * App\Domain\HR\Application\EmploymentService catches its specific
 * constraint-violation and raises this exception, never distinguishing
 * "does not exist" from "exists in another School" (the same cross-
 * School "no oracle" principle every other lookup in this repository
 * already establishes).
 */
class EmployeeCategoryNotFoundException extends HrException
{
    public function __construct(public readonly string $employeeCategoryId)
    {
        parent::__construct(422, 'HR_EMPLOYEE_CATEGORY_NOT_FOUND', "No employee category with id '{$employeeCategoryId}' was found in this School.");
    }
}
