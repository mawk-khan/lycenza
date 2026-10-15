<?php

namespace App\Domain\AcademicStructure\Application\Exceptions;

/**
 * UAT-C1 (UAT-YR-07, owner decision "0 days early"): a draft Academic Year
 * may be activated only once its start date has arrived in the School's
 * own timezone. Activating closes the current active Year, so an early
 * activation would end the real school year with no way back (a closed
 * Year is never reopened). Future-year preparation stays in `draft`.
 */
class AcademicYearNotStartedException extends AcademicStructureException
{
    public function __construct(string $startsOn)
    {
        parent::__construct(
            422,
            'ACADEMIC_YEAR_NOT_STARTED',
            "This Academic Year starts on {$startsOn} and cannot be activated before that date.",
        );
    }
}
