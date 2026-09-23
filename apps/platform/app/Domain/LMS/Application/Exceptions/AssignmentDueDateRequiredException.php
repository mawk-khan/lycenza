<?php

namespace App\Domain\LMS\Application\Exceptions;

/**
 * An Assignment may only be published once it has a due date -- the
 * one real invariant `publish()` checks, the identical "checked only
 * at the state-changing transition" shape ADR 0035's GradeScale
 * `assertComplete()` (a GradeScale needs a 0.00 band before it may
 * become `active`) already established. A `draft` Assignment may
 * legitimately have no due date yet while still being prepared.
 */
class AssignmentDueDateRequiredException extends LmsException
{
    public function __construct()
    {
        parent::__construct(422, 'ASSIGNMENT_DUE_DATE_REQUIRED', 'An Assignment must have a due date before it can be published.');
    }
}
