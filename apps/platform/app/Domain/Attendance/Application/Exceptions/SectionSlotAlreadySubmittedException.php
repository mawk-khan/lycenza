<?php

namespace App\Domain\Attendance\Application\Exceptions;

/**
 * A register already exists for this Section + Period identity + date, via a DIFFERENT TimetableEntry. Reachable because deactivating an entry immediately frees its slot, so a recreated entry could otherwise double-register one cohort.
 */
class SectionSlotAlreadySubmittedException extends AttendanceException
{
    public function __construct()
    {
        parent::__construct(409, 'ATTENDANCE_SECTION_SLOT_ALREADY_SUBMITTED', 'A register has already been submitted for this Section and Period on this date.');
    }
}
