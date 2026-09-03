<?php

namespace App\Domain\Attendance\Application\Exceptions;

/**
 * A correction whose new_status equals the current status changes nothing, so it is rejected rather than writing a misleading corrected_at and audit record.
 */
class AttendanceCorrectionNoOpException extends AttendanceException
{
    public function __construct(public readonly string $status)
    {
        parent::__construct(422, 'ATTENDANCE_CORRECTION_NO_OP', "The record is already '{$status}'; a correction must change the status.");
    }
}
