<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * RES.2 (ADR 0068 §6, §19.2 #12): a mark's status and value do not have the required shape (present needs a value from 0 to the paper's maximum; absent and exempt carry none). The message is fixed text: it never
 * carries a mark value, a Student name or a database message.
 */
class StudentMarkInvalidValueException extends ExaminationException
{
    public function __construct(public readonly ?string $studentId = null)
    {
        parent::__construct(422, 'STUDENT_MARK_INVALID_VALUE', 'A mark has an invalid status or value; nothing was saved.');
    }
}
