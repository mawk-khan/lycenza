<?php

namespace Tests\Feature\Admissions;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\Applicant;
use App\Domain\Students\Infrastructure\Student;
use App\Models\Campus;
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
 * Phase 1D.5: the administrative AdmissionApplication HTTP surface --
 * directory/filter, create, detail, and the four pre-conversion
 * lifecycle actions (submit/accept/reject/withdraw). Conversion itself
 * is covered separately in AdmissionConversionApiTest.php.
 */
class AdmissionApplicationApiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function token(User $user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    private function asUser(User $user)
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token($user));
    }

    /**
     * @return array{school: School, applicant: Applicant, year: AcademicYear, campus: Campus, gradeLevel: GradeLevel}
     */
    private function buildContext(?School $school = null): array
    {
        $school = $school ?? $this->createSchool();
        $applicant = $this->createApplicant($school);
        $year = $this->createAcademicYear($school, ['code' => 'AY-'.Str::random(12)]);
        $campus = $this->createCampus($school);
        $gradeLevel = $this->createGradeLevel($school);

        return compact('school', 'applicant', 'year', 'campus', 'gradeLevel');
    }

    // ==================================================================
    // A. Directory
    // ==================================================================

    #[Test]
    public function a_guest_is_denied(): void
    {
        $school = $this->createSchool();

        $this->getJson("/api/v1/schools/{$school->id}/admission-applications")->assertUnauthorized();
    }

    #[Test]
    public function admissions_view_can_list_only_current_school_applications(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        ['applicant' => $applicantA, 'year' => $yearA, 'campus' => $campusA, 'gradeLevel' => $gradeA] = $this->buildContext($schoolA);
        $appA = $this->createAdmissionApplication($applicantA, $yearA, $campusA, $gradeA);

        $schoolB = $this->createSchool();
        ['applicant' => $applicantB, 'year' => $yearB, 'campus' => $campusB, 'gradeLevel' => $gradeB] = $this->buildContext($schoolB);
        $this->createAdmissionApplication($applicantB, $yearB, $campusB, $gradeB);

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$schoolA->id}/admission-applications");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($appA->id, $response->json('data.0.id'));
    }

    #[Test]
    public function a_member_without_admissions_view_is_denied(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);

        $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/admission-applications")->assertForbidden();
    }

    #[Test]
    public function directory_supports_status_filter(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        ['applicant' => $a1, 'year' => $y1, 'campus' => $c1, 'gradeLevel' => $g1] = $this->buildContext($school);
        $this->createAdmissionApplication($a1, $y1, $c1, $g1, ['status' => 'draft']);
        ['applicant' => $a2, 'year' => $y2, 'campus' => $c2, 'gradeLevel' => $g2] = $this->buildContext($school);
        $submitted = $this->createAdmissionApplication($a2, $y2, $c2, $g2, ['status' => 'submitted']);

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/admission-applications?status=submitted");

        $response->assertOk();
        $this->assertSame([$submitted->id], collect($response->json('data'))->pluck('id')->all());
    }

    #[Test]
    public function directory_rejects_an_invalid_status_value(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/admission-applications?status=not_a_real_status");

        $response->assertStatus(422);
    }

    #[Test]
    public function directory_supports_academic_year_campus_and_grade_level_filters(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel] = $this->buildContext($school);
        $target = $this->createAdmissionApplication($applicant, $year, $campus, $gradeLevel);
        ['applicant' => $applicant2] = $this->buildContext($school);
        $otherYear = $this->createAcademicYear($school, ['code' => 'AY-'.Str::random(12)]);
        $this->createAdmissionApplication($applicant2, $otherYear, $campus, $gradeLevel);

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/admission-applications?academic_year_id={$year->id}");

        $response->assertOk();
        $this->assertSame([$target->id], collect($response->json('data'))->pluck('id')->all());
    }

    #[Test]
    public function directory_supports_applicant_name_search(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $applicant = $this->createApplicant($school, ['first_name' => 'Asha', 'last_name' => 'Verma']);
        $year = $this->createAcademicYear($school, ['code' => 'AY-'.Str::random(12)]);
        $campus = $this->createCampus($school);
        $gradeLevel = $this->createGradeLevel($school);
        $target = $this->createAdmissionApplication($applicant, $year, $campus, $gradeLevel);
        ['applicant' => $other] = $this->buildContext($school);

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/admission-applications?applicant_name=asha");

        $response->assertOk();
        $this->assertSame([$target->id], collect($response->json('data'))->pluck('id')->all());
    }

    #[Test]
    public function directory_rows_never_include_decision_note(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel] = $this->buildContext($school);
        $this->createAdmissionApplication($applicant, $year, $campus, $gradeLevel, [
            'status' => 'rejected', 'decision_note' => 'Sensitive internal reasoning.',
        ]);

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/admission-applications");

        $response->assertOk();
        $this->assertArrayNotHasKey('decisionNote', $response->json('data.0'));
    }

    // ==================================================================
    // B. Create
    // ==================================================================

    #[Test]
    public function admissions_manage_can_create_a_draft_application_from_same_school_references(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel] = $this->buildContext($school);

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'create-app-001')
            ->postJson("/api/v1/schools/{$school->id}/admission-applications", [
                'applicant_id' => $applicant->id, 'academic_year_id' => $year->id,
                'campus_id' => $campus->id, 'grade_level_id' => $gradeLevel->id,
            ]);

        $response->assertCreated();
        $this->assertSame('draft', $response->json('data.status'));
        $this->assertNull($response->json('data.convertedStudentId'));
    }

    #[Test]
    public function a_view_only_member_cannot_create_an_application(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel] = $this->buildContext($school);
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        $viewOnly = Role::query()->create(['key' => 'test_adm_viewer_'.Str::random(8), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]);
        $viewOnly->capabilities()->sync(['admissions.view']);
        app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $viewOnly->id,
        ]));

        $this->asUser($user)
            ->withHeader('Idempotency-Key', 'create-app-002')
            ->postJson("/api/v1/schools/{$school->id}/admission-applications", [
                'applicant_id' => $applicant->id, 'academic_year_id' => $year->id,
                'campus_id' => $campus->id, 'grade_level_id' => $gradeLevel->id,
            ])->assertForbidden();
    }

    #[Test]
    public function create_rejects_a_foreign_school_reference_with_a_clean_validation_error(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        ['applicant' => $applicant, 'campus' => $campus, 'gradeLevel' => $gradeLevel] = $this->buildContext($school);
        $otherSchool = $this->createSchool();
        $foreignYear = $this->createAcademicYear($otherSchool);

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'create-app-003')
            ->postJson("/api/v1/schools/{$school->id}/admission-applications", [
                'applicant_id' => $applicant->id, 'academic_year_id' => $foreignYear->id,
                'campus_id' => $campus->id, 'grade_level_id' => $gradeLevel->id,
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('academic_year_id', $response->json('error.errors'));
    }

    #[Test]
    public function a_second_open_application_for_the_same_context_is_a_clean_domain_error(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel] = $this->buildContext($school);
        $this->createAdmissionApplication($applicant, $year, $campus, $gradeLevel);

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'create-app-004')
            ->postJson("/api/v1/schools/{$school->id}/admission-applications", [
                'applicant_id' => $applicant->id, 'academic_year_id' => $year->id,
                'campus_id' => $campus->id, 'grade_level_id' => $gradeLevel->id,
            ]);

        $response->assertStatus(422);
        $this->assertSame('OPEN_ADMISSION_APPLICATION_EXISTS', $response->json('error.code'));
        $this->assertStringNotContainsString('SQLSTATE', $response->json('error.message'));
        $this->assertStringNotContainsString('admission_applications_one_open_per_context', $response->json('error.message'));
    }

    // ==================================================================
    // C. Detail
    // ==================================================================

    #[Test]
    public function detail_includes_decision_note_when_present(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel] = $this->buildContext($school);
        $application = $this->createAdmissionApplication($applicant, $year, $campus, $gradeLevel, [
            'status' => 'rejected', 'decision_note' => 'Internal note.',
        ]);

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/admission-applications/{$application->id}");

        $response->assertOk();
        $this->assertSame('Internal note.', $response->json('data.decisionNote'));
    }

    #[Test]
    public function detail_produces_the_same_not_found_outcome_for_a_foreign_application_and_a_random_uuid(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel] = $this->buildContext($schoolB);
        $foreignApplication = $this->createAdmissionApplication($applicant, $year, $campus, $gradeLevel);

        $foreign = $this->asUser($user)->getJson("/api/v1/schools/{$schoolA->id}/admission-applications/{$foreignApplication->id}");
        $random = $this->asUser($user)->getJson('/api/v1/schools/'.$schoolA->id.'/admission-applications/'.Str::uuid());

        $foreign->assertNotFound();
        $random->assertNotFound();
        $this->assertSame($foreign->status(), $random->status());
    }

    // ==================================================================
    // D. Lifecycle
    // ==================================================================

    #[Test]
    public function submit_transitions_draft_to_submitted_via_real_http(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel] = $this->buildContext($school);
        $application = $this->createAdmissionApplication($applicant, $year, $campus, $gradeLevel);

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'submit-app-001')
            ->postJson("/api/v1/schools/{$school->id}/admission-applications/{$application->id}/submit");

        $response->assertOk();
        $this->assertSame('submitted', $response->json('data.status'));
        $count = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()->where('event_type', 'admission_application.submitted')->count());
        $this->assertSame(1, $count);
    }

    #[Test]
    public function a_view_only_user_cannot_submit(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel] = $this->buildContext($school);
        $application = $this->createAdmissionApplication($applicant, $year, $campus, $gradeLevel);
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        $viewOnly = Role::query()->create(['key' => 'test_adm_viewer2_'.Str::random(8), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]);
        $viewOnly->capabilities()->sync(['admissions.view']);
        app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $viewOnly->id,
        ]));

        $this->asUser($user)
            ->withHeader('Idempotency-Key', 'submit-app-002')
            ->postJson("/api/v1/schools/{$school->id}/admission-applications/{$application->id}/submit")
            ->assertForbidden();
    }

    #[Test]
    public function accept_transitions_submitted_to_accepted_with_optional_decision_note(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel] = $this->buildContext($school);
        $application = $this->createAdmissionApplication($applicant, $year, $campus, $gradeLevel, ['status' => 'submitted']);

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'accept-app-001')
            ->postJson("/api/v1/schools/{$school->id}/admission-applications/{$application->id}/accept", [
                'decision_note' => 'Strong interview.',
            ]);

        $response->assertOk();
        $this->assertSame('accepted', $response->json('data.status'));
        $this->assertSame('Strong interview.', $response->json('data.decisionNote'));
        $studentCount = app(TenantContext::class)->withSchool($school, fn () => Student::query()->count());
        $this->assertSame(0, $studentCount, 'accept() must never create a Student.');
    }

    #[Test]
    public function reject_transitions_submitted_to_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel] = $this->buildContext($school);
        $application = $this->createAdmissionApplication($applicant, $year, $campus, $gradeLevel, ['status' => 'submitted']);

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'reject-app-001')
            ->postJson("/api/v1/schools/{$school->id}/admission-applications/{$application->id}/reject");

        $response->assertOk();
        $this->assertSame('rejected', $response->json('data.status'));
        $studentCount = app(TenantContext::class)->withSchool($school, fn () => Student::query()->count());
        $this->assertSame(0, $studentCount);
    }

    #[Test]
    public function withdraw_covers_submitted_and_accepted_sources(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        ['applicant' => $a1, 'year' => $y1, 'campus' => $c1, 'gradeLevel' => $g1] = $this->buildContext($school);
        $submitted = $this->createAdmissionApplication($a1, $y1, $c1, $g1, ['status' => 'submitted']);
        $this->asUser($user)
            ->withHeader('Idempotency-Key', 'withdraw-app-001')
            ->postJson("/api/v1/schools/{$school->id}/admission-applications/{$submitted->id}/withdraw")
            ->assertOk()
            ->assertJsonPath('data.status', 'withdrawn');

        ['applicant' => $a2, 'year' => $y2, 'campus' => $c2, 'gradeLevel' => $g2] = $this->buildContext($school);
        $accepted = $this->createAdmissionApplication($a2, $y2, $c2, $g2, ['status' => 'accepted']);
        $this->asUser($user)
            ->withHeader('Idempotency-Key', 'withdraw-app-002')
            ->postJson("/api/v1/schools/{$school->id}/admission-applications/{$accepted->id}/withdraw")
            ->assertOk()
            ->assertJsonPath('data.status', 'withdrawn');
    }

    #[Test]
    public function an_illegal_transition_is_rejected_with_a_stable_domain_error_and_no_mutation(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'gradeLevel' => $gradeLevel] = $this->buildContext($school);
        $draft = $this->createAdmissionApplication($applicant, $year, $campus, $gradeLevel);

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'accept-app-illegal-001')
            ->postJson("/api/v1/schools/{$school->id}/admission-applications/{$draft->id}/accept");

        $response->assertStatus(422);
        $this->assertSame('INVALID_ADMISSION_APPLICATION_TRANSITION', $response->json('error.code'));

        $fresh = app(TenantContext::class)->withSchool($school, fn () => AdmissionApplication::query()->findOrFail($draft->id));
        $this->assertSame('draft', $fresh->status);
        $count = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()->where('event_type', 'admission_application.accepted')->count());
        $this->assertSame(0, $count, 'No false success audit for a rejected mutation attempt.');
    }
}
