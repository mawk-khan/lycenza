<?php

namespace App\Support\ServiceAuth;

use Carbon\CarbonImmutable;
use Throwable;

final class ServiceKeyDates
{
    /** A strict `YYYY-MM-DD` calendar date (UTC midnight), or null. */
    public static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            return null;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (Throwable) {
            return null;
        }

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value ? $date : null;
    }

    /** A strict `YYYY-MM-DDTHH:MM:SSZ` UTC instant, or null. */
    public static function instant(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $value) !== 1) {
            return null;
        }
        try {
            $instant = CarbonImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $value, 'UTC');
        } catch (Throwable) {
            return null;
        }

        return $instant instanceof CarbonImmutable && $instant->format('Y-m-d\TH:i:s\Z') === $value ? $instant : null;
    }
}
