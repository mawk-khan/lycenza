<?php

namespace Tests\Feature\StudentSubjectEnrollment;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Infrastructure\Student;
use App\Models\Campus;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1C.1: the administrative StudentSubjectEnrollment HTTP surface
 * (App\Domain\Students\Http\Controllers\StudentSubjectEnrollmentController).
 * Mirrors StudentEnrollmentApiTest.php's conventions -- authentication,
 * School membership, academics.subjects.view/.manage capability
 * independence (reused rather than new capabilities -- see the
 * documentation file's "Authorization" section), tenant-safe id
 * resolution, cross-School forgery denial.
 */
class StudentSubjectEnrollmentApiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    private function asUser($user)
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token($user));
    }

    private function grantViewOnly($user, School $school): void
    {
        $membership = $this->createMembership($user, $school);
        $role = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => 'test_subject_enrollment_viewer_'.Str::random(8), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $role->capabilities()->sync(['academics.subjects.view']));
        app(TenantContext::class)->withSchool($school, fn () => LocalCatalogueFixtures::asOwner(fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $role->id,
        ])));
    }

    private function grantManageAndView($user, School $school): void
    {
        $membership = $this->createMembership($user, $school);
        $role = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => 'test_subject_enrollment_manager_'.Str::random(8), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $role->capabilities()->sync(['academics.subjects.view', 'academics.subjects.manage']));
        app(TenantContext::class)->withSchool($school, fn () => LocalCatalogueFixtures::asOwner(fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $role->id,
        ])));
    }

    /**
     * @return array{school: School, campus: Campus, year: AcademicYear, grade: GradeLevel, offering: SubjectOffering, student: Student}
     */
    private function buildCompatibleContext(): array
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school), ['is_required' => false]);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->createStudentEnrollment($student, $section);

        return compact('school', 'campus', 'year', 'grade', 'offering', 'student');
    }

    #[Test]
    public function a_guest_is_denied_on_the_roster(): void
    {
        ['school' => $school, 'offering' => $offering] = $this->buildCompatibleContext();

        $this->getJson("/api/v1/schools/{$school->id}/subject-offerings/{$offering->id}/roster")->assertUnauthorized();
    }

    #[Test]
    public function academics_subjects_view_allows_reading_the_roster(): void
    {
        ['school' => $school, 'offering' => $offering] = $this->buildCompatibleContext();
        $user = $this->createUser();
        $this->grantManageAndView($user, $school);

        $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/subject-offerings/{$offering->id}/roster")->assertOk();
    }

    #[Test]
    public function academics_subjects_manage_allows_enrolling_a_student(): void
    {
        ['school' => $school, 'offering' => $offering, 'student' => $student] = $this->buildCompatibleContext();
        $user = $this->createUser();
        $this->grantManageAndView($user, $school);

        $this->asUser($user)
            ->withHeader('Idempotency-Key', 'sub-enroll-001')
            ->postJson("/api/v1/schools/{$school->id}/students/{$student->id}/subject-enrollments", [
                'subject_offering_id' => $offering->id, 'starts_on' => '2026-06-01',
            ])->assertCreated();
    }

    #[Test]
    public function academics_subjects_view_only_is_denied_enroll(): void
    {
        ['school' => $school, 'offering' => $offering, 'student' => $student] = $this->buildCompatibleContext();
        $user = $this->createUser();
        $this->grantViewOnly($user, $school);

        $this->asUser($user)
            ->withHeader('Idempotency-Key', 'sub-enroll-002')
            ->postJson("/api/v1/schools/{$school->id}/students/{$student->id}/subject-enrollments", [
                'subject_offering_id' => $offering->id, 'starts_on' => '2026-06-01',
            ])->assertForbidden();
    }

    #[Test]
    public function no_membership_is_not_found_not_forbidden(): void
    {
        $user = $this->createUser();
        ['school' => $school, 'offering' => $offering] = $this->buildCompatibleContext();

        $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/subject-offerings/{$offering->id}/roster")->assertNotFound();
    }

    #[Test]
    public function forging_another_schools_subject_offering_id_on_enroll_is_rejected(): void
    {
        ['school' => $schoolA, 'student' => $studentA] = $this->buildCompatibleContext();
        ['offering' => $offeringB] = $this->buildCompatibleContext();
        $user = $this->createUser();
        $this->grantManageAndView($user, $schoolA);

        $this->asUser($user)
            ->withHeader('Idempotency-Key', 'sub-enroll-003')
            ->postJson("/api/v1/schools/{$schoolA->id}/students/{$studentA->id}/subject-enrollments", [
                'subject_offering_id' => $offeringB->id, 'starts_on' => '2026-06-01',
            ])->assertUnprocessable(); // Rule::exists(...)->where('school_id', ...) rejects the foreign id
    }

    #[Test]
    public function requesting_a_foreign_schools_offering_roster_is_not_found(): void
    {
        ['school' => $schoolA] = $this->buildCompatibleContext();
        ['offering' => $offeringB] = $this->buildCompatibleContext();
        $user = $this->createUser();
        $this->grantManageAndView($user, $schoolA);

        $this->asUser($user)->getJson("/api/v1/schools/{$schoolA->id}/subject-offerings/{$offeringB->id}/roster")->assertNotFound();
    }

    #[Test]
    public function the_roster_response_never_exposes_grades_attendance_or_fee_fields(): void
    {
        ['school' => $school, 'offering' => $offering] = $this->buildCompatibleContext();
        $user = $this->createUser();
        $this->grantManageAndView($user, $school);

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/subject-offerings/{$offering->id}/roster");

        $response->assertOk();
        $json = $response->json();
        $this->assertArrayNotHasKey('grade', $json);
        $this->assertArrayNotHasKey('attendance', $json);
        $this->assertArrayNotHasKey('feeStatus', $json);
        $this->assertArrayNotHasKey('medical', $json);
    }

    // --- Inactive SubjectOffering eligibility (Phase 1C.1A) ----------------

    #[Test]
    public function enrolling_against_an_inactive_offering_is_rejected(): void
    {
        ['school' => $school, 'offering' => $offering, 'student' => $student] = $this->buildCompatibleContext();
        app(TenantContext::class)->withSchool($school, fn () => $offering->update(['status' => 'inactive']));
        $user = $this->createUser();
        $this->grantManageAndView($user, $school);

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'sub-enroll-inactive-001')
            ->postJson("/api/v1/schools/{$school->id}/students/{$student->id}/subject-enrollments", [
                'subject_offering_id' => $offering->id, 'starts_on' => '2026-06-01',
            ]);

        $response->assertUnprocessable();
        $this->assertStringNotContainsString('SQLSTATE', json_encode($response->json()));
    }

    #[Test]
    public function transferring_to_an_inactive_target_offering_is_rejected(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'offering' => $french, 'student' => $student] = $this->buildCompatibleContext();
        $spanish = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school, ['code' => 'ES']), ['is_required' => false, 'status' => 'inactive']);
        $user = $this->createUser();
        $this->grantManageAndView($user, $school);
        $source = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'sub-transfer-inactive-source')
            ->postJson("/api/v1/schools/{$school->id}/students/{$student->id}/subject-enrollments", [
                'subject_offering_id' => $french->id, 'starts_on' => '2026-06-01',
            ])->json('data.id');

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'sub-transfer-inactive-001')
            ->postJson("/api/v1/schools/{$school->id}/subject-enrollments/{$source}/transfer", [
                'target_subject_offering_id' => $spanish->id, 'effective_date' => '2026-09-01',
            ]);

        $response->assertUnprocessable();
        $this->assertStringNotContainsString('SQLSTATE', json_encode($response->json()));
    }

    #[Test]
    public function the_roster_for_an_inactive_offering_returns_an_empty_authorized_response(): void
    {
        ['school' => $school, 'offering' => $offering] = $this->buildCompatibleContext();
        app(TenantContext::class)->withSchool($school, fn () => $offering->update(['status' => 'inactive']));
        $user = $this->createUser();
        $this->grantManageAndView($user, $school);

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/subject-offerings/{$offering->id}/roster");

        $response->assertOk();
        $response->assertJsonPath('data', []);
        $response->assertJsonPath('meta.count', 0);
    }
}
