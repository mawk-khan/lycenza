<?php

namespace Tests\Feature\Admissions;

use App\Domain\Admissions\Infrastructure\Applicant;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1D.5: the administrative Applicant HTTP surface
 * (App\Domain\Admissions\Http\Controllers\ApplicantController). Mirrors
 * StudentApiTest.php's exact pattern.
 */
class ApplicantApiTest extends TestCase
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

    // --- List / search --------------------------------------------------

    #[Test]
    public function a_guest_is_denied(): void
    {
        $school = $this->createSchool();

        $this->getJson("/api/v1/schools/{$school->id}/applicants")->assertUnauthorized();
    }

    #[Test]
    public function a_member_without_membership_gets_a_not_found_outcome(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();

        $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/applicants")->assertNotFound();
    }

    #[Test]
    public function a_member_with_admissions_view_can_list_applicants(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->createApplicant($school);

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/applicants");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    #[Test]
    public function a_member_without_admissions_view_is_denied(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);

        $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/applicants")->assertForbidden();
    }

    #[Test]
    public function list_only_returns_current_school_applicants(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $this->createApplicant($schoolA, ['first_name' => 'InSchoolA']);
        $this->createApplicant($schoolB, ['first_name' => 'InSchoolB']);

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$schoolA->id}/applicants");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('InSchoolA', $response->json('data.0.firstName'));
    }

    #[Test]
    public function list_supports_case_insensitive_name_search(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->createApplicant($school, ['first_name' => 'Asha', 'last_name' => 'Verma']);
        $this->createApplicant($school, ['first_name' => 'Rohit', 'last_name' => 'Sharma']);

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/applicants?name=asha");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Asha', $response->json('data.0.firstName'));
    }

    #[Test]
    public function list_is_paginated_with_meta(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        foreach (range(1, 3) as $i) {
            $this->createApplicant($school);
        }

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/applicants?per_page=2");

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertSame(1, $response->json('meta.page'));
        $this->assertSame(2, $response->json('meta.perPage'));
        $this->assertSame(3, $response->json('meta.total'));
    }

    #[Test]
    public function list_rows_never_include_date_of_birth(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->createApplicant($school);

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/applicants");

        $response->assertOk();
        $this->assertArrayNotHasKey('dateOfBirth', $response->json('data.0'));
    }

    // --- Show ---------------------------------------------------------

    #[Test]
    public function show_a_same_school_applicant_succeeds_and_includes_date_of_birth(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $applicant = $this->createApplicant($school, ['date_of_birth' => '2018-04-12']);

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/applicants/{$applicant->id}");

        $response->assertOk();
        $this->assertSame('2018-04-12', $response->json('data.dateOfBirth'));
    }

    #[Test]
    public function show_a_foreign_school_applicant_and_a_random_uuid_produce_the_same_not_found_outcome(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $applicantB = $this->createApplicant($schoolB);

        $foreign = $this->asUser($user)->getJson("/api/v1/schools/{$schoolA->id}/applicants/{$applicantB->id}");
        $random = $this->asUser($user)->getJson('/api/v1/schools/'.$schoolA->id.'/applicants/'.Str::uuid());

        $foreign->assertNotFound();
        $random->assertNotFound();
        $this->assertSame($foreign->status(), $random->status());
    }

    #[Test]
    public function show_is_denied_without_admissions_view(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        $applicant = $this->createApplicant($school);
        $user = $this->createUser();
        $this->createMembership($user, $school);

        $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/applicants/{$applicant->id}")->assertForbidden();
    }

    // --- Create -------------------------------------------------------

    #[Test]
    public function admissions_manage_can_create_an_applicant(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'create-applicant-001')
            ->postJson("/api/v1/schools/{$school->id}/applicants", [
                'first_name' => 'Asha', 'last_name' => 'Verma', 'date_of_birth' => '2018-04-12',
            ]);

        $response->assertCreated();
        $this->assertSame('Asha', $response->json('data.firstName'));
        $stored = app(TenantContext::class)->withSchool($school, fn () => Applicant::query()->findOrFail($response->json('data.id')));
        $this->assertSame($school->id, $stored->school_id);
    }

    #[Test]
    public function a_view_only_member_cannot_create_an_applicant(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $membership = $this->createMembership($user, $school);
        $viewOnly = Role::query()->create(['key' => 'test_admissions_viewer_http', 'name' => 'Test', 'scope' => 'school', 'is_system' => false]);
        $viewOnly->capabilities()->sync(['admissions.view']);
        app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $viewOnly->id,
        ]));

        $this->asUser($user)
            ->withHeader('Idempotency-Key', 'create-applicant-002')
            ->postJson("/api/v1/schools/{$school->id}/applicants", [
                'first_name' => 'Asha', 'date_of_birth' => '2018-04-12',
            ])->assertForbidden();
    }

    #[Test]
    public function create_rejects_invalid_input(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'create-applicant-003')
            ->postJson("/api/v1/schools/{$school->id}/applicants", ['first_name' => 'Asha']);

        $response->assertStatus(422);
        $this->assertArrayHasKey('date_of_birth', $response->json('error.errors'));
    }

    #[Test]
    public function a_school_id_field_in_the_payload_is_ignored(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'create-applicant-004')
            ->postJson("/api/v1/schools/{$schoolA->id}/applicants", [
                'first_name' => 'Asha', 'date_of_birth' => '2018-04-12', 'school_id' => $schoolB->id,
            ]);

        $response->assertCreated();
        $created = app(TenantContext::class)->withSchool($schoolA, fn () => Applicant::query()->findOrFail($response->json('data.id')));
        $this->assertSame($schoolA->id, $created->school_id);
    }

    // --- Application history --------------------------------------------

    #[Test]
    public function application_history_preserves_full_reapplication_history(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $applicant = $this->createApplicant($school);
        $year = $this->createAcademicYear($school, ['code' => 'AY-'.Str::random(12)]);
        $campus = $this->createCampus($school);
        $gradeLevel = $this->createGradeLevel($school);
        $rejected = $this->createAdmissionApplication($applicant, $year, $campus, $gradeLevel, ['status' => 'rejected']);
        $draft = $this->createAdmissionApplication($applicant, $year, $campus, $gradeLevel, ['status' => 'draft']);

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/applicants/{$applicant->id}/applications");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertCount(2, $ids);
        $this->assertContains($rejected->id, $ids);
        $this->assertContains($draft->id, $ids);
    }

    #[Test]
    public function application_history_for_a_foreign_applicant_is_not_found(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $applicantB = $this->createApplicant($schoolB);

        $this->asUser($user)->getJson("/api/v1/schools/{$schoolA->id}/applicants/{$applicantB->id}/applications")->assertNotFound();
    }
}
