<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * RES.5 (ADR 0068 §27): StudentMark processing is refused in this
 * environment -- production waits for RES-L1 (E36). One fixed message; it
 * names no School, paper or Student.
 */
class StudentMarksUnavailableException extends ExaminationException
{
    public function __construct()
    {
        parent::__construct(403, 'STUDENT_MARKS_UNAVAILABLE', 'Student marks are not available in this environment.');
    }
}
