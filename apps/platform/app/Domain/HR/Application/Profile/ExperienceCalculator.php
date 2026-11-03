<?php

namespace App\Domain\HR\Application\Profile;

use Carbon\CarbonImmutable;

/**
 * HR.2A (brief §26–§27): experience is DERIVED from the dated records, never a
 * second editable number that could drift. Pure: no database, no clock (the
 * caller passes the School-local today).
 *
 * - **Previous experience** = the union of the live previous-experience
 *   records' inclusive date ranges. Overlapping or adjacent periods are merged
 *   first, so concurrent jobs are never double counted. A record with no end
 *   date is NOT counted (its length is unknown) and is reported as open-ended.
 *   A period that runs past today is cut at today.
 * - **Service in this School** = the union of the Employee's employments'
 *   inclusive ranges up to today (rehires add up; the gap between them does
 *   not). Kept separate from previous experience.
 * - **Precision:** whole days are summed; the display is whole months
 *   (floor(days / 30.4375), the mean Gregorian month), split into years and
 *   months. Rounded down: never overstated.
 */
final class ExperienceCalculator
{
    private const DAYS_PER_MONTH = 30.4375;

    /**
     * @param  list<array{0: string, 1: ?string}>  $periods  [starts_on, ends_on|null], inclusive
     * @return array{days: int, years: int, months: int, openEnded: int}
     */
    public static function previous(array $periods, string $today): array
    {
        $closed = array_values(array_filter($periods, fn (array $p) => $p[1] !== null));

        return self::summarize(self::mergedDays($closed, $today)) + ['openEnded' => count($periods) - count($closed)];
    }

    /**
     * @param  list<array{0: string, 1: ?string}>  $employments  [starts_on, ends_on|null], inclusive; null = still employed
     * @return array{days: int, years: int, months: int}
     */
    public static function service(array $employments, string $today): array
    {
        return self::summarize(self::mergedDays($employments, $today));
    }

    /** @param  list<array{0: string, 1: ?string}>  $periods */
    private static function mergedDays(array $periods, string $today): int
    {
        $ranges = [];
        foreach ($periods as [$start, $end]) {
            $end = $end === null || $end > $today ? $today : $end;
            if ($start <= $end) {
                $ranges[] = [$start, $end];
            }
        }
        usort($ranges, fn (array $a, array $b) => strcmp($a[0], $b[0]));

        $days = 0;
        $current = null;
        foreach ($ranges as [$start, $end]) {
            if ($current !== null && $start <= CarbonImmutable::parse($current[1])->addDay()->toDateString()) {
                $current[1] = max($current[1], $end);

                continue;
            }
            if ($current !== null) {
                $days += self::inclusiveDays($current[0], $current[1]);
            }
            $current = [$start, $end];
        }
        if ($current !== null) {
            $days += self::inclusiveDays($current[0], $current[1]);
        }

        return $days;
    }

    private static function inclusiveDays(string $start, string $end): int
    {
        return (int) CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)) + 1;
    }

    /** @return array{days: int, years: int, months: int} */
    private static function summarize(int $days): array
    {
        $months = (int) floor($days / self::DAYS_PER_MONTH);

        return ['days' => $days, 'years' => intdiv($months, 12), 'months' => $months % 12];
    }
}
