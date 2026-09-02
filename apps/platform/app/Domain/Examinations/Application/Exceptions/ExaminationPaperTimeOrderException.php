<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * An ExaminationPaper's sitting cannot end before or at the same time it
 * starts. The database's own `examination_papers_time_order_check` is
 * the authoritative guarantee; this returns the ordinary case as a clean
 * 422. Same-day sittings only -- overnight sittings are unsupported.
 */
class ExaminationPaperTimeOrderException extends ExaminationException
{
    public function __construct(public readonly string $startsAt, public readonly string $endsAt)
    {
        parent::__construct(422, 'EXAMINATION_PAPER_TIME_ORDER', "The end time {$endsAt} does not fall after the start time {$startsAt}.");
    }
}
