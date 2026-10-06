<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * RES.2 (ADR 0068 §5, §6.2): the Student was not eligible for the paper's
 * Offering on the paper's date (P3), or the history is unusable. The reason is
 * a closed SubjectOfferingEligibility code; the message never carries a value
 * or a name.
 */
class StudentMarkNotEligibleException extends ExaminationException
{
    public function __construct(public readonly ?string $studentId = null, public readonly ?string $reason = null)
    {
        parent::__construct(422, 'STUDENT_MARK_NOT_ELIGIBLE', 'A Student in this request was not eligible for this paper on its date; nothing was saved.');
    }
}
