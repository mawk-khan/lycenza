<?php

namespace App\Domain\Attendance\Application\Exceptions;

/**
 * The same student_enrollment_id appears more than once in one submitted register.
 */
class DuplicateEnrollmentInRegisterException extends AttendanceException
{
    public function __construct(public readonly string $studentEnrollmentId)
    {
        parent::__construct(422, 'ATTENDANCE_DUPLICATE_ENROLLMENT_IN_REGISTER', "StudentEnrollment {$studentEnrollmentId} appears more than once in this register.");
    }
}
