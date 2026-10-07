<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * RES.4 (ADR 0068 §25.5; ADR 0063 §18): the teacher's non-disclosing answer
 * for a paper -- identical whether the paper does not exist, belongs to
 * another School, or is one whose Offering the teacher does not own on its
 * date. It never says which.
 */
class TeacherStudentMarkPaperNotFoundException extends ExaminationException
{
    public function __construct()
    {
        parent::__construct(404, 'STUDENT_MARK_PAPER_NOT_FOUND', 'Examination paper not found.');
    }
}
