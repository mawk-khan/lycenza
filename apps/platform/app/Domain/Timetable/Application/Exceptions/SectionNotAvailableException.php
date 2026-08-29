<?php

namespace App\Domain\Timetable\Application\Exceptions;

class SectionNotAvailableException extends TimetableException
{
    public function __construct()
    {
        parent::__construct(422, 'TIMETABLE_SECTION_NOT_AVAILABLE', 'This Section is not active and cannot be scheduled.');
    }
}
