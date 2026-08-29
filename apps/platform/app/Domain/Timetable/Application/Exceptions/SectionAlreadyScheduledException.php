<?php

namespace App\Domain\Timetable\Application\Exceptions;

/**
 * Translated from the database's own
 * `timetable_entries_section_slot_unique` partial unique index
 * (WHERE status = 'active') -- this Section already has another active
 * TimetableEntry for the same School/day-of-week/Period.
 */
class SectionAlreadyScheduledException extends TimetableException
{
    public function __construct()
    {
        parent::__construct(409, 'TIMETABLE_SECTION_ALREADY_SCHEDULED', 'This Section already has another active class scheduled for this day and Period.');
    }
}
