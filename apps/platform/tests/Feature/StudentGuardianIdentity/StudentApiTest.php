<?php

namespace Tests\Feature\StudentGuardianIdentity;

use App\Domain\Students\Infrastructure\Student;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\TestCase;

/**
 * Phase 1A.5: the administrative Student HTTP surface
 * (App\Domain\Students\Http\Controllers\StudentController). See
 * docs/modules/STUDENT-GUARDIAN-IDENTITY.md ("Administrative HTTP
 * boundary").
 */
class StudentApiTest extends TestCase
{
    use CreatesTenancyFixtures;
    use ProvidesSensitiveActionMfa;

    private function token(User $user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    private function asUser(User $user)
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token($user));
    }

    // --- List -------------------------------------------------------

    #[Test]
    public function a_guest_is_denied(): void
    {
        $school = $this->createSchool();

        $this->getJson("/api/v1/schools/{$school->id}/students")->assertUnauthorized();
    }

    #[Test]
    public function a_member_with_students_view_can_list_students(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->createStudent($school, ['student_number' => 'S-1001']);

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/students");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    #[Test]
    public function a_member_without_students_view_is_denied(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);

        $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/students")->assertForbidden();
    }

    #[Test]
    public function list_only_returns_current_school_students(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $this->createStudent($schoolA, ['student_number' => 'A-1']);
        $this->createStudent($schoolB, ['student_number' => 'B-1']);

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$schoolA->id}/students");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('A-1', $response->json('data.0.studentNumber'));
    }

    #[Test]
    public function list_is_paginated_with_meta(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        foreach (range(1, 3) as $i) {
            $this->createStudent($school, ['student_number' => "S-100{$i}"]);
        }

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/students?per_page=2");

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertSame(1, $response->json('meta.page'));
        $this->assertSame(2, $response->json('meta.perPage'));
        $this->assertSame(3, $response->json('meta.total'));
    }

    #[Test]
    public function list_supports_exact_student_number_search(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->createStudent($school, ['student_number' => 'S-1002']);

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/students?student_number=S-1001");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('S-1001', $response->json('data.0.studentNumber'));
    }

    #[Test]
    public function list_supports_name_search(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        // Every name column is pinned: the search is a substring match, so a
        // random factory surname (e.g. "Pasha") would otherwise also match.
        $this->createStudent($school, ['student_number' => 'S-1001', 'first_name' => 'Asha', 'middle_name' => null, 'last_name' => 'Rao']);
        $this->createStudent($school, ['student_number' => 'S-1002', 'first_name' => 'Rohit', 'middle_name' => null, 'last_name' => 'Verma']);

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/students?name=Asha");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Asha', $response->json('data.0.firstName'));
    }

    #[Test]
    public function list_supports_status_filter(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->createStudent($school, ['student_number' => 'S-1001', 'status' => 'active']);
        $this->createStudent($school, ['student_number' => 'S-1002', 'status' => 'inactive']);

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/students?status=inactive");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('S-1002', $response->json('data.0.studentNumber'));
    }

    #[Test]
    public function list_rows_never_include_date_of_birth(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->createStudent($school, ['student_number' => 'S-1001']);

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/students");

        $response->assertOk();
        $this->assertArrayNotHasKey('dateOfBirth', $response->json('data.0'));
    }

    // --- Show ---------------------------------------------------------

    #[Test]
    public function show_a_same_school_student_succeeds(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/students/{$student->id}");

        $response->assertOk();
        $this->assertSame('S-1001', $response->json('data.studentNumber'));
        $this->assertNotNull($response->json('data.dateOfBirth'));
    }

    #[Test]
    public function show_a_foreign_school_student_is_not_found(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $studentB = $this->createStudent($schoolB, ['student_number' => 'B-1']);

        $this->asUser($user)->getJson("/api/v1/schools/{$schoolA->id}/students/{$studentB->id}")->assertNotFound();
    }

    #[Test]
    public function show_a_random_uuid_is_not_found_indistinguishably(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $studentB = $this->createStudent($schoolB, ['student_number' => 'B-1']);

        $foreign = $this->asUser($user)->getJson("/api/v1/schools/{$schoolA->id}/students/{$studentB->id}");
        $random = $this->asUser($user)->getJson('/api/v1/schools/'.$schoolA->id.'/students/'.Str::uuid());

        $foreign->assertNotFound();
        $random->assertNotFound();
        $this->assertSame($foreign->status(), $random->status());
    }

    #[Test]
    public function show_is_denied_without_students_view(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $user = $this->createUser();
        $this->createMembership($user, $school);

        $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/students/{$student->id}")->assertForbidden();
    }

    // --- Create -------------------------------------------------------

    #[Test]
    public function students_manage_can_create_a_student(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'create-student-001')
            ->postJson("/api/v1/schools/{$school->id}/students", [
                'first_name' => 'Asha', 'last_name' => 'Verma', 'date_of_birth' => '2015-04-12',
            ]);

        $response->assertCreated();
        $this->assertSame('1', $response->json('data.studentNumber'), 'SIS 2.3: allocated by the School counter');
        $stored = app(TenantContext::class)->withSchool($school, fn () => Student::query()->findOrFail($response->json('data.id')));
        $this->assertSame($school->id, $stored->school_id);
    }

    #[Test]
    public function a_view_only_member_cannot_create_a_student(): void
    {
        // Principal is granted students.manage too in this checkpoint's
        // role design -- use a raw view-only role to isolate the case.
        $user = $this->createUser();
        $school = $this->createSchool();
        $membership = $this->createMembership($user, $school);
        $viewOnly = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => 'test_students_viewer_http', 'name' => 'Test', 'scope' => 'school', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $viewOnly->capabilities()->sync(['students.view']));
        app(TenantContext::class)->withSchool($school, fn () => LocalCatalogueFixtures::asOwner(fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $viewOnly->id,
        ])));

        $this->asUser($user)
            ->withHeader('Idempotency-Key', 'create-student-002')
            ->postJson("/api/v1/schools/{$school->id}/students", [
                'first_name' => 'Asha', 'date_of_birth' => '2015-04-12',
            ])->assertForbidden();
    }

    #[Test]
    public function create_rejects_invalid_input(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'create-student-003')
            ->postJson("/api/v1/schools/{$school->id}/students", [
                'first_name' => 'Asha',
            ]);

        $response->assertStatus(422);
        $this->assertArrayNotHasKey('student_number', $response->json('error.errors'));
        $this->assertArrayHasKey('date_of_birth', $response->json('error.errors'));
    }

    #[Test]
    public function a_future_date_of_birth_is_refused(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'create-student-future-dob')
            ->postJson("/api/v1/schools/{$school->id}/students", [
                'first_name' => 'Asha', 'date_of_birth' => now()->addDays(3)->toDateString(),
            ]);

        $response->assertStatus(422);
        $this->assertSame(['The date of birth cannot be in the future.'], $response->json('error.errors.date_of_birth'));
    }

    #[Test]
    public function a_supplied_student_number_is_refused_with_a_field_error(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'create-student-004')
            ->postJson("/api/v1/schools/{$school->id}/students", [
                'student_number' => 'S-1001', 'first_name' => 'Rohit', 'date_of_birth' => '2016-01-01',
            ]);

        $response->assertStatus(422);
        $this->assertSame(['The Student number is allocated by the School and cannot be supplied.'], $response->json('error.errors.student_number'));
        $this->assertSame(0, app(TenantContext::class)->withSchool($school, fn () => Student::query()->count()));
    }

    #[Test]
    public function a_possible_duplicate_is_a_409_with_count_and_acknowledgement_only(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $existing = $this->createStudent($school, ['first_name' => 'Rohit', 'middle_name' => null, 'last_name' => 'Rao', 'date_of_birth' => '2016-01-01']);
        $url = "/api/v1/schools/{$school->id}/students";
        $payload = ['first_name' => 'rohit', 'last_name' => 'RAO', 'date_of_birth' => '2016-01-01'];

        $refused = $this->asUser($user)->withHeader('Idempotency-Key', 'create-student-dup-1')->postJson($url, $payload);
        $refused->assertStatus(409);
        $this->assertSame('POSSIBLE_DUPLICATE_STUDENT', $refused->json('error.code'));
        $this->assertSame(['candidateCount' => 1, 'duplicateAcknowledgement' => $refused->json('error.details.duplicateAcknowledgement')], $refused->json('error.details'));
        $this->assertStringNotContainsString($existing->id, $refused->getContent());
        $this->assertStringNotContainsString($existing->student_number, $refused->getContent());

        // The refusal is stored under its key and replayed, like any response.
        $this->asUser($user)->withHeader('Idempotency-Key', 'create-student-dup-1')->postJson($url, $payload)
            ->assertStatus(409)->assertHeader('Idempotency-Replayed', 'true');

        // An invented flag is not an acknowledgement.
        $this->asUser($user)->withHeader('Idempotency-Key', 'create-student-dup-1-flag')
            ->postJson($url, [...$payload, 'duplicate_acknowledgement' => 'true'])->assertStatus(409);

        $created = $this->asUser($user)->withHeader('Idempotency-Key', 'create-student-dup-1-ack')
            ->postJson($url, [...$payload, 'duplicate_acknowledgement' => $refused->json('error.details.duplicateAcknowledgement')]);
        $created->assertCreated();
        $this->assertNotSame($existing->id, $created->json('data.id'));
    }

    #[Test]
    public function a_school_id_field_in_the_payload_is_ignored(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'create-student-005')
            ->postJson("/api/v1/schools/{$schoolA->id}/students", [
                'first_name' => 'Asha', 'date_of_birth' => '2015-04-12',
                'school_id' => $schoolB->id,
            ]);

        $response->assertCreated();
        $created = app(TenantContext::class)->withSchool($schoolA, fn () => Student::query()->findOrFail($response->json('data.id')));
        $this->assertSame($schoolA->id, $created->school_id);
    }

    // --- Update -------------------------------------------------------

    #[Test]
    public function students_manage_can_update_a_student(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school, ['student_number' => 'S-1001', 'last_name' => 'Verma']);

        $response = $this->asUser($user)->patchJson("/api/v1/schools/{$school->id}/students/{$student->id}", [
            'last_name' => 'Sharma',
        ]);

        $response->assertOk();
        $this->assertSame('Sharma', $response->json('data.lastName'));
    }

    #[Test]
    public function a_foreign_school_student_is_inaccessible_for_update(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $studentB = $this->createStudent($schoolB, ['student_number' => 'B-1']);

        $this->asUser($user)
            ->patchJson("/api/v1/schools/{$schoolA->id}/students/{$studentB->id}", ['last_name' => 'Hacked'])
            ->assertNotFound();
    }

    #[Test]
    public function update_ignores_id_school_id_and_created_at_fields(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $student = $this->createStudent($schoolA, ['student_number' => 'S-1001']);
        $originalId = $student->id;
        $originalCreatedAt = $student->created_at->toIso8601String();

        $response = $this->asUser($user)->patchJson("/api/v1/schools/{$schoolA->id}/students/{$student->id}", [
            'id' => (string) Str::uuid(),
            'school_id' => $schoolB->id,
            'created_at' => '2000-01-01T00:00:00Z',
            'last_name' => 'Updated',
        ]);

        $response->assertOk();
        $this->assertSame($originalId, $response->json('data.id'));
        $this->assertSame($originalCreatedAt, $response->json('data.createdAt'));
        $fresh = app(TenantContext::class)->withSchool($schoolA, fn () => Student::query()->findOrFail($originalId));
        $this->assertSame($schoolA->id, $fresh->school_id);
    }

    // --- Status ---------------------------------------------------------

    #[Test]
    public function status_change_succeeds_for_students_manage(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'status-student-001')
            ->postJson("/api/v1/schools/{$school->id}/students/{$student->id}/status", ['status' => 'inactive', 'reason_code' => 'left_school', 'mfa_code' => $this->freshMfaCode($user)]);

        $response->assertOk();
        $this->assertSame('inactive', $response->json('data.status'));
    }

    #[Test]
    public function status_change_rejects_an_unsupported_value(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'status-student-002')
            ->postJson("/api/v1/schools/{$school->id}/students/{$student->id}/status", ['status' => 'graduated']);

        $response->assertStatus(422);
    }

    // --- Capability isolation -------------------------------------------

    #[Test]
    public function the_same_central_user_has_independent_capabilities_per_school(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $this->createMembership($user, $schoolB);
        Auth::forgetGuards();

        $studentA = $this->createStudent($schoolA, ['student_number' => 'A-1']);
        $studentB = $this->createStudent($schoolB, ['student_number' => 'B-1']);

        $this->asUser($user)->patchJson("/api/v1/schools/{$schoolA->id}/students/{$studentA->id}", ['last_name' => 'X'])->assertOk();
        Auth::forgetGuards();
        $this->asUser($user)->patchJson("/api/v1/schools/{$schoolB->id}/students/{$studentB->id}", ['last_name' => 'X'])->assertForbidden();
    }
}
