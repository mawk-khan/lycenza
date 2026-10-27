<?php

namespace App\Support\Dates;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * SIS 10.2 (F-2): a date-only value in an operator-facing message, written
 * the way the pages write it -- `resources/js/datetime.ts`
 * formatCalendarDate(): "1 Apr 2026", never "2026-04-01".
 *
 * A calendar date has no time and no zone, so nothing is converted: the
 * value's own year, month and day are shown. Comparisons and stored values
 * keep using `toDateString()`; this is for the words only.
 */
final class CalendarDate
{
    public static function human(CarbonInterface|string $date): string
    {
        $day = $date instanceof CarbonInterface ? $date->toDateString() : $date;

        return Carbon::parse($day)->format('j M Y');
    }
}
