<?php

namespace App\Domain\TeachingAssignments\Application\Exceptions;

/**
 * TCH.2 (ADR 0063 D-05): electives are excluded -- an elective is a
 * Student-level enrollment choice, not a Section-wide cohort.
 */
class RequiredOfferingOnlyException extends TeachingAssignmentException
{
    public function __construct()
    {
        parent::__construct(422, 'TEACHING_ASSIGNMENT_REQUIRED_OFFERING_ONLY', 'Only a required Subject Offering can be assigned to a Section teacher.');
    }
}
