<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * RES.2 (ADR 0068 §6, §19.2 #12): marks are recorded only on an active ExaminationPaper. The message is fixed text: it never
 * carries a mark value, a Student name or a database message.
 */
class StudentMarkPaperInactiveException extends ExaminationException
{
    public function __construct(public readonly ?string $studentId = null)
    {
        parent::__construct(422, 'STUDENT_MARK_PAPER_INACTIVE', 'Marks can only be recorded for an active examination paper.');
    }
}
