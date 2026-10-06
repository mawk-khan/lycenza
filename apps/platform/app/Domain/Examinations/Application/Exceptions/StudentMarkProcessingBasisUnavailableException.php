<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * RES.2 (ADR 0068 §6, §19.2 #12): the Student has no currently qualifying ADR 0038 processing authorization, so no mark may be recorded or changed. The message is fixed text: it never
 * carries a mark value, a Student name or a database message.
 */
class StudentMarkProcessingBasisUnavailableException extends ExaminationException
{
    public function __construct(public readonly ?string $studentId = null)
    {
        parent::__construct(422, 'STUDENT_MARK_PROCESSING_BASIS_UNAVAILABLE', 'No current processing basis exists for a Student in this request; nothing was saved.');
    }
}
