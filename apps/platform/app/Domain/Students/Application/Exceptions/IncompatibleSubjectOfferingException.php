<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * Phase 1C.1 section 11: a Student may only be enrolled into a
 * SubjectOffering whose AcademicYear/GradeLevel/Campus matches the
 * Student's own CURRENT active StudentEnrollment. Thrown when no
 * compatible active StudentEnrollment exists, or when it exists but its
 * AcademicYear/GradeLevel/Campus does not match the chosen
 * SubjectOffering.
 */
class IncompatibleSubjectOfferingException extends StudentException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'INCOMPATIBLE_SUBJECT_OFFERING',
            "This SubjectOffering does not match the Student's current active academic placement.",
        );
    }
}
