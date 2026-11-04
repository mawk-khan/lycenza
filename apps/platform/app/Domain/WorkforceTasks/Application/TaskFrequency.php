<?php

namespace App\Domain\WorkforceTasks\Application;

use App\Domain\WorkforceTasks\Infrastructure\TaskTemplateVersion;
use Carbon\CarbonImmutable;

/**
 * HR.13 (owner decision 2026-11-04, "scheduled, calendar-neutral"): when a
 * template version has an occurrence. A closed list; the database CHECK
 * (`workforce_task_template_versions_schedule_check`) enforces each shape.
 * No holiday or working calendar is consulted (WorkSchedule is gated, and the
 * staff calendar belongs to Leave, which HR never depends on).
 *
 * - daily: on every weekday in the template's set (bit 1 = Monday ... 64 = Sunday);
 * - weekly: on its one weekday;
 * - monthly: on day 1-28, or on the last day of every month;
 * - yearly: on a month and a day that exists every year (never 29 February),
 *   or on that month's last day.
 */
enum TaskFrequency: string
{
    case DAILY = 'daily';
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';
    case YEARLY = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::DAILY => 'Daily',
            self::WEEKLY => 'Weekly',
            self::MONTHLY => 'Monthly',
            self::YEARLY => 'Yearly',
        };
    }

    /** The bit of a School-local date's weekday (Monday = 1 ... Sunday = 64). */
    public static function weekdayBit(CarbonImmutable $date): int
    {
        return 1 << ($date->dayOfWeekIso - 1);
    }

    /** Whether the version has an occurrence on this School-local date. */
    public static function occursOn(TaskTemplateVersion $version, CarbonImmutable $date): bool
    {
        return match (self::from($version->frequency)) {
            self::DAILY, self::WEEKLY => ($version->weekdays & self::weekdayBit($date)) !== 0,
            self::MONTHLY => $version->last_day ? $date->day === $date->daysInMonth : $date->day === $version->month_day,
            self::YEARLY => $date->month === $version->year_month
                && ($version->last_day ? $date->day === $date->daysInMonth : $date->day === $version->month_day),
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $f) => ['value' => $f->value, 'label' => $f->label()], self::cases());
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $f) => $f->value, self::cases());
    }
}
