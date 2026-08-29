<?php

namespace Tests\Feature\App;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\Subject;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Domain\Timetable\Infrastructure\TimetablePeriod;
use App\Models\Campus;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Timetable\Concerns\CreatesTimetableFixtures;
use Tests\TestCase;

/**
 * Phase 0H -- the administrative Timetable Inertia UI
 * (App\Http\Controllers\App\Timetable\*). Backend authorization/tenant-
 * safety/domain invariants are already proven by the Domain-layer
 * Application-service test suites
 * (TimetablePeriodServiceTest/TimetableScheduleServiceTest/concurrency
 * tests) -- these tests cover the Inertia-specific integration: page
 * rendering, capability-aware props, and -- carrying forward the
 * Canteen/Inventory/Library/Transport/Visitor/Hostel anti-P1
 * precedent -- that the Schedule placement form's live SubjectOffering/
 * Section/Teacher/Room/Period search endpoints reject an unauthorized
 * School member exactly like the mutation itself, AND (this
 * checkpoint's specific new requirement) that those helpers are gated
 * by Timetable's OWN capabilities, never Academic Structure's
 * `academics.*` or HR's employee-management capabilities. Mirrors
 * CanteenAdminUiTest's exact pattern.
 */
class TimetableAdminUiTest extends TestCase
{
    use CreatesTimetableFixtures;

