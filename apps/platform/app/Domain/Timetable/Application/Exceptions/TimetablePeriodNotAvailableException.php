<?php

namespace App\Domain\Timetable\Application\Exceptions;

class TimetablePeriodNotAvailableException extends TimetableException
{
    public function __construct()
    {
        parent::__construct(422, 'TIMETABLE_PERIOD_NOT_AVAILABLE', 'This Period is not active and cannot be scheduled against.');
    }
}
