<?php

namespace App\Domain\LMS\Application\Exceptions;

/**
 * The requested state pair is not one of the exactly three legal
 * Assignment transitions (draft->published, published->closed,
 * closed->published). Every other transition, INCLUDING every no-op,
 * is illegal -- mirrors
 * App\Domain\LMS\Application\Exceptions\LearningContentIllegalTransitionException's
 * exact reasoning: both values belonging to the status vocabulary is
 * NOT sufficient, so the machine can never grow an unreviewed edge.
 */
class AssignmentIllegalTransitionException extends LmsException
{
    public function __construct(public readonly string $fromStatus, public readonly string $toStatus)
    {
        parent::__construct(422, 'ASSIGNMENT_ILLEGAL_TRANSITION', "Cannot transition an Assignment from '{$fromStatus}' to '{$toStatus}'.");
    }
}
