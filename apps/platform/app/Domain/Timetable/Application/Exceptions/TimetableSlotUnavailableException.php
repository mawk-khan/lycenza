<?php

namespace App\Domain\Timetable\Application\Exceptions;

/**
 * ORG.7D-1 (ADR 0076 §37, D1-12): what a Campus-scoped scheduler sees for ANY
 * teacher, Section or Room slot collision -- in their Campus or another one
 * -- so the answer never says who or what holds the slot, or where.
 * Whole-School schedulers keep the specific TeacherAlreadyScheduled /
 * SectionAlreadyScheduled / RoomAlreadyScheduled messages
 * (TimetableScheduleAccess::concealSlotConflict()). The School-wide
 * uniqueness indexes are unchanged.
 */
class TimetableSlotUnavailableException extends TimetableException
{
    public function __construct()
    {
        parent::__construct(409, 'TIMETABLE_SLOT_UNAVAILABLE', 'This class cannot be scheduled for this day and Period. Choose another teacher, Room, day or Period.');
    }
}
