<?php

namespace App\Domain\Leave\Application;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * HRX.1 (ADR 0065 §4.3): the deterministic leave year containing a date
 * for a start month. Pure. Only LeaveYearService uses it, to cut a NEW
 * materialized year; existing years keep their own frozen bounds.
 */
final class LeaveYearBounds
{
    private function __construct(
        public readonly string $startsOn,
        public readonly string $endsOn,
        public readonly string $label,
        public readonly int $startMonth,
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
}
