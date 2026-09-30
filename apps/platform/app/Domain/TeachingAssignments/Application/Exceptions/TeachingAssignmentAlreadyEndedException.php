<?php

namespace App\Domain\TeachingAssignments\Application\Exceptions;

/**
 * TCH.2 (ADR 0063 section 9): an ended assignment is immutable -- checked
 * under the row lock, so a second of two concurrent ends is refused, never
 * silently rewriting the first end.
 */
class TeachingAssignmentAlreadyEndedException extends TeachingAssignmentException
{
    public function __construct()
    {
        parent::__construct(409, 'TEACHING_ASSIGNMENT_ALREADY_ENDED', 'This Teaching Assignment has already been ended.');
    }
}
