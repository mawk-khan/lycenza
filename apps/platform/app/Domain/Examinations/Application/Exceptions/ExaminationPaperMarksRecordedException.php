<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * RES.2 (ADR 0068 §20): once a paper has recorded marks, its maximum marks and
 * its date (the P3 eligibility date) never change, so a recorded mark keeps its
 * meaning. Database-enforced (`examination_papers_freeze_when_marked`).
 */
class ExaminationPaperMarksRecordedException extends ExaminationException
{
    public function __construct()
    {
        parent::__construct(409, 'EXAMINATION_PAPER_HAS_MARKS', 'This paper has recorded marks; its maximum marks and date cannot change.');
    }
}
