<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * RES.3 (ADR 0068 §7, §21): corrections exist only for a locked paper; an open paper is changed through ordinary entry. The message is fixed text: it never carries a
 * mark value, a Student name or a database message.
 */
class StudentMarkCorrectionPaperNotLockedException extends ExaminationException
{
    public function __construct(public readonly ?string $studentId = null)
    {
        parent::__construct(409, 'STUDENT_MARK_CORRECTION_PAPER_NOT_LOCKED', 'Corrections are only for a paper whose marks are locked.');
    }
}
