<?php

namespace App\Domain\Students\Domain;

use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\Tenancy\SchoolTimezone;
use Carbon\CarbonImmutable;

/**
 * Phase 0H.4D-P2 -- the canonical, School-local age computation this
 * checkpoint needs (no shared age helper existed anywhere in this
 * repository before it). Deliberately explicit month/day comparison,
 * never Carbon's diff-in-years convenience methods directly and never
 * approximate month-count arithmetic (`config('app.timezone')`/server
 * UTC alone would silently misclassify a Student around midnight for
 * a School in a materially different timezone) -- this is the exact
 * class of ambiguity `App\Support\Auth\AssuranceFreshness` was
 * introduced to avoid for signed time diffs, applied here to age.
 *
 * A Student becomes an adult ON their 18th birthday (inclusive), in
 * the School's configured local calendar date -- never the server's.
 * `$asOf` is always explicit or School-local "now"; it is never
 * implicitly PHP's/the container's local timezone.
 *
 * Leap-day (`Feb 29`) DOB convention, deliberately chosen and tested
 * (`StudentAgeTest`) since the approved privacy decision does not
 * specify one: in a non-leap `$asOf` year, `Feb 28` is still the
 * PRIOR age (the birthday has not yet structurally occurred that
 * year), and the new age begins `Mar 1`.
 */
final class StudentAge
{
    public static function isAdult(Student $student, School $school, ?CarbonImmutable $asOf = null): bool
    {
        return self::years($student, $school, $asOf) >= 18;
    }

    public static function years(Student $student, School $school, ?CarbonImmutable $asOf = null): int
    {
        $timezone = SchoolTimezone::resolve($school);
        $asOfDate = ($asOf ?? CarbonImmutable::now($timezone))->setTimezone($timezone)->startOfDay();
        $dob = CarbonImmutable::parse($student->date_of_birth->format('Y-m-d'), $timezone)->startOfDay();

        $age = $asOfDate->year - $dob->year;

        $birthdayNotYetReachedThisYear = $asOfDate->month < $dob->month
            || ($asOfDate->month === $dob->month && $asOfDate->day < $dob->day);

        if ($birthdayNotYetReachedThisYear) {
            $age--;
        }

        return $age;
    }
}
