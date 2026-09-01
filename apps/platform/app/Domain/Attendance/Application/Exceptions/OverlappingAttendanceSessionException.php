<?php

namespace App\Domain\Attendance\Application\Exceptions;

/**
 * Another submitted register for this Section and date occupies an overlapping half-open [start, end) wall-clock interval. Catches the case Period-identity uniqueness cannot: a retired Period P and a new Period Q can both denote 09:00-10:00 under different ids. There is no replace-register operation in v1, so this is a conflict, not a merge.
 */
class OverlappingAttendanceSessionException extends AttendanceException
{
    public function __construct(public readonly string $conflictingSessionId)
    {
        parent::__construct(409, 'ATTENDANCE_SESSION_TIME_OVERLAP', "Another submitted register for this Section on this date overlaps this class's time range (session {$conflictingSessionId}).");
    }
}
