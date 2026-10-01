<?php

namespace App\Domain\LMS\Application\Exceptions;

/**
 * TCH.5D: the actor may read this teacher-owned or Offering-wide Assignment but is not its owner -- teacher writes are owner-only (ADR 0063 section 34.5).
 */
class AssignmentNotOwnedException extends LmsException
{
    public function __construct()
    {
        parent::__construct(403, 'ASSIGNMENT_NOT_OWNED', 'Only the teacher who created this Assignment can change it.');
    }
}
