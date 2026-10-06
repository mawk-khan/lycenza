<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * RES.3 (ADR 0068 §7, §21): the paper's marks are already locked (one-way; there is no unlock). The message is fixed text: it never carries a
 * mark value, a Student name or a database message.
 */
class StudentMarksAlreadyLockedException extends ExaminationException
{
    public function __construct(public readonly ?string $studentId = null)
    {
        parent::__construct(409, 'STUDENT_MARKS_ALREADY_LOCKED', 'This paper\'s marks are already locked.');
    }
}
