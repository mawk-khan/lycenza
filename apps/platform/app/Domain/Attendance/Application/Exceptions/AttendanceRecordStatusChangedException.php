<?php

namespace App\Domain\Attendance\Application\Exceptions;

/**
 * Compare-and-swap failure: the record's current status is not the expected_status the caller supplied, so another correction landed first. Refusing here is what stops a stale administrator silently overwriting a colleague's correction.
 */
class AttendanceRecordStatusChangedException extends AttendanceException
{
    public function __construct(public readonly string $expected, public readonly string $actual)
    {
        // SIS 6.1: the operator's words, never status keys.
        parent::__construct(409, 'ATTENDANCE_RECORD_STATUS_CHANGED', 'This mark was changed to '.ucfirst($actual).' after you opened the register (you saw '.ucfirst($expected).'). Nothing was corrected.');
    }
}
