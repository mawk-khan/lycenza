<?php

namespace App\Domain\Timetable\Application\Exceptions;

class TimetablePeriodNotFoundException extends TimetableException
{
    public function __construct(?string $periodId = null)
    {
        parent::__construct(404, 'TIMETABLE_PERIOD_NOT_FOUND', $periodId !== null
            ? "TimetablePeriod '{$periodId}' was not found for this School."
            : 'TimetablePeriod was not found for this School.');
    }
}
