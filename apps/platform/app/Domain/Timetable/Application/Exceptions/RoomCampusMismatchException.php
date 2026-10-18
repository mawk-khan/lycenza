<?php

namespace App\Domain\Timetable\Application\Exceptions;

/**
 * ORG.7D-0 R1 (ADR 0076 §36): a Room may be scheduled only for an entry of
 * its own Campus. `timetable_entries_room_campus_fk` is the structural
 * backstop; this is the typed refusal the service raises first.
 */
class RoomCampusMismatchException extends TimetableException
{
    public function __construct()
    {
        parent::__construct(422, 'TIMETABLE_ROOM_CAMPUS_MISMATCH', 'This Room is on a different Campus from the Section and cannot be scheduled for it.');
    }
}
