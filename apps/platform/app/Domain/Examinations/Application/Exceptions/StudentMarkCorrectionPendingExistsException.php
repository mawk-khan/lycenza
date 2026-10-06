<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * RES.3 (ADR 0068 §7, §21): a mark has at most one pending correction. The message is fixed text: it never carries a
 * mark value, a Student name or a database message.
 */
class StudentMarkCorrectionPendingExistsException extends ExaminationException
{
    public function __construct(public readonly ?string $studentId = null)
    {
        parent::__construct(409, 'STUDENT_MARK_CORRECTION_PENDING_EXISTS', 'This mark already has a pending correction.');
    }
}
