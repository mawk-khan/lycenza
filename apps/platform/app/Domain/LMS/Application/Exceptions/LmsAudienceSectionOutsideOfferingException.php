<?php

namespace App\Domain\LMS\Application\Exceptions;

/**
 * TCH.5B: a Section in a teacher-owned resource's audience is not an
 * active Section of the resource's SubjectOffering context (same School,
 * AcademicYear, Campus and GradeLevel). The database refuses the same
 * shape structurally; this is the clean application answer.
 */
class LmsAudienceSectionOutsideOfferingException extends LmsException
{
    public function __construct()
    {
        parent::__construct(422, 'LMS_AUDIENCE_SECTION_OUTSIDE_OFFERING', 'Every audience Section must be an active Section of this Subject Offering\'s class.');
    }
}
