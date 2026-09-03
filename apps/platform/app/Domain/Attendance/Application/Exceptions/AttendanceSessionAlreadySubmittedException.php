<?php

namespace App\Domain\Attendance\Application\Exceptions;

/**
 * A register already exists for this TimetableEntry + date. Deliberately a conflict, never a silent return of the existing Session: a second submission is an error the caller must see, and changing an already-submitted register is the explicit correction command's job.
 */
class AttendanceSessionAlreadySubmittedException extends AttendanceException
{
    public function __construct()
    {
        parent::__construct(409, 'ATTENDANCE_SESSION_ALREADY_SUBMITTED', 'A register has already been submitted for this class on this date.');
    }
}
