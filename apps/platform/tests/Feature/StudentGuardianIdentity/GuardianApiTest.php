<?php

namespace Tests\Feature\StudentGuardianIdentity;

use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1A.5: the administrative Guardian HTTP surface
 * (App\Domain\Guardians\Http\Controllers\{GuardianController,
 * GuardianContactController}). See
 * docs/modules/STUDENT-GUARDIAN-IDENTITY.md ("Administrative HTTP
 * boundary").
 */
class GuardianApiTest extends TestCase
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

    // --- List / Show ------------------------------------------------

    #[Test]
    public function guardians_view_can_list_guardians(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->createGuardian($school);

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/guardians");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    #[Test]
    public function missing_guardians_view_is_denied(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);

        $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/guardians")->assertForbidden();
    }

    #[Test]
    public function list_only_returns_current_school_guardians(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $this->createGuardian($schoolA, ['first_name' => 'InSchoolA']);
        $this->createGuardian($schoolB, ['first_name' => 'InSchoolB']);

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$schoolA->id}/guardians");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('InSchoolA', $response->json('data.0.firstName'));
    }

    #[Test]
    public function a_foreign_guardian_is_inaccessible(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $guardianB = $this->createGuardian($schoolB);

        $this->asUser($user)->getJson("/api/v1/schools/{$schoolA->id}/guardians/{$guardianB->id}")->assertNotFound();
    }

    #[Test]
    public function show_includes_contacts_and_linked_students(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'parent@example.com');
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->createStudentGuardianRelationship($student, $guardian);

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/guardians/{$guardian->id}");

        $response->assertOk();
        $this->assertCount(1, $response->json('data.contacts'));
        $this->assertSame('parent@example.com', $response->json('data.contacts.0.value'));
        $this->assertCount(1, $response->json('data.students'));
        $this->assertSame('S-1001', $response->json('data.students.0.student.studentNumber'));
    }

    // --- Create / Update --------------------------------------------

    #[Test]
    public function guardians_manage_can_create_and_update_a_guardian(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $create = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'create-guardian-001')
            ->postJson("/api/v1/schools/{$school->id}/guardians", ['first_name' => 'Sunita']);
        $create->assertCreated();

        $update = $this->asUser($user)->patchJson("/api/v1/schools/{$school->id}/guardians/{$create->json('data.id')}", [
            'first_name' => 'Sunita Devi',
        ]);
        $update->assertOk();
        $this->assertSame('Sunita Devi', $update->json('data.firstName'));
    }

    #[Test]
    public function view_only_cannot_create_or_update_a_guardian(): void
    {
        $school = $this->createSchool();
        $viewer = $this->createUser();
        $membership = $this->createMembership($viewer, $school);
        $viewOnly = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => 'test_guardians_viewer_http', 'name' => 'Test', 'scope' => 'school', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $viewOnly->capabilities()->sync(['guardians.view']));
        app(TenantContext::class)->withSchool($school, fn () => LocalCatalogueFixtures::asOwner(fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $viewOnly->id,
        ])));

        $this->asUser($viewer)
            ->withHeader('Idempotency-Key', 'create-guardian-002')
            ->postJson("/api/v1/schools/{$school->id}/guardians", ['first_name' => 'Sunita'])
            ->assertForbidden();
    }

    #[Test]
    public function a_school_id_field_in_the_create_payload_is_ignored(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'create-guardian-003')
            ->postJson("/api/v1/schools/{$schoolA->id}/guardians", [
                'first_name' => 'Sunita', 'school_id' => $schoolB->id,
            ]);

        $response->assertCreated();
        $stored = app(TenantContext::class)->withSchool(
            $schoolA,
            fn () => Guardian::query()->findOrFail($response->json('data.id')),
        );
        $this->assertSame($schoolA->id, $stored->school_id);
    }

    #[Test]
    public function a_foreign_school_guardian_is_inaccessible_for_update(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $guardianB = $this->createGuardian($schoolB);

        $this->asUser($user)
            ->patchJson("/api/v1/schools/{$schoolA->id}/guardians/{$guardianB->id}", ['first_name' => 'Hacked'])
            ->assertNotFound();
    }

    #[Test]
    public function status_change_succeeds_for_guardians_manage(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'status-guardian-001')
            ->postJson("/api/v1/schools/{$school->id}/guardians/{$guardian->id}/status", ['status' => 'inactive']);

        $response->assertOk();
        $this->assertSame('inactive', $response->json('data.status'));
    }

    // --- Contact display ------------------------------------------------

    #[Test]
    public function contact_representation_never_exposes_internal_crypto_fields(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Mobile, '+919876543210');

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/guardians/{$guardian->id}");

        $response->assertOk();
        $contact = $response->json('data.contacts.0');
        $this->assertArrayNotHasKey('encrypted_value', $contact);
        $this->assertArrayNotHasKey('lookup_hash', $contact);
        $this->assertArrayNotHasKey('lookup_key_version', $contact);
        $this->assertArrayNotHasKey('encryptedValue', $contact);
        $this->assertArrayNotHasKey('lookupHash', $contact);
        $this->assertArrayNotHasKey('lookupKeyVersion', $contact);
        $this->assertSame('+919876543210', $contact['value']);
    }

    // --- Contact mutation -------------------------------------------------

    #[Test]
    public function guardians_manage_can_add_a_contact(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'add-contact-001')
            ->postJson("/api/v1/schools/{$school->id}/guardians/{$guardian->id}/contacts", [
                'type' => 'email', 'value' => 'parent@example.com',
            ]);

        $response->assertCreated();
        $this->assertSame('parent@example.com', $response->json('data.value'));
        $this->assertSame('email', $response->json('data.type'));
    }

    #[Test]
    public function view_only_cannot_add_update_or_deactivate_a_contact(): void
    {
        [$adminUser, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $contact = $this->createGuardianContact($guardian, ContactType::Email, 'parent@example.com');

        $viewer = $this->createUser();
        $membership = $this->createMembership($viewer, $school);
        $viewOnly = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => 'test_guardians_viewer_contacts_http', 'name' => 'Test', 'scope' => 'school', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $viewOnly->capabilities()->sync(['guardians.view']));
        app(TenantContext::class)->withSchool($school, fn () => LocalCatalogueFixtures::asOwner(fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $viewOnly->id,
        ])));

        $this->asUser($viewer)
            ->withHeader('Idempotency-Key', 'add-contact-002')
            ->postJson("/api/v1/schools/{$school->id}/guardians/{$guardian->id}/contacts", ['type' => 'email', 'value' => 'other@example.com'])
            ->assertForbidden();

        $this->asUser($viewer)
            ->withHeader('Idempotency-Key', 'set-primary-002')
            ->postJson("/api/v1/schools/{$school->id}/guardian-contacts/{$contact->id}/primary")
            ->assertForbidden();

        $this->asUser($viewer)
            ->withHeader('Idempotency-Key', 'deactivate-002')
            ->postJson("/api/v1/schools/{$school->id}/guardian-contacts/{$contact->id}/deactivate")
            ->assertForbidden();
    }

    #[Test]
    public function an_invalid_email_is_rejected_cleanly(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'add-contact-003')
            ->postJson("/api/v1/schools/{$school->id}/guardians/{$guardian->id}/contacts", [
                'type' => 'email', 'value' => 'not-an-email',
            ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function a_noncanonical_mobile_number_is_rejected_cleanly_and_never_auto_converted(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);

        $response = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'add-contact-004')
            ->postJson("/api/v1/schools/{$school->id}/guardians/{$guardian->id}/contacts", [
                'type' => 'mobile', 'value' => '9876543210',
            ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function set_primary_and_deactivate_work(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $contact = $this->createGuardianContact($guardian, ContactType::Email, 'parent@example.com');

        $primary = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'set-primary-001')
            ->postJson("/api/v1/schools/{$school->id}/guardian-contacts/{$contact->id}/primary");
        $primary->assertOk();
        $this->assertTrue($primary->json('data.isPrimary'));

        $deactivated = $this->asUser($user)
            ->withHeader('Idempotency-Key', 'deactivate-001')
            ->postJson("/api/v1/schools/{$school->id}/guardian-contacts/{$contact->id}/deactivate");
        $deactivated->assertOk();
        $this->assertFalse($deactivated->json('data.isActive'));
    }

    #[Test]
    public function the_same_contact_shared_between_guardians_remains_allowed(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $guardianA = $this->createGuardian($school);
        $guardianB = $this->createGuardian($school);

        $this->asUser($user)
            ->withHeader('Idempotency-Key', 'shared-contact-a')
            ->postJson("/api/v1/schools/{$school->id}/guardians/{$guardianA->id}/contacts", ['type' => 'mobile', 'value' => '+919999999999'])
            ->assertCreated();

        $this->asUser($user)
            ->withHeader('Idempotency-Key', 'shared-contact-b')
            ->postJson("/api/v1/schools/{$school->id}/guardians/{$guardianB->id}/contacts", ['type' => 'mobile', 'value' => '+919999999999'])
            ->assertCreated();
    }

    // --- Candidate lookup -------------------------------------------------

    #[Test]
    public function exact_email_candidate_lookup_finds_a_same_school_guardian(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school, ['first_name' => 'Findable']);
        $this->createGuardianContact($guardian, ContactType::Email, 'parent@example.com');

        $response = $this->asUser($user)->postJson("/api/v1/schools/{$school->id}/guardian-candidates", [
            'type' => 'email', 'value' => 'parent@example.com',
        ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Findable', $response->json('data.0.firstName'));
    }

    #[Test]
    public function exact_mobile_candidate_lookup_returns_multiple_shared_candidates(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $guardianA = $this->createGuardian($school);
        $guardianB = $this->createGuardian($school);
        $this->createGuardianContact($guardianA, ContactType::Mobile, '+919999999999');
        $this->createGuardianContact($guardianB, ContactType::Mobile, '+919999999999');

        $response = $this->asUser($user)->postJson("/api/v1/schools/{$school->id}/guardian-candidates", [
            'type' => 'mobile', 'value' => '+919999999999',
        ]);

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    #[Test]
    public function candidate_lookup_never_returns_a_foreign_school_candidate(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $guardianB = $this->createGuardian($schoolB);
        $this->createGuardianContact($guardianB, ContactType::Email, 'parent@example.com');

        $response = $this->asUser($user)->postJson("/api/v1/schools/{$schoolA->id}/guardian-candidates", [
            'type' => 'email', 'value' => 'parent@example.com',
        ]);

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    #[Test]
    public function candidate_lookup_response_never_exposes_a_raw_digest(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'parent@example.com');

        $response = $this->asUser($user)->postJson("/api/v1/schools/{$school->id}/guardian-candidates", [
            'type' => 'email', 'value' => 'parent@example.com',
        ]);

        $response->assertOk();
        $candidate = $response->json('data.0');
        $this->assertArrayNotHasKey('lookup_hash', $candidate);
        $this->assertArrayNotHasKey('lookupHash', $candidate);
        $this->assertArrayNotHasKey('contacts', $candidate);
    }

    #[Test]
    public function candidate_lookup_requires_guardians_view(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);

        $this->asUser($user)->postJson("/api/v1/schools/{$school->id}/guardian-candidates", [
            'type' => 'email', 'value' => 'parent@example.com',
        ])->assertForbidden();
    }
}
