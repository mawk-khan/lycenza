<?php

namespace App\Domain\LMS\Application\Exceptions;

/**
 * The requested state pair is not one of the exactly three legal
 * Learning Content transitions (draft->published, published->archived,
 * archived->published). Every other transition, INCLUDING every no-op,
 * is illegal -- mirrors
 * App\Domain\Examinations\Application\Exceptions\GradeScaleIllegalTransitionException's
 * exact reasoning: both values belonging to the status vocabulary is
 * NOT sufficient, so the machine can never grow an unreviewed edge.
 */
class LearningContentIllegalTransitionException extends LmsException
{
    public function __construct(public readonly string $fromStatus, public readonly string $toStatus)
    {
        parent::__construct(422, 'LEARNING_CONTENT_ILLEGAL_TRANSITION', "Cannot transition Learning Content from '{$fromStatus}' to '{$toStatus}'.");
    }
}
