<?php

namespace App\Domain\Attendance\Application\Exceptions;

/**
 * A correction whose new_status equals the current status changes nothing, so it is rejected rather than writing a misleading corrected_at and audit record.
 */
class AttendanceCorrectionNoOpException extends AttendanceException
{
    public function __construct(public readonly string $status)
    {
        // SIS 9.1: the operator's words, never a status key (same code).
        parent::__construct(422, 'ATTENDANCE_CORRECTION_NO_OP', 'This mark is already '.ucfirst($status).'. Choose a different status to correct it.');
    }
}
