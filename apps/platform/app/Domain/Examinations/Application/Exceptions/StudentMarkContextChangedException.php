<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * RES.5 (ADR 0068 §20.1, §27): an existing mark is never re-derived. When P3,
 * re-run for an ordinary edit, now answers with a different placement,
 * eligibility source or elective row than the mark was recorded under (e.g.
 * the Offering's required/elective flag was flipped, or a backdated transfer
 * moved the date's placement), the edit fails closed -- the same rule the
 * correction path applies (StudentMarkCorrectionContextChangedException).
 * Fixed text: never a value, a name or a database message.
 */
class StudentMarkContextChangedException extends ExaminationException
{
    public function __construct(public readonly ?string $studentId = null)
    {
        parent::__construct(409, 'STUDENT_MARK_CONTEXT_CHANGED', 'The Student\'s eligibility context for this paper has changed since the mark was recorded; nothing was saved.');
    }
}
