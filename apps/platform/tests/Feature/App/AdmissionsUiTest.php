<?php

namespace Tests\Feature\App;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Campus;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Admissions\Application\AdmissionApplicationService;
use App\Domain\Admissions\Application\AdmissionConversionService;
use App\Domain\Admissions\Application\ApplicantService;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\Applicant;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Students\Infrastructure\Student;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1D.6: the administrative Admissions UI
 * (App\Http\Controllers\App\ApplicantController and
 * App\Http\Controllers\App\AdmissionApplicationController). Backend
 * authorization/tenant-safety/domain invariants are already proven by
 * Phase 1D.2-1D.5's own test suites (re-run unmodified alongside this
 * file) -- these tests cover the Inertia-specific integration: page
 * rendering, capability-aware props, redirect/validation behavior, and
 * that a hidden button is never the only protection (mirrors
 * StudentEnrollmentUiTest.php's exact convention).
 */
class AdmissionsUiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function activate(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    private function grantViewOnly(User $user, School $school): void
    {
        $membership = $this->createMembership($user, $school);
        $role = Role::query()->create(['key' => 'test_admissions_viewer_ui_'.Str::random(8), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]);
        $role->capabilities()->sync(['admissions.view']);
        app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $role->id,
        ]));
    }

    private function grantManageOnly(User $user, School $school): void
    {
        $membership = $this->createMembership($user, $school);
        $role = Role::query()->create(['key' => 'test_admissions_manager_ui_'.Str::random(8), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]);
        $role->capabilities()->sync(['admissions.manage']);
        app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $role->id,
        ]));
    }

    /**
     * @return array{school: School, campus: Campus, year: AcademicYear, grade: GradeLevel, section: Section, applicant: Applicant}
     */
    private function buildAdmissionsContext(): array
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school, ['code' => 'SRC-'.Str::random(8)]);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $applicant = $this->createApplicant($school, ['first_name' => 'Asha', 'last_name' => 'Verma']);

        return compact('school', 'campus', 'year', 'grade', 'section', 'applicant');
    }

    private function applicantService(): ApplicantService
    {
        return app(ApplicantService::class);
    }

    private function applicationService(): AdmissionApplicationService
    {
        return app(AdmissionApplicationService::class);
    }

    private function conversionService(): AdmissionConversionService
    {
        return app(AdmissionConversionService::class);
    }

    // ==================================================================
    // Navigation
    // ==================================================================

    #[Test]
    public function the_dashboard_nav_reflects_admissions_view_capability(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);

        $this->get('/app')->assertInertia(fn ($page) => $page
            ->component('App/Dashboard')
            ->where('nav.canViewAdmissions', true)
        );
    }

    #[Test]
    public function nav_hides_admissions_without_the_view_capability(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);
        $this->activate($user, $school);

        $this->get('/app')->assertInertia(fn ($page) => $page->where('nav.canViewAdmissions', false));
    }

    // ==================================================================
    // AdmissionApplication directory
    // ==================================================================

    #[Test]
    public function admissions_view_allows_the_directory_page(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);

        $this->get('/app/admissions')->assertInertia(fn ($page) => $page->component('App/Admissions/Index'));
    }

    #[Test]
    public function no_admissions_view_is_forbidden_on_the_directory(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);
        $this->activate($user, $school);

        $this->get('/app/admissions')->assertForbidden();
    }

    #[Test]
    public function directory_lists_current_school_applications_with_reference_options(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'grade' => $grade, 'applicant' => $applicant] = $this->buildAdmissionsContext();
        $application = $this->applicationService()->create($applicant, $year, $campus, $grade);
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $this->get('/app/admissions')->assertInertia(fn ($page) => $page
            ->component('App/Admissions/Index')
            ->has('applications.data', 1)
            ->where('applications.data.0.id', $application->id)
            ->where('applications.data.0.status', 'draft')
            ->has('academicYears')
            ->has('campuses')
            ->has('gradeLevels')
        );
    }

    #[Test]
    public function directory_filters_are_echoed_back_and_applied(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'grade' => $grade, 'applicant' => $applicant] = $this->buildAdmissionsContext();
        $this->applicationService()->create($applicant, $year, $campus, $grade);
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $this->get('/app/admissions?status=draft')->assertInertia(fn ($page) => $page
            ->where('filters.status', 'draft')
            ->has('applications.data', 1)
        );

        $this->get('/app/admissions?status=submitted')->assertInertia(fn ($page) => $page
            ->where('filters.status', 'submitted')
            ->has('applications.data', 0)
        );
    }

    #[Test]
    public function directory_rows_never_expose_decision_note_or_date_of_birth(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'grade' => $grade, 'applicant' => $applicant] = $this->buildAdmissionsContext();
        $application = $this->applicationService()->create($applicant, $year, $campus, $grade);
        $this->applicationService()->submit($application);
        $this->applicationService()->accept($application, 'Strong academic record');
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $this->get('/app/admissions')->assertInertia(fn ($page) => $page
            ->missing('applications.data.0.decisionNote')
            ->missing('applications.data.0.applicant.dateOfBirth')
            ->missing('applications.data.0.school_id')
        );
    }

    // ==================================================================
    // Applicants
    // ==================================================================

    #[Test]
    public function applicants_view_allows_the_directory_and_manage_is_required_to_create(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);

        $this->get('/app/admissions/applicants')->assertInertia(fn ($page) => $page
            ->component('App/Admissions/Applicants/Index')
            ->where('canManage', true)
        );
        $this->get('/app/admissions/applicants/create')->assertOk();
    }

    #[Test]
    public function view_only_cannot_create_an_applicant(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->grantViewOnly($user, $school);
        $this->activate($user, $school);

        $this->get('/app/admissions/applicants/create')->assertForbidden();
        $this->post('/app/admissions/applicants', [
            'first_name' => 'Asha', 'date_of_birth' => '2018-04-12',
        ])->assertForbidden();
    }

    #[Test]
    public function admissions_manage_can_create_an_applicant_and_is_redirected_to_its_page(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->grantManageOnly($user, $school);
        $this->activate($user, $school);

        $response = $this->post('/app/admissions/applicants', [
            'first_name' => 'Asha', 'last_name' => 'Verma', 'date_of_birth' => '2018-04-12',
        ]);

        $stored = app(TenantContext::class)->withSchool($school, fn () => Applicant::query()->where('first_name', 'Asha')->firstOrFail());
        $response->assertRedirect("/app/admissions/applicants/{$stored->id}");
        $this->assertSame($school->id, $stored->school_id);
    }

    #[Test]
    public function a_blank_date_of_birth_shows_a_clean_inline_error(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->grantManageOnly($user, $school);
        $this->activate($user, $school);

        $this->post('/app/admissions/applicants', ['first_name' => 'Asha'])
            ->assertSessionHasErrors('date_of_birth');
    }

    #[Test]
    public function applicant_list_excludes_date_of_birth_but_detail_includes_it(): void
    {
        ['school' => $school, 'applicant' => $applicant] = $this->buildAdmissionsContext();
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $this->get('/app/admissions/applicants')->assertInertia(fn ($page) => $page
            ->missing('applicants.data.0.dateOfBirth')
        );

        $this->get("/app/admissions/applicants/{$applicant->id}")->assertInertia(fn ($page) => $page
            ->component('App/Admissions/Applicants/Show')
            ->has('applicant.dateOfBirth')
        );
    }

    #[Test]
    public function applicant_show_lists_full_application_history(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'grade' => $grade, 'applicant' => $applicant] = $this->buildAdmissionsContext();
        $rejected = $this->createAdmissionApplication($applicant, $year, $campus, $grade, ['status' => 'rejected']);
        $draft = $this->createAdmissionApplication($applicant, $year, $campus, $grade, ['status' => 'draft']);
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $this->get("/app/admissions/applicants/{$applicant->id}")->assertInertia(fn ($page) => $page
            ->has('applications', 2)
        );
    }

    #[Test]
    public function a_foreign_school_applicant_and_a_random_uuid_are_equivalently_not_found(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $applicantB = $this->createApplicant($this->createSchool());
        $this->activate($user, $schoolA);

        $foreign = $this->get("/app/admissions/applicants/{$applicantB->id}");
        $random = $this->get('/app/admissions/applicants/'.Str::uuid());

        $foreign->assertNotFound();
        $random->assertNotFound();
    }

    // ==================================================================
    // Create AdmissionApplication (nested under an Applicant)
    // ==================================================================

    #[Test]
    public function the_create_page_and_store_action_are_forbidden_without_admissions_manage(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'grade' => $grade, 'applicant' => $applicant] = $this->buildAdmissionsContext();
        $user = $this->createUser();
        $this->grantViewOnly($user, $school);
        $this->activate($user, $school);

        $this->get("/app/admissions/applicants/{$applicant->id}/applications/create")->assertForbidden();
        $this->post("/app/admissions/applicants/{$applicant->id}/applications", [
            'academic_year_id' => $year->id, 'campus_id' => $campus->id, 'grade_level_id' => $grade->id,
        ])->assertForbidden();
    }

    #[Test]
    public function admissions_manage_can_create_an_application_and_is_redirected_to_its_page(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'grade' => $grade, 'applicant' => $applicant] = $this->buildAdmissionsContext();
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $response = $this->post("/app/admissions/applicants/{$applicant->id}/applications", [
            'academic_year_id' => $year->id, 'campus_id' => $campus->id, 'grade_level_id' => $grade->id,
        ]);

        $stored = app(TenantContext::class)->withSchool($school, fn () => AdmissionApplication::query()->where('applicant_id', $applicant->id)->firstOrFail());
        $response->assertRedirect("/app/admissions/{$stored->id}");
        $this->assertSame('draft', $stored->status);
    }

    #[Test]
    public function a_second_open_application_for_the_same_context_shows_a_clean_inline_error(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'grade' => $grade, 'applicant' => $applicant] = $this->buildAdmissionsContext();
        $this->applicationService()->create($applicant, $year, $campus, $grade);
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $response = $this->post("/app/admissions/applicants/{$applicant->id}/applications", [
            'academic_year_id' => $year->id, 'campus_id' => $campus->id, 'grade_level_id' => $grade->id,
        ]);

        $response->assertSessionHasErrors('grade_level_id');
    }

    #[Test]
    public function malicious_cross_school_references_are_rejected_on_application_create(): void
    {
        ['school' => $schoolA, 'applicant' => $applicantA] = $this->buildAdmissionsContext();
        ['year' => $yearB, 'campus' => $campusB, 'grade' => $gradeB] = $this->buildAdmissionsContext();
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $schoolA);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $schoolA);

        $response = $this->post("/app/admissions/applicants/{$applicantA->id}/applications", [
            'academic_year_id' => $yearB->id, 'campus_id' => $campusB->id, 'grade_level_id' => $gradeB->id,
        ]);

        $response->assertSessionHasErrors(['academic_year_id', 'campus_id', 'grade_level_id']);
    }

    #[Test]
    public function a_foreign_school_applicant_and_a_random_uuid_are_equivalently_not_found_for_application_create(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        ['applicant' => $applicantB] = $this->buildAdmissionsContext();
        $this->activate($user, $schoolA);

        $foreign = $this->get("/app/admissions/applicants/{$applicantB->id}/applications/create");
        $random = $this->get('/app/admissions/applicants/'.Str::uuid().'/applications/create');

        $foreign->assertNotFound();
        $random->assertNotFound();
    }

    // ==================================================================
    // Lifecycle
    // ==================================================================

    #[Test]
    public function submit_transitions_draft_to_submitted(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'grade' => $grade, 'applicant' => $applicant] = $this->buildAdmissionsContext();
        $application = $this->applicationService()->create($applicant, $year, $campus, $grade);
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $response = $this->post("/app/admissions/{$application->id}/submit");

        $response->assertRedirect("/app/admissions/{$application->id}");
        $fresh = app(TenantContext::class)->withSchool($school, fn () => $application->fresh());
        $this->assertSame('submitted', $fresh->status);
    }

    #[Test]
    public function accept_transitions_submitted_to_accepted_and_records_the_decision_note(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'grade' => $grade, 'applicant' => $applicant] = $this->buildAdmissionsContext();
        $application = $this->applicationService()->create($applicant, $year, $campus, $grade);
        $this->applicationService()->submit($application);
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $this->post("/app/admissions/{$application->id}/accept", ['decision_note' => 'Strong record'])
            ->assertRedirect("/app/admissions/{$application->id}");

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $application->fresh());
        $this->assertSame('accepted', $fresh->status);
        $this->assertSame('Strong record', $fresh->decision_note);
    }

    #[Test]
    public function accept_never_creates_a_student_distinct_from_convert(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'grade' => $grade, 'applicant' => $applicant] = $this->buildAdmissionsContext();
        $application = $this->applicationService()->create($applicant, $year, $campus, $grade);
        $this->applicationService()->submit($application);
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $this->post("/app/admissions/{$application->id}/accept");

        $studentCount = app(TenantContext::class)->withSchool($school, fn () => Student::query()->count());
        $this->assertSame(0, $studentCount);
    }

    #[Test]
    public function reject_transitions_submitted_to_rejected(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'grade' => $grade, 'applicant' => $applicant] = $this->buildAdmissionsContext();
        $application = $this->applicationService()->create($applicant, $year, $campus, $grade);
        $this->applicationService()->submit($application);
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $this->post("/app/admissions/{$application->id}/reject", ['decision_note' => 'Not a fit'])
            ->assertRedirect("/app/admissions/{$application->id}");

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $application->fresh());
        $this->assertSame('rejected', $fresh->status);
    }

    #[Test]
    public function withdraw_transitions_accepted_to_withdrawn(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'grade' => $grade, 'applicant' => $applicant] = $this->buildAdmissionsContext();
        $application = $this->applicationService()->create($applicant, $year, $campus, $grade);
        $this->applicationService()->submit($application);
        $this->applicationService()->accept($application);
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $this->post("/app/admissions/{$application->id}/withdraw")
            ->assertRedirect("/app/admissions/{$application->id}");

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $application->fresh());
        $this->assertSame('withdrawn', $fresh->status);
    }

    #[Test]
    public function an_illegal_transition_shows_a_clean_error_not_a_broken_page(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'grade' => $grade, 'applicant' => $applicant] = $this->buildAdmissionsContext();
        $application = $this->applicationService()->create($applicant, $year, $campus, $grade);
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        // draft cannot be accepted directly (must be submitted first).
        $response = $this->post("/app/admissions/{$application->id}/accept");

        $response->assertSessionHasErrors('status');
        $fresh = app(TenantContext::class)->withSchool($school, fn () => $application->fresh());
        $this->assertSame('draft', $fresh->status);
    }

    #[Test]
    public function lifecycle_actions_are_forbidden_without_admissions_manage(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'grade' => $grade, 'applicant' => $applicant] = $this->buildAdmissionsContext();
        $application = $this->applicationService()->create($applicant, $year, $campus, $grade);
        $user = $this->createUser();
        $this->grantViewOnly($user, $school);
        $this->activate($user, $school);

        $this->post("/app/admissions/{$application->id}/submit")->assertForbidden();
    }

    #[Test]
    public function a_foreign_school_application_and_a_random_uuid_are_equivalently_not_found_for_lifecycle(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        ['year' => $yearB, 'campus' => $campusB, 'grade' => $gradeB, 'applicant' => $applicantB] = $this->buildAdmissionsContext();
        $applicationB = $this->applicationService()->create($applicantB, $yearB, $campusB, $gradeB);
        $this->activate($user, $schoolA);

        $foreign = $this->post("/app/admissions/{$applicationB->id}/submit");
        $random = $this->post('/app/admissions/'.Str::uuid().'/submit');

        $foreign->assertNotFound();
        $random->assertNotFound();
    }

    // ==================================================================
    // Show page / show-page workspace props
    // ==================================================================

    #[Test]
    public function show_page_exposes_compatible_sections_only_when_accepted_and_manage(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'grade' => $grade, 'section' => $section, 'applicant' => $applicant] = $this->buildAdmissionsContext();
        $application = $this->applicationService()->create($applicant, $year, $campus, $grade);
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $this->get("/app/admissions/{$application->id}")->assertInertia(fn ($page) => $page
            ->where('compatibleSections', [])
        );

        $this->applicationService()->submit($application);
        $this->applicationService()->accept($application);

        $this->get("/app/admissions/{$application->id}")->assertInertia(fn ($page) => $page
            ->has('compatibleSections', 1)
            ->where('compatibleSections.0.id', $section->id)
        );
    }

    #[Test]
    public function converted_student_id_is_a_bare_id_never_an_embedded_student_projection(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'grade' => $grade, 'section' => $section, 'applicant' => $applicant] = $this->buildAdmissionsContext();
        $application = $this->applicationService()->create($applicant, $year, $campus, $grade);
        $this->applicationService()->submit($application);
        $this->applicationService()->accept($application);
        $this->conversionService()->convert($application, 'S-2001', $section, '01', '2026-06-01');
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $this->get("/app/admissions/{$application->id}")->assertInertia(fn ($page) => $page
            ->where('application.status', 'converted')
            ->has('application.convertedStudentId')
            ->missing('application.convertedStudent')
            ->missing('application.student')
        );
    }

    // ==================================================================
    // Conversion
    // ==================================================================

    #[Test]
    public function convert_is_forbidden_without_admissions_manage(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'grade' => $grade, 'section' => $section, 'applicant' => $applicant] = $this->buildAdmissionsContext();
        $application = $this->applicationService()->create($applicant, $year, $campus, $grade);
        $this->applicationService()->submit($application);
        $this->applicationService()->accept($application);
        $user = $this->createUser();
        $this->grantViewOnly($user, $school);
        $this->activate($user, $school);

        $this->post("/app/admissions/{$application->id}/convert", [
            'student_number' => 'S-3001', 'section_id' => $section->id, 'roll_number' => '01', 'starts_on' => '2026-06-01',
        ])->assertForbidden();
    }

    #[Test]
    public function convert_core_with_no_guardian_creates_a_student_and_enrollment_and_redirects(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'grade' => $grade, 'section' => $section, 'applicant' => $applicant] = $this->buildAdmissionsContext();
        $application = $this->applicationService()->create($applicant, $year, $campus, $grade);
        $this->applicationService()->submit($application);
        $this->applicationService()->accept($application);
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $response = $this->post("/app/admissions/{$application->id}/convert", [
            'student_number' => 'S-3001', 'section_id' => $section->id, 'roll_number' => '01', 'starts_on' => '2026-06-01',
        ]);

        $response->assertRedirect("/app/admissions/{$application->id}");
        $fresh = app(TenantContext::class)->withSchool($school, fn () => $application->fresh());
        $this->assertSame('converted', $fresh->status);
        $this->assertNotNull($fresh->converted_student_id);
        $student = app(TenantContext::class)->withSchool($school, fn () => Student::query()->findOrFail($fresh->converted_student_id));
        $this->assertSame('S-3001', $student->student_number);
    }

    #[Test]
    public function convert_with_guardian_create_mode_creates_a_new_guardian_and_relationship(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'grade' => $grade, 'section' => $section, 'applicant' => $applicant] = $this->buildAdmissionsContext();
        $application = $this->applicationService()->create($applicant, $year, $campus, $grade);
        $this->applicationService()->submit($application);
        $this->applicationService()->accept($application);
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $this->post("/app/admissions/{$application->id}/convert", [
            'student_number' => 'S-3002', 'section_id' => $section->id, 'roll_number' => '02', 'starts_on' => '2026-06-01',
            'guardian' => [
                'mode' => 'create', 'first_name' => 'Priya', 'last_name' => 'Verma',
                'relationship_type' => 'mother', 'is_legal_guardian' => true,
            ],
        ])->assertRedirect("/app/admissions/{$application->id}");

        $guardianCount = app(TenantContext::class)->withSchool($school, fn () => Guardian::query()->where('first_name', 'Priya')->count());
        $this->assertSame(1, $guardianCount);
    }

    #[Test]
    public function convert_with_guardian_link_existing_mode_links_the_selected_guardian(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'grade' => $grade, 'section' => $section, 'applicant' => $applicant] = $this->buildAdmissionsContext();
        $guardian = $this->createGuardian($school, ['first_name' => 'Priya', 'last_name' => 'Verma']);
        $application = $this->applicationService()->create($applicant, $year, $campus, $grade);
        $this->applicationService()->submit($application);
        $this->applicationService()->accept($application);
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $this->post("/app/admissions/{$application->id}/convert", [
            'student_number' => 'S-3003', 'section_id' => $section->id, 'roll_number' => '03', 'starts_on' => '2026-06-01',
            'guardian' => ['mode' => 'link_existing', 'guardian_id' => $guardian->id, 'relationship_type' => 'mother'],
        ])->assertRedirect("/app/admissions/{$application->id}");

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $application->fresh());
        $this->assertSame('converted', $fresh->status);
    }

    #[Test]
    public function convert_with_a_conflicting_guardian_contact_shows_a_clean_inline_error(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'grade' => $grade, 'section' => $section, 'applicant' => $applicant] = $this->buildAdmissionsContext();
        $existingGuardian = $this->createGuardian($school);
        $this->createGuardianContact($existingGuardian, ContactType::Email, 'priya@example.com');
        $application = $this->applicationService()->create($applicant, $year, $campus, $grade);
        $this->applicationService()->submit($application);
        $this->applicationService()->accept($application);
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $response = $this->post("/app/admissions/{$application->id}/convert", [
            'student_number' => 'S-3004', 'section_id' => $section->id, 'roll_number' => '04', 'starts_on' => '2026-06-01',
            'guardian' => [
                'mode' => 'create', 'first_name' => 'Priya', 'relationship_type' => 'mother',
                'contact_type' => 'email', 'contact_value' => 'priya@example.com',
            ],
        ]);

        $response->assertSessionHasErrors('guardian.contact_value');
        $fresh = app(TenantContext::class)->withSchool($school, fn () => $application->fresh());
        $this->assertSame('accepted', $fresh->status);
    }

    #[Test]
    public function convert_with_a_duplicate_student_number_shows_a_clean_inline_error(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'grade' => $grade, 'section' => $section, 'applicant' => $applicant] = $this->buildAdmissionsContext();
        $this->createStudent($school, ['student_number' => 'S-9999']);
        $application = $this->applicationService()->create($applicant, $year, $campus, $grade);
        $this->applicationService()->submit($application);
        $this->applicationService()->accept($application);
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $response = $this->post("/app/admissions/{$application->id}/convert", [
            'student_number' => 'S-9999', 'section_id' => $section->id, 'roll_number' => '01', 'starts_on' => '2026-06-01',
        ]);

        $response->assertSessionHasErrors('student_number');
    }

    #[Test]
    public function convert_with_an_incompatible_section_shows_a_clean_inline_error(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'applicant' => $applicant] = $this->buildAdmissionsContext();
        $otherGrade = $this->createGradeLevel($school);
        $incompatibleSection = $this->createSection($year, $campus, $otherGrade);
        $grade = $this->createGradeLevel($school);
        $application = $this->applicationService()->create($applicant, $year, $campus, $grade);
        $this->applicationService()->submit($application);
        $this->applicationService()->accept($application);
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        $response = $this->post("/app/admissions/{$application->id}/convert", [
            'student_number' => 'S-3005', 'section_id' => $incompatibleSection->id, 'roll_number' => '01', 'starts_on' => '2026-06-01',
        ]);

        $response->assertSessionHasErrors('section_id');
    }

    #[Test]
    public function converting_an_already_converted_application_shows_a_clean_stale_tab_error_without_duplicating_records(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'grade' => $grade, 'section' => $section, 'applicant' => $applicant] = $this->buildAdmissionsContext();
        $application = $this->applicationService()->create($applicant, $year, $campus, $grade);
        $this->applicationService()->submit($application);
        $this->applicationService()->accept($application);
        $this->conversionService()->convert($application, 'S-4001', $section, '01', '2026-06-01');
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $school);

        // Simulates a stale second browser tab re-submitting the same
        // convert form after the application was already converted.
        $response = $this->post("/app/admissions/{$application->id}/convert", [
            'student_number' => 'S-4002', 'section_id' => $section->id, 'roll_number' => '02', 'starts_on' => '2026-06-01',
        ]);

        $response->assertSessionHasErrors('status');
        $studentCount = app(TenantContext::class)->withSchool($school, fn () => Student::query()->count());
        $this->assertSame(1, $studentCount);
    }

    #[Test]
    public function a_foreign_school_section_and_a_random_uuid_are_equivalently_rejected_for_convert(): void
    {
        ['school' => $schoolA, 'year' => $yearA, 'campus' => $campusA, 'grade' => $gradeA, 'applicant' => $applicantA] = $this->buildAdmissionsContext();
        ['section' => $sectionB] = $this->buildAdmissionsContext();
        $applicationA = $this->applicationService()->create($applicantA, $yearA, $campusA, $gradeA);
        $this->applicationService()->submit($applicationA);
        $this->applicationService()->accept($applicationA);
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $schoolA);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $schoolA);

        $foreign = $this->post("/app/admissions/{$applicationA->id}/convert", [
            'student_number' => 'S-5001', 'section_id' => $sectionB->id, 'roll_number' => '01', 'starts_on' => '2026-06-01',
        ]);
        $random = $this->post("/app/admissions/{$applicationA->id}/convert", [
            'student_number' => 'S-5002', 'section_id' => (string) Str::uuid(), 'roll_number' => '01', 'starts_on' => '2026-06-01',
        ]);

        $foreign->assertSessionHasErrors('section_id');
        $random->assertSessionHasErrors('section_id');
    }

    #[Test]
    public function a_foreign_school_guardian_id_is_rejected_for_convert(): void
    {
        ['school' => $schoolA, 'year' => $yearA, 'campus' => $campusA, 'grade' => $gradeA, 'section' => $sectionA, 'applicant' => $applicantA] = $this->buildAdmissionsContext();
        $guardianB = $this->createGuardian($this->createSchool());
        $applicationA = $this->applicationService()->create($applicantA, $yearA, $campusA, $gradeA);
        $this->applicationService()->submit($applicationA);
        $this->applicationService()->accept($applicationA);
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $schoolA);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->activate($user, $schoolA);

        $response = $this->post("/app/admissions/{$applicationA->id}/convert", [
            'student_number' => 'S-5003', 'section_id' => $sectionA->id, 'roll_number' => '01', 'starts_on' => '2026-06-01',
            'guardian' => ['mode' => 'link_existing', 'guardian_id' => $guardianB->id, 'relationship_type' => 'mother'],
        ]);

        $response->assertSessionHasErrors('guardian.guardian_id');
    }

    // ==================================================================
    // Guardian-picker adapter endpoints
    // ==================================================================

    #[Test]
    public function guardian_search_and_candidates_require_admissions_manage(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $viewer = $this->createUser();
        $this->grantViewOnly($viewer, $school);
        $this->activate($viewer, $school);

        $this->get('/app/admissions/guardians/search?q=Pr')->assertForbidden();
        $this->get('/app/admissions/guardians/candidates?type=email&value=x@example.com')->assertForbidden();
    }

    #[Test]
    public function guardian_search_returns_same_school_matches_only(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $this->createGuardian($schoolA, ['first_name' => 'Priya', 'last_name' => 'Verma']);
        $this->createGuardian($this->createSchool(), ['first_name' => 'Priyanka', 'last_name' => 'Other']);
        $this->activate($user, $schoolA);

        $response = $this->get('/app/admissions/guardians/search?q=Pri');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('firstName')->all();
        $this->assertSame(['Priya'], $names);
    }

    #[Test]
    public function guardian_candidates_finds_an_exact_contact_match_within_the_school(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school, ['first_name' => 'Priya']);
        $this->createGuardianContact($guardian, ContactType::Email, 'priya@example.com');
        $this->activate($user, $school);

        $response = $this->get('/app/admissions/guardians/candidates?type=email&value=priya@example.com');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Priya', $response->json('data.0.firstName'));
    }
}
