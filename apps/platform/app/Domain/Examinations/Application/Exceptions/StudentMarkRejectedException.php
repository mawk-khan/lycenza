<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * RES.2 (ADR 0068 §6, §19.2 #12): the database refused a mark write (a structural rule); translated so no SQL, binding or value reaches a response or a log. The message is fixed text: it never
 * carries a mark value, a Student name or a database message.
 */
class StudentMarkRejectedException extends ExaminationException
{
    public function __construct(public readonly ?string $studentId = null)
    {
        parent::__construct(422, 'STUDENT_MARK_REJECTED', 'A mark could not be saved; nothing in the request was saved.');
    }
}
