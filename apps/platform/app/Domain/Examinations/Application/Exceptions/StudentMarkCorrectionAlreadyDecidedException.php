<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * RES.3 (ADR 0068 §7, §21): the correction is no longer pending (a decision is terminal). The message is fixed text: it never carries a
 * mark value, a Student name or a database message.
 */
class StudentMarkCorrectionAlreadyDecidedException extends ExaminationException
{
    public function __construct(public readonly ?string $studentId = null)
    {
        parent::__construct(409, 'STUDENT_MARK_CORRECTION_ALREADY_DECIDED', 'This correction has already been decided.');
    }
}
