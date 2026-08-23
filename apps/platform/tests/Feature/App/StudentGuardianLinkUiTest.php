<?php

namespace Tests\Feature\App;

use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1A.6: the "Add Guardian" workflow
 * (App\Http\Controllers\App\StudentGuardianRelationshipController) --
 * covers this checkpoint's brief workflows B (new Guardian), C
 * (existing Guardian / sibling reuse), D (shared household contact),
 * and the dual-capability enforcement matrix.
 */
class StudentGuardianLinkUiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function activate(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    private function grantOnly(School $school, array $capabilities): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        $role = Role::query()->create(['key' => 'test_role_'.uniqid(), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]);
        $role->capabilities()->sync($capabilities);
        app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $role->id,
        ]));

        return $user;
    }

    // --- Workflow B: create new Guardian and link ------------------------

    #[Test]
    public function workflow_b_create_new_guardian_add_contact_link_and_reflect_on_student(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);

        $this->get("/app/students/{$student->id}/guardians/add")->assertInertia(fn ($page) => $page
            ->component('App/Students/AddGuardian')
            ->where('student.id', $student->id)
        );

        $this->post("/app/students/{$student->id}/guardians", [
            'first_name' => 'Fatima', 'last_name' => 'Sharma',
            'relationship_type' => 'mother', 'is_primary_ignored' => true, // not a real field; ensures unknown input is harmless
            'is_legal_guardian' => true,
        ])->assertRedirect("/app/students/{$student->id}");

        $guardian = app(TenantContext::class)->withSchool($school, fn () => Guardian::query()->where('first_name', 'Fatima')->firstOrFail());

        $this->post("/app/guardians/{$guardian->id}/contacts", ['type' => 'mobile', 'value' => '+919876543210'])
            ->assertRedirect("/app/guardians/{$guardian->id}");

        $this->get("/app/students/{$student->id}")->assertInertia(fn ($page) => $page
            ->has('relationships', 1)
            ->where('relationships.0.guardian.firstName', 'Fatima')
            ->where('relationships.0.isLegalGuardian', true)
            ->where('relationships.0.contact.value', '+919876543210')
        );
    }

    // --- Workflow C: existing Guardian / sibling reuse --------------------

    #[Test]
    public function workflow_c_find_existing_guardian_by_name_and_link_to_a_second_student_sibling_reuse(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $studentA = $this->createStudent($school, ['student_number' => 'S-1001']);
        $studentB = $this->createStudent($school, ['student_number' => 'S-1002']);
        $guardian = $this->createGuardian($school, ['first_name' => 'Ramesh', 'last_name' => 'Iyer']);
        $this->createStudentGuardianRelationship($studentA, $guardian, ['relationship_type' => 'father']);

        // Name search finds the existing Guardian.
        $search = $this->getJson("/app/students/{$studentB->id}/guardians/search?q=Ramesh");
        $search->assertOk();
        $this->assertSame($guardian->id, $search->json('data.0.id'));

        $this->post("/app/students/{$studentB->id}/guardians/link", [
            'guardian_id' => $guardian->id, 'relationship_type' => 'father',
        ])->assertRedirect("/app/students/{$studentB->id}");

        // Guardian detail shows both Students -- one identity, two relationships.
        $this->get("/app/guardians/{$guardian->id}")->assertInertia(fn ($page) => $page
            ->has('students', 2)
        );
        $guardianCount = app(TenantContext::class)->withSchool($school, fn () => Guardian::query()->where('first_name', 'Ramesh')->count());
        $this->assertSame(1, $guardianCount, 'Only one Guardian identity must exist.');
    }

    // --- Workflow D: shared household contact ------------------------------

    #[Test]
    public function workflow_d_exact_contact_candidate_lookup_surfaces_a_shared_household_guardian_without_forcing_reuse(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $guardianA = $this->createGuardian($school, ['first_name' => 'ParentA']);
        $this->createGuardianContact($guardianA, ContactType::Mobile, '+919999999999');
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);

        $candidates = $this->getJson("/app/students/{$student->id}/guardians/candidates?".http_build_query(['type' => 'mobile', 'value' => '+919999999999']));
        $candidates->assertOk();
        $this->assertCount(1, $candidates->json('data'));
        $this->assertSame('ParentA', $candidates->json('data.0.firstName'));

        // Staff deliberately creates a SEPARATE Guardian B anyway
        // (shared household number is legitimate, never auto-merged).
        $this->post("/app/students/{$student->id}/guardians", [
            'first_name' => 'ParentB', 'relationship_type' => 'father',
        ])->assertRedirect("/app/students/{$student->id}");

        $this->post('/app/guardians/'.app(TenantContext::class)->withSchool($school, fn () => Guardian::query()->where('first_name', 'ParentB')->firstOrFail())->id.'/contacts', [
            'type' => 'mobile', 'value' => '+919999999999',
        ])->assertRedirect();

        $bothGuardians = app(TenantContext::class)->withSchool($school, fn () => Guardian::query()->count());
        $this->assertSame(2, $bothGuardians, 'Both Guardians must remain valid, separate identities.');
    }

    // --- Update / primary / unlink -----------------------------------------

    #[Test]
    public function update_relationship_set_primary_and_unlink(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $guardianA = $this->createGuardian($school);
        $guardianB = $this->createGuardian($school);
        $relA = $this->createStudentGuardianRelationship($student, $guardianA, ['is_primary' => true]);
        $relB = $this->createStudentGuardianRelationship($student, $guardianB);

        $this->put("/app/relationships/{$relB->id}", [
            'relationship_type' => 'legal_guardian', 'is_legal_guardian' => true,
        ])->assertRedirect("/app/students/{$student->id}");
        $freshB = app(TenantContext::class)->withSchool($school, fn () => $relB->fresh());
        $this->assertSame('legal_guardian', $freshB->relationship_type->value);

        $this->post("/app/relationships/{$relB->id}/primary")->assertRedirect("/app/students/{$student->id}");
        $this->assertTrue(app(TenantContext::class)->withSchool($school, fn () => $relB->fresh())->is_primary);
        $this->assertFalse(app(TenantContext::class)->withSchool($school, fn () => $relA->fresh())->is_primary);

        $this->delete("/app/relationships/{$relA->id}")->assertRedirect("/app/students/{$student->id}");
        app(TenantContext::class)->withSchool($school, function () use ($relA, $student, $guardianA): void {
            $this->assertNull(StudentGuardianRelationship::query()->find($relA->id));
            $this->assertNotNull($student->fresh());
            $this->assertNotNull($guardianA->fresh());
        });
    }

    #[Test]
    public function a_duplicate_relationship_is_rejected_cleanly(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $guardian = $this->createGuardian($school);
        $this->createStudentGuardianRelationship($student, $guardian);

        $this->post("/app/students/{$student->id}/guardians/link", [
            'guardian_id' => $guardian->id, 'relationship_type' => 'other',
        ])->assertSessionHasErrors('guardian_id');
    }

    // --- Dual-capability enforcement ---------------------------------------

    #[Test]
    public function students_manage_alone_cannot_link_a_guardian(): void
    {
        $school = $this->createSchool();
        $user = $this->grantOnly($school, ['students.view', 'students.manage']);
        $this->activate($user, $school);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $guardian = $this->createGuardian($school);

        $this->get("/app/students/{$student->id}/guardians/add")->assertForbidden();
        $this->post("/app/students/{$student->id}/guardians/link", [
            'guardian_id' => $guardian->id, 'relationship_type' => 'mother',
        ])->assertForbidden();
    }

    #[Test]
    public function guardians_manage_alone_cannot_link_a_guardian(): void
    {
        $school = $this->createSchool();
        $user = $this->grantOnly($school, ['guardians.view', 'guardians.manage']);
        $this->activate($user, $school);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $guardian = $this->createGuardian($school);

        $this->post("/app/students/{$student->id}/guardians/link", [
            'guardian_id' => $guardian->id, 'relationship_type' => 'mother',
        ])->assertForbidden();
    }

    #[Test]
    public function unlink_and_primary_change_also_require_both_capabilities(): void
    {
        [$adminUser, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $guardian = $this->createGuardian($school);
        $relationship = $this->createStudentGuardianRelationship($student, $guardian);

        $user = $this->grantOnly($school, ['students.view', 'students.manage']);
        $this->activate($user, $school);

        $this->post("/app/relationships/{$relationship->id}/primary")->assertForbidden();
        $this->delete("/app/relationships/{$relationship->id}")->assertForbidden();
    }

    // --- Cross-School safety -------------------------------------------------

    #[Test]
    public function a_foreign_school_guardian_cannot_be_linked_even_by_a_fully_authorized_actor(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $this->activate($user, $schoolA);
        $student = $this->createStudent($schoolA, ['student_number' => 'S-1001']);
        $guardianB = $this->createGuardian($schoolB);

        $this->post("/app/students/{$student->id}/guardians/link", [
            'guardian_id' => $guardianB->id, 'relationship_type' => 'mother',
        ])->assertSessionHasErrors('guardian_id');
    }

    #[Test]
    public function a_foreign_school_student_is_not_found_for_the_add_guardian_page(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $studentB = $this->createStudent($schoolB, ['student_number' => 'B-1']);
        $this->activate($user, $schoolA);

        $this->get("/app/students/{$studentB->id}/guardians/add")->assertNotFound();
    }

    #[Test]
    public function name_search_never_returns_a_foreign_school_guardian(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $this->activate($user, $schoolA);
        $student = $this->createStudent($schoolA, ['student_number' => 'S-1001']);
        $this->createGuardian($schoolB, ['first_name' => 'OnlyInSchoolB']);

        $response = $this->getJson("/app/students/{$student->id}/guardians/search?q=OnlyInSchoolB");
        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }
}
