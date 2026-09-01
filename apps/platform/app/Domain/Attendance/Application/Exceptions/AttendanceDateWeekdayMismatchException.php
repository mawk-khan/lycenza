<?php

namespace App\Domain\Attendance\Application\Exceptions;

/**
 * The attendance date's ISO weekday does not match the locked TimetableEntry's scheduled day_of_week. Checked against the entry AS LOCKED, which is also why day_of_week never needs to be snapshotted -- weekday is always derivable from attendance_date afterwards.
 */
class AttendanceDateWeekdayMismatchException extends AttendanceException
{
    public function __construct(public readonly int $expectedDayOfWeek, public readonly int $actualDayOfWeek)
    {
        parent::__construct(422, 'ATTENDANCE_DATE_WEEKDAY_MISMATCH', "Attendance date falls on ISO weekday {$actualDayOfWeek}, but this TimetableEntry is scheduled on weekday {$expectedDayOfWeek}.");
    }
}
