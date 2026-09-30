<?php

namespace App\Domain\TeachingAssignments\Application\Exceptions;

/**
 * TCH.2: new assignments are planned only in a draft or active AcademicYear,
 * never a closed or archived one.
 */
class AcademicYearNotOpenException extends TeachingAssignmentException
{
    public function __construct()
    {
        parent::__construct(422, 'TEACHING_ASSIGNMENT_ACADEMIC_YEAR_NOT_OPEN', 'Teaching Assignments can only be created in a draft or active academic year.');
    }
}
