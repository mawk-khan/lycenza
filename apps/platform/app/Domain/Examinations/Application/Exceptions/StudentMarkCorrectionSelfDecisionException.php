<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * RES.3 (ADR 0068 §7, §21): maker/checker: the requester can never decide their own correction. The message is fixed text: it never carries a
 * mark value, a Student name or a database message.
 */
class StudentMarkCorrectionSelfDecisionException extends ExaminationException
{
    public function __construct(public readonly ?string $studentId = null)
    {
        parent::__construct(403, 'STUDENT_MARK_CORRECTION_SELF_DECISION', 'A correction must be decided by someone other than its requester.');
    }
}
