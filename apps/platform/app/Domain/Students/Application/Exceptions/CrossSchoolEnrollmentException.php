<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * Defense-in-depth ahead of the database's own composite-FK rejection
 * (`student_enrollments_student_id_school_id_foreign`/
 * `_section_id_school_id_foreign` and friends, Phase 1B.1) -- this
 * service never relies on that FK failure alone to catch a Student and
 * Section from two different Schools; it checks first and fails with a
 * clean domain error, mirroring
 * App\Domain\Guardians\Application\Exceptions\CrossSchoolRelationshipException's
 * identical rationale.
 */
class CrossSchoolEnrollmentException extends StudentException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'CROSS_SCHOOL_ENROLLMENT',
            'A Student and Section must belong to the same School to create an Enrollment.',
        );
    }
}
