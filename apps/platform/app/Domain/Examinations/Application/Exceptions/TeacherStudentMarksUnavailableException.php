<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * RES.4 (ADR 0068 §25.3): teacher StudentMark processing is refused in this
 * environment -- the interim, owner-authorised-development production block
 * while RES-L2 (E37) and the teacher RES-L0 re-review (E35) are undetermined.
 * One fixed message; it names no School, paper or Student.
 */
class TeacherStudentMarksUnavailableException extends ExaminationException
{
    public function __construct()
    {
        parent::__construct(403, 'TEACHER_STUDENT_MARKS_UNAVAILABLE', 'Teacher marks entry is not available in this environment.');
    }
}
