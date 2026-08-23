<?php

namespace Tests\Feature\Authorization;

use App\Domain\Guardians\Application\Exceptions\CrossSchoolRelationshipException;
use App\Domain\Guardians\Application\GuardianContactService;
use App\Domain\Guardians\Application\GuardianService;
use App\Domain\Guardians\Application\StudentGuardianRelationshipService;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Guardians\Infrastructure\RelationshipType;
use App\Domain\Students\Application\StudentService;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1A.4: the `students.*`/`guardians.*` capability grants
 * (docs/modules/STUDENT-GUARDIAN-IDENTITY.md "Capability model").
 * Application-layer services (StudentService, GuardianService,
 * StudentGuardianRelationshipService, GuardianContactService) are
 * deliberately authorization-neutral -- these tests exercise the
 * `Gate::authorize('capability', ...)`/CapabilityResolver boundary
 * directly, exactly as a future controller will, since no controller
 * exists yet in this checkpoint (see "Authorization boundary" in the
 * same doc).
 */
class StudentGuardianCapabilityTest extends TestCase
{
    use CreatesTenancyFixtures;

    // --- Student view/manage -------------------------------------------

    #[Test]
    public function a_member_with_students_view_can_read_a_same_school_student(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['students.view', $school]));

        $found = app(TenantContext::class)->withSchool($school, fn () => $student->fresh());
        $this->assertNotNull($found);
    }

    #[Test]
    public function a_member_without_students_view_is_denied(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);

        $this->assertFalse(Gate::forUser($user)->allows('capability', ['students.view', $school]));
    }

    #[Test]
    public function students_view_does_not_imply_students_manage(): void
    {
        // Neither existing School-scoped role (school_admin, principal)
        // holds students.view without students.manage today -- this
        // test proves the two capabilities are resolved independently
        // at the CapabilityResolver level regardless of role design, by
        // granting a view-only role directly (Role::capabilities() is
        // the same mechanism CapabilityAndRoleSeeder uses).
        $user = $this->createUser();
        $school = $this->createSchool();
        $membership = $this->createMembership($user, $school);
        $viewOnlyRole = Role::query()->create(['key' => 'test_student_viewer', 'name' => 'Test Student Viewer', 'scope' => 'school', 'is_system' => false]);
        $viewOnlyRole->capabilities()->sync(['students.view']);
        app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id,
            'school_membership_id' => $membership->id,
            'role_id' => $viewOnlyRole->id,
        ]));

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['students.view', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['students.manage', $school]));
    }

    #[Test]
    public function an_authorized_member_can_create_and_update_a_student(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['students.manage', $school]));

        $student = app(StudentService::class)->create($school, [
            'student_number' => 'S-1001', 'first_name' => 'Asha', 'date_of_birth' => '2015-04-12',
        ], $user);

        $this->assertSame($school->id, $student->school_id);
    }

    #[Test]
    public function an_unauthorized_member_cannot_mutate_a_student(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);

        $this->assertFalse(Gate::forUser($user)->allows('capability', ['students.manage', $school]));
    }

    // --- Guardian view/manage -------------------------------------------

    #[Test]
    public function an_authorized_member_can_read_a_guardian_and_its_contacts(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, 'parent@example.com');

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['guardians.view', $school]));

        $contactCount = app(TenantContext::class)->withSchool($school, fn () => $guardian->contacts()->count());
        $this->assertSame(1, $contactCount);
    }

    #[Test]
    public function a_member_without_guardians_view_is_denied(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);

        $this->assertFalse(Gate::forUser($user)->allows('capability', ['guardians.view', $school]));
    }

    #[Test]
    public function an_authorized_member_can_create_update_and_deactivate_a_guardian_and_its_contact(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['guardians.manage', $school]));

        $guardian = app(GuardianService::class)->create($school, ['first_name' => 'Sunita'], $user);
        $contact = $this->createGuardianContact($guardian, ContactType::Mobile, '+919876543210');

        $updated = app(GuardianService::class)->update($guardian, ['first_name' => 'Sunita Devi'], $user);
        $this->assertSame('Sunita Devi', $updated->first_name);

        $deactivated = app(GuardianContactService::class)->deactivate($contact, $user);
        $this->assertFalse($deactivated->is_active);
    }

    #[Test]
    public function an_unauthorized_member_cannot_mutate_a_guardian_or_its_contact(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);

        $this->assertFalse(Gate::forUser($user)->allows('capability', ['guardians.manage', $school]));
    }

    // --- Relationship ----------------------------------------------------

    #[Test]
    public function a_properly_authorized_member_holds_both_capabilities_needed_to_link_a_guardian_to_a_student(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['students.manage', $school]));
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['guardians.manage', $school]));
    }

    #[Test]
    public function an_unauthorized_member_cannot_link_or_unlink(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);

        $this->assertFalse(Gate::forUser($user)->allows('capability', ['students.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['guardians.manage', $school]));
    }

    #[Test]
    public function primary_change_requires_management_authorization_on_both_sides(): void
    {
        // Holding guardians.manage alone (never students.manage) must
        // still fail the "both capabilities" pre-check a future
        // controller performs before calling
        // StudentGuardianRelationshipService::setPrimary()/link() --
        // proves the design decision (require BOTH management
        // capabilities, docs/modules/STUDENT-GUARDIAN-IDENTITY.md
        // "Authorization") actually rejects a partially-authorized
        // actor, not just a fully unauthorized one.
        $user = $this->createUser();
        $school = $this->createSchool();
        $membership = $this->createMembership($user, $school);
        $guardianManagerOnly = Role::query()->create(['key' => 'test_guardian_manager_only', 'name' => 'Test Guardian Manager Only', 'scope' => 'school', 'is_system' => false]);
        $guardianManagerOnly->capabilities()->sync(['guardians.view', 'guardians.manage']);
        app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id,
            'school_membership_id' => $membership->id,
            'role_id' => $guardianManagerOnly->id,
        ]));

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['guardians.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['students.manage', $school]));

        $bothRequired = Gate::forUser($user)->allows('capability', ['students.manage', $school])
            && Gate::forUser($user)->allows('capability', ['guardians.manage', $school]);
        $this->assertFalse($bothRequired, 'guardians.manage alone must not satisfy the "both capabilities" linking pre-check.');
    }

    #[Test]
    public function a_cross_school_relationship_remains_impossible_even_for_a_highly_privileged_member_in_their_own_school(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $student = $this->createStudent($schoolA, ['student_number' => 'S-1001']);
        $guardian = $this->createGuardian($schoolB);

        // Fully authorized in School A -- still cannot link across
        // Schools; the domain guard (CrossSchoolRelationshipException)
        // fires regardless of capability.
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['students.manage', $schoolA]));
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['guardians.manage', $schoolA]));

        $this->expectException(CrossSchoolRelationshipException::class);

        app(StudentGuardianRelationshipService::class)->link($student, $guardian, RelationshipType::Mother);
    }

    // --- Capability isolation --------------------------------------------

    #[Test]
    public function a_capability_in_school_a_does_not_grant_access_in_school_b(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['students.manage', $schoolA]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['students.manage', $schoolB]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['guardians.manage', $schoolB]));
    }

    #[Test]
    public function membership_in_school_b_without_the_required_capability_remains_denied(): void
    {
        [, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $user = $this->createUser();
        $this->createMembership($user, $schoolB);

        $this->assertFalse(Gate::forUser($user)->allows('capability', ['students.view', $schoolA]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['students.view', $schoolB]));
    }

    #[Test]
    public function central_user_identity_alone_provides_no_student_or_guardian_access(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();

        $this->assertFalse(app(CapabilityResolver::class)->canInSchool($user, 'students.view', $school));
        $this->assertFalse(app(CapabilityResolver::class)->canInSchool($user, 'guardians.view', $school));
    }
}
