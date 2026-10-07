<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * RES.4 (ADR 0068 §25.6): a Student in a teacher's batch is outside the
 * teacher's scope -- identical whether the id is unknown, the Student was not
 * P3-eligible on the paper's date (for any reason) or the teacher did not own
 * the Student's class on that date. Unlike the administrative
 * StudentMarkNotEligibleException it carries no reason, so it discloses
 * nothing about another teacher's Students. Nothing was saved.
 */
class TeacherStudentMarkStudentNotFoundException extends ExaminationException
{
    public function __construct(public readonly ?string $studentId = null)
    {
        parent::__construct(404, 'STUDENT_MARK_STUDENT_NOT_FOUND', 'A Student in this request was not found; nothing was saved.');
    }
}
