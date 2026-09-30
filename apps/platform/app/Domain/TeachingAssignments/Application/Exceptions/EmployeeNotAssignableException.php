<?php

namespace App\Domain\TeachingAssignments\Application\Exceptions;

/**
 * TCH.2 (ADR 0063 section 15): the Employee record is archived, or has no
 * planned or current employment (pre_joining, active or notice_period)
 * covering the start date. One message for both.
 */
class EmployeeNotAssignableException extends TeachingAssignmentException
{
    public function __construct()
    {
        parent::__construct(422, 'TEACHING_ASSIGNMENT_EMPLOYEE_NOT_ASSIGNABLE', 'This Employee cannot be assigned: the record is archived or has no employment covering the start date.');
    }
}
