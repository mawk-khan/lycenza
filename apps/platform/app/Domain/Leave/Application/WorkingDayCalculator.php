<?php

namespace App\Domain\Leave\Application;

use Carbon\CarbonImmutable;

/**
 * HRX.1 (ADR 0065 §4.7): how many half-day units of a requested day portion
 * fall on staff working time. Pure and deterministic:
 *
 *   working halves = the weekday's halves (full: 1+2, first_half: 1, off: none)
 *                    minus the holiday's halves (full: 1+2, first_half: 1, second_half: 2)
 *   units          = |requested halves ∩ working halves|
 *
 * So a holiday or weekly off consumes nothing, a half-working day counts one
 * unit, and only working time ever counts. HRX.2 sums this over a request's
 * dates.
 */
final class WorkingDayCalculator
{
    private const WEEKDAY_HALVES = ['full' => [1, 2], 'first_half' => [1], 'off' => []];

    /**
     * @param  array<int, string>  $weekdays  ISO weekday (1..7) => full|first_half|off; all seven required
     * @param  array<string, string>  $holidays  Y-m-d => full|first_half|second_half
     */
    public function __construct(private readonly array $weekdays, private readonly array $holidays) {}

    public function units(string $date, DayPortion $portion): int
    {
        $weekday = CarbonImmutable::createFromFormat('!Y-m-d', $date)->dayOfWeekIso;
        $working = self::WEEKDAY_HALVES[$this->weekdays[$weekday]];

        if (isset($this->holidays[$date])) {
            $working = array_diff($working, DayPortion::from($this->holidays[$date])->halves());
        }

        return count(array_intersect($portion->halves(), $working));
    }

    /**
     * HRX.2 (ADR 0065 §23.4): the working part of a requested portion on a
     * date, as a portion, or null when none of it is working time.
     */
    public function chargeable(string $date, DayPortion $requested): ?DayPortion
    {
        $weekday = CarbonImmutable::createFromFormat('!Y-m-d', $date)->dayOfWeekIso;
        $working = self::WEEKDAY_HALVES[$this->weekdays[$weekday]];
        if (isset($this->holidays[$date])) {
            $working = array_diff($working, DayPortion::from($this->holidays[$date])->halves());
        }

        return DayPortion::fromHalves(array_values(array_intersect($requested->halves(), $working)));
    }

    /**
     * HRX.3 (ADR 0065 §24.3): today's classification of each half of a date,
     * for Staff Attendance's working-time check and read model. A half off in
     * the weekly pattern is `off`; otherwise a staff holiday covering it makes
     * it `holiday` (the dated fact wins over the weekly pattern only where the
     * pattern would have worked); otherwise `working`.
     *
     * @return array{1: 'working'|'holiday'|'off', 2: 'working'|'holiday'|'off'}
     */
    public function halves(string $date): array
    {
        $weekday = CarbonImmutable::createFromFormat('!Y-m-d', $date)->dayOfWeekIso;
        $working = self::WEEKDAY_HALVES[$this->weekdays[$weekday]];
        $holiday = isset($this->holidays[$date]) ? DayPortion::from($this->holidays[$date])->halves() : [];
        $state = fn (int $half) => ! in_array($half, $working, true) ? 'off' : (in_array($half, $holiday, true) ? 'holiday' : 'working');

        return [1 => $state(1), 2 => $state(2)];
    }

    public function isWorkingDay(string $date): bool
    {
        return $this->units($date, DayPortion::Full) > 0;
    }
}
