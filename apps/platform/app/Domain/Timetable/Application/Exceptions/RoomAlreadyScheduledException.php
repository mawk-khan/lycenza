<?php

namespace App\Domain\Timetable\Application\Exceptions;

/**
 * Translated from the database's own
 * `timetable_entries_room_slot_unique` partial unique index
 * (WHERE status = 'active' AND room_id IS NOT NULL) -- this Room
 * already hosts another active TimetableEntry for the same
 * School/day-of-week/Period.
 */
class RoomAlreadyScheduledException extends TimetableException
{
    public function __construct()
    {
        parent::__construct(409, 'TIMETABLE_ROOM_ALREADY_SCHEDULED', 'This Room already hosts another active class scheduled for this day and Period.');
    }
}
