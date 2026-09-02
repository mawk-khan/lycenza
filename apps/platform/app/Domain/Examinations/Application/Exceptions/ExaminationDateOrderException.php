<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * An examination window cannot end before it starts. The database's own
 * `examinations_date_order_check` is the authoritative guarantee; this
 * returns the ordinary case as a clean 422.
 */
class ExaminationDateOrderException extends ExaminationException
{
    public function __construct(public readonly string $startsOn, public readonly string $endsOn)
    {
        parent::__construct(422, 'EXAMINATION_DATE_ORDER', "The end date {$endsOn} precedes the start date {$startsOn}.");
    }
}
