<?php

namespace App\Support\Retention;

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
}
