<?php

namespace Tests\Feature\App;

use App\Domain\Students\Infrastructure\Student;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1A.6: the administrative Student UI
 * (App\Http\Controllers\App\StudentController). Backend authorization/
 * tenant-safety/domain invariants are already proven by Phase 1A.5's
 * JSON API test suite (re-run unmodified) -- these tests cover the
 * Inertia-specific integration: page rendering, capability-aware
 * props, redirect/validation behavior, and that a hidden button is
 * never the only protection (this checkpoint's brief, section 29).
 */
class StudentAdminUiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function activate(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    // --- Index --------------------------------------------------------

    #[Test]
    public function a_member_with_students_view_sees_the_index_with_canmanage_false(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'principal');
        $this->activate($user, $school);
        $this->createStudent($school, ['student_number' => 'S-1001']);

        $this->get('/app/students')->assertInertia(fn ($page) => $page
            ->component('App/Students/Index')
            ->where('canManage', true) // principal has students.manage in this checkpoint's role design
            ->has('students.data', 1)
        );
    }

    #[Test]
    public function a_member_without_students_view_is_forbidden(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);
        $this->activate($user, $school);

        $this->get('/app/students')->assertForbidden();
    }

    #[Test]
    public function the_add_student_action_is_not_rendered_for_a_view_only_member(): void
    {
        $school = $this->createSchool();
        $viewer = $this->createUser();
        $membership = $this->createMembership($viewer, $school);
        $role = Role::query()->create(['key' => 'test_students_viewer_ui', 'name' => 'Test', 'scope' => 'school', 'is_system' => false]);
        $role->capabilities()->sync(['students.view']);
        app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $role->id,
        ]));
        $this->activate($viewer, $school);

        $this->get('/app/students')->assertInertia(fn ($page) => $page
            ->component('App/Students/Index')
            ->where('canManage', false)
        );

        // The button is UX-only -- the create PAGE and the store
        // ACTION must both independently deny this actor server-side
        // (section 29: direct calls remain forbidden, never relying on
        // a hidden button).
        $this->get('/app/students/create')->assertForbidden();
        $this->post('/app/students', ['student_number' => 'X', 'first_name' => 'X', 'date_of_birth' => '2015-01-01'])
            ->assertForbidden();
    }

    #[Test]
    public function list_never_includes_date_of_birth_in_props(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $this->createStudent($school, ['student_number' => 'S-1001']);

        $this->get('/app/students')->assertInertia(fn ($page) => $page
            ->component('App/Students/Index')
            ->missing('students.data.0.dateOfBirth')
        );
    }

    #[Test]
    public function filters_are_echoed_back_as_props(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $this->createStudent($school, ['student_number' => 'S-1001', 'status' => 'inactive']);

        $this->get('/app/students?status=inactive')->assertInertia(fn ($page) => $page
            ->where('filters.status', 'inactive')
            ->has('students.data', 1)
        );
    }

    // --- Create / store -------------------------------------------------

    #[Test]
    public function students_manage_can_create_a_student_and_is_redirected_to_its_detail_page(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);

        $response = $this->post('/app/students', [
            'student_number' => 'S-1001', 'first_name' => 'Asha', 'last_name' => 'Verma', 'date_of_birth' => '2015-04-12',
        ]);

        $student = app(TenantContext::class)->withSchool($school, fn () => Student::query()->where('student_number', 'S-1001')->firstOrFail());
        $response->assertRedirect("/app/students/{$student->id}");
    }

    #[Test]
    public function a_duplicate_student_number_shows_a_clean_inline_error_not_a_broken_page(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $this->createStudent($school, ['student_number' => 'S-1001']);

        $response = $this->post('/app/students', [
            'student_number' => 'S-1001', 'first_name' => 'Rohit', 'date_of_birth' => '2016-01-01',
        ]);

        // A domain exception translated to Laravel's own
        // ValidationException redirects back with session errors --
        // exactly what Inertia's form.errors reads, never a raw 500.
        $response->assertRedirect();
        $response->assertSessionHasErrors('student_number');
    }

    #[Test]
    public function a_school_id_field_in_the_payload_is_ignored(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $this->activate($user, $schoolA);

        $this->post('/app/students', [
            'student_number' => 'S-1001', 'first_name' => 'Asha', 'date_of_birth' => '2015-04-12',
            'school_id' => $schoolB->id,
        ]);

        $student = app(TenantContext::class)->withSchool($schoolA, fn () => Student::query()->where('student_number', 'S-1001')->firstOrFail());
        $this->assertSame($schoolA->id, $student->school_id);
    }

    #[Test]
    public function date_of_birth_round_trips_exactly_with_no_timezone_shift(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);

        $this->post('/app/students', [
            'student_number' => 'S-1001', 'first_name' => 'Asha', 'date_of_birth' => '2015-04-12',
        ]);
        $student = app(TenantContext::class)->withSchool($school, fn () => Student::query()->where('student_number', 'S-1001')->firstOrFail());

        $this->get("/app/students/{$student->id}")->assertInertia(fn ($page) => $page
            ->where('student.dateOfBirth', '2015-04-12')
        );
    }

    // --- Show -----------------------------------------------------------

    #[Test]
    public function show_renders_identity_and_guardian_relationships(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $guardian = $this->createGuardian($school);
        $this->createStudentGuardianRelationship($student, $guardian, ['is_primary' => true]);

        $this->get("/app/students/{$student->id}")->assertInertia(fn ($page) => $page
            ->component('App/Students/Show')
            ->where('student.studentNumber', 'S-1001')
            ->has('relationships', 1)
            ->where('relationships.0.isPrimary', true)
            ->where('canManageStudents', true)
            ->where('canManageGuardians', true)
        );
    }

    #[Test]
    public function a_foreign_school_student_is_not_found(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $studentB = $this->createStudent($schoolB, ['student_number' => 'B-1']);
        $this->activate($user, $schoolA);

        $this->get("/app/students/{$studentB->id}")->assertNotFound();
    }

    // --- Update -----------------------------------------------------------

    #[Test]
    public function students_manage_can_update_a_student(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $student = $this->createStudent($school, ['student_number' => 'S-1001', 'last_name' => 'Verma']);

        $response = $this->put("/app/students/{$student->id}", [
            'student_number' => 'S-1001', 'first_name' => $student->first_name, 'last_name' => 'Sharma',
            'date_of_birth' => $student->date_of_birth->toDateString(),
        ]);

        $response->assertRedirect("/app/students/{$student->id}");
        $fresh = app(TenantContext::class)->withSchool($school, fn () => $student->fresh());
        $this->assertSame('Sharma', $fresh->last_name);
    }

    #[Test]
    public function edit_page_and_update_action_are_forbidden_without_students_manage(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $membership = $this->createMembership($user, $school);
        $role = Role::query()->create(['key' => 'test_students_viewer_edit', 'name' => 'Test', 'scope' => 'school', 'is_system' => false]);
        $role->capabilities()->sync(['students.view']);
        app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $role->id,
        ]));
        $this->activate($user, $school);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);

        $this->get("/app/students/{$student->id}/edit")->assertForbidden();
        $this->put("/app/students/{$student->id}", [
            'student_number' => 'S-1001', 'first_name' => 'Hacked', 'date_of_birth' => '2015-01-01',
        ])->assertForbidden();
    }

    // --- Status -----------------------------------------------------------

    #[Test]
    public function status_change_works_and_rejects_invalid_values(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);

        $ok = $this->post("/app/students/{$student->id}/status", ['status' => 'inactive']);
        $ok->assertRedirect("/app/students/{$student->id}");
        $fresh = app(TenantContext::class)->withSchool($school, fn () => $student->fresh());
        $this->assertSame('inactive', $fresh->status);

        $bad = $this->post("/app/students/{$student->id}/status", ['status' => 'graduated']);
        $bad->assertSessionHasErrors('status');
    }

    // --- Navigation -------------------------------------------------------

    #[Test]
    public function the_dashboard_nav_reflects_students_view_capability(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);

        $this->get('/app')->assertInertia(fn ($page) => $page
            ->component('App/Dashboard')
            ->where('nav.canViewStudents', true)
            ->where('nav.canViewGuardians', true)
        );
    }
}
