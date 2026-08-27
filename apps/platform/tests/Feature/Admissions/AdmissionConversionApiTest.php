<?php

namespace Tests\Feature\Admissions;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\Applicant;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
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
 * Phase 1D.5: the administrative AdmissionApplication conversion HTTP
 * endpoint (`POST .../convert`). Real HTTP end-to-end coverage --
 * domain-level forced-rollback/concurrency proof already exists in
 * Phase 1D.3 (AdmissionConversionServiceTest/
 * AdmissionConversionConcurrencyTest); this class does not reproduce
 * that low-level coverage, only proves the HTTP boundary composes it
 * correctly.
 */
class AdmissionConversionApiTest extends TestCase
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
     * @return array{school: School, applicant: Applicant, year: AcademicYear, campus: Campus, gradeLevel: GradeLevel, section: Section, application: AdmissionApplication}
     */
    private function buildAcceptedContext(?School $school = null): array
    {
        $school = $school ?? $this->createSchool();
        $applicant = $this->createApplicant($school, [
            'first_name' => 'Asha', 'middle_name' => 'K', 'last_name' => 'Verma', 'date_of_birth' => '2018-04-12',
        ]);
        $year = $this->createAcademicYear($school, ['code' => 'AY-'.Str::random(12)]);
        $campus = $this->createCampus($school);
        $gradeLevel = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $gradeLevel);
        $application = $this->createAdmissionApplication($applicant, $year, $campus, $gradeLevel, ['status' => 'accepted']);

        return compact('school', 'applicant', 'year', 'campus', 'gradeLevel', 'section', 'application');
    }

    private function studentCount(School $school): int
    {
        return app(TenantContext::class)->withSchool($school, fn () => Student::query()->count());
    }

    private function enrollmentCount(School $school): int
    {
        return app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->count());
    }

    // ==================================================================
    // A. Core success (real HTTP, real chain)
    // ==================================================================

    #[Test]
    public function a_full_http_lifecycle_from_create_through_convert_succeeds(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $applicant = $this->createApplicant($school, ['first_name' => 'Rohit', 'date_of_birth' => '2017-01-01']);
        $year = $this->createAcademicYear($school, ['code' => 'AY-'.Str::random(12)]);
        $campus = $this->createCampus($school);
        $gradeLevel = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $gradeLevel);

        $created = $this->asUser($user)->withHeader('Idempotency-Key', 'e2e-create')
            ->postJson("/api/v1/schools/{$school->id}/admission-applications", [
                'applicant_id' => $applicant->id, 'academic_year_id' => $year->id,
                'campus_id' => $campus->id, 'grade_level_id' => $gradeLevel->id,
            ])->assertCreated();
        $applicationId = $created->json('data.id');

        $this->asUser($user)->withHeader('Idempotency-Key', 'e2e-submit')
            ->postJson("/api/v1/schools/{$school->id}/admission-applications/{$applicationId}/submit")
            ->assertOk()->assertJsonPath('data.status', 'submitted');

        $this->asUser($user)->withHeader('Idempotency-Key', 'e2e-accept')
            ->postJson("/api/v1/schools/{$school->id}/admission-applications/{$applicationId}/accept")
            ->assertOk()->assertJsonPath('data.status', 'accepted');

        $response = $this->asUser($user)->withHeader('Idempotency-Key', 'e2e-convert')
            ->postJson("/api/v1/schools/{$school->id}/admission-applications/{$applicationId}/convert", [
                'student_number' => 'S-E2E-1', 'section_id' => $section->id,
                'roll_number' => '01', 'starts_on' => '2026-06-01',
            ]);

        $response->assertOk();
        $this->assertSame('converted', $response->json('data.application.status'));
        $this->assertSame('Rohit', $response->json('data.student.firstName'));
        $this->assertSame('S-E2E-1', $response->json('data.student.studentNumber'));
        $this->assertNotNull($response->json('data.enrollment.id'));
        $this->assertNull($response->json('data.guardian'));
        $this->assertSame(1, $this->studentCount($school));
        $this->assertSame(1, $this->enrollmentCount($school));
    }

    #[Test]
    public function convert_response_contains_complete_safe_provenance_and_no_guardian(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        ['section' => $section, 'application' => $application] = $this->buildAcceptedContext($school);

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'convert-core-001')
            ->postJson("/api/v1/schools/{$school->id}/admission-applications/{$application->id}/convert", [
                'student_number' => 'S-2001', 'section_id' => $section->id,
                'roll_number' => '02', 'starts_on' => '2026-06-01',
            ]);

        $response->assertOk();
        $this->assertSame('converted', $response->json('data.application.status'));
        $this->assertSame($response->json('data.student.id'), $response->json('data.application.convertedStudentId'));
        $this->assertSame($response->json('data.enrollment.id'), $response->json('data.application.convertedStudentEnrollmentId'));
        $this->assertNull($response->json('data.guardian'));
        $this->assertNull($response->json('data.relationship'));
    }

    #[Test]
    public function a_view_only_user_cannot_convert(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        ['section' => $section, 'application' => $application] = $this->buildAcceptedContext($school);
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        $viewOnly = Role::query()->create(['key' => 'test_adm_viewer_conv_'.Str::random(8), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]);
        $viewOnly->capabilities()->sync(['admissions.view']);
        app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $viewOnly->id,
        ]));

        $this->asUser($user)
            ->withHeader('Idempotency-Key', 'convert-denied-001')
            ->postJson("/api/v1/schools/{$school->id}/admission-applications/{$application->id}/convert", [
                'student_number' => 'S-3001', 'section_id' => $section->id,
                'roll_number' => '03', 'starts_on' => '2026-06-01',
            ])->assertForbidden();

        $this->assertSame(0, $this->studentCount($school));
    }

    #[Test]
    public function a_manage_only_custom_role_can_convert(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        ['section' => $section, 'application' => $application] = $this->buildAcceptedContext($school);
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        $manageOnly = Role::query()->create(['key' => 'test_adm_manager_conv_'.Str::random(8), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]);
        $manageOnly->capabilities()->sync(['admissions.manage']);
        app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $manageOnly->id,
        ]));

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'convert-manage-only-001')
            ->postJson("/api/v1/schools/{$school->id}/admission-applications/{$application->id}/convert", [
                'student_number' => 'S-3002', 'section_id' => $section->id,
                'roll_number' => '04', 'starts_on' => '2026-06-01',
            ]);

        $response->assertOk();
        $this->assertSame(1, $this->studentCount($school));
    }

    // ==================================================================
    // B. Guardian modes
    // ==================================================================

    #[Test]
    public function convert_with_create_guardian_mode_creates_canonical_guardian_contact_and_relationship(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        ['section' => $section, 'application' => $application] = $this->buildAcceptedContext($school);

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'convert-guardian-create-001')
            ->postJson("/api/v1/schools/{$school->id}/admission-applications/{$application->id}/convert", [
                'student_number' => 'S-4001', 'section_id' => $section->id,
                'roll_number' => '05', 'starts_on' => '2026-06-01',
                'guardian' => [
                    'mode' => 'create',
                    'relationship_type' => 'mother',
                    'is_legal_guardian' => true,
                    'first_name' => 'Priya',
                    'last_name' => 'Verma',
                    'contact_type' => 'email',
                    'contact_value' => 'priya.verma+api@example.com',
                ],
            ]);

        $response->assertOk();
        $this->assertNotNull($response->json('data.guardian.id'));
        $this->assertSame('Priya', $response->json('data.guardian.firstName'));
        $this->assertSame('mother', $response->json('data.relationship.relationshipType'));
        $this->assertTrue($response->json('data.relationship.isLegalGuardian'));

        // Admissions itself never persists the contact value, and the
        // response never echoes it back.
        $haystack = strtolower(json_encode($response->json('data')));
        $this->assertStringNotContainsString('priya.verma+api@example.com', $haystack);

        $guardianCount = app(TenantContext::class)->withSchool($school, fn () => Guardian::query()->count());
        $this->assertSame(1, $guardianCount);
    }

    #[Test]
    public function convert_with_link_existing_guardian_mode_creates_no_new_guardian(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        ['section' => $section, 'application' => $application] = $this->buildAcceptedContext($school);
        $existingGuardian = $this->createGuardian($school, ['first_name' => 'Priya', 'last_name' => 'Verma']);

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'convert-guardian-link-001')
            ->postJson("/api/v1/schools/{$school->id}/admission-applications/{$application->id}/convert", [
                'student_number' => 'S-4002', 'section_id' => $section->id,
                'roll_number' => '06', 'starts_on' => '2026-06-01',
                'guardian' => ['mode' => 'link_existing', 'relationship_type' => 'mother', 'guardian_id' => $existingGuardian->id],
            ]);

        $response->assertOk();
        $this->assertSame($existingGuardian->id, $response->json('data.guardian.id'));
        $guardianCount = app(TenantContext::class)->withSchool($school, fn () => Guardian::query()->count());
        $this->assertSame(1, $guardianCount, 'No new Guardian should have been created.');
    }

    #[Test]
    public function a_foreign_school_guardian_id_is_rejected_with_a_clean_validation_error(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        ['section' => $section, 'application' => $application] = $this->buildAcceptedContext($school);
        $otherSchool = $this->createSchool();
        $foreignGuardian = $this->createGuardian($otherSchool);

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'convert-guardian-foreign-001')
            ->postJson("/api/v1/schools/{$school->id}/admission-applications/{$application->id}/convert", [
                'student_number' => 'S-4003', 'section_id' => $section->id,
                'roll_number' => '07', 'starts_on' => '2026-06-01',
                'guardian' => ['mode' => 'link_existing', 'relationship_type' => 'mother', 'guardian_id' => $foreignGuardian->id],
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('guardian.guardian_id', $response->json('error.errors'));
        $this->assertSame(0, $this->studentCount($school), 'No Student may persist after a rejected conversion attempt.');
    }

    #[Test]
    public function a_guardian_contact_candidate_conflict_returns_a_stable_selection_required_response_with_no_leakage(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        ['section' => $section, 'application' => $application] = $this->buildAcceptedContext($school);
        $existingGuardian = $this->createGuardian($school, ['first_name' => 'Priya']);
        $this->createGuardianContact($existingGuardian, ContactType::Email, 'shared.contact@example.com');

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'convert-guardian-conflict-001')
            ->postJson("/api/v1/schools/{$school->id}/admission-applications/{$application->id}/convert", [
                'student_number' => 'S-4004', 'section_id' => $section->id,
                'roll_number' => '08', 'starts_on' => '2026-06-01',
                'guardian' => [
                    'mode' => 'create', 'relationship_type' => 'mother',
                    'first_name' => 'Priya', 'last_name' => 'Verma',
                    'contact_type' => 'email', 'contact_value' => 'shared.contact@example.com',
                ],
            ]);

        $response->assertStatus(422);
        $this->assertSame('ADMISSION_GUARDIAN_SELECTION_REQUIRED', $response->json('error.code'));

        $haystack = strtolower(json_encode($response->json()));
        $this->assertStringNotContainsString(strtolower($existingGuardian->id), $haystack);
        $this->assertStringNotContainsString('shared.contact@example.com', $haystack);
        $this->assertStringNotContainsString('priya', $haystack);

        $guardianCount = app(TenantContext::class)->withSchool($school, fn () => Guardian::query()->count());
        $this->assertSame(1, $guardianCount, 'Only the pre-existing candidate Guardian may remain.');
        $this->assertSame(0, $this->studentCount($school));
    }

    // ==================================================================
    // C. Idempotency / already converted
    // ==================================================================

    #[Test]
    public function converting_an_already_converted_application_returns_a_stable_error_with_no_duplicate_records(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        ['section' => $section, 'application' => $application] = $this->buildAcceptedContext($school);

        $this->asUser($user)->withHeader('Idempotency-Key', 'convert-already-001')
            ->postJson("/api/v1/schools/{$school->id}/admission-applications/{$application->id}/convert", [
                'student_number' => 'S-5001', 'section_id' => $section->id,
                'roll_number' => '09', 'starts_on' => '2026-06-01',
            ])->assertOk();

        $this->assertSame(1, $this->studentCount($school));

        $second = $this->asUser($user)->withHeader('Idempotency-Key', 'convert-already-002')
            ->postJson("/api/v1/schools/{$school->id}/admission-applications/{$application->id}/convert", [
                'student_number' => 'S-5002', 'section_id' => $section->id,
                'roll_number' => '10', 'starts_on' => '2026-06-01',
            ]);

        $second->assertStatus(422);
        $this->assertSame('ADMISSION_APPLICATION_ALREADY_CONVERTED', $second->json('error.code'));
        $this->assertSame(1, $this->studentCount($school));
        $this->assertSame(1, $this->enrollmentCount($school));
    }

    // ==================================================================
    // D. Section compatibility
    // ==================================================================

    #[Test]
    public function a_section_from_the_wrong_academic_year_is_rejected_with_no_student_persisted(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        ['campus' => $campus, 'gradeLevel' => $gradeLevel, 'application' => $application] = $this->buildAcceptedContext($school);
        $wrongYear = $this->createAcademicYear($school, ['code' => 'AY-'.Str::random(12)]);
        $wrongSection = $this->createSection($wrongYear, $campus, $gradeLevel);

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'convert-wrong-year-001')
            ->postJson("/api/v1/schools/{$school->id}/admission-applications/{$application->id}/convert", [
                'student_number' => 'S-6001', 'section_id' => $wrongSection->id,
                'roll_number' => '11', 'starts_on' => '2026-06-01',
            ]);

        $response->assertStatus(422);
        $this->assertSame('INCOMPATIBLE_CONVERSION_SECTION', $response->json('error.code'));
        $this->assertSame(0, $this->studentCount($school));
    }

    #[Test]
    public function a_foreign_school_section_id_is_rejected_with_a_clean_validation_error(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        ['application' => $application] = $this->buildAcceptedContext($school);
        $otherSchool = $this->createSchool();
        $foreignYear = $this->createAcademicYear($otherSchool);
        $foreignCampus = $this->createCampus($otherSchool);
        $foreignGrade = $this->createGradeLevel($otherSchool);
        $foreignSection = $this->createSection($foreignYear, $foreignCampus, $foreignGrade);

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'convert-foreign-section-001')
            ->postJson("/api/v1/schools/{$school->id}/admission-applications/{$application->id}/convert", [
                'student_number' => 'S-6002', 'section_id' => $foreignSection->id,
                'roll_number' => '12', 'starts_on' => '2026-06-01',
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('section_id', $response->json('error.errors'));
        $this->assertSame(0, $this->studentCount($school));
    }

    // ==================================================================
    // E. Student Number / roll number collisions
    // ==================================================================

    #[Test]
    public function a_colliding_student_number_is_rejected_cleanly_without_sqlstate_exposure(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        ['section' => $section, 'application' => $application] = $this->buildAcceptedContext($school);
        $this->createStudent($school, ['student_number' => 'S-DUP']);

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'convert-dup-number-001')
            ->postJson("/api/v1/schools/{$school->id}/admission-applications/{$application->id}/convert", [
                'student_number' => 'S-DUP', 'section_id' => $section->id,
                'roll_number' => '13', 'starts_on' => '2026-06-01',
            ]);

        $response->assertStatus(422);
        $this->assertSame('DUPLICATE_STUDENT_NUMBER', $response->json('error.code'));
        $this->assertStringNotContainsString('SQLSTATE', $response->json('error.message'));

        $fresh = app(TenantContext::class)->withSchool($school, fn () => AdmissionApplication::query()->findOrFail($application->id));
        $this->assertSame('accepted', $fresh->status);
        $this->assertSame(1, $this->studentCount($school), 'Only the pre-existing colliding Student may remain.');
        $this->assertSame(0, $this->enrollmentCount($school));
    }

    // ==================================================================
    // F. Cross-School application
    // ==================================================================

    #[Test]
    public function converting_a_foreign_schools_application_is_not_found(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        ['section' => $sectionB, 'application' => $applicationB] = $this->buildAcceptedContext($schoolB);

        $this->asUser($user)
            ->withHeader('Idempotency-Key', 'convert-foreign-app-001')
            ->postJson("/api/v1/schools/{$schoolA->id}/admission-applications/{$applicationB->id}/convert", [
                'student_number' => 'S-7001', 'section_id' => $sectionB->id,
                'roll_number' => '14', 'starts_on' => '2026-06-01',
            ])->assertNotFound();
    }
}
