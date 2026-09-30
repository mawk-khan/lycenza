<?php

namespace App\Domain\LMS\Application\Exceptions;

/**
 * TCH.5C: the actor may read this teacher-owned or Offering-wide Learning Content but is not its owner -- teacher writes are owner-only (ADR 0063 section 34.5).
 */
class LearningContentNotOwnedException extends LmsException
{
    public function __construct()
    {
        parent::__construct(403, 'LEARNING_CONTENT_NOT_OWNED', 'Only the teacher who created this Learning Content can change it.');
    }
}
