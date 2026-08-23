<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * Translates the database's partial unique index
 * (`student_enrollments_one_active_per_student_year`, Phase 1B.1)
 * rejecting a second `active` Enrollment for the same Student in the
 * same AcademicYear into a predictable domain error -- the database
 * index remains the authoritative concurrency guarantee (this is a
 * translation, not a replacement, of that constraint); see
 * App\Domain\Students\Application\StudentEnrollmentService::enroll().
 */
class ActiveEnrollmentConflictException extends StudentException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'ACTIVE_ENROLLMENT_CONFLICT',
            'This Student already has an active Enrollment for this Academic Year.',
        );
    }
}
