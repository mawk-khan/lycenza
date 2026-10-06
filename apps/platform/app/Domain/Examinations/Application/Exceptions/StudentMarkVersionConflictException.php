<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * RES.2 (ADR 0068 §6, §19.2 #12): the mark changed since it was read (optimistic concurrency); nothing in the request was saved. The message is fixed text: it never
 * carries a mark value, a Student name or a database message.
 */
class StudentMarkVersionConflictException extends ExaminationException
{
    public function __construct(public readonly ?string $studentId = null)
    {
        parent::__construct(409, 'STUDENT_MARK_VERSION_CONFLICT', 'A mark changed since it was loaded. Reload the paper and try again.');
    }
}
