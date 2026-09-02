<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * `max_marks` must be strictly positive. The database's own
 * `examination_papers_max_marks_check` is the authoritative guarantee;
 * this returns the ordinary case as a clean 422.
 */
class ExaminationPaperInvalidMaxMarksException extends ExaminationException
{
    public function __construct(public readonly string $maxMarks)
    {
        parent::__construct(422, 'EXAMINATION_PAPER_INVALID_MAX_MARKS', "The maximum marks {$maxMarks} must be greater than zero.");
    }
}
