<?php

namespace App\Domain\TeachingAssignments\Application\Exceptions;

/**
 * TCH-E (ADR 0063 section 45): an elective teaching assignment is only for an
 * elective Subject Offering; a required one is owned per Section through a
 * TeachingAssignment.
 */
class ElectiveOfferingOnlyException extends TeachingAssignmentException
{
    public function __construct()
    {
        parent::__construct(422, 'ELECTIVE_TEACHING_ASSIGNMENT_ELECTIVE_OFFERING_ONLY', 'Only an elective Subject Offering can have an elective teacher; assign a required one per Section.');
    }
}
