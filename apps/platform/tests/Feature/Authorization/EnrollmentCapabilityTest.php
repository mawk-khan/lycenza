<?php

namespace Tests\Feature\Authorization;

use App\Models\Capability;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1B.4: the `enrollments.view`/`enrollments.manage` capability
 * grants (docs/modules/STUDENT-ENROLLMENT.md "Authorization"). Mirrors
 * StudentGuardianCapabilityTest.php's exact pattern -- no controller
 * exists yet, so these tests exercise the
 * `Gate::authorize('capability', ...)`/CapabilityResolver boundary
 * directly, exactly as a future controller will.
 */
class EnrollmentCapabilityTest extends TestCase
{
    use CreatesTenancyFixtures;

    // --- Catalog integrity ------------------------------------------------

    #[Test]
    public function the_capability_catalog_contains_each_enrollment_capability_exactly_once(): void
    {
        $this->assertSame(1, Capability::query()->where('key', 'enrollments.view')->count());
        $this->assertSame(1, Capability::query()->where('key', 'enrollments.manage')->count());
    }

    // --- Default role grants -----------------------------------------------

    #[Test]
    public function school_admin_holds_both_enrollment_capabilities(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['enrollments.view', $school]));
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['enrollments.manage', $school]));
    }

    #[Test]
    public function principal_holds_both_enrollment_capabilities(): void
    {
        [$user, $school] = $this->createSchoolAdmin('principal');

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['enrollments.view', $school]));
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['enrollments.manage', $school]));
    }

    #[Test]
    public function a_member_without_any_role_is_denied_both_capabilities(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);

        $this->assertFalse(Gate::forUser($user)->allows('capability', ['enrollments.view', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['enrollments.manage', $school]));
    }

    // --- View/manage independence -------------------------------------------

    #[Test]
    public function enrollments_view_does_not_imply_enrollments_manage(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $membership = $this->createMembership($user, $school);
        $viewOnlyRole = Role::query()->create(['key' => 'test_enrollment_viewer', 'name' => 'Test Enrollment Viewer', 'scope' => 'school', 'is_system' => false]);
        $viewOnlyRole->capabilities()->sync(['enrollments.view']);
        app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id,
            'school_membership_id' => $membership->id,
            'role_id' => $viewOnlyRole->id,
        ]));

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['enrollments.view', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['enrollments.manage', $school]));
    }

    #[Test]
    public function enrollments_manage_does_not_imply_enrollments_view(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $membership = $this->createMembership($user, $school);
        $manageOnlyRole = Role::query()->create(['key' => 'test_enrollment_manager', 'name' => 'Test Enrollment Manager', 'scope' => 'school', 'is_system' => false]);
        $manageOnlyRole->capabilities()->sync(['enrollments.manage']);
        app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id,
            'school_membership_id' => $membership->id,
            'role_id' => $manageOnlyRole->id,
        ]));

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['enrollments.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['enrollments.view', $school]));
    }

    // --- Tenant isolation ---------------------------------------------------

    #[Test]
    public function an_enrollment_capability_in_school_a_does_not_grant_access_in_school_b(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['enrollments.view', $schoolA]));
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['enrollments.manage', $schoolA]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['enrollments.view', $schoolB]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['enrollments.manage', $schoolB]));
    }

    #[Test]
    public function membership_in_school_b_without_the_required_capability_remains_denied_in_school_a(): void
    {
        [, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        $user = $this->createUser();
        $this->createMembership($user, $schoolB);

        $this->assertFalse(Gate::forUser($user)->allows('capability', ['enrollments.view', $schoolA]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['enrollments.view', $schoolB]));
    }

    #[Test]
    public function central_user_identity_alone_provides_no_enrollment_access(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();

        $this->assertFalse(app(CapabilityResolver::class)->canInSchool($user, 'enrollments.view', $school));
        $this->assertFalse(app(CapabilityResolver::class)->canInSchool($user, 'enrollments.manage', $school));
    }

    // --- Adding enrollments.* must not disturb students.*/guardians.* -------

    #[Test]
    public function existing_students_and_guardians_grants_are_unaffected_by_the_new_enrollment_capabilities(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['students.view', $school]));
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['students.manage', $school]));
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['guardians.view', $school]));
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['guardians.manage', $school]));

        [$principal, $schoolTwo] = $this->createSchoolAdmin('principal');
        $this->assertTrue(Gate::forUser($principal)->allows('capability', ['students.manage', $schoolTwo]));
        $this->assertTrue(Gate::forUser($principal)->allows('capability', ['guardians.manage', $schoolTwo]));
    }
}
