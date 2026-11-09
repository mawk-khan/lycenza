<?php

namespace App\Domain\Leave\Application\Calendar;

use App\Models\School;

/**
 * HR.R1-C22 (WO-5, D6-4, D14-08): after a calendar change is saved, each owner answers what its own
 * records in the changed range might reflect differently. Counts only; nothing is recalculated.
 * Registered by tag in AppServiceProvider, so Leave never names Staff Attendance or Payroll.
 */
interface CalendarChangeImpactSource
{
    public const TAG = 'leave.calendar.impact_sources';

    /** @return array{label: string, count: int} */
    public function impact(School $school, string $from, string $to): array;
}
