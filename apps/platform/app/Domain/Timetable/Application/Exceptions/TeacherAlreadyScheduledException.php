<?php

namespace App\Domain\Timetable\Application\Exceptions;

/**
 * Translated from the database's own
 * `timetable_entries_teacher_slot_unique` partial unique index
 * (WHERE status = 'active') -- this teacher already has another active
 * TimetableEntry for the same School/day-of-week/Period. The database
 * constraint is the real concurrency guarantee; this is the clean,
 * typed error a caller sees instead of a raw QueryException.
 */
class TeacherAlreadyScheduledException extends TimetableException
{
    public function __construct()
    {
        parent::__construct(409, 'TIMETABLE_TEACHER_ALREADY_SCHEDULED', 'This teacher already has another active class scheduled for this day and Period.');
    }
}
