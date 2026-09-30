<?php

namespace App\Domain\Attendance\Application\Exceptions;

/**
 * TCH.4 (ADR 0063 section 11): an acting teacher asked to submit a register
 * for one of their classes on a date their TeachingAssignment does not
 * cover (before it starts, after it ends), or their ownership of a
 * register's date ended while the write waited. Raised only for a class the
 * teacher already owns at some date, so it discloses nothing they could not
 * read.
 */
class AttendanceOutsideTeachingAssignmentException extends AttendanceException
{
    public function __construct()
    {
        parent::__construct(422, 'ATTENDANCE_OUTSIDE_TEACHING_ASSIGNMENT', 'This date is outside your Teaching Assignment for this class.');
    }
}
