<?php

namespace Tests\Feature\App;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Campus;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1B.6: the administrative Enrollment UI
 * (App\Http\Controllers\App\StudentEnrollmentController and the
 * Enrollment integration inside StudentController::show()). Backend
 * authorization/tenant-safety/domain invariants are already proven by
 * Phase 1B.5's JSON API test suite (re-run unmodified) -- these tests
 * cover the Inertia-specific integration: page rendering,
 * capability-aware props, redirect/validation behavior, and that a
 * hidden button is never the only protection (mirrors
 * StudentAdminUiTest.php's exact convention).
 */
class StudentEnrollmentUiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function activate(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    private function grantViewOnly(User $user, School $school): void
    {
        $membership = $this->createMembership($user, $school);
        $role = Role::query()->create(['key' => 'test_enrollment_viewer_ui_'.Str::random(8), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]);
        $role->capabilities()->sync(['enrollments.view']);
        app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $role->id,
        ]));
    }

    private function grantManageOnly(User $user, School $school): void
    {
        $membership = $this->createMembership($user, $school);
        $role = Role::query()->create(['key' => 'test_enrollment_manager_ui_'.Str::random(8), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]);
        $role->capabilities()->sync(['enrollments.manage']);
        app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $role->id,
        ]));
    }

    private function grantStudentsViewOnly(User $user, School $school): void
    {
        $membership = $this->createMembership($user, $school);
        $role = Role::query()->create(['key' => 'test_students_viewer_no_enrollments_'.Str::random(8), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]);
        $role->capabilities()->sync(['students.view']);
        app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $role->id,
        ]));
    }

    /**
     * @return array{school: School, campus: Campus, year: AcademicYear, grade: GradeLevel, section: Section, student: Student}
     */
    private function buildPlacementContext(): array
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school, ['code' => 'SRC']);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);

        return compact('school', 'campus', 'year', 'grade', 'section', 'student');
    }

    private function service(): StudentEnrollmentService
    {
        return app(StudentEnrollmentService::class);
    }

    // ==================================================================
    // Directory permissions (section 45)
    // ==================================================================

    #[Test]
    public function enrollments_view_allows_the_directory_page(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);

        $this->get('/app/enrollments')->assertInertia(fn ($page) => $page->component('App/Enrollments/Index'));
    }

    #[Test]
    public function no_enrollments_view_is_forbidden_on_the_directory(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);
        $this->activate($user, $school);

        $this->get('/app/enrollments')->assertForbidden();
    }

    #[Test]
    public function enrollments_manage_only_does_not_gain_directory_read_access(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->grantManageOnly($user, $school);
        $this->activate($user, $school);

        $this->get('/app/enrollments')->assertForbidden();
    }

    #[Test]
    public function directory_lists_current_school_enrollments_with_reference_options(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $student] = $this->buildPlacementContext();
        $this->service()->enroll($student, $section, '01', '2026-06-01');
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $this->get('/app/enrollments')->assertInertia(fn ($page) => $page
            ->component('App/Enrollments/Index')
            ->has('enrollments.data', 1)
            ->where('enrollments.data.0.rollNumber', '01')
            ->has('academicYears')
            ->has('campuses')
            ->has('gradeLevels')
            ->has('sections')
        );
    }

    #[Test]
    public function directory_filters_are_echoed_back_and_preserved_through_pagination(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $student] = $this->buildPlacementContext();
        $this->service()->enroll($student, $section, '01', '2026-06-01');
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $this->get('/app/enrollments?status=active')->assertInertia(fn ($page) => $page
            ->where('filters.status', 'active')
            ->has('enrollments.data', 1)
        );

        $this->get('/app/enrollments?status=withdrawn')->assertInertia(fn ($page) => $page
            ->where('filters.status', 'withdrawn')
            ->has('enrollments.data', 0)
        );
    }

    #[Test]
    public function directory_props_never_expose_sensitive_fields(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $student] = $this->buildPlacementContext();
        $this->service()->enroll($student, $section, '01', '2026-06-01');
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $this->get('/app/enrollments')->assertInertia(fn ($page) => $page
            ->missing('enrollments.data.0.student.dateOfBirth')
            ->missing('enrollments.data.0.date_of_birth')
            ->missing('enrollments.data.0.school_id')
            ->missing('enrollments.data.0.guardian')
            ->missing('enrollments.data.0.encrypted_value')
            ->missing('enrollments.data.0.lookup_hash')
            ->missing('enrollments.data.0.lookup_key_version')
        );
    }

    // ==================================================================
    // Navigation
    // ==================================================================

    #[Test]
    public function the_dashboard_nav_reflects_enrollments_view_capability(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);

        $this->get('/app')->assertInertia(fn ($page) => $page
            ->component('App/Dashboard')
            ->where('nav.canViewEnrollments', true)
        );
    }

    // ==================================================================
    // Create Enrollment (sections 46, 47, 48)
    // ==================================================================

    #[Test]
    public function the_create_page_and_store_action_are_forbidden_without_enrollments_manage(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $student] = $this->buildPlacementContext();
        $user = $this->createUser();
        $this->grantViewOnly($user, $school);
        $this->activate($user, $school);

        $this->get("/app/students/{$student->id}/enrollments/create")->assertForbidden();
        $this->post("/app/students/{$student->id}/enrollments", [
            'section_id' => $section->id, 'roll_number' => '01', 'starts_on' => '2026-06-01',
        ])->assertForbidden();
    }

    #[Test]
    public function neither_capability_is_forbidden_on_create(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $student] = $this->buildPlacementContext();
        $user = $this->createUser();
        $this->createMembership($user, $school);
        $this->activate($user, $school);

        $this->post("/app/students/{$student->id}/enrollments", [
            'section_id' => $section->id, 'roll_number' => '01', 'starts_on' => '2026-06-01',
        ])->assertForbidden();
    }

    #[Test]
    public function enrollments_manage_can_enroll_a_student_and_is_redirected_to_the_student_page(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $student] = $this->buildPlacementContext();
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $response = $this->post("/app/students/{$student->id}/enrollments", [
            'section_id' => $section->id, 'roll_number' => '007', 'starts_on' => '2026-06-01',
        ]);

        $response->assertRedirect("/app/students/{$student->id}");
        $stored = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->where('student_id', $student->id)->firstOrFail());
        $this->assertSame('007', $stored->roll_number);
        $this->assertSame($section->academic_year_id, $stored->academic_year_id);
        $this->assertSame($section->campus_id, $stored->campus_id);
        $this->assertSame($section->grade_level_id, $stored->grade_level_id);
    }

    #[Test]
    public function malicious_redundant_placement_fields_never_override_the_derived_placement_on_create(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $student] = $this->buildPlacementContext();
        ['school' => $otherSchool, 'campus' => $otherCampus, 'year' => $otherYear, 'grade' => $otherGrade] = $this->buildPlacementContext();
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $response = $this->post("/app/students/{$student->id}/enrollments", [
            'section_id' => $section->id, 'roll_number' => '01', 'starts_on' => '2026-06-01',
            'school_id' => $otherSchool->id,
            'academic_year_id' => $otherYear->id,
            'campus_id' => $otherCampus->id,
            'grade_level_id' => $otherGrade->id,
        ]);

        $response->assertRedirect();
        $stored = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->where('student_id', $student->id)->firstOrFail());
        $this->assertSame($school->id, $stored->school_id);
        $this->assertSame($section->academic_year_id, $stored->academic_year_id);
        $this->assertSame($section->campus_id, $stored->campus_id);
        $this->assertSame($section->grade_level_id, $stored->grade_level_id);
    }

    #[Test]
    public function a_duplicate_active_enrollment_shows_a_clean_inline_error_not_a_broken_page(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'section' => $sectionOne, 'student' => $student] = $this->buildPlacementContext();
        $sectionTwo = $this->createSection($year, $campus, $grade, ['name' => 'B', 'code' => 'B']);
        $this->service()->enroll($student, $sectionOne, '01', '2026-06-01');
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $response = $this->post("/app/students/{$student->id}/enrollments", [
            'section_id' => $sectionTwo->id, 'roll_number' => '02', 'starts_on' => '2026-06-02',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('section_id');
    }

    #[Test]
    public function a_blank_roll_number_shows_a_clean_inline_error(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $student] = $this->buildPlacementContext();
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $response = $this->post("/app/students/{$student->id}/enrollments", [
            'section_id' => $section->id, 'roll_number' => '', 'starts_on' => '2026-06-01',
        ]);

        $response->assertSessionHasErrors('roll_number');
    }

    #[Test]
    public function a_foreign_school_section_and_a_random_uuid_are_equivalently_rejected_on_create(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $studentA = $this->createStudent($schoolA, ['student_number' => 'A-1']);
        ['section' => $sectionB] = $this->buildPlacementContext();
        $this->activate($user, $schoolA);

        $foreign = $this->post("/app/students/{$studentA->id}/enrollments", [
            'section_id' => $sectionB->id, 'roll_number' => '01', 'starts_on' => '2026-06-01',
        ]);
        $random = $this->post("/app/students/{$studentA->id}/enrollments", [
            'section_id' => (string) Str::uuid(), 'roll_number' => '01', 'starts_on' => '2026-06-01',
        ]);

        $foreign->assertSessionHasErrors('section_id');
        $random->assertSessionHasErrors('section_id');
    }

    #[Test]
    public function a_foreign_school_student_and_a_random_uuid_are_equivalently_not_found_for_create(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        ['student' => $studentB] = $this->buildPlacementContext();
        $this->activate($user, $schoolA);

        $foreign = $this->get("/app/students/{$studentB->id}/enrollments/create");
        $random = $this->get('/app/students/'.Str::uuid().'/enrollments/create');

        $foreign->assertNotFound();
        $random->assertNotFound();
    }

    // ==================================================================
    // Lifecycle (sections 49, 50, 51)
    // ==================================================================

    #[Test]
    public function complete_transitions_active_to_completed_and_redirects_to_the_student(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $student] = $this->buildPlacementContext();
        $enrollment = $this->service()->enroll($student, $section, '01', '2026-06-01');
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $response = $this->post("/app/enrollments/{$enrollment->id}/complete", ['ends_on' => '2027-04-30']);

        $response->assertRedirect("/app/students/{$student->id}");
        $fresh = app(TenantContext::class)->withSchool($school, fn () => $enrollment->fresh());
        $this->assertSame('completed', $fresh->status);
        $this->assertSame('2027-04-30', $fresh->ends_on->toDateString());
        $studentFresh = app(TenantContext::class)->withSchool($school, fn () => $student->fresh());
        $this->assertSame('S-1001', $studentFresh->student_number);
    }

    #[Test]
    public function withdraw_transitions_active_to_withdrawn_and_retains_the_student(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $student] = $this->buildPlacementContext();
        $enrollment = $this->service()->enroll($student, $section, '01', '2026-06-01');
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $this->post("/app/enrollments/{$enrollment->id}/withdraw", ['ends_on' => '2026-09-01'])
            ->assertRedirect("/app/students/{$student->id}");

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $enrollment->fresh());
        $this->assertSame('withdrawn', $fresh->status);
        $studentStillExists = app(TenantContext::class)->withSchool($school, fn () => Student::query()->find($student->id));
        $this->assertNotNull($studentStillExists);
    }

    #[Test]
    public function cancel_transitions_active_to_cancelled_without_deleting_anything(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $student] = $this->buildPlacementContext();
        $enrollment = $this->service()->enroll($student, $section, '01', '2026-06-01');
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $this->post("/app/enrollments/{$enrollment->id}/cancel", ['ends_on' => '2026-06-05'])
            ->assertRedirect("/app/students/{$student->id}");

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $enrollment->fresh());
        $this->assertSame('cancelled', $fresh->status);
        $this->assertNotNull($fresh); // retained, never deleted
    }

    #[Test]
    public function lifecycle_actions_are_forbidden_without_enrollments_manage(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $student] = $this->buildPlacementContext();
        $enrollment = $this->service()->enroll($student, $section, '01', '2026-06-01');
        $user = $this->createUser();
        $this->grantViewOnly($user, $school);
        $this->activate($user, $school);

        $this->post("/app/enrollments/{$enrollment->id}/complete", ['ends_on' => '2027-04-30'])->assertForbidden();
        $this->post("/app/enrollments/{$enrollment->id}/withdraw", ['ends_on' => '2027-04-30'])->assertForbidden();
        $this->post("/app/enrollments/{$enrollment->id}/cancel", ['ends_on' => '2027-04-30'])->assertForbidden();
    }

    #[Test]
    public function a_completed_enrollment_cannot_be_withdrawn_and_shows_a_clean_error(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $student] = $this->buildPlacementContext();
        $enrollment = $this->service()->enroll($student, $section, '01', '2026-06-01');
        $this->service()->complete($enrollment, '2027-04-30');
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $response = $this->post("/app/enrollments/{$enrollment->id}/withdraw", ['ends_on' => '2027-05-01']);

        $response->assertSessionHasErrors('ends_on');
    }

    #[Test]
    public function a_foreign_school_enrollment_and_a_random_uuid_are_equivalently_not_found_for_lifecycle(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        ['section' => $sectionB, 'student' => $studentB] = $this->buildPlacementContext();
        $enrollmentB = $this->service()->enroll($studentB, $sectionB, '01', '2026-06-01');
        $this->activate($user, $schoolA);

        $foreign = $this->post("/app/enrollments/{$enrollmentB->id}/complete", ['ends_on' => '2027-04-30']);
        $random = $this->post('/app/enrollments/'.Str::uuid().'/complete', ['ends_on' => '2027-04-30']);

        $foreign->assertNotFound();
        $random->assertNotFound();
    }

    // ==================================================================
    // Transfer (sections 52, 53, 54, 55)
    // ==================================================================

    #[Test]
    public function transfer_page_and_action_are_forbidden_without_enrollments_manage(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $student] = $this->buildPlacementContext();
        $enrollment = $this->service()->enroll($student, $section, '01', '2026-06-01');
        $user = $this->createUser();
        $this->grantViewOnly($user, $school);
        $this->activate($user, $school);

        $this->get("/app/enrollments/{$enrollment->id}/transfer")->assertForbidden();
        $this->post("/app/enrollments/{$enrollment->id}/transfer", [
            'target_section_id' => (string) Str::uuid(), 'roll_number' => '02', 'effective_date' => '2026-07-01',
        ])->assertForbidden();
    }

    #[Test]
    public function transfer_moves_the_student_to_the_target_section_and_redirects(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'section' => $sourceSection, 'student' => $student] = $this->buildPlacementContext();
        $targetSection = $this->createSection($year, $campus, $grade, ['name' => 'B', 'code' => 'B']);
        $sourceEnrollment = $this->service()->enroll($student, $sourceSection, '01', '2026-06-01');
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $this->get("/app/enrollments/{$sourceEnrollment->id}/transfer")->assertInertia(fn ($page) => $page
            ->component('App/Enrollments/Transfer')
            ->where('enrollment.id', $sourceEnrollment->id)
        );

        $response = $this->post("/app/enrollments/{$sourceEnrollment->id}/transfer", [
            'target_section_id' => $targetSection->id, 'roll_number' => '09', 'effective_date' => '2026-07-01',
        ]);

        $response->assertRedirect("/app/students/{$student->id}");

        $freshSource = app(TenantContext::class)->withSchool($school, fn () => $sourceEnrollment->fresh());
        $this->assertSame('transferred', $freshSource->status);
        $this->assertSame('2026-06-30', $freshSource->ends_on->toDateString());

        $newEnrollment = app(TenantContext::class)->withSchool(
            $school,
            fn () => StudentEnrollment::query()->where('student_id', $student->id)->where('status', 'active')->firstOrFail(),
        );
        $this->assertSame($targetSection->id, $newEnrollment->section_id);
        $this->assertSame('09', $newEnrollment->roll_number);
    }

    #[Test]
    public function transfer_failure_leaves_the_source_untouched_and_shows_a_clean_error(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'section' => $sourceSection, 'student' => $studentA] = $this->buildPlacementContext();
        $targetSection = $this->createSection($year, $campus, $grade, ['name' => 'B', 'code' => 'B']);
        $studentB = $this->createStudent($school, ['student_number' => 'S-1002']);
        $this->service()->enroll($studentB, $targetSection, '05', '2026-06-01'); // occupies roll "05"
        $sourceEnrollment = $this->service()->enroll($studentA, $sourceSection, '01', '2026-06-01');

        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $response = $this->post("/app/enrollments/{$sourceEnrollment->id}/transfer", [
            'target_section_id' => $targetSection->id, 'roll_number' => '05', 'effective_date' => '2026-07-01',
        ]);

        $response->assertSessionHasErrors('roll_number');

        $freshSource = app(TenantContext::class)->withSchool($school, fn () => $sourceEnrollment->fresh());
        $this->assertSame('active', $freshSource->status);
        $this->assertNull($freshSource->ends_on);

        $totalForStudentA = app(TenantContext::class)->withSchool(
            $school,
            fn () => StudentEnrollment::query()->where('student_id', $studentA->id)->count(),
        );
        $this->assertSame(1, $totalForStudentA);

        $transferredAudits = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'student_enrollment.transferred')
                ->whereJsonContains('metadata->studentId', $studentA->id)->count(),
        );
        $this->assertSame(0, $transferredAudits);
    }

    #[Test]
    public function transfer_to_a_different_academic_year_shows_a_clean_error(): void
    {
        ['school' => $school, 'campus' => $campus, 'grade' => $grade, 'section' => $sourceSection, 'student' => $student] = $this->buildPlacementContext();
        $otherYear = $this->createAcademicYear($school, ['code' => 'TGT']);
        $targetSection = $this->createSection($otherYear, $campus, $grade);
        $sourceEnrollment = $this->service()->enroll($student, $sourceSection, '01', '2026-06-01');
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $response = $this->post("/app/enrollments/{$sourceEnrollment->id}/transfer", [
            'target_section_id' => $targetSection->id, 'roll_number' => '02', 'effective_date' => '2026-07-01',
        ]);

        $response->assertSessionHasErrors('target_section_id');
    }

    #[Test]
    public function transfer_to_a_different_grade_level_shows_a_clean_error(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'section' => $sourceSection, 'student' => $student] = $this->buildPlacementContext();
        $otherGrade = $this->createGradeLevel($school);
        $targetSection = $this->createSection($year, $campus, $otherGrade);
        $sourceEnrollment = $this->service()->enroll($student, $sourceSection, '01', '2026-06-01');
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $response = $this->post("/app/enrollments/{$sourceEnrollment->id}/transfer", [
            'target_section_id' => $targetSection->id, 'roll_number' => '02', 'effective_date' => '2026-07-01',
        ]);

        $response->assertSessionHasErrors('target_section_id');
    }

    #[Test]
    public function a_foreign_school_target_section_and_a_random_uuid_are_equivalently_rejected_for_transfer(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $campusA = $this->createCampus($schoolA);
        $yearA = $this->createAcademicYear($schoolA);
        $gradeA = $this->createGradeLevel($schoolA);
        $sectionA = $this->createSection($yearA, $campusA, $gradeA);
        $studentA = $this->createStudent($schoolA, ['student_number' => 'A-1']);
        $enrollmentA = $this->service()->enroll($studentA, $sectionA, '01', '2026-06-01');
        ['section' => $sectionB] = $this->buildPlacementContext();
        $this->activate($user, $schoolA);

        $foreign = $this->post("/app/enrollments/{$enrollmentA->id}/transfer", [
            'target_section_id' => $sectionB->id, 'roll_number' => '02', 'effective_date' => '2026-07-01',
        ]);
        $random = $this->post("/app/enrollments/{$enrollmentA->id}/transfer", [
            'target_section_id' => (string) Str::uuid(), 'roll_number' => '02', 'effective_date' => '2026-07-01',
        ]);

        $foreign->assertSessionHasErrors('target_section_id');
        $random->assertSessionHasErrors('target_section_id');
    }

    // ==================================================================
    // Student page integration (sections 42, 43, 44)
    // ==================================================================

    #[Test]
    public function student_page_shows_current_and_full_history_without_treating_terminal_records_as_current(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'section' => $sectionOne, 'student' => $student] = $this->buildPlacementContext();
        $sectionTwo = $this->createSection($year, $campus, $grade, ['name' => 'B', 'code' => 'B']);
        $sectionThree = $this->createSection($year, $campus, $grade, ['name' => 'C', 'code' => 'C']);

        $completed = $this->service()->enroll($student, $sectionOne, '01', '2026-01-01');
        $this->service()->complete($completed, '2026-03-01');
        $reEnrolled = $this->service()->enroll($student, $sectionTwo, '02', '2026-03-02');
        $this->service()->transferPlacement($reEnrolled, $sectionThree, '03', '2026-04-01');
        app(TenantContext::class)->withSchool($school, fn () => $year->update(['status' => 'active']));

        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $this->get("/app/students/{$student->id}")->assertInertia(fn ($page) => $page
            ->component('App/Students/Show')
            ->where('canViewEnrollments', true)
            ->where('canManageEnrollments', true)
            ->where('currentEnrollment.status', 'active')
            ->where('currentEnrollment.section.id', $sectionThree->id)
            ->has('enrollmentHistory', 3)
        );
    }

    #[Test]
    public function student_page_renders_normally_with_no_enrollment_history(): void
    {
        ['school' => $school, 'student' => $student] = $this->buildPlacementContext();
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $this->get("/app/students/{$student->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->component('App/Students/Show')
            ->where('canViewEnrollments', true)
            ->where('currentEnrollment', null)
            ->has('enrollmentHistory', 0)
        );
    }

    #[Test]
    public function students_view_without_enrollments_view_sees_the_student_but_no_enrollment_data(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $student] = $this->buildPlacementContext();
        $this->service()->enroll($student, $section, '01', '2026-06-01');
        $user = $this->createUser();
        $this->grantStudentsViewOnly($user, $school);
        $this->activate($user, $school);

        $this->get("/app/students/{$student->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->component('App/Students/Show')
            ->where('student.studentNumber', 'S-1001')
            ->where('canViewEnrollments', false)
            ->missing('currentEnrollment')
            ->missing('enrollmentHistory')
            ->missing('canManageEnrollments')
        );
    }
}
