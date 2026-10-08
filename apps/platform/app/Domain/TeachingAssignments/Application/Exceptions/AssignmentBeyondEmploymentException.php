<?php

namespace App\Domain\TeachingAssignments\Application\Exceptions;

/**
 * S7 (ADR 0063 §47): the employment covering the start date ends before the
 * assignment would (an open-ended assignment included). Teaching ownership
 * never outlives the employment that justifies it; assign up to the
 * employment's last day, and assign again after a rehire.
 */
class AssignmentBeyondEmploymentException extends TeachingAssignmentException
{
    public function __construct()
    {
        parent::__construct(422, 'TEACHING_ASSIGNMENT_BEYOND_EMPLOYMENT', 'This assignment would run past the end of the Employee\'s employment. End it on or before the employment\'s last day.');
    }
}
