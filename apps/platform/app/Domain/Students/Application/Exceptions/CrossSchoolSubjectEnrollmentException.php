<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * Defense-in-depth ahead of the database's own composite-FK rejection --
 * mirrors CrossSchoolEnrollmentException's identical rationale, applied
 * to a Student/SubjectOffering pair from two different Schools.
 */
class CrossSchoolSubjectEnrollmentException extends StudentException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'CROSS_SCHOOL_SUBJECT_ENROLLMENT',
            'A Student and SubjectOffering must belong to the same School to create a subject enrollment.',
        );
    }
}
