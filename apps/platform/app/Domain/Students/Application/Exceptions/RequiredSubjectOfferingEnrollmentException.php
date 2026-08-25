<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * A REQUIRED SubjectOffering (`is_required = true`) never gets an
 * explicit StudentSubjectEnrollment row -- every compatible Student is
 * implicitly enrolled, derived at read time by
 * App\Domain\Students\Application\SubjectOfferingRosterReadService.
 * Explicitly rejecting an attempt to enroll into one here prevents an
 * ambiguous state where a required offering has some Students with an
 * explicit row and others without one.
 */
class RequiredSubjectOfferingEnrollmentException extends StudentException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'REQUIRED_SUBJECT_OFFERING_ENROLLMENT',
            'A required SubjectOffering does not accept explicit Student subject enrollments -- every compatible Student is already implicitly enrolled.',
        );
    }
}
