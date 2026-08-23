<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * Translates the database's `unique(school_id, student_number)`
 * constraint violation into a predictable domain error -- the database
 * remains the authoritative guarantee (this is a translation, not a
 * replacement, of that constraint); see
 * App\Domain\Students\Application\StudentService.
 */
class DuplicateStudentNumberException extends StudentException
{
    public function __construct(string $studentNumber)
    {
        parent::__construct(
            422,
            'DUPLICATE_STUDENT_NUMBER',
            "Student number \"{$studentNumber}\" is already in use at this School.",
        );
    }
}
