<?php

namespace App\Support\Tenancy;

use App\Models\School;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5A.4 §18 -- `schools.timezone` (Phase 0B, default
 * `Asia/Kolkata`) is a real, editable-via-`App\Http\Controllers\App\SchoolSettingsController`
 * field, so scheduling uses the PREFERRED model: a user enters a
 * scheduled time in their School's configured timezone, the server
 * converts it to canonical UTC for storage, and the due-time
 * comparison and every display back to the user go through this same
 * timezone consistently.
 *
 * X-02 (OD-V11-7, ADR 0075 section 12): every write path now accepts
 * only isSupported() identifiers (SupportedSchoolTimezone rule): a
 * canonical PHP zone identifier that PostgreSQL also recognises, because
 * the value is consumed by both Carbon/DateTimeZone and database triggers
 * (`now() AT TIME ZONE v_timezone`, late-fee runs and payment receipts).
 * `resolve()` keeps its UTC fallback as a backstop for any value written
 * before this check; none exists (column default, factories and demo all
 * use `Asia/Kolkata`).
 */
final class SchoolTimezone
{
    /**
     * The identifiers a School may choose: PHP's canonical IANA zone list
     * (no deprecated aliases such as `Asia/Calcutta`) that PostgreSQL also
     * knows -- so the settings list never offers a zone the database
     * triggers would reject. One query.
     *
     * @return list<string>
     */
    public static function identifiers(): array
    {
        $php = DateTimeZone::listIdentifiers();
        $postgres = DB::table('pg_timezone_names')->whereIn('name', $php)->pluck('name')->all();

        return array_values(array_intersect($php, $postgres));
    }

    /** Whether both runtime consumers (PHP and PostgreSQL) accept `$timezone`. */
    public static function isSupported(string $timezone): bool
    {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            return false;
        }

        return DB::selectOne('SELECT 1 AS ok FROM pg_timezone_names WHERE name = ?', [$timezone]) !== null;
    }

    /**
     * ORG.7E-0 (ADR 0076 §42): the School's own calendar date (Y-m-d) now --
     * the date "current" HR placement is judged on, never the UTC or server
     * date. The one shared form of the `CarbonImmutable::now(resolve())`
     * idiom for new readers.
     */
    public static function today(School $school): string
    {
        return CarbonImmutable::now(self::resolve($school))->toDateString();
    }

    /**
     * ORG.9B (ADR 0076 §68): the exclusive upper bound "through the end of
     * this School-local date" -- the START of the next School-local day, as
     * the UTC wall-clock string timestamps are stored in. Compare with `<`,
     * never a 23:59:59 approximation. Local midnight is taken from the zone's
     * own calendar, so a DST day of 23 or 25 hours is bounded exactly.
     */
    public static function startOfNextDayUtc(School $school, string $localDate): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $localDate, self::resolve($school))
            ->addDay()
            ->startOfDay()
            ->utc()
            ->format('Y-m-d H:i:s');
    }

    /** ORG.9B: the inclusive lower bound -- the START of this School-local date, as a UTC wall-clock string. */
    public static function startOfDayUtc(School $school, string $localDate): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $localDate, self::resolve($school))
            ->startOfDay()
            ->utc()
            ->format('Y-m-d H:i:s');
    }

    public static function resolve(School $school): DateTimeZone
    {
        try {
            return new DateTimeZone($school->timezone);
        } catch (\Exception) {
            return new DateTimeZone((string) config('app.timezone'));
        }
    }
}
