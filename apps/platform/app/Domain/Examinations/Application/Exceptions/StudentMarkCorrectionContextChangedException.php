<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * RES.3 (ADR 0068 §7, §21): the mark's P3 eligibility context is no longer what it was recorded under, so it is not corrected (fail closed). The message is fixed text: it never carries a
 * mark value, a Student name or a database message.
 */
class StudentMarkCorrectionContextChangedException extends ExaminationException
{
    public function __construct(public readonly ?string $studentId = null)
    {
        parent::__construct(422, 'STUDENT_MARK_CORRECTION_CONTEXT_CHANGED', 'The Student\'s eligibility for this paper has changed; the mark cannot be corrected.');
    }
}
