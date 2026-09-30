<?php

namespace App\Domain\TeachingAssignments\Application\Exceptions;

/**
 * TCH.2: an assignment's dates must fall inside its AcademicYear.
 */
class AssignmentOutsideAcademicYearException extends TeachingAssignmentException
{
    public function __construct()
    {
        parent::__construct(422, 'TEACHING_ASSIGNMENT_OUTSIDE_ACADEMIC_YEAR', 'The assignment dates must fall inside the academic year.');
    }
}
