<?php

namespace Tests\Feature\Authorization;

use App\Domain\Timetable\Application\TimetablePeriodService;
use App\Domain\Timetable\Application\TimetableScheduleService;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Timetable\Concerns\CreatesTimetableFixtures;
use Tests\TestCase;

/**
 * Phase 0H (Timetable foundation) -- no controller exists yet, so
 * TimetablePeriodService/TimetableScheduleService are the authoritative
 * production entry points and authorize `timetable.periods.manage`/
 * `timetable.schedule.manage` themselves (see both classes' own
 * docblocks). Mirrors HrDirectoryAuthorizationTest.php's exact
 * allow/deny pattern -- CLAUDE.md rule 13's "both an allow and unauthorized
 * test" requirement, applied at the Application-layer boundary this
 * checkpoint actually has, since there is no HTTP layer yet to test it
 * at instead.
 */
class TimetableServiceAuthorizationTest extends TestCase
{
    use CreatesTimetableFixtures;

    #[Test]
    public function timetable_periods_manage_allows_period_creation(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, ['timetable.periods.manage']);

        $period = app(TimetablePeriodService::class)->create($school, [
            'code' => 'P1', 'name' => 'Period 1', 'start_time' => '09:00:00', 'end_time' => '09:45:00',
        ], $actor);

        $this->assertSame('P1', $period->code);
    }

    #[Test]
    public function an_unrelated_capability_does_not_grant_period_management(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, ['timetable.periods.view']);

        $this->expectException(AuthorizationException::class);

        app(TimetablePeriodService::class)->create($school, [
            'code' => 'P1', 'name' => 'Period 1', 'start_time' => '09:00:00', 'end_time' => '09:45:00',
        ], $actor);
    }

    #[Test]
    public function timetable_schedule_manage_allows_entry_creation(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $subject = $this->createSubject($school);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $subject, ['is_required' => true, 'status' => 'active']);
        $section = $this->createSection($year, $campus, $grade, ['status' => 'active']);
        $teacher = $this->createEmployee($school, ['record_status' => 'active']);
        $period = $this->createTimetablePeriod($school, ['start_time' => '09:00:00', 'end_time' => '09:45:00']);
        $actor = $this->createUserWithCapabilities($school, ['timetable.schedule.manage']);

        $entry = app(TimetableScheduleService::class)->create($school, $offering, $section, $teacher, null, $period, 1, $actor);

        $this->assertSame('active', $entry->status);
    }

    #[Test]
    public function an_unrelated_capability_does_not_grant_schedule_management(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $subject = $this->createSubject($school);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $subject, ['is_required' => true, 'status' => 'active']);
        $section = $this->createSection($year, $campus, $grade, ['status' => 'active']);
        $teacher = $this->createEmployee($school, ['record_status' => 'active']);
        $period = $this->createTimetablePeriod($school, ['start_time' => '09:00:00', 'end_time' => '09:45:00']);
        $actor = $this->createUserWithCapabilities($school, ['timetable.schedule.view']);

        $this->expectException(AuthorizationException::class);

        app(TimetableScheduleService::class)->create($school, $offering, $section, $teacher, null, $period, 1, $actor);
    }

    #[Test]
    public function a_wrong_school_actor_is_denied_period_management(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $actor = $this->createUserWithCapabilities($schoolB, ['timetable.periods.manage']);

        $this->expectException(AuthorizationException::class);

        app(TimetablePeriodService::class)->create($schoolA, [
            'code' => 'P1', 'name' => 'Period 1', 'start_time' => '09:00:00', 'end_time' => '09:45:00',
        ], $actor);
    }
}
