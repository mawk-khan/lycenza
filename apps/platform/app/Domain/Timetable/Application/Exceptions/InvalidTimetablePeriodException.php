<?php

namespace App\Domain\Timetable\Application\Exceptions;

/**
 * A Period's own attributes are invalid independent of any other
 * Period -- currently: start_time >= end_time. The database's own
 * `timetable_periods_start_before_end_check` CHECK constraint is the
 * structural backstop; this exception is what
 * App\Domain\Timetable\Application\TimetablePeriodService throws BEFORE
 * ever attempting the write, so a caller gets a clean, typed error
 * rather than a raw CHECK-violation message.
 */
class InvalidTimetablePeriodException extends TimetableException
{
    public function __construct(string $message = 'A Period\'s start_time must be strictly before its end_time.')
    {
        parent::__construct(422, 'TIMETABLE_PERIOD_INVALID', $message);
    }
}
