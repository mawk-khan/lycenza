<?php

namespace App\Domain\Attendance\Application\Exceptions;

/** ORG.8E (ADR 0076 §63): a correction needs one of the closed correction reasons. */
class AttendanceCorrectionReasonException extends AttendanceException
{
    public function __construct()
    {
        parent::__construct(422, 'ATTENDANCE_CORRECTION_REASON_INVALID', 'Choose why this attendance is being corrected.');
    }
}
