<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * Only `active` -> `withdrawn`/`cancelled`/`transferred` are valid
 * transitions. Every terminal status is final -- mirrors
 * InvalidEnrollmentTransitionException's identical shape.
 */
class InvalidSubjectEnrollmentTransitionException extends StudentException
{
    public function __construct(string $from, string $to)
    {
        parent::__construct(
            422,
            'INVALID_SUBJECT_ENROLLMENT_TRANSITION',
            "Cannot transition a Student subject enrollment from '{$from}' to '{$to}'.",
        );
    }
}
