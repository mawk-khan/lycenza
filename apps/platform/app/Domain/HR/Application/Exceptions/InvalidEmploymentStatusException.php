<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * TCH.1 (ADR 0063 section 5): EmploymentService::create() accepted any
 * caller-supplied `status` string until now. `status` is an authorization
 * input for ActingEmployee, so it is validated against the closed
 * catalogue (EmploymentRecord::STATUSES) on every write, and the database
 * enforces the same list (`employment_records_status_check`).
 */
class InvalidEmploymentStatusException extends HrException
{
    public function __construct(public readonly string $attemptedStatus)
    {
        parent::__construct(422, 'HR_INVALID_EMPLOYMENT_STATUS', 'The Employment status is not one of the recognised values.');
    }
}
