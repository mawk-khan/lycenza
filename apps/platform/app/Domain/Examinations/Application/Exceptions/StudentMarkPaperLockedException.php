<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * RES.3 (ADR 0068 §7, §21): the paper's marks are locked; ordinary entry refuses every create and change (post-lock changes are corrections). The message is fixed text: it never carries a
 * mark value, a Student name or a database message.
 */
class StudentMarkPaperLockedException extends ExaminationException
{
    public function __construct(public readonly ?string $studentId = null)
    {
        parent::__construct(409, 'STUDENT_MARK_PAPER_LOCKED', 'This paper\'s marks are locked; use a correction.');
    }
}
