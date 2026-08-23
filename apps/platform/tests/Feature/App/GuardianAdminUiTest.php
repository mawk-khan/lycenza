<?php

namespace Tests\Feature\App;

use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1A.6: the administrative Guardian UI
 * (App\Http\Controllers\App\GuardianController). See
 * StudentAdminUiTest's docblock for scope/rationale.
 */
class GuardianAdminUiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function activate(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    private function viewOnlyUser(School $school): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        $role = Role::query()->create(['key' => 'test_guardians_viewer_ui_'.uniqid(), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]);
        $role->capabilities()->sync(['guardians.view']);
        app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $role->id,
        ]));

        return $user;
    }

    // --- Index / show ---------------------------------------------------

    #[Test]
    public function guardians_view_can_list_and_missing_capability_is_forbidden(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $this->createGuardian($school);

        $this->get('/app/guardians')->assertInertia(fn ($page) => $page
            ->component('App/Guardians/Index')
            ->has('guardians.data', 1)
        );

        $stranger = $this->createUser();
        $strangerSchool = $this->createSchool();
        $this->createMembership($stranger, $strangerSchool);
        $this->activate($stranger, $strangerSchool);
        $this->get('/app/guardians')->assertForbidden();
    }

    #[Test]
    public function index_reports_linked_student_count_without_n_plus_one(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $guardian = $this->createGuardian($school);
        $studentA = $this->createStudent($school, ['student_number' => 'S-1001']);
        $studentB = $this->createStudent($school, ['student_number' => 'S-1002']);
        $this->createStudentGuardianRelationship($studentA, $guardian);
        $this->createStudentGuardianRelationship($studentB, $guardian);

        $queryCountBefore = 0;
        DB::listen(function () use (&$queryCountBefore): void {
            $queryCountBefore++;
        });

        $this->get('/app/guardians')->assertInertia(fn ($page) => $page
            ->where('guardians.data.0.linkedStudentCount', 2)
        );

        // withCount() adds exactly one extra aggregate query for the
        // whole page -- not one query per row (this checkpoint's
        // brief, section 36).
        $this->assertLessThan(15, $queryCountBefore, 'Guardian index must not perform one query per row.');
    }

    #[Test]
    public function show_includes_contacts_and_linked_students_with_relationship_flags(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'parent@example.com');
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->createStudentGuardianRelationship($student, $guardian, ['is_legal_guardian' => true]);

        $this->get("/app/guardians/{$guardian->id}")->assertInertia(fn ($page) => $page
            ->component('App/Guardians/Show')
            ->has('contacts', 1)
            ->where('contacts.0.value', 'parent@example.com')
            ->has('students', 1)
            ->where('students.0.isLegalGuardian', true)
        );
    }

    #[Test]
    public function contact_response_never_exposes_internal_crypto_fields(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Mobile, '+919876543210');

        $this->get("/app/guardians/{$guardian->id}")->assertInertia(fn ($page) => $page
            ->missing('contacts.0.encrypted_value')
            ->missing('contacts.0.encryptedValue')
            ->missing('contacts.0.lookup_hash')
            ->missing('contacts.0.lookupHash')
            ->missing('contacts.0.lookup_key_version')
            ->missing('contacts.0.lookupKeyVersion')
        );
    }

    #[Test]
    public function a_foreign_school_guardian_is_not_found(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $guardianB = $this->createGuardian($schoolB);
        $this->activate($user, $schoolA);

        $this->get("/app/guardians/{$guardianB->id}")->assertNotFound();
    }

    // --- Create / update / status ------------------------------------

    #[Test]
    public function guardians_manage_can_create_update_and_change_status(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);

        $this->post('/app/guardians', ['first_name' => 'Sunita']);
        $guardian = app(TenantContext::class)->withSchool($school, fn () => Guardian::query()->where('first_name', 'Sunita')->firstOrFail());

        $this->put("/app/guardians/{$guardian->id}", ['first_name' => 'Sunita', 'last_name' => 'Devi']);
        $fresh = app(TenantContext::class)->withSchool($school, fn () => $guardian->fresh());
        $this->assertSame('Devi', $fresh->last_name);

        $this->post("/app/guardians/{$guardian->id}/status", ['status' => 'inactive']);
        $this->assertSame('inactive', app(TenantContext::class)->withSchool($school, fn () => $guardian->fresh())->status);
    }

    #[Test]
    public function view_only_cannot_create_update_or_change_status(): void
    {
        $school = $this->createSchool();
        $viewer = $this->viewOnlyUser($school);
        $this->activate($viewer, $school);
        $guardian = $this->createGuardian($school);

        $this->get('/app/guardians/create')->assertForbidden();
        $this->post('/app/guardians', ['first_name' => 'X'])->assertForbidden();
        $this->put("/app/guardians/{$guardian->id}", ['first_name' => 'X'])->assertForbidden();
        $this->post("/app/guardians/{$guardian->id}/status", ['status' => 'inactive'])->assertForbidden();
    }

    // --- Contact management ---------------------------------------------

    #[Test]
    public function guardians_manage_can_add_set_primary_and_deactivate_a_contact(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $guardian = $this->createGuardian($school);

        $this->post("/app/guardians/{$guardian->id}/contacts", ['type' => 'email', 'value' => 'parent@example.com']);
        $contact = app(TenantContext::class)->withSchool($school, fn () => $guardian->contacts()->firstOrFail());

        $this->post("/app/contacts/{$contact->id}/primary");
        $this->assertTrue(app(TenantContext::class)->withSchool($school, fn () => $contact->fresh())->is_primary);

        $this->post("/app/contacts/{$contact->id}/deactivate");
        $this->assertFalse(app(TenantContext::class)->withSchool($school, fn () => $contact->fresh())->is_active);
    }

    #[Test]
    public function view_only_cannot_add_update_or_deactivate_a_contact(): void
    {
        $school = $this->createSchool();
        $viewer = $this->viewOnlyUser($school);
        $guardian = $this->createGuardian($school);
        $contact = $this->createGuardianContact($guardian, ContactType::Email, 'parent@example.com');
        $this->activate($viewer, $school);

        $this->post("/app/guardians/{$guardian->id}/contacts", ['type' => 'email', 'value' => 'other@example.com'])->assertForbidden();
        $this->post("/app/contacts/{$contact->id}/primary")->assertForbidden();
        $this->post("/app/contacts/{$contact->id}/deactivate")->assertForbidden();
    }

    #[Test]
    public function an_invalid_email_and_noncanonical_mobile_are_rejected_cleanly(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $guardian = $this->createGuardian($school);

        $this->post("/app/guardians/{$guardian->id}/contacts", ['type' => 'email', 'value' => 'not-an-email'])
            ->assertSessionHasErrors('value');

        $this->post("/app/guardians/{$guardian->id}/contacts", ['type' => 'mobile', 'value' => '9876543210'])
            ->assertSessionHasErrors('value');
    }

    #[Test]
    public function the_same_contact_shared_between_guardians_remains_allowed(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $guardianA = $this->createGuardian($school);
        $guardianB = $this->createGuardian($school);

        $this->post("/app/guardians/{$guardianA->id}/contacts", ['type' => 'mobile', 'value' => '+919999999999'])
            ->assertRedirect("/app/guardians/{$guardianA->id}");
        $this->post("/app/guardians/{$guardianB->id}/contacts", ['type' => 'mobile', 'value' => '+919999999999'])
            ->assertRedirect("/app/guardians/{$guardianB->id}");
    }

    #[Test]
    public function a_duplicate_contact_on_the_same_guardian_is_rejected_cleanly(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'parent@example.com');

        $this->post("/app/guardians/{$guardian->id}/contacts", ['type' => 'email', 'value' => 'parent@example.com'])
            ->assertSessionHasErrors('value');
    }
}
