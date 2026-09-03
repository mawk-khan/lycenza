<?php

namespace App\Domain\Attendance\Application\Exceptions;

/**
 * No StudentEnrollment qualifies for this Section on this date. An empty AttendanceSession would be a register asserting nothing, so it is refused outright rather than created.
 */
class EmptyRosterException extends AttendanceException
{
    public function __construct()
    {
        parent::__construct(422, 'ATTENDANCE_EMPTY_ROSTER', 'No Student was placed in this Section on this date; there is no register to take.');
    }
}
