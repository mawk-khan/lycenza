<?php

namespace Tests\Feature\Students\ProcessingAuthorization;

use App\Domain\Students\Domain\StudentAge;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Students\ProcessingAuthorization\Concerns\CreatesProcessingAuthorizationFixtures;
use Tests\TestCase;

/**
 * Phase 0H.4D-P2 §22/§48 -- deterministic dates only, never wall-clock
 * dependent, and always School-timezone-aware (never server UTC).
 */
class StudentAgeTest extends TestCase
{
    use CreatesProcessingAuthorizationFixtures;

    #[Test]
    public function the_day_before_the_18th_birthday_is_still_17(): void
    {
        $school = $this->createSchool(['timezone' => 'UTC']);
        $student = $this->createStudent($school, ['date_of_birth' => '2008-06-15']);

        $this->assertSame(17, StudentAge::years($student, $school, CarbonImmutable::parse('2026-06-14')));
        $this->assertFalse(StudentAge::isAdult($student, $school, CarbonImmutable::parse('2026-06-14')));
    }

    #[Test]
    public function the_exact_18th_birthday_is_already_18(): void
    {
        $school = $this->createSchool(['timezone' => 'UTC']);
        $student = $this->createStudent($school, ['date_of_birth' => '2008-06-15']);

        $this->assertSame(18, StudentAge::years($student, $school, CarbonImmutable::parse('2026-06-15')));
        $this->assertTrue(StudentAge::isAdult($student, $school, CarbonImmutable::parse('2026-06-15')));
    }

    #[Test]
    public function the_day_after_the_18th_birthday_is_18(): void
    {
        $school = $this->createSchool(['timezone' => 'UTC']);
        $student = $this->createStudent($school, ['date_of_birth' => '2008-06-15']);

        $this->assertSame(18, StudentAge::years($student, $school, CarbonImmutable::parse('2026-06-16')));
        $this->assertTrue(StudentAge::isAdult($student, $school, CarbonImmutable::parse('2026-06-16')));
    }

    #[Test]
    public function a_leap_day_date_of_birth_becomes_the_new_age_on_march_first_in_a_non_leap_year(): void
    {
        $school = $this->createSchool(['timezone' => 'UTC']);
        $student = $this->createStudent($school, ['date_of_birth' => '2008-02-29']);

        // 2026 is not a leap year -- Feb 29 does not exist that year.
        $this->assertSame(17, StudentAge::years($student, $school, CarbonImmutable::parse('2026-02-28')));
        $this->assertSame(18, StudentAge::years($student, $school, CarbonImmutable::parse('2026-03-01')));
    }

    #[Test]
    public function a_leap_day_date_of_birth_turns_on_the_leap_day_itself_in_a_leap_year(): void
    {
        $school = $this->createSchool(['timezone' => 'UTC']);
        $student = $this->createStudent($school, ['date_of_birth' => '2004-02-29']);

        // 2024 IS a leap year -- Feb 29 exists.
        $this->assertSame(20, StudentAge::years($student, $school, CarbonImmutable::parse('2024-02-29')));
    }

    #[Test]
    public function age_is_evaluated_in_the_schools_local_timezone_not_utc(): void
    {
        // 2026-06-15 00:30 UTC is still 2026-06-14 in a School west of
        // UTC (e.g. America/Los_Angeles, UTC-7 in June) -- the birthday
        // has NOT yet occurred there even though it has in UTC.
        $school = $this->createSchool(['timezone' => 'America/Los_Angeles']);
        $student = $this->createStudent($school, ['date_of_birth' => '2008-06-15']);

        $asOf = CarbonImmutable::parse('2026-06-15 00:30:00', 'UTC');

        $this->assertSame(17, StudentAge::years($student, $school, $asOf));
        $this->assertFalse(StudentAge::isAdult($student, $school, $asOf));
    }

    #[Test]
    public function age_is_evaluated_in_the_schools_local_timezone_crossing_midnight_the_other_direction(): void
    {
        // For a School east of UTC (Asia/Kolkata, UTC+5:30), a UTC
        // instant still on 2026-06-14 can already be 2026-06-15 local
        // -- the birthday HAS occurred there.
        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $student = $this->createStudent($school, ['date_of_birth' => '2008-06-15']);

        $asOf = CarbonImmutable::parse('2026-06-14 20:00:00', 'UTC');

        $this->assertSame(18, StudentAge::years($student, $school, $asOf));
        $this->assertTrue(StudentAge::isAdult($student, $school, $asOf));
    }
}
