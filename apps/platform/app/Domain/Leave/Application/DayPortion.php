<?php

namespace App\Domain\Leave\Application;

/**
 * HRX.1 (ADR 0065 §4.4, owner decision 2026-10-03): the ONE day-portion
 * contract Leave and the future Staff Attendance share. Quantities are
 * integer half-day units: FULL = 2, FIRST_HALF = 1, SECOND_HALF = 1. Never
 * 0.5, never a float or decimal. A half-day always names which half it is,
 * so Leave and Attendance can be reconciled deterministically.
 */
enum DayPortion: string
{
    case Full = 'full';
    case FirstHalf = 'first_half';
    case SecondHalf = 'second_half';

    public function units(): int
    {
        return $this === self::Full ? 2 : 1;
    }

    /** @return list<int> the halves this portion covers (1 = first, 2 = second) */
    public function halves(): array
    {
        return match ($this) {
            self::Full => [1, 2],
            self::FirstHalf => [1],
            self::SecondHalf => [2],
        };
    }
}
