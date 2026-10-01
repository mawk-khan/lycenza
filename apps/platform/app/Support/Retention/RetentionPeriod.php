<?php

namespace App\Support\Retention;

use Carbon\CarbonImmutable;

/**
 * E21 (docs/security/E21-RETENTION-DETERMINATION.md): reads one retention
 * period. A period is a positive whole number of days; unset means "not
 * configured" and the caller deletes nothing (fail-closed). Anything else
 * is a configuration error, never silently treated as a period.
 */
final class RetentionPeriod
{
    /**
     * @return int|null days, or null when unset
     *
     * @throws \InvalidArgumentException when set but not a positive whole number
     */
    public static function days(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_numeric($raw) || (int) $raw != $raw || (int) $raw < 1) {
            throw new \InvalidArgumentException('A retention period must be a whole number of days, at least 1.');
        }

        return (int) $raw;
    }

    /**
     * E21.2B: a period in calendar years (validated exactly like days).
     *
     * @throws \InvalidArgumentException when set but not a positive whole number
     */
    public static function years(mixed $raw): ?int
    {
        return self::days($raw);
    }

    /**
     * The cutoff for a calendar-year period, now() minus $years years in UTC,
     * without month overflow: 29 February minus one year is 28 February,
     * never 1 March. PostgreSQL's interval arithmetic agrees, so this matches
     * the database age floor (`retention_assert_floor`). A row is eligible
     * only when its trigger is strictly before the cutoff (the E21.2A
     * convention).
     */
    public static function yearsBeforeNow(int $years): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->subYearsNoOverflow($years);
    }
}
