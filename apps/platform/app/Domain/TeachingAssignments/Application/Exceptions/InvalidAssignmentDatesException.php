<?php

namespace App\Domain\TeachingAssignments\Application\Exceptions;

/**
 * TCH.2: an end date before the start date (or, when ending, a later end
 * date than one already set) -- never stored, never used to simulate a
 * cancellation (ADR 0063 section 9).
 */
class InvalidAssignmentDatesException extends TeachingAssignmentException
{
    public function __construct()
    {
        parent::__construct(422, 'TEACHING_ASSIGNMENT_INVALID_DATES', 'The assignment end date must be on or after its start date and cannot extend an existing end date.');
    }
}
