<?php

namespace Tests\Feature\Timetable;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\Subject;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Timetable\Infrastructure\TimetablePeriod;
use App\Models\Campus;
use App\Models\School;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Timetable\Concerns\CreatesTimetableFixtures;
use Tests\TestCase;

/**
 * Phase 0H -- the /api/v1 Timetable Period + Schedule (Entry) surface:
 * authentication/authorization boundaries, cross-School rejection
 * (IDOR), and the Timetable-owned helper/search endpoints' capability
 * boundary (never Academic Structure's `academics.*` or HR's employee-
 * management capabilities). Mirrors CanteenOrderApiTest's exact
 * pattern. Domain-level invariants (overlap, double-booking,
 * concurrency) are already proven by the Application-layer test
 * suites; these tests cover the HTTP boundary only.
 */
class TimetableApiTest extends TestCase
{
    use CreatesTimetableFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    /**
     * @return array{school: School, year: AcademicYear, campus: Campus, grade: GradeLevel, subject: Subject, offering: SubjectOffering, section: Section, teacher: Employee, period: TimetablePeriod}
     */
    private function buildSchedulableGraph($school): array
    {
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $subject = $this->createSubject($school);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $subject, ['is_required' => true, 'status' => 'active']);
        $section = $this->createSection($year, $campus, $grade, ['status' => 'active']);
        $teacher = $this->createEmployee($school, ['record_status' => 'active']);
        $period = $this->createTimetablePeriod($school, ['start_time' => '09:00:00', 'end_time' => '09:45:00']);

