<?php

namespace App\Support\Retention\Erasure;

use App\Models\School;
use App\Support\Retention\RetentionPeriod;
use App\Support\Tenancy\SchoolTimezone;
use Carbon\CarbonImmutable;

/**
 * E21.2F: the School-local cutoff and first eligible day for one adopted
 * period, computed exactly like the retention commands do (calendar years,
 * no leap-day overflow, strict boundary).
 */
final class ErasurePeriods
{
    /** The School-local cutoff date: a trigger strictly before it is past the period. */
    public static function cutoff(School $school, int $years): string
    {
        return CarbonImmutable::now(SchoolTimezone::resolve($school))->subYearsNoOverflow($years)->toDateString();
    }

    /** The first day a trigger on `$date` is past the period. */
    public static function firstEligibleDay(string $date, int $years): string
    {
        return CarbonImmutable::parse($date)->addYearsNoOverflow($years)->addDay()->toDateString();
    }

    /** A configured period, or null (unset: nothing is ever eligible). */
    public static function years(string $key): ?int
    {
        return RetentionPeriod::years(config("retention.{$key}"));
    }
}
