<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * Translates the database's
 * `unique(school_id, academic_year_id, section_id, roll_number)`
 * constraint violation (Phase 1B.1) into a predictable domain error --
 * the database remains the authoritative guarantee (this is a
 * translation, not a replacement, of that constraint); see
 * App\Domain\Students\Application\StudentEnrollmentService::enroll().
 */
class DuplicateEnrollmentRollNumberException extends StudentException
{
    public function __construct(string $rollNumber)
    {
        parent::__construct(
            422,
            'DUPLICATE_ENROLLMENT_ROLL_NUMBER',
            "Roll number \"{$rollNumber}\" is already in use in this Section for this Academic Year.",
        );
    }
}