    private function activate($user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    private function grantOnly(School $school, $user, string $roleKey, array $capabilities): void
    {
        $membership = $this->createMembership($user, $school);
        $role = Role::query()->create(['key' => $roleKey, 'name' => 'Test', 'scope' => 'school', 'is_system' => false]);
        $role->capabilities()->sync($capabilities);
        app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id,
            'school_membership_id' => $membership->id,
            'role_id' => $role->id,
        ]));
    }

    /**
     * Builds a full, schedulable graph: an active required
     * SubjectOffering, an active Section (same AcademicYear/Campus/
     * GradeLevel context), an active teacher Employee, and an active
     * TimetablePeriod. Returns them keyed for readability.
     *
     * @return array{school: School, year: AcademicYear, campus: Campus, grade: GradeLevel, subject: Subject, offering: SubjectOffering, section: Section, teacher: Employee, period: TimetablePeriod}
     */
    private function buildSchedulableGraph(School $school): array
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
        // scope here and RLS would silently filter them to null (the
        // exact "no ambient current tenant" invariant CLAUDE.md rule 5
        // describes), so callers that need the parent School's
        // AcademicYear/Subject use these already-in-hand instances
        // instead of a fresh relation read.
        return compact('school', 'year', 'campus', 'grade', 'subject', 'offering', 'section', 'teacher', 'period');
    }

    // --- Periods --------------------------------------------------------------

    #[Test]
    public function a_member_with_periods_view_sees_the_periods_index(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'principal');
        $this->activate($user, $school);
        $this->createTimetablePeriod($school, ['code' => 'P1', 'name' => 'Period 1']);

        $this->get('/app/timetable-periods')->assertInertia(fn ($page) => $page
            ->component('App/Timetable/Periods/Index')
            ->where('canManage', true)
            ->has('periods.data', 1)
        );
    }

    #[Test]
    public function a_member_without_periods_view_is_forbidden_from_periods_index(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);
        $this->activate($user, $school);

        $this->get('/app/timetable-periods')->assertForbidden();
    }

    #[Test]
    public function a_school_admin_can_add_and_toggle_a_period_via_the_ui(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);

        $response = $this->post('/app/timetable-periods', [
            'code' => 'ui-p1', 'name' => 'Period 1', 'start_time' => '09:00:00', 'end_time' => '09:45:00',
        ]);
        $response->assertRedirect();

        $period = app(TenantContext::class)->withSchool($school, fn () => TimetablePeriod::query()->where('code', 'UI-P1')->first());
        $this->assertNotNull($period);
        $this->assertSame('active', $period->status);

        $update = $this->patch("/app/timetable-periods/{$period->id}", ['status' => 'inactive']);
        $update->assertRedirect();
        $updated = app(TenantContext::class)->withSchool($school, fn () => $period->fresh());
        $this->assertSame('inactive', $updated->status);
    }

    #[Test]
    public function a_wrong_school_period_id_is_not_visible_via_the_ui(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $schoolA);
        $schoolB = $this->createSchool();
        $periodB = $this->createTimetablePeriod($schoolB);

        $this->patch("/app/timetable-periods/{$periodB->id}", ['status' => 'inactive'])->assertNotFound();
    }

    // --- Schedule ---------------------------------------------------------------

    #[Test]
    public function a_member_with_schedule_view_sees_the_schedule_index(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'principal');
        $this->activate($user, $school);
        $graph = $this->buildSchedulableGraph($school);
        $this->createTimetableEntry($graph['offering'], $graph['section'], $graph['teacher'], $graph['period']);

        $this->get('/app/timetable-schedule')->assertInertia(fn ($page) => $page
            ->component('App/Timetable/Schedule/Index')
            ->where('canManage', true)
            ->has('entries.data', 1)
        );
    }

    #[Test]
    public function a_member_without_schedule_view_is_forbidden_from_schedule_index(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);
        $this->activate($user, $school);

        $this->get('/app/timetable-schedule')->assertForbidden();
    }

    /**
     * Sensitive-tier data-minimization pin (this checkpoint's own
     * requirement): the Schedule index must never expose the teacher's
     * work_email/work_phone -- only id/name.
     */
    #[Test]
    public function schedule_index_never_leaks_teacher_hr_fields(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $graph = $this->buildSchedulableGraph($school);
        $graph['teacher']->update(['work_email' => 'teacher@example.test', 'work_phone' => '555-0100']);
        $this->createTimetableEntry($graph['offering'], $graph['section'], $graph['teacher'], $graph['period']);

        $this->get('/app/timetable-schedule')->assertInertia(fn ($page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.teacherName', $graph['teacher']->full_name)
            ->missing('entries.data.0.teacherEmail')
            ->missing('entries.data.0.workEmail')
            ->missing('entries.data.0.workPhone')
        );
    }

    #[Test]
    public function schedule_create_page_requires_schedule_manage(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);
        $this->activate($user, $school);

        $this->get('/app/timetable-schedule/create')->assertForbidden();
    }

    #[Test]
    public function a_school_admin_can_schedule_and_toggle_a_class_via_the_ui(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $graph = $this->buildSchedulableGraph($school);

        $store = $this->post('/app/timetable-schedule', [
            'subject_offering_id' => $graph['offering']->id,
            'section_id' => $graph['section']->id,
            'teacher_id' => $graph['teacher']->id,
            'period_id' => $graph['period']->id,
            'day_of_week' => 1,
        ]);
        $store->assertRedirect();

        $entry = app(TenantContext::class)->withSchool($school, fn () => TimetableEntry::query()->where('teacher_id', $graph['teacher']->id)->firstOrFail());
        $this->assertSame('active', $entry->status);

        $this->post("/app/timetable-schedule/{$entry->id}/deactivate")->assertRedirect();
        $deactivated = app(TenantContext::class)->withSchool($school, fn () => $entry->fresh());
        $this->assertSame('inactive', $deactivated->status);

        $this->post("/app/timetable-schedule/{$entry->id}/activate")->assertRedirect();
        $reactivated = app(TenantContext::class)->withSchool($school, fn () => $entry->fresh());
        $this->assertSame('active', $reactivated->status);
    }

    #[Test]
    public function double_booking_the_same_teacher_slot_via_the_ui_is_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $graph = $this->buildSchedulableGraph($school);
        $this->createTimetableEntry($graph['offering'], $graph['section'], $graph['teacher'], $graph['period'], ['day_of_week' => 1]);

        $campus2 = $this->createCampus($school);
        $grade2 = $this->createGradeLevel($school);
        $subject2 = $this->createSubject($school);
        $offering2 = $this->createSubjectOffering($graph['year'], $campus2, $grade2, $subject2, ['is_required' => true, 'status' => 'active']);
        $section2 = $this->createSection($graph['year'], $campus2, $grade2, ['status' => 'active']);

        $store = $this->post('/app/timetable-schedule', [
            'subject_offering_id' => $offering2->id,
            'section_id' => $section2->id,
            'teacher_id' => $graph['teacher']->id,
            'period_id' => $graph['period']->id,
            'day_of_week' => 1,
        ]);

        $store->assertSessionHasErrors('period_id');
    }

    // --- Timetable-owned helper/search endpoints -- capability boundary --------

    #[Test]
    public function a_member_without_schedule_manage_cannot_use_the_schedule_search_endpoints(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();
        $this->grantOnly($school, $user, 'test_schedule_viewer_ui', ['timetable.schedule.view']);
        $this->activate($user, $school);

        $this->get('/app/timetable-schedule/search/subject-offerings?q=a')->assertForbidden();
        $this->get('/app/timetable-schedule/search/sections?q=a')->assertForbidden();
        $this->get('/app/timetable-schedule/search/teachers?q=a')->assertForbidden();
        $this->get('/app/timetable-schedule/search/rooms?q=a')->assertForbidden();
    }

    #[Test]
    public function a_member_with_neither_timetable_capability_cannot_use_any_schedule_helper(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();
        $this->createMembership($user, $school);
        $this->activate($user, $school);

        $this->get('/app/timetable-schedule/search/subject-offerings?q=a')->assertForbidden();
        $this->get('/app/timetable-schedule/search/sections?q=a')->assertForbidden();
        $this->get('/app/timetable-schedule/search/teachers?q=a')->assertForbidden();
        $this->get('/app/timetable-schedule/search/rooms?q=a')->assertForbidden();
        $this->get('/app/timetable-schedule/search/periods?q=a')->assertForbidden();
    }

    /**
     * THE load-bearing capability-boundary assertion this checkpoint's
     * brief calls out explicitly: a user granted ONLY
     * `timetable.schedule.manage` -- deliberately NO
     * `academics.subjects.manage`, NO HR employee-management
     * capability -- can still use every SubjectOffering/Section/
     * teacher/Room helper. Proves these helpers are gated by
     * Timetable's OWN capability, never borrowed from Academic
     * Structure or HR.
     */
    #[Test]
    public function a_schedule_manage_member_without_academics_or_hr_capability_can_use_the_search_endpoints(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();
        $this->grantOnly($school, $user, 'test_schedule_manager_ui', ['timetable.schedule.manage']);
        $this->activate($user, $school);

        $graph = $this->buildSchedulableGraph($school);
        $room = $this->createRoom($this->createCampus($school));

        $this->get('/app/timetable-schedule/search/subject-offerings?q='.$graph['subject']->code)
            ->assertOk()->assertJsonCount(1, 'data');
        $this->get('/app/timetable-schedule/search/sections?q='.$graph['section']->code)
            ->assertOk()->assertJsonCount(1, 'data');
        $this->get('/app/timetable-schedule/search/teachers?q='.$graph['teacher']->full_name)
            ->assertOk()->assertJsonCount(1, 'data');
        $this->get('/app/timetable-schedule/search/rooms?q='.$room->code)
            ->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function the_teacher_search_endpoint_never_leaks_hr_fields(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();
        $this->grantOnly($school, $user, 'test_schedule_manager_ui_2', ['timetable.schedule.manage']);
        $this->activate($user, $school);
        $teacher = $this->createEmployee($school, ['record_status' => 'active', 'full_name' => 'Findable Teacher', 'work_email' => 'findable@example.test']);

        $response = $this->get('/app/timetable-schedule/search/teachers?q=Findable')->assertOk();
        $row = $response->json('data.0');
        $this->assertSame($teacher->id, $row['id']);
        $this->assertSame('Findable Teacher', $row['fullName']);
        $this->assertArrayNotHasKey('workEmail', $row);
        $this->assertArrayNotHasKey('work_email', $row);
        $this->assertArrayNotHasKey('workPhone', $row);
    }

    #[Test]
    public function periods_helper_requires_periods_view_not_merely_schedule_manage(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();
        // Deliberately grants ONLY timetable.schedule.manage -- no
        // timetable.periods.* -- to pin the Period helper's own
        // capability choice documented on
        // App\Http\Controllers\App\Timetable\TimetableEntryController::searchPeriods().
        $this->grantOnly($school, $user, 'test_schedule_manager_ui_3', ['timetable.schedule.manage']);
        $this->activate($user, $school);

        $this->get('/app/timetable-schedule/search/periods?q=a')->assertForbidden();
    }

    #[Test]
    public function periods_view_alone_is_sufficient_for_the_period_search_endpoint(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();
        $this->grantOnly($school, $user, 'test_periods_viewer_ui', ['timetable.periods.view']);
        $this->activate($user, $school);
        $this->createTimetablePeriod($school, ['code' => 'FINDABLE', 'name' => 'Findable Period']);

        $this->get('/app/timetable-schedule/search/periods?q=FINDABLE')->assertOk()->assertJsonCount(1, 'data');
    }

    // --- Navigation -------------------------------------------------------------

    #[Test]
    public function dashboard_nav_shows_timetable_links_only_when_the_matching_capability_is_held(): void
    {
        $school = $this->createSchool();
        $periodsViewer = $this->createUser();
        $this->grantOnly($school, $periodsViewer, 'test_timetable_periods_viewer_ui', ['timetable.periods.view']);
        $this->activate($periodsViewer, $school);

        $this->get('/app')->assertInertia(fn ($page) => $page
            ->where('nav.canViewTimetablePeriods', true)
            ->where('nav.canViewTimetableSchedule', false)
        );

        $noAccess = $this->createUser();
        $this->createMembership($noAccess, $school);
        $this->activate($noAccess, $school);

        $this->get('/app')->assertInertia(fn ($page) => $page
            ->where('nav.canViewTimetablePeriods', false)
            ->where('nav.canViewTimetableSchedule', false)
        );

        [$admin, $adminSchool] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $adminSchool);

        $this->get('/app')->assertInertia(fn ($page) => $page
            ->where('nav.canViewTimetablePeriods', true)
            ->where('nav.canViewTimetableSchedule', true)
        );
    }
}
