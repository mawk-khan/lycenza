<?php

namespace App\Support\Validation;

use App\Models\School;
use App\Support\Tenancy\SchoolTimezone;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * SIS 3.2: THE date-of-birth rule for a Student and an Applicant -- a
 * date of birth is never later than the School's own calendar date
 * (SchoolTimezone::today(), never UTC or the server date). A date-only
 * value: compared as `Y-m-d`, never converted between time zones. No age
 * rule: this only refuses a future date.
 *
 * Controllers validate with rules()/messages() (a field error); the
 * Application services that write a date of birth assert() it again as
 * the authoritative backstop, so no caller can bypass the form rule.
 */
final class DateOfBirth
{
    public const string FUTURE_MESSAGE = 'The date of birth cannot be in the future.';

    /** @return list<string> */
    public static function rules(School $school, bool $required = true): array
    {
        return [$required ? 'required' : 'sometimes', 'date', 'before_or_equal:'.SchoolTimezone::today($school)];
    }

    /** @return array<string, string> */
    public static function messages(string $field = 'date_of_birth'): array
    {
        return ["{$field}.before_or_equal" => self::FUTURE_MESSAGE];
    }

    public static function isFuture(School $school, CarbonInterface|string $dateOfBirth): bool
    {
        // Parsed as a calendar date only (no zone conversion), so "2026-1-5" compares correctly.
        $day = ($dateOfBirth instanceof CarbonInterface ? $dateOfBirth : CarbonImmutable::parse($dateOfBirth))->toDateString();

        return $day > SchoolTimezone::today($school);
    }

    /** The field error for a future date of birth. */
    public static function assert(School $school, CarbonInterface|string $dateOfBirth, string $field = 'date_of_birth'): void
    {
        if (self::isFuture($school, $dateOfBirth)) {
            throw ValidationException::withMessages([$field => [self::FUTURE_MESSAGE]]);
        }
    }
}
