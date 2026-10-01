<?php

namespace App\Domain\LMS\Application\Exceptions;

/**
 * TCH.5D: the owner no longer holds a current TeachingAssignment for every audience Section, so the Assignment cannot be changed by a teacher (ADR 0063 section 34.5). An administrator can.
 */
class AssignmentOutsideTeachingAssignmentException extends LmsException
{
    public function __construct()
    {
        parent::__construct(422, 'ASSIGNMENT_OUTSIDE_TEACHING_ASSIGNMENT', 'You no longer teach every Section this Assignment is for.');
    }
}