        // `$year`/`$subject` are returned directly (not read back via
        // `$offering->academicYear`/`->subject`) -- those relations
        // would lazy-load OUTSIDE any `TenantContext::withSchool()`
        // scope here and RLS would silently filter them to null.
        return compact('school', 'year', 'campus', 'grade', 'subject', 'offering', 'section', 'teacher', 'period');
    }

    // --- Guest / unauthenticated -----------------------------------------

    #[Test]
    public function a_guest_is_denied_on_every_timetable_endpoint(): void
    {
        $school = $this->createSchool();

        $this->getJson("/api/v1/schools/{$school->id}/timetable-periods")->assertUnauthorized();
        $this->postJson("/api/v1/schools/{$school->id}/timetable-periods", [])->assertUnauthorized();
        $this->getJson("/api/v1/schools/{$school->id}/timetable-entries")->assertUnauthorized();
        $this->postJson("/api/v1/schools/{$school->id}/timetable-entries", [])->assertUnauthorized();
    }

    // --- Periods: authorization -------------------------------------------

    #[Test]
    public function a_member_with_periods_view_can_list_periods(): void
    {
        [$user, $school] = $this->createSchoolAdmin('principal');
        $this->createTimetablePeriod($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/timetable-periods")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function a_member_without_periods_manage_cannot_create_a_period(): void
    {
        $school = $this->createSchool();
        $deniedUser = $this->createUserWithCapabilities($school, ['timetable.periods.view']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($deniedUser))
            ->postJson("/api/v1/schools/{$school->id}/timetable-periods", [
                'code' => 'P1', 'name' => 'Period 1', 'start_time' => '09:00:00', 'end_time' => '09:45:00',
            ])
            ->assertForbidden();
    }

    #[Test]
    public function a_periods_manage_member_can_create_activate_and_deactivate_a_period(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $create = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/timetable-periods", [
                'code' => 'p1', 'name' => 'Period 1', 'start_time' => '09:00:00', 'end_time' => '09:45:00',
            ]);
        $create->assertCreated();
        $this->assertSame('P1', $create->json('data.code'));
        $periodId = $create->json('data.id');

        $deactivate = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/timetable-periods/{$periodId}/deactivate");
        $deactivate->assertOk();
        $this->assertSame('inactive', $deactivate->json('data.status'));

        $activate = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/timetable-periods/{$periodId}/activate");
        $activate->assertOk();
        $this->assertSame('active', $activate->json('data.status'));
    }

    // --- Periods: cross-School rejection (IDOR) ----------------------------

    #[Test]
    public function school_a_cannot_see_school_bs_period(): void
    {
        [$userA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $periodB = $this->createTimetablePeriod($schoolB);

        $this->withHeader('Authorization', 'Bearer '.$this->token($userA))
            ->getJson("/api/v1/schools/{$schoolA->id}/timetable-periods/{$periodB->id}")
            ->assertNotFound();
    }

    // --- Schedule (Entry): authorization ------------------------------------

    #[Test]
    public function a_member_without_schedule_manage_cannot_create_an_entry(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $graph = $this->buildSchedulableGraph($school);
        $deniedUser = $this->createUserWithCapabilities($school, ['timetable.schedule.view']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($deniedUser))
            ->postJson("/api/v1/schools/{$school->id}/timetable-entries", [
                'subject_offering_id' => $graph['offering']->id,
                'section_id' => $graph['section']->id,
                'teacher_id' => $graph['teacher']->id,
                'period_id' => $graph['period']->id,
                'day_of_week' => 1,
            ])
            ->assertForbidden();
    }

    #[Test]
    public function a_schedule_manage_member_can_create_and_deactivate_an_entry(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $graph = $this->buildSchedulableGraph($school);

        $create = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/timetable-entries", [
                'subject_offering_id' => $graph['offering']->id,
                'section_id' => $graph['section']->id,
                'teacher_id' => $graph['teacher']->id,
                'period_id' => $graph['period']->id,
                'day_of_week' => 1,
            ]);
        $create->assertCreated();
        $this->assertSame('active', $create->json('data.status'));
        $entryId = $create->json('data.id');

        $deactivate = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/timetable-entries/{$entryId}/deactivate");
        $deactivate->assertOk();
        $this->assertSame('inactive', $deactivate->json('data.status'));
    }

    /**
     * Sensitive-tier data-minimization pin at the API boundary: the
     * entry presenter must never include the teacher's work_email/
     * work_phone.
     */
    #[Test]
    public function the_entry_presenter_never_leaks_teacher_hr_fields(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $graph = $this->buildSchedulableGraph($school);
        $graph['teacher']->update(['work_email' => 'teacher@example.test']);
        $entry = $this->createTimetableEntry($graph['offering'], $graph['section'], $graph['teacher'], $graph['period']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/timetable-entries/{$entry->id}")
            ->assertOk();

        $row = $response->json('data');
        $this->assertArrayNotHasKey('teacherWorkEmail', $row);
        $this->assertArrayNotHasKey('workEmail', $row);
        $this->assertArrayNotHasKey('teacherEmail', $row);
    }

    // --- Schedule (Entry): cross-School rejection (IDOR) --------------------

    #[Test]
    public function school_a_cannot_see_school_bs_entry(): void
    {
        [$userA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $graphB = $this->buildSchedulableGraph($schoolB);
        $entryB = $this->createTimetableEntry($graphB['offering'], $graphB['section'], $graphB['teacher'], $graphB['period']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($userA))
            ->getJson("/api/v1/schools/{$schoolA->id}/timetable-entries/{$entryB->id}")
            ->assertNotFound();
    }

    // --- Timetable-owned helper/search endpoints: capability boundary ------

    #[Test]
    public function a_member_without_schedule_manage_cannot_use_the_entry_search_endpoints(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['timetable.schedule.view']);

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));
        $client->getJson("/api/v1/schools/{$school->id}/timetable-entries/search/subject-offerings?q=a")->assertForbidden();
        $client->getJson("/api/v1/schools/{$school->id}/timetable-entries/search/sections?q=a")->assertForbidden();
        $client->getJson("/api/v1/schools/{$school->id}/timetable-entries/search/teachers?q=a")->assertForbidden();
        $client->getJson("/api/v1/schools/{$school->id}/timetable-entries/search/rooms?q=a")->assertForbidden();
    }

    #[Test]
    public function a_member_with_neither_timetable_capability_cannot_use_any_entry_helper(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, []);

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));
        $client->getJson("/api/v1/schools/{$school->id}/timetable-entries/search/subject-offerings?q=a")->assertForbidden();
        $client->getJson("/api/v1/schools/{$school->id}/timetable-entries/search/sections?q=a")->assertForbidden();
        $client->getJson("/api/v1/schools/{$school->id}/timetable-entries/search/teachers?q=a")->assertForbidden();
        $client->getJson("/api/v1/schools/{$school->id}/timetable-entries/search/rooms?q=a")->assertForbidden();
        $client->getJson("/api/v1/schools/{$school->id}/timetable-entries/search/periods?q=a")->assertForbidden();
    }

    /**
     * THE load-bearing capability-boundary assertion this checkpoint's
     * brief calls out explicitly, pinned at the /api/v1 boundary too: a
     * user granted ONLY `timetable.schedule.manage` (deliberately NO
     * `academics.subjects.manage`, NO HR employee-management
     * capability) can still use every SubjectOffering/Section/teacher/
     * Room helper.
     */
    #[Test]
    public function a_schedule_manage_member_without_academics_or_hr_capability_can_use_the_search_endpoints(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['timetable.schedule.manage']);
        $graph = $this->buildSchedulableGraph($school);
        $room = $this->createRoom($this->createCampus($school));

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));
        $client->getJson("/api/v1/schools/{$school->id}/timetable-entries/search/subject-offerings?q=".$graph['subject']->code)
            ->assertOk()->assertJsonCount(1, 'data');
        $client->getJson("/api/v1/schools/{$school->id}/timetable-entries/search/sections?q=".$graph['section']->code)
            ->assertOk()->assertJsonCount(1, 'data');
        $client->getJson("/api/v1/schools/{$school->id}/timetable-entries/search/teachers?q=".$graph['teacher']->full_name)
            ->assertOk()->assertJsonCount(1, 'data');
        $client->getJson("/api/v1/schools/{$school->id}/timetable-entries/search/rooms?q=".$room->code)
            ->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function the_teacher_search_endpoint_never_leaks_hr_fields_via_the_api(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['timetable.schedule.manage']);
        $teacher = $this->createEmployee($school, ['record_status' => 'active', 'full_name' => 'Findable Teacher', 'work_email' => 'findable@example.test']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/timetable-entries/search/teachers?q=Findable")
            ->assertOk();

        $row = $response->json('data.0');
        $this->assertSame($teacher->id, $row['id']);
        $this->assertArrayHasKey('fullName', $row);
        $this->assertArrayNotHasKey('workEmail', $row);
        $this->assertArrayNotHasKey('work_email', $row);
        $this->assertArrayNotHasKey('workPhone', $row);
    }

    #[Test]
    public function the_period_search_endpoint_requires_periods_view_not_schedule_manage(): void
    {
        $school = $this->createSchool();
        // Deliberately grants ONLY timetable.schedule.manage -- no
        // timetable.periods.* -- pinning
        // TimetableEntryController::searchPeriods()'s own capability
        // choice at the API layer too.
        $user = $this->createUserWithCapabilities($school, ['timetable.schedule.manage']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/timetable-entries/search/periods?q=a")
            ->assertForbidden();
    }

    #[Test]
    public function periods_view_alone_is_sufficient_for_the_period_search_endpoint(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['timetable.periods.view']);
        $this->createTimetablePeriod($school, ['code' => 'FINDABLE', 'name' => 'Findable']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/timetable-entries/search/periods?q=FINDABLE")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    // --- Double-booking surfaces as a clean, typed 409 -----------------------

    #[Test]
    public function double_booking_the_same_teacher_slot_via_the_api_returns_a_typed_conflict(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $graph = $this->buildSchedulableGraph($school);
        $this->createTimetableEntry($graph['offering'], $graph['section'], $graph['teacher'], $graph['period'], ['day_of_week' => 1]);

        $campus2 = $this->createCampus($school);
        $grade2 = $this->createGradeLevel($school);
        $subject2 = $this->createSubject($school);
        $offering2 = $this->createSubjectOffering($graph['year'], $campus2, $grade2, $subject2, ['is_required' => true, 'status' => 'active']);
        $section2 = $this->createSection($graph['year'], $campus2, $grade2, ['status' => 'active']);

        $conflict = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->postJson("/api/v1/schools/{$school->id}/timetable-entries", [
                'subject_offering_id' => $offering2->id,
                'section_id' => $section2->id,
                'teacher_id' => $graph['teacher']->id,
                'period_id' => $graph['period']->id,
                'day_of_week' => 1,
            ]);

        $conflict->assertStatus(409);
        $this->assertSame('TIMETABLE_TEACHER_ALREADY_SCHEDULED', $conflict->json('error.code'));
    }
}
