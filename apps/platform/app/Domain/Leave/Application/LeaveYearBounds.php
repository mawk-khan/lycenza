<?php

namespace App\Domain\Leave\Application;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * HRX.1 (ADR 0065 §4.3, §22.1): the deterministic leave year containing a
 * date. Pure. Only LeaveYearService uses it, to cut a NEW materialized year;
 * existing years keep their own frozen bounds.
 *
 * `inSchedule()` applies the School's schedule: the base start month, then
 * each scheduled change from its `effective_from`. The year that would
 * cross a change is cut short the day before it takes effect, giving an
 * explicit TRANSITION year. So consecutive years never overlap and never
 * leave a gap.
 */
final class LeaveYearBounds
{
    private function __construct(
        public readonly string $startsOn,
        public readonly string $endsOn,
        public readonly string $label,
        public readonly int $startMonth,
        public readonly bool $isTransition = false,
    ) {}

    /** @param  string  $date  School-local Y-m-d */
    public static function containing(int $startMonth, string $date): self
    {
        if ($startMonth < 1 || $startMonth > 12) {
            throw new InvalidArgumentException('The leave-year start month is 1..12.');
        }

        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        $year = $day->month >= $startMonth ? $day->year : $day->year - 1;
        $starts = CarbonImmutable::create($year, $startMonth, 1);
        $ends = $starts->addYearNoOverflow()->subDay();

        return new self(
            $starts->toDateString(),
            $ends->toDateString(),
            $startMonth === 1 ? (string) $year : sprintf('%d-%02d', $year, ($year + 1) % 100),
            $startMonth,
        );
    }

    /**
     * @param  list<array{effective_from: string, start_month: int}>  $changes  ascending by effective_from
     * @param  string  $date  School-local Y-m-d
     */
    public static function inSchedule(int $baseMonth, array $changes, string $date): self
    {
        $month = $baseMonth;
        $next = null;
        foreach ($changes as $change) {
            if ($change['effective_from'] <= $date) {
                $month = $change['start_month'];
            } else {
                $next = $change['effective_from'];
                break;
            }
        }

        $full = self::containing($month, $date);
        if ($next === null || $full->endsOn < $next) {
            return $full;
        }

        // The year that would cross the change ends the day before it.
        $ends = CarbonImmutable::createFromFormat('!Y-m-d', $next)->subDay()->toDateString();

        return new self($full->startsOn, $ends, substr($full->startsOn, 0, 7).'/'.substr($ends, 0, 7), $month, true);
    }
}
