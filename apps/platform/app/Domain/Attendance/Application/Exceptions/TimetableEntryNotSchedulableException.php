<?php

namespace App\Domain\Attendance\Application\Exceptions;

/**
 * A register may only be submitted from a currently ACTIVE TimetableEntry. Selection always reflects the CURRENT schedule (an inactive entry is not offered), unlike a historical register read, which uses its own immutable Session snapshot.
 */
class TimetableEntryNotSchedulableException extends AttendanceException
{
    public function __construct()
    {
        parent::__construct(422, 'ATTENDANCE_TIMETABLE_ENTRY_NOT_SCHEDULABLE', 'Attendance can only be submitted for an active TimetableEntry.');
    }
}
