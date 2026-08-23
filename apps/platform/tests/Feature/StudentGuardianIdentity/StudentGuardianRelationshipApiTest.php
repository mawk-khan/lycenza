<?php

namespace Tests\Feature\StudentGuardianIdentity;

use App\Domain\Guardians\Infrastructure\RelationshipType;
use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1A.5: the administrative Student<->Guardian relationship HTTP
 * surface (App\Domain\Guardians\Http\Controllers\
 * StudentGuardianRelationshipController). Every mutation requires BOTH
 * students.manage AND guardians.manage (Phase 1A.4's accepted design).
 * See docs/modules/STUDENT-GUARDIAN-IDENTITY.md
 * ("Administrative HTTP boundary").
 */
class StudentGuardianRelationshipApiTest extends TestCase
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

    private function grantOnly(User $user, $school, array $capabilities, string $roleKey): void
    {
        $membership = $this->createMembership($user, $school);
        $role = Role::query()->create(['key' => $roleKey, 'name' => $roleKey, 'scope' => 'school', 'is_system' => false]);
        $role->capabilities()->sync($capabilities);
        app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $role->id,
        ]));
    }

    #[Test]
    public function a_member_with_both_management_capabilities_can_link(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $guardian = $this->createGuardian($school);

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'link-001')
            ->postJson("/api/v1/schools/{$school->id}/students/{$student->id}/guardians", [
                'guardian_id' => $guardian->id, 'relationship_type' => 'mother',
            ]);

        $response->assertCreated();
        $this->assertSame('mother', $response->json('data.relationshipType'));
        $this->assertSame($guardian->id, $response->json('data.guardian.id'));
    }

    #[Test]
    public function students_manage_alone_is_denied(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $guardian = $this->createGuardian($school);
        $user = $this->createUser();
        $this->grantOnly($user, $school, ['students.view', 'students.manage'], 'test_students_only');

        $this->asUser($user)
            ->withHeader('Idempotency-Key', 'link-002')
            ->postJson("/api/v1/schools/{$school->id}/students/{$student->id}/guardians", [
                'guardian_id' => $guardian->id, 'relationship_type' => 'mother',
            ])->assertForbidden();
    }

    #[Test]
    public function guardians_manage_alone_is_denied(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $guardian = $this->createGuardian($school);
        $user = $this->createUser();
        $this->grantOnly($user, $school, ['guardians.view', 'guardians.manage'], 'test_guardians_only');

        $this->asUser($user)
            ->withHeader('Idempotency-Key', 'link-003')
            ->postJson("/api/v1/schools/{$school->id}/students/{$student->id}/guardians", [
                'guardian_id' => $guardian->id, 'relationship_type' => 'mother',
            ])->assertForbidden();
    }

    #[Test]
    public function no_management_capability_is_denied(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $guardian = $this->createGuardian($school);
        $user = $this->createUser();
        $this->createMembership($user, $school);

        $this->asUser($user)
            ->withHeader('Idempotency-Key', 'link-004')
            ->postJson("/api/v1/schools/{$school->id}/students/{$student->id}/guardians", [
                'guardian_id' => $guardian->id, 'relationship_type' => 'mother',
            ])->assertForbidden();
    }

    #[Test]
    public function a_foreign_school_guardian_cannot_be_linked(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $student = $this->createStudent($schoolA, ['student_number' => 'S-1001']);
        $guardianB = $this->createGuardian($schoolB);

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'link-005')
            ->postJson("/api/v1/schools/{$schoolA->id}/students/{$student->id}/guardians", [
                'guardian_id' => $guardianB->id, 'relationship_type' => 'mother',
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('guardian_id', $response->json('error.errors'));
    }

    #[Test]
    public function a_foreign_school_student_is_inaccessible_for_linking(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $studentB = $this->createStudent($schoolB, ['student_number' => 'B-1']);
        $guardian = $this->createGuardian($schoolA);

        $this->asUser($user)
            ->withHeader('Idempotency-Key', 'link-006')
            ->postJson("/api/v1/schools/{$schoolA->id}/students/{$studentB->id}/guardians", [
                'guardian_id' => $guardian->id, 'relationship_type' => 'mother',
            ])->assertNotFound();
    }

    #[Test]
    public function a_duplicate_relationship_is_mapped_to_a_clean_422(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $guardian = $this->createGuardian($school);
        $this->createStudentGuardianRelationship($student, $guardian);

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'link-007')
            ->postJson("/api/v1/schools/{$school->id}/students/{$student->id}/guardians", [
                'guardian_id' => $guardian->id, 'relationship_type' => 'other',
            ]);

        $response->assertStatus(422);
        $this->assertSame('DUPLICATE_STUDENT_GUARDIAN_RELATIONSHIP', $response->json('error.code'));
    }

    #[Test]
    public function update_changes_relationship_type_and_flags(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $guardian = $this->createGuardian($school);
        $relationship = $this->createStudentGuardianRelationship($student, $guardian, ['relationship_type' => RelationshipType::Other]);

        $response = $this->asUser($user)->patchJson("/api/v1/schools/{$school->id}/student-guardian-relationships/{$relationship->id}", [
            'relationship_type' => 'legal_guardian', 'is_legal_guardian' => true,
        ]);

        $response->assertOk();
        $this->assertSame('legal_guardian', $response->json('data.relationshipType'));
        $this->assertTrue($response->json('data.isLegalGuardian'));
    }

    #[Test]
    public function set_primary_promotes_and_demotes_atomically(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $guardianA = $this->createGuardian($school);
        $guardianB = $this->createGuardian($school);
        $relA = $this->createStudentGuardianRelationship($student, $guardianA, ['is_primary' => true]);
        $relB = $this->createStudentGuardianRelationship($student, $guardianB);

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'primary-001')
            ->postJson("/api/v1/schools/{$school->id}/student-guardian-relationships/{$relB->id}/primary");

        $response->assertOk();
        $this->assertTrue($response->json('data.isPrimary'));
        $fresh = app(TenantContext::class)->withSchool($school, fn () => $relA->fresh());
        $this->assertFalse($fresh->is_primary);
    }

    #[Test]
    public function unlink_removes_the_relationship_and_preserves_both_identities(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $guardian = $this->createGuardian($school);
        $relationship = $this->createStudentGuardianRelationship($student, $guardian);

        $response = $this->asUser($user)->deleteJson("/api/v1/schools/{$school->id}/student-guardian-relationships/{$relationship->id}");

        $response->assertStatus(204);
        app(TenantContext::class)->withSchool($school, function () use ($relationship, $student, $guardian): void {
            $this->assertNull(StudentGuardianRelationship::query()->find($relationship->id));
            $this->assertNotNull($student->fresh());
            $this->assertNotNull($guardian->fresh());
        });
    }

    #[Test]
    public function unlink_preserves_a_shared_guardians_relationship_with_a_sibling(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $studentA = $this->createStudent($school, ['student_number' => 'S-1001']);
        $studentB = $this->createStudent($school, ['student_number' => 'S-1002']);
        $relA = $this->createStudentGuardianRelationship($studentA, $guardian);
        $relB = $this->createStudentGuardianRelationship($studentB, $guardian);

        $this->asUser($user)->deleteJson("/api/v1/schools/{$school->id}/student-guardian-relationships/{$relA->id}")->assertStatus(204);

        app(TenantContext::class)->withSchool($school, function () use ($relB): void {
            $this->assertNotNull($relB->fresh());
        });
    }

    #[Test]
    public function index_lists_a_students_relationships(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $guardian = $this->createGuardian($school);
        $this->createStudentGuardianRelationship($student, $guardian);

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/students/{$student->id}/guardians");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }
}
