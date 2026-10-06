<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * RES.3 (ADR 0068 §7, §21): the correction is malformed (an unknown reason code, or it changes nothing). The message is fixed text: it never carries a
 * mark value, a Student name or a database message.
 */
class StudentMarkCorrectionInvalidException extends ExaminationException
{
    public function __construct(public readonly ?string $studentId = null)
    {
        parent::__construct(422, 'STUDENT_MARK_CORRECTION_INVALID', 'The correction request is invalid; nothing was saved.');
    }
}
