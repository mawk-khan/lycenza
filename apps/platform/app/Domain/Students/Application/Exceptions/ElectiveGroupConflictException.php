<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * Phase 1F.2: translates the database's partial unique index
 * (`student_subject_enrollments_one_active_per_elective_group`)
 * rejecting a second `active` participation for the same
 * StudentEnrollment in the same ElectiveGroup into a predictable domain
 * error -- mirrors ActiveSubjectEnrollmentConflictException's identical
 * rationale, for the group-level invariant rather than the
 * same-offering one.
 */
class ElectiveGroupConflictException extends StudentException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'ELECTIVE_GROUP_CONFLICT',
            'This StudentEnrollment already has an active elective in this ElectiveGroup.',
        );
    }
}
