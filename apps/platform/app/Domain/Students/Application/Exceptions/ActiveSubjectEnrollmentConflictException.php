<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * Translates the database's partial unique index
 * (`student_subject_enrollments_one_active_per_offering`) rejecting a
 * second `active` subject enrollment for the same Student in the same
 * SubjectOffering into a predictable domain error -- mirrors
 * ActiveEnrollmentConflictException's identical rationale.
 */
class ActiveSubjectEnrollmentConflictException extends StudentException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'ACTIVE_SUBJECT_ENROLLMENT_CONFLICT',
            'This Student already has an active subject enrollment for this SubjectOffering.',
        );
    }
}
