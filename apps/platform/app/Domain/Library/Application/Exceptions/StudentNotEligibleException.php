<?php

namespace App\Domain\Library\Application\Exceptions;

/**
 * Checkpoint brief section 12 / docs/modules/LIBRARY.md ("Student
 * eligibility assumption"): this repository has no dedicated concept
 * of "library borrowing eligibility" -- the minimum EXISTING Student
 * state this checkpoint requires is simply
 * App\Domain\Students\Infrastructure\Student::status === 'active'
 * (the same status every other module already treats as "is this
 * Student a going concern at this School"), not a new configurable
 * eligibility engine.
 */
class StudentNotEligibleException extends LibraryException
{
    public function __construct()
    {
        parent::__construct(422, 'LIBRARY_STUDENT_NOT_ELIGIBLE', 'This Student is not active and cannot check out a Library item.');
    }
}
