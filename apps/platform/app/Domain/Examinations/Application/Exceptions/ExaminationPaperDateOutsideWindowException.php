<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * An ExaminationPaper's `scheduled_on` must fall inside its owning
 * Examination's inclusive [starts_on, ends_on] window. Future Paper dates
 * are permitted -- only the bound is enforced.
 */
class ExaminationPaperDateOutsideWindowException extends ExaminationException
{
    public function __construct(public readonly string $scheduledOn)
    {
        parent::__construct(422, 'EXAMINATION_PAPER_DATE_OUTSIDE_WINDOW', "The scheduled date {$scheduledOn} falls outside this Examination's window.");
    }
}
