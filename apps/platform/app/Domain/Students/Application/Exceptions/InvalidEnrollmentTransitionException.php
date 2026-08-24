<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * Phase 1B.3: only `active` -> `completed`/`withdrawn`/`cancelled`/
 * `transferred` are valid transitions. Every terminal status
 * (`completed`/`withdrawn`/`cancelled`/`transferred`) is final -- none
 * of them may transition again through the sanctioned service,
 * including back to `active` (re-enrollment is always a NEW
 * StudentEnrollment row, never a reactivated old one). Mirrors
 * App\Domain\AcademicStructure\Application\Exceptions\InvalidAcademicYearTransitionException's
 * identical shape.
 */
class InvalidEnrollmentTransitionException extends StudentException
{
    public function __construct(string $from, string $to)
    {
        parent::__construct(
            422,
            'INVALID_ENROLLMENT_TRANSITION',
            "Cannot transition a Student Enrollment from '{$from}' to '{$to}'.",
        );
    }
}
