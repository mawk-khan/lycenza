<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * A blank Roll Number (empty or whitespace-only after trimming) is
 * never a valid placement value -- caught before the database is
 * touched, distinct from DuplicateEnrollmentRollNumberException (which
 * translates a genuine uniqueness conflict on an otherwise-valid
 * value).
 */
class InvalidEnrollmentRollNumberException extends StudentException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'INVALID_ENROLLMENT_ROLL_NUMBER',
            'Roll number must not be blank.',
        );
    }
}
