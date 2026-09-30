<?php

namespace App\Domain\TeachingAssignments\Application\Exceptions;

/**
 * TCH.2: the Section and the SubjectOffering belong to different
 * AcademicYear/Campus/GradeLevel contexts (the composite foreign keys would
 * refuse the row anyway; this is the clean 422).
 */
class TeachingContextMismatchException extends TeachingAssignmentException
{
    public function __construct()
    {
        parent::__construct(422, 'TEACHING_ASSIGNMENT_CONTEXT_MISMATCH', 'The Section and the Subject Offering belong to different academic contexts.');
    }
}
