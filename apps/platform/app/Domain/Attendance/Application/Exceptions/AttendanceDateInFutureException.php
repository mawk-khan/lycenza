<?php

namespace App\Domain\Attendance\Application\Exceptions;

/**
 * A register cannot be taken for a class that has not happened yet. Evaluated against the platform's UTC application date (Carbon::now()), the same clock every other module treats as authoritative.
 */
class AttendanceDateInFutureException extends AttendanceException
{
    public function __construct(public readonly string $attendanceDate)
    {
        parent::__construct(422, 'ATTENDANCE_DATE_IN_FUTURE', "Attendance date {$attendanceDate} is in the future.");
    }
}
