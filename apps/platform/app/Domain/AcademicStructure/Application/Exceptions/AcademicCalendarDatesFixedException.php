<?php

namespace App\Domain\AcademicStructure\Application\Exceptions;

/**
 * SIS 5.1 (MC-3, ADR 0077): only a DRAFT Academic Year's dates, and the dates
 * of its Terms, can be corrected. Once a year is active or closed its calendar
 * is fixed (the database refuses it too, `academic_years_freeze_dates`).
 */
class AcademicCalendarDatesFixedException extends AcademicStructureException
{
    public function __construct(string $status)
    {
        parent::__construct(
            422,
            'ACADEMIC_CALENDAR_DATES_FIXED',
            "Only a draft Academic Year's dates (and its Terms' dates) can be corrected. This Academic Year is {$status}, so its calendar is fixed.",
        );
    }
}
