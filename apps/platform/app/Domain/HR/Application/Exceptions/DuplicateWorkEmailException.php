<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * Phase 8A closure correction ("Work contact fields"): raised when
 * `employees.work_email` would collide with another Employee in the
 * SAME School (`unique(school_id, work_email)` where not null,
 * `employees_work_email_unique`). A different School reusing the
 * identical work_email is never rejected -- School-scoped, matching
 * `employee_number`'s own uniqueness scope.
 */
class DuplicateWorkEmailException extends HrException
{
    public function __construct(public readonly string $workEmail)
    {
        parent::__construct(422, 'HR_DUPLICATE_WORK_EMAIL', "Work email '{$workEmail}' is already in use by another Employee in this School.");
    }
}
