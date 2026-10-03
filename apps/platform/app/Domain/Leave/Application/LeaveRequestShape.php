<?php

namespace App\Domain\Leave\Application;

use App\Domain\Leave\Application\Exceptions\LeaveException;
use Carbon\CarbonImmutable;

/**
 * HRX.2 (ADR 0065 §23.1): the requested period of a leave request, and its
 * deterministic expansion into dated half-days. Pure.
 *
 * - One day: start and end portions are equal (`full`, `first_half` or
 *   `second_half`).
 * - Several days: the first day is `full` or `second_half`, the last day
 *   `full` or `first_half`, and every day between is `full`. The request is
 *   one contiguous run of half-days, so it never has a gap.
 *
 * The database CHECK `leave_requests_shape_check` enforces the same shape.
 */
final class LeaveRequestShape
{
    public const MAX_SPAN_DAYS = 366;

    private function __construct(
        public readonly string $startsOn,
        public readonly DayPortion $startPortion,
        public readonly string $endsOn,
        public readonly DayPortion $endPortion,
    ) {}

    public static function of(string $startsOn, string $startPortion, string $endsOn, string $endPortion): self
    {
        $start = DayPortion::tryFrom($startPortion);
        $end = DayPortion::tryFrom($endPortion);
        if ($start === null || $end === null || ! self::isDate($startsOn) || ! self::isDate($endsOn)) {
            throw LeaveException::invalid('LEAVE_REQUEST_SHAPE_INVALID', 'A request has a start date and portion and an end date and portion.');
        }
        if ($startsOn > $endsOn) {
            throw LeaveException::invalid('LEAVE_REQUEST_SHAPE_INVALID', 'The request ends on or after the day it starts.');
        }
        $span = CarbonImmutable::createFromFormat('!Y-m-d', $startsOn)->diffInDays(CarbonImmutable::createFromFormat('!Y-m-d', $endsOn));
        if ($span > self::MAX_SPAN_DAYS) {
            throw LeaveException::invalid('LEAVE_REQUEST_TOO_LONG', 'A single request covers at most '.self::MAX_SPAN_DAYS.' days.');
        }
        if ($startsOn === $endsOn ? $start !== $end : ($start === DayPortion::FirstHalf || $end === DayPortion::SecondHalf)) {
            throw LeaveException::invalid('LEAVE_REQUEST_SHAPE_INVALID', $startsOn === $endsOn
                ? 'A one-day request has one portion: full, first half or second half.'
                : 'A multi-day request starts with a full day or the second half and ends with a full day or the first half.');
        }

        return new self($startsOn, $start, $endsOn, $end);
    }

    private static function isDate(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    public function usesHalfDays(): bool
    {
        return $this->startPortion !== DayPortion::Full || $this->endPortion !== DayPortion::Full;
    }

    /** @return array<string, DayPortion> every date of the request => the portion requested that day */
    public function days(): array
    {
        if ($this->startsOn === $this->endsOn) {
            return [$this->startsOn => $this->startPortion];
        }

        $days = [];
        for ($d = CarbonImmutable::createFromFormat('!Y-m-d', $this->startsOn); $d->toDateString() <= $this->endsOn; $d = $d->addDay()) {
            $days[$d->toDateString()] = DayPortion::Full;
        }
        $days[$this->startsOn] = $this->startPortion;
        $days[$this->endsOn] = $this->endPortion;

        return $days;
    }

    /**
     * The chargeable part of each date: requested halves that are staff working time.
     *
     * @return array<string, DayPortion> only dates with at least one chargeable half
     */
    public function chargeable(WorkingDayCalculator $calendar): array
    {
        $chargeable = [];
        foreach ($this->days() as $date => $portion) {
            if (($charged = $calendar->chargeable($date, $portion)) !== null) {
                $chargeable[$date] = $charged;
            }
        }

        return $chargeable;
    }
}
