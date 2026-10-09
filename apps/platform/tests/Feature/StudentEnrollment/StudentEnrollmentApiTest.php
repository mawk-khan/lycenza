<?php

namespace Tests\Feature\StudentEnrollment;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1B.5: the administrative StudentEnrollment HTTP surface
 * (App\Domain\Students\Http\Controllers\StudentEnrollmentController).
 * Mirrors StudentApiTest.php's exact conventions. This checkpoint does
 * NOT redesign the Enrollment domain (Phase 1B.1-1B.4A) -- every test
 * here exercises the HTTP boundary around it: authentication, School
 * membership, `enrollments.view`/`enrollments.manage` capability
 * independence, tenant-safe id resolution, and safe translation of the
 * existing domain exceptions.
 */
class StudentEnrollmentApiTest extends TestCase
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

    private function grantViewOnly(User $user, School $school): void
    {
        $membership = $this->createMembership($user, $school);
        $role = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => 'test_enrollment_viewer_http_'.Str::random(8), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $role->capabilities()->sync(['enrollments.view']));
        app(TenantContext::class)->withSchool($school, fn () => LocalCatalogueFixtures::asOwner(fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $role->id,
        ])));
    }

    private function grantManageOnly(User $user, School $school): void
    {
        $membership = $this->createMembership($user, $school);
        $role = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => 'test_enrollment_manager_http_'.Str::random(8), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $role->capabilities()->sync(['enrollments.manage']));
        app(TenantContext::class)->withSchool($school, fn () => LocalCatalogueFixtures::asOwner(fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $role->id,
        ])));
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

    private function enrollmentIdFor(School $school, Student $student): string
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => StudentEnrollment::query()->where('student_id', $student->id)->firstOrFail()->id,
        );
    }

    // ==================================================================
    // Authentication (section 37)
    // ==================================================================

    #[Test]
    public function a_guest_is_denied_on_the_directory(): void
    {
        $school = $this->createSchool();

        $this->getJson("/api/v1/schools/{$school->id}/enrollments")->assertUnauthorized();
    }

    #[Test]
    public function a_guest_is_denied_on_create(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $student] = $this->buildPlacementContext();

        $this->postJson("/api/v1/schools/{$school->id}/students/{$student->id}/enrollments", [
            'section_id' => $section->id, 'roll_number' => '01', 'starts_on' => '2026-06-01',
        ])->assertUnauthorized();
    }

    // ==================================================================
    // School membership (section 38)
    // ==================================================================

    #[Test]
    public function a_central_user_without_membership_is_not_found_not_forbidden(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();

        $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/enrollments")->assertNotFound();
    }

    // ==================================================================
    // View capability independence (section 39)
    // ==================================================================

    #[Test]
    public function enrollments_view_allows_reading_the_directory(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/enrollments")->assertOk();
    }

    #[Test]
    public function no_enrollments_view_denies_the_directory(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);

        $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/enrollments")->assertForbidden();
    }

    #[Test]
    public function enrollments_manage_only_is_still_denied_read_access(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->grantManageOnly($user, $school);

        $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/enrollments")->assertForbidden();
    }

    // ==================================================================
    // Manage capability independence (section 40)
    // ==================================================================

    #[Test]
    public function enrollments_manage_allows_create(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $student] = $this->buildPlacementContext();
        $user = $this->createUser();
        $this->grantManageOnly($user, $school);

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'enroll-001')
            ->postJson("/api/v1/schools/{$school->id}/students/{$student->id}/enrollments", [
                'section_id' => $section->id, 'roll_number' => '01', 'starts_on' => '2026-06-01',
            ]);

        $response->assertCreated();
    }

    #[Test]
    public function enrollments_view_only_is_denied_create(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $student] = $this->buildPlacementContext();
        $user = $this->createUser();
        $this->grantViewOnly($user, $school);

        $this->asUser($user)
            ->withHeader('Idempotency-Key', 'enroll-002')
            ->postJson("/api/v1/schools/{$school->id}/students/{$student->id}/enrollments", [
                'section_id' => $section->id, 'roll_number' => '01', 'starts_on' => '2026-06-01',
            ])->assertForbidden();
    }

    #[Test]
    public function neither_capability_is_denied_create(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $student] = $this->buildPlacementContext();
        $user = $this->createUser();
        $this->createMembership($user, $school);

        $this->asUser($user)
            ->withHeader('Idempotency-Key', 'enroll-003')
            ->postJson("/api/v1/schools/{$school->id}/students/{$student->id}/enrollments", [
                'section_id' => $section->id, 'roll_number' => '01', 'starts_on' => '2026-06-01',
            ])->assertForbidden();
    }

    // ==================================================================
    // Cross-School capability (section 41)
    // ==================================================================

    #[Test]
    public function enrollments_manage_in_school_a_does_not_permit_mutation_in_school_b(): void
    {
        ['school' => $schoolA, 'section' => $sectionA, 'student' => $studentA] = $this->buildPlacementContext();
        ['school' => $schoolB, 'section' => $sectionB, 'student' => $studentB] = $this->buildPlacementContext();
        $user = $this->createUser();
        $this->grantManageOnly($user, $schoolA);

        $this->asUser($user)
            ->withHeader('Idempotency-Key', 'enroll-004')
            ->postJson("/api/v1/schools/{$schoolB->id}/students/{$studentB->id}/enrollments", [
                'section_id' => $sectionB->id, 'roll_number' => '01', 'starts_on' => '2026-06-01',
            ])->assertNotFound(); // no membership in School B at all
    }

    // ==================================================================
    // Directory / privacy (sections 11, 12, 42, 54)
    // ==================================================================

    #[Test]
    public function directory_returns_only_current_school_enrollments_paginated(): void
    {
        ['school' => $schoolA, 'section' => $sectionA, 'student' => $studentA] = $this->buildPlacementContext();
        ['section' => $sectionB, 'student' => $studentB] = $this->buildPlacementContext();
        $this->service()->enroll($studentA, $sectionA, '01', '2026-06-01');
        $this->service()->enroll($studentB, $sectionB, '01', '2026-06-01');

        [$user] = [$this->createUser()];
        $membership = $this->createMembership($user, $schoolA);
        $this->assignSchoolRole($membership, 'school_admin');

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$schoolA->id}/enrollments");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame(1, $response->json('meta.page'));
        $this->assertSame(25, $response->json('meta.perPage'));
        $this->assertSame(1, $response->json('meta.total'));
    }

    #[Test]
    public function directory_rows_never_expose_sensitive_fields(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $student] = $this->buildPlacementContext();
        $this->service()->enroll($student, $section, '01', '2026-06-01');
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/enrollments");

        $response->assertOk();
        $row = $response->json('data.0');
        $this->assertArrayNotHasKey('dateOfBirth', $row['student']);
        $this->assertArrayNotHasKey('date_of_birth', $row);
        $this->assertArrayNotHasKey('guardian', $row);
        $this->assertArrayNotHasKey('guardians', $row);
        $this->assertArrayNotHasKey('encrypted_value', $row);
        $this->assertArrayNotHasKey('lookup_hash', $row);
        $this->assertArrayNotHasKey('lookup_key_version', $row);
        $this->assertArrayNotHasKey('school_id', $row);
        $this->assertSame($section->id, $row['section']['id']);
        $this->assertSame('01', $row['rollNumber']);
        $this->assertSame('active', $row['status']);
    }

    #[Test]
    public function directory_filters_combine_by_status_and_grade(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $gradeFive, 'section' => $sectionFive, 'student' => $studentA] = $this->buildPlacementContext();
        $gradeSix = $this->createGradeLevel($school);
        $sectionSix = $this->createSection($year, $campus, $gradeSix);
        $studentB = $this->createStudent($school, ['student_number' => 'S-1002']);

        $this->service()->enroll($studentA, $sectionFive, '01', '2026-06-01');
        $enrollmentB = $this->service()->enroll($studentB, $sectionSix, '02', '2026-06-01');
        $this->service()->complete($enrollmentB, '2026-12-01');

        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');

        $byGrade = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/enrollments?grade_level_id={$gradeFive->id}");
        $byGrade->assertOk();
        $this->assertCount(1, $byGrade->json('data'));
        $this->assertSame('S-1001', $byGrade->json('data.0.student.studentNumber'));

        $byStatus = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/enrollments?status=completed");
        $byStatus->assertOk();
        $this->assertCount(1, $byStatus->json('data'));
        $this->assertSame('S-1002', $byStatus->json('data.0.student.studentNumber'));

        $byRoll = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/enrollments?roll_number=01");
        $byRoll->assertOk();
        $this->assertCount(1, $byRoll->json('data'));
    }

    // ==================================================================
    // Detail (section 13, 34, 57)
    // ==================================================================

    #[Test]
    public function show_returns_enrollment_detail(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $student] = $this->buildPlacementContext();
        $enrollment = $this->service()->enroll($student, $section, '01', '2026-06-01');
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/enrollments/{$enrollment->id}");

        $response->assertOk();
        $this->assertSame($enrollment->id, $response->json('data.id'));
        $this->assertSame($student->id, $response->json('data.student.id'));
        $this->assertNotNull($response->json('data.createdAt'));
        $this->assertArrayNotHasKey('dateOfBirth', $response->json('data.student'));
    }

    #[Test]
    public function show_a_foreign_school_enrollment_and_a_random_uuid_are_equivalently_not_found(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        ['section' => $sectionB, 'student' => $studentB] = $this->buildPlacementContext();
        $enrollmentB = $this->service()->enroll($studentB, $sectionB, '01', '2026-06-01');

        $foreign = $this->asUser($user)->getJson("/api/v1/schools/{$schoolA->id}/enrollments/{$enrollmentB->id}");
        $random = $this->asUser($user)->getJson('/api/v1/schools/'.$schoolA->id.'/enrollments/'.Str::uuid());

        $foreign->assertNotFound();
        $random->assertNotFound();
        $this->assertSame($foreign->status(), $random->status());
    }

    // ==================================================================
    // Student history / current (sections 14, 15, 35, 52, 57)
    // ==================================================================

    #[Test]
    public function history_returns_completed_transferred_and_active_enrollments(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'section' => $sectionOne, 'student' => $student] = $this->buildPlacementContext();
        $sectionTwo = $this->createSection($year, $campus, $grade, ['name' => 'B', 'code' => 'B']);
        $sectionThree = $this->createSection($year, $campus, $grade, ['name' => 'C', 'code' => 'C']);

        $completed = $this->service()->enroll($student, $sectionOne, '01', '2026-01-01');
        $this->service()->complete($completed, '2026-03-01');

        $reEnrolled = $this->service()->enroll($student, $sectionTwo, '02', '2026-03-02');
        $this->service()->transferPlacement($reEnrolled, $sectionThree, '03', '2026-04-01');

        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/students/{$student->id}/enrollments");

        $response->assertOk();
        $statuses = collect($response->json('data'))->pluck('status')->all();
        $this->assertCount(3, $statuses);
        $this->assertContains('completed', $statuses);
        $this->assertContains('transferred', $statuses);
        $this->assertContains('active', $statuses);
    }

    #[Test]
    public function current_returns_only_the_active_enrollment_for_the_resolved_year(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'section' => $sectionOne, 'student' => $student] = $this->buildPlacementContext();
        $sectionTwo = $this->createSection($year, $campus, $grade, ['name' => 'B', 'code' => 'B']);
        $completed = $this->service()->enroll($student, $sectionOne, '01', '2026-01-01');
        $this->service()->complete($completed, '2026-03-01');
        $this->service()->enroll($student, $sectionTwo, '02', '2026-03-02');
        app(TenantContext::class)->withSchool($school, fn () => $year->update(['status' => 'active']));

        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/students/{$student->id}/enrollments/current");

        $response->assertOk();
        $this->assertSame('active', $response->json('data.status'));
        $this->assertSame($sectionTwo->id, $response->json('data.section.id'));
    }

    #[Test]
    public function current_returns_null_data_when_no_active_enrollment_exists(): void
    {
        ['school' => $school, 'student' => $student] = $this->buildPlacementContext();
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/students/{$student->id}/enrollments/current");

        $response->assertOk();
        $this->assertNull($response->json('data'));
    }

    #[Test]
    public function current_with_a_foreign_school_academic_year_id_and_a_random_uuid_are_equivalently_not_found(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $studentA = $this->createStudent($schoolA, ['student_number' => 'A-1']);
        ['year' => $yearB] = $this->buildPlacementContext();

        $foreign = $this->asUser($user)->getJson("/api/v1/schools/{$schoolA->id}/students/{$studentA->id}/enrollments/current?academic_year_id={$yearB->id}");
        $random = $this->asUser($user)->getJson("/api/v1/schools/{$schoolA->id}/students/{$studentA->id}/enrollments/current?academic_year_id=".Str::uuid());

        $foreign->assertNotFound();
        $random->assertNotFound();
        $this->assertSame($foreign->status(), $random->status());
    }

    #[Test]
    public function student_foreign_vs_random_uuid_are_equivalently_not_found_for_history(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        ['student' => $studentB] = $this->buildPlacementContext();

        $foreign = $this->asUser($user)->getJson("/api/v1/schools/{$schoolA->id}/students/{$studentB->id}/enrollments");
        $random = $this->asUser($user)->getJson('/api/v1/schools/'.$schoolA->id.'/students/'.Str::uuid().'/enrollments');

        $foreign->assertNotFound();
        $random->assertNotFound();
        $this->assertSame($foreign->status(), $random->status());
    }

    // ==================================================================
    // Create: input contract / placement derivation (sections 16, 55, 56)
    // ==================================================================

    #[Test]
    public function create_derives_placement_entirely_from_the_given_section(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $student] = $this->buildPlacementContext();
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'enroll-derive-001')
            ->postJson("/api/v1/schools/{$school->id}/students/{$student->id}/enrollments", [
                'section_id' => $section->id, 'roll_number' => '007', 'starts_on' => '2026-06-01',
            ]);

        $response->assertCreated();
        $this->assertSame('007', $response->json('data.rollNumber'));
        $this->assertIsString($response->json('data.rollNumber'));
        $this->assertSame($section->id, $response->json('data.section.id'));
        $this->assertSame($section->academic_year_id, $response->json('data.academicYear.id'));
        $this->assertSame($section->campus_id, $response->json('data.campus.id'));
        $this->assertSame($section->grade_level_id, $response->json('data.gradeLevel.id'));

        $stored = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->findOrFail($response->json('data.id')));
        $this->assertSame($section->academic_year_id, $stored->academic_year_id);
        $this->assertSame($section->campus_id, $stored->campus_id);
        $this->assertSame($section->grade_level_id, $stored->grade_level_id);
        $this->assertSame('007', $stored->roll_number);
    }

    #[Test]
    public function malicious_redundant_placement_fields_never_override_the_derived_placement(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $student] = $this->buildPlacementContext();
        ['school' => $otherSchool, 'campus' => $otherCampus, 'year' => $otherYear, 'grade' => $otherGrade] = $this->buildPlacementContext();
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'enroll-malicious-001')
            ->postJson("/api/v1/schools/{$school->id}/students/{$student->id}/enrollments", [
                'section_id' => $section->id, 'roll_number' => '01', 'starts_on' => '2026-06-01',
                'school_id' => $otherSchool->id,
                'academic_year_id' => $otherYear->id,
                'campus_id' => $otherCampus->id,
                'grade_level_id' => $otherGrade->id,
                'student_id' => (string) Str::uuid(),
            ]);

        $response->assertCreated();
        $stored = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->findOrFail($response->json('data.id')));
        $this->assertSame($school->id, $stored->school_id);
        $this->assertSame($student->id, $stored->student_id);
        $this->assertSame($section->academic_year_id, $stored->academic_year_id);
        $this->assertSame($section->campus_id, $stored->campus_id);
        $this->assertSame($section->grade_level_id, $stored->grade_level_id);
    }

    #[Test]
    public function a_foreign_school_section_id_and_a_random_uuid_are_equivalently_rejected(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $studentA = $this->createStudent($schoolA, ['student_number' => 'A-1']);
        ['section' => $sectionB] = $this->buildPlacementContext();

        $foreign = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'enroll-foreign-section')
            ->postJson("/api/v1/schools/{$schoolA->id}/students/{$studentA->id}/enrollments", [
                'section_id' => $sectionB->id, 'roll_number' => '01', 'starts_on' => '2026-06-01',
            ]);
        $random = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'enroll-random-section')
            ->postJson("/api/v1/schools/{$schoolA->id}/students/{$studentA->id}/enrollments", [
                'section_id' => (string) Str::uuid(), 'roll_number' => '01', 'starts_on' => '2026-06-01',
            ]);

        $foreign->assertStatus(422);
        $random->assertStatus(422);
        $this->assertSame($foreign->status(), $random->status());
        $this->assertArrayHasKey('section_id', $foreign->json('error.errors'));
        $this->assertArrayHasKey('section_id', $random->json('error.errors'));
    }

    #[Test]
    public function student_id_in_route_is_authoritative_and_foreign_student_id_is_not_found(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        ['section' => $sectionB, 'student' => $studentB] = $this->buildPlacementContext();

        $this->asUser($user)
            ->withHeader('Idempotency-Key', 'enroll-foreign-student')
            ->postJson("/api/v1/schools/{$schoolA->id}/students/{$studentB->id}/enrollments", [
                'section_id' => $sectionB->id, 'roll_number' => '01', 'starts_on' => '2026-06-01',
            ])->assertNotFound();
    }

    // ==================================================================
    // Create: conflicts and validation (sections 21, 22, 23)
    // ==================================================================

    #[Test]
    public function a_second_active_enrollment_in_the_same_year_is_a_clean_conflict(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'section' => $sectionOne, 'student' => $student] = $this->buildPlacementContext();
        $sectionTwo = $this->createSection($year, $campus, $grade, ['name' => 'B', 'code' => 'B']);
        $this->service()->enroll($student, $sectionOne, '01', '2026-06-01');
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'enroll-conflict-001')
            ->postJson("/api/v1/schools/{$school->id}/students/{$student->id}/enrollments", [
                'section_id' => $sectionTwo->id, 'roll_number' => '02', 'starts_on' => '2026-06-02',
            ]);

        $response->assertStatus(422);
        $this->assertSame('ACTIVE_ENROLLMENT_CONFLICT', $response->json('error.code'));
        $this->assertStringNotContainsString('SQLSTATE', $response->json('error.message'));
        $this->assertStringNotContainsString('student_enrollments_one_active_per_student_year', $response->json('error.message'));
    }

    #[Test]
    public function a_duplicate_roll_number_in_the_same_section_and_year_is_a_clean_conflict_and_preserves_the_string(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $studentA] = $this->buildPlacementContext();
        $studentB = $this->createStudent($school, ['student_number' => 'S-1002']);
        $this->service()->enroll($studentA, $section, '007', '2026-06-01');
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'enroll-duplicate-roll-001')
            ->postJson("/api/v1/schools/{$school->id}/students/{$studentB->id}/enrollments", [
                'section_id' => $section->id, 'roll_number' => '007', 'starts_on' => '2026-06-01',
            ]);

        $response->assertStatus(422);
        $this->assertSame('DUPLICATE_ENROLLMENT_ROLL_NUMBER', $response->json('error.code'));
        $this->assertStringContainsString('007', $response->json('error.message'));
        $this->assertStringNotContainsString('SQLSTATE', $response->json('error.message'));
    }

    #[Test]
    public function a_blank_roll_number_is_a_validation_error_not_a_sql_error(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $student] = $this->buildPlacementContext();
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');

        $blank = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'enroll-blank-roll-001')
            ->postJson("/api/v1/schools/{$school->id}/students/{$student->id}/enrollments", [
                'section_id' => $section->id, 'roll_number' => '', 'starts_on' => '2026-06-01',
            ]);
        $blank->assertStatus(422);
        $this->assertArrayHasKey('roll_number', $blank->json('error.errors'));

        // Laravel's own `required` rule already treats a whitespace-only
        // string as empty (trims before checking) -- this never even
        // reaches StudentEnrollmentService::enroll()'s own
        // InvalidEnrollmentRollNumberException guard, but the caller
        // still gets a clean 422 validation error either way, never a
        // SQL error.
        $whitespace = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'enroll-blank-roll-002')
            ->postJson("/api/v1/schools/{$school->id}/students/{$student->id}/enrollments", [
                'section_id' => $section->id, 'roll_number' => '   ', 'starts_on' => '2026-06-01',
            ]);
        $whitespace->assertStatus(422);
        $this->assertArrayHasKey('roll_number', $whitespace->json('error.errors'));
    }

    // ==================================================================
    // Lifecycle: complete / withdraw / cancel (sections 24, 25, 26, 30)
    // ==================================================================

    #[Test]
    public function complete_transitions_the_enrollment_and_preserves_placement(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $student] = $this->buildPlacementContext();
        $enrollment = $this->service()->enroll($student, $section, '01', '2026-06-01');
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'complete-001')
            ->postJson("/api/v1/schools/{$school->id}/enrollments/{$enrollment->id}/complete", ['ends_on' => '2027-04-30']);

        $response->assertOk();
        $this->assertSame('completed', $response->json('data.status'));
        $this->assertSame('2027-04-30', $response->json('data.endsOn'));
        $this->assertSame($section->id, $response->json('data.section.id'));
    }

    #[Test]
    public function withdraw_transitions_the_enrollment(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $student] = $this->buildPlacementContext();
        $enrollment = $this->service()->enroll($student, $section, '01', '2026-06-01');
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'withdraw-001')
            ->postJson("/api/v1/schools/{$school->id}/enrollments/{$enrollment->id}/withdraw", ['ends_on' => '2026-09-01']);

        $response->assertOk();
        $this->assertSame('withdrawn', $response->json('data.status'));
    }

    #[Test]
    public function cancel_transitions_the_enrollment(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $student] = $this->buildPlacementContext();
        $enrollment = $this->service()->enroll($student, $section, '01', '2026-06-01');
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'cancel-001')
            ->postJson("/api/v1/schools/{$school->id}/enrollments/{$enrollment->id}/cancel", ['ends_on' => '2026-06-05']);

        $response->assertOk();
        $this->assertSame('cancelled', $response->json('data.status'));
    }

    #[Test]
    public function completed_to_withdraw_is_a_clean_transition_conflict(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $student] = $this->buildPlacementContext();
        $enrollment = $this->service()->enroll($student, $section, '01', '2026-06-01');
        $this->service()->complete($enrollment, '2027-04-30');
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'withdraw-conflict-001')
            ->postJson("/api/v1/schools/{$school->id}/enrollments/{$enrollment->id}/withdraw", ['ends_on' => '2027-05-01']);

        $response->assertStatus(422);
        $this->assertSame('INVALID_ENROLLMENT_TRANSITION', $response->json('error.code'));
        $this->assertStringNotContainsString('Exception', $response->json('error.message'));
    }

    #[Test]
    public function lifecycle_endpoints_require_enrollments_manage_not_view(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $student] = $this->buildPlacementContext();
        $enrollment = $this->service()->enroll($student, $section, '01', '2026-06-01');
        $user = $this->createUser();
        $this->grantViewOnly($user, $school);

        $this->asUser($user)
            ->withHeader('Idempotency-Key', 'complete-denied-001')
            ->postJson("/api/v1/schools/{$school->id}/enrollments/{$enrollment->id}/complete", ['ends_on' => '2027-04-30'])
            ->assertForbidden();
    }

    #[Test]
    public function enrollment_route_ownership_a_foreign_school_enrollment_for_lifecycle_is_not_found(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        ['section' => $sectionB, 'student' => $studentB] = $this->buildPlacementContext();
        $enrollmentB = $this->service()->enroll($studentB, $sectionB, '01', '2026-06-01');

        $this->asUser($user)
            ->withHeader('Idempotency-Key', 'complete-foreign-001')
            ->postJson("/api/v1/schools/{$schoolA->id}/enrollments/{$enrollmentB->id}/complete", ['ends_on' => '2027-04-30'])
            ->assertNotFound();
    }

    // ==================================================================
    // Transfer (sections 27, 28, 29, 31, 32, 33)
    // ==================================================================

    #[Test]
    public function transfer_moves_the_student_to_the_target_section(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'section' => $sourceSection, 'student' => $student] = $this->buildPlacementContext();
        $targetSection = $this->createSection($year, $campus, $grade, ['name' => 'B', 'code' => 'B']);
        $sourceEnrollment = $this->service()->enroll($student, $sourceSection, '01', '2026-06-01');
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'transfer-001')
            ->postJson("/api/v1/schools/{$school->id}/enrollments/{$sourceEnrollment->id}/transfer", [
                'target_section_id' => $targetSection->id, 'roll_number' => '09', 'effective_date' => '2026-07-01',
            ]);

        $response->assertCreated();
        $this->assertSame('active', $response->json('data.status'));
        $this->assertSame($targetSection->id, $response->json('data.section.id'));
        $this->assertSame('09', $response->json('data.rollNumber'));
        $this->assertNotSame($sourceEnrollment->id, $response->json('data.id'));

        $freshSource = app(TenantContext::class)->withSchool($school, fn () => $sourceEnrollment->fresh());
        $this->assertSame('transferred', $freshSource->status);
        $this->assertSame('2026-06-30', $freshSource->ends_on->toDateString());
    }

    #[Test]
    public function transfer_rejects_redundant_target_placement_fields_and_derives_from_target_section(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'section' => $sourceSection, 'student' => $student] = $this->buildPlacementContext();
        $targetSection = $this->createSection($year, $campus, $grade, ['name' => 'B', 'code' => 'B']);
        $sourceEnrollment = $this->service()->enroll($student, $sourceSection, '01', '2026-06-01');
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'transfer-002')
            ->postJson("/api/v1/schools/{$school->id}/enrollments/{$sourceEnrollment->id}/transfer", [
                'target_section_id' => $targetSection->id, 'roll_number' => '10', 'effective_date' => '2026-07-01',
                'target_academic_year_id' => (string) Str::uuid(),
                'target_campus_id' => (string) Str::uuid(),
                'target_grade_level_id' => (string) Str::uuid(),
            ]);

        $response->assertCreated();
        $this->assertSame($targetSection->academic_year_id, $response->json('data.academicYear.id'));
        $this->assertSame($targetSection->campus_id, $response->json('data.campus.id'));
        $this->assertSame($targetSection->grade_level_id, $response->json('data.gradeLevel.id'));
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

        $foreign = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'transfer-foreign-001')
            ->postJson("/api/v1/schools/{$schoolA->id}/enrollments/{$enrollmentA->id}/transfer", [
                'target_section_id' => $sectionB->id, 'roll_number' => '02', 'effective_date' => '2026-07-01',
            ]);
        $random = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'transfer-random-001')
            ->postJson("/api/v1/schools/{$schoolA->id}/enrollments/{$enrollmentA->id}/transfer", [
                'target_section_id' => (string) Str::uuid(), 'roll_number' => '02', 'effective_date' => '2026-07-01',
            ]);

        $foreign->assertStatus(422);
        $random->assertStatus(422);
        $this->assertSame($foreign->status(), $random->status());
        $this->assertArrayHasKey('target_section_id', $foreign->json('error.errors'));
        $this->assertArrayHasKey('target_section_id', $random->json('error.errors'));
    }

    #[Test]
    public function transfer_failure_is_fully_atomic_through_http(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'section' => $sourceSection, 'student' => $studentA] = $this->buildPlacementContext();
        $targetSection = $this->createSection($year, $campus, $grade, ['name' => 'B', 'code' => 'B']);
        $studentB = $this->createStudent($school, ['student_number' => 'S-1002']);
        $this->service()->enroll($studentB, $targetSection, '05', '2026-06-01'); // occupies roll "05" in target
        $sourceEnrollment = $this->service()->enroll($studentA, $sourceSection, '01', '2026-06-01');

        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'transfer-atomic-001')
            ->postJson("/api/v1/schools/{$school->id}/enrollments/{$sourceEnrollment->id}/transfer", [
                'target_section_id' => $targetSection->id, 'roll_number' => '05', 'effective_date' => '2026-07-01',
            ]);

        $response->assertStatus(422);
        $this->assertSame('DUPLICATE_ENROLLMENT_ROLL_NUMBER', $response->json('error.code'));

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
    public function transfer_to_a_different_academic_year_is_rejected(): void
    {
        ['school' => $school, 'campus' => $campus, 'grade' => $grade, 'section' => $sourceSection, 'student' => $student] = $this->buildPlacementContext();
        $otherYear = $this->createAcademicYear($school, ['code' => 'TGT']);
        $targetSection = $this->createSection($otherYear, $campus, $grade);
        $sourceEnrollment = $this->service()->enroll($student, $sourceSection, '01', '2026-06-01');
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'transfer-cross-year-001')
            ->postJson("/api/v1/schools/{$school->id}/enrollments/{$sourceEnrollment->id}/transfer", [
                'target_section_id' => $targetSection->id, 'roll_number' => '02', 'effective_date' => '2026-07-01',
            ]);

        $response->assertStatus(422);
        $this->assertSame('CROSS_ACADEMIC_YEAR_TRANSFER', $response->json('error.code'));
    }

    #[Test]
    public function transfer_to_a_different_grade_level_is_rejected(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'section' => $sourceSection, 'student' => $student] = $this->buildPlacementContext();
        $otherGrade = $this->createGradeLevel($school);
        $targetSection = $this->createSection($year, $campus, $otherGrade);
        $sourceEnrollment = $this->service()->enroll($student, $sourceSection, '01', '2026-06-01');
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'transfer-cross-grade-001')
            ->postJson("/api/v1/schools/{$school->id}/enrollments/{$sourceEnrollment->id}/transfer", [
                'target_section_id' => $targetSection->id, 'roll_number' => '02', 'effective_date' => '2026-07-01',
            ]);

        $response->assertStatus(422);
        $this->assertSame('INTRA_YEAR_GRADE_CHANGE', $response->json('error.code'));
    }

    #[Test]
    public function transfer_to_a_different_campus_same_year_and_grade_succeeds(): void
    {
        ['school' => $school, 'year' => $year, 'grade' => $grade, 'section' => $sourceSection, 'student' => $student] = $this->buildPlacementContext();
        $otherCampus = $this->createCampus($school);
        $targetSection = $this->createSection($year, $otherCampus, $grade);
        $sourceEnrollment = $this->service()->enroll($student, $sourceSection, '01', '2026-06-01');
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'transfer-cross-campus-001')
            ->postJson("/api/v1/schools/{$school->id}/enrollments/{$sourceEnrollment->id}/transfer", [
                'target_section_id' => $targetSection->id, 'roll_number' => '02', 'effective_date' => '2026-07-01',
            ]);

        $response->assertCreated();
        $this->assertSame($otherCampus->id, $response->json('data.campus.id'));
    }

    // ==================================================================
    // TenantContext hardening regression through the HTTP layer (section 58)
    // ==================================================================

    #[Test]
    public function a_duplicate_roll_number_conflict_then_a_valid_create_both_succeed_through_the_same_connection(): void
    {
        ['school' => $school, 'section' => $section, 'student' => $studentA] = $this->buildPlacementContext();
        $studentB = $this->createStudent($school, ['student_number' => 'S-1002']);
        $studentC = $this->createStudent($school, ['student_number' => 'S-1003']);
        $this->service()->enroll($studentA, $section, '007', '2026-06-01');
        [$user] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');

        $conflict = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'tenant-hardening-001')
            ->postJson("/api/v1/schools/{$school->id}/students/{$studentB->id}/enrollments", [
                'section_id' => $section->id, 'roll_number' => '007', 'starts_on' => '2026-06-01',
            ]);
        $conflict->assertStatus(422);

        $valid = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'tenant-hardening-002')
            ->postJson("/api/v1/schools/{$school->id}/students/{$studentC->id}/enrollments", [
                'section_id' => $section->id, 'roll_number' => '008', 'starts_on' => '2026-06-01',
            ]);
        $valid->assertCreated();
        $this->assertStringNotContainsString('25P02', json_encode($valid->json()));
    }

    // ==================================================================
    // Capability isolation across Schools for the same central user (section 41)
    // ==================================================================

    #[Test]
    public function the_same_central_user_has_independent_enrollment_capabilities_per_school(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $this->createMembership($user, $schoolB);
        Auth::forgetGuards();

        $this->asUser($user)->getJson("/api/v1/schools/{$schoolA->id}/enrollments")->assertOk();
        Auth::forgetGuards();
        $this->asUser($user)->getJson("/api/v1/schools/{$schoolB->id}/enrollments")->assertForbidden();
    }
}
