<?php

namespace App\Domain\Attendance\Application\Exceptions;

/**
 * The attendance date lies outside the snapshotted AcademicYear's inclusive [starts_on, ends_on] range.
 */
class AttendanceDateOutsideAcademicYearException extends AttendanceException
{
    public function __construct(public readonly string $attendanceDate)
    {
        parent::__construct(422, 'ATTENDANCE_DATE_OUTSIDE_ACADEMIC_YEAR', "Attendance date {$attendanceDate} falls outside the AcademicYear this class belongs to.");
    }
}
