<?php

namespace App\Domain\AcademicStructure\Application\Exceptions;

/**
 * SIS 5.1: a date correction was prepared against a version of the Academic
 * Year or Term that is no longer current (someone else corrected it, or the
 * year was activated). It is refused, never applied over the newer state.
 */
class AcademicCalendarChangedException extends AcademicStructureException
{
    public function __construct()
    {
        parent::__construct(
            409,
            'ACADEMIC_CALENDAR_CHANGED',
            'This record changed after you opened it. Reload the page and make your correction again.',
        );
    }
}
