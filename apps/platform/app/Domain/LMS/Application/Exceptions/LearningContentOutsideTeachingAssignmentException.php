<?php

namespace App\Domain\LMS\Application\Exceptions;

/**
 * TCH.5C: the owner no longer holds a current TeachingAssignment for every audience Section, so the resource cannot be changed by a teacher (ADR 0063 section 34.5). An administrator can.
 */
class LearningContentOutsideTeachingAssignmentException extends LmsException
{
    public function __construct()
    {
        parent::__construct(422, 'LEARNING_CONTENT_OUTSIDE_TEACHING_ASSIGNMENT', 'You no longer teach every Section this Learning Content is for.');
    }
}
