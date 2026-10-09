<?php

namespace Tests\Feature\Authorization;

use App\Models\Capability;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1D.4: the `admissions.view`/`admissions.manage` capability
 * grants (docs/modules/ADMISSIONS.md §13). Mirrors
 * EnrollmentCapabilityTest.php's exact pattern -- no controller exists
 * yet, so these tests exercise the `Gate::authorize('capability', ...)`/
 * CapabilityResolver boundary directly, exactly as a future controller
 * will.
 */
class AdmissionsCapabilityTest extends TestCase
{
    use CreatesTenancyFixtures;

    // --- Catalog integrity ------------------------------------------------

    #[Test]
    public function the_capability_catalog_contains_each_admissions_capability_exactly_once(): void
    {
        $this->assertSame(1, Capability::query()->where('key', 'admissions.view')->count());
        $this->assertSame(1, Capability::query()->where('key', 'admissions.manage')->count());
    }

    #[Test]
    public function no_speculative_admissions_capabilities_were_added(): void
    {
        $this->assertSame(0, Capability::query()->where('key', 'admissions.create')->count());
        $this->assertSame(0, Capability::query()->where('key', 'admissions.accept')->count());
        $this->assertSame(0, Capability::query()->where('key', 'admissions.convert')->count());
        $this->assertSame(0, Capability::query()->where('key', 'admissions.delete')->count());
    }

    // --- Default role grants -----------------------------------------------

    #[Test]
    public function school_admin_holds_both_admissions_capabilities(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['admissions.view', $school]));
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['admissions.manage', $school]));
    }

    #[Test]
    public function principal_holds_both_admissions_capabilities(): void
    {
        [$user, $school] = $this->createSchoolAdmin('principal');

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['admissions.view', $school]));
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['admissions.manage', $school]));
    }

    #[Test]
    public function a_member_without_any_role_is_denied_both_capabilities(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);

        $this->assertFalse(Gate::forUser($user)->allows('capability', ['admissions.view', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['admissions.manage', $school]));
    }

    #[Test]
    public function an_unrelated_capability_never_accidentally_grants_admissions_access(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['communications.view', 'hr.employees.view']);

        $this->assertFalse(Gate::forUser($user)->allows('capability', ['admissions.view', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['admissions.manage', $school]));
    }

    // --- View/manage independence -------------------------------------------

    #[Test]
    public function admissions_view_does_not_imply_admissions_manage(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['admissions.view']);

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['admissions.view', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['admissions.manage', $school]));
    }

    #[Test]
    public function admissions_manage_does_not_imply_admissions_view(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['admissions.manage']);

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['admissions.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['admissions.view', $school]));
    }

    // --- Direct capability grant, independent of role labels -----------------

    #[Test]
    public function a_custom_role_holding_only_admissions_manage_can_authorize_via_capability_not_role_name(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $membership = $this->createMembership($user, $school);
        $customRole = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => 'test_admissions_officer', 'name' => 'Test Admissions Officer', 'scope' => 'school', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $customRole->capabilities()->sync(['admissions.manage']));
        app(TenantContext::class)->withSchool($school, fn () => LocalCatalogueFixtures::asOwner(fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id,
            'school_membership_id' => $membership->id,
            'role_id' => $customRole->id,
        ])));

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['admissions.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['admissions.view', $school]));
    }

    // --- Tenant isolation ---------------------------------------------------

    #[Test]
    public function an_admissions_capability_in_school_a_does_not_grant_access_in_school_b(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['admissions.view', $schoolA]));
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['admissions.manage', $schoolA]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['admissions.view', $schoolB]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['admissions.manage', $schoolB]));
    }

    #[Test]
    public function central_user_identity_alone_provides_no_admissions_access(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();

        $this->assertFalse(app(CapabilityResolver::class)->canInSchool($user, 'admissions.view', $school));
        $this->assertFalse(app(CapabilityResolver::class)->canInSchool($user, 'admissions.manage', $school));
    }

    // --- Adding admissions.* must not disturb existing grants ---------------

    #[Test]
    public function existing_students_guardians_and_enrollment_grants_are_unaffected_by_the_new_admissions_capabilities(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['students.view', $school]));
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['students.manage', $school]));
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['guardians.view', $school]));
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['guardians.manage', $school]));
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['enrollments.view', $school]));
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['enrollments.manage', $school]));

        [$principal, $schoolTwo] = $this->createSchoolAdmin('principal');
        $this->assertTrue(Gate::forUser($principal)->allows('capability', ['students.manage', $schoolTwo]));
        $this->assertTrue(Gate::forUser($principal)->allows('capability', ['guardians.manage', $schoolTwo]));
        $this->assertTrue(Gate::forUser($principal)->allows('capability', ['enrollments.manage', $schoolTwo]));
    }
}
