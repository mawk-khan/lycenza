<?php

namespace App\Domain\LMS\Application\Exceptions;

/**
 * TCH.5C: a teacher may target only Sections of the Offering they currently teach (a TeachingAssignment covering today), and must teach every one of them (ADR 0063 section 34.4).
 */
class LmsAudienceSectionNotTaughtException extends LmsException
{
    public function __construct()
    {
        parent::__construct(422, 'LMS_AUDIENCE_SECTION_NOT_TAUGHT', 'Every audience Section must be one you currently teach for this Subject Offering.');
    }
}
