<?php

namespace Tests\Feature\Authorization;

use App\Models\Capability;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10A: the `library.catalogue.view`/`.manage` and
 * `library.circulation.view`/`.manage` capability grants
 * (docs/modules/LIBRARY.md "Capabilities"). Mirrors
 * AdmissionsCapabilityTest.php's exact pattern.
 */
class LibraryCapabilityTest extends TestCase
{
    use CreatesTenancyFixtures;

    // --- Catalog integrity ------------------------------------------------

    #[Test]
    public function the_capability_catalog_contains_each_library_capability_exactly_once(): void
    {
        foreach (['library.catalogue.view', 'library.catalogue.manage', 'library.circulation.view', 'library.circulation.manage'] as $key) {
            $this->assertSame(1, Capability::query()->where('key', $key)->count(), "Expected exactly one '{$key}' capability row.");
        }
    }

    #[Test]
    public function no_speculative_library_capabilities_were_added(): void
    {
        foreach (['library.fines.manage', 'library.reservations.manage', 'library.renewals.manage', 'library.documents.manage'] as $key) {
            $this->assertSame(0, Capability::query()->where('key', $key)->count(), "'{$key}' must not exist -- out of scope for Phase 10A.");
        }
    }

    // --- Default role grants -----------------------------------------------

    #[Test]
    public function school_admin_holds_all_four_library_capabilities(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['library.catalogue.view', $school]));
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['library.catalogue.manage', $school]));
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['library.circulation.view', $school]));
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['library.circulation.manage', $school]));
    }

    #[Test]
    public function principal_holds_all_four_library_capabilities(): void
    {
        [$user, $school] = $this->createSchoolAdmin('principal');

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['library.catalogue.view', $school]));
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['library.catalogue.manage', $school]));
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['library.circulation.view', $school]));
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['library.circulation.manage', $school]));
    }

    #[Test]
    public function a_member_without_any_role_is_denied_all_library_capabilities(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);

        $this->assertFalse(Gate::forUser($user)->allows('capability', ['library.catalogue.view', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['library.catalogue.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['library.circulation.view', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['library.circulation.manage', $school]));
    }

    #[Test]
    public function an_unrelated_capability_never_accidentally_grants_library_access(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['communications.view', 'hr.employees.view']);

        $this->assertFalse(Gate::forUser($user)->allows('capability', ['library.catalogue.view', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['library.circulation.manage', $school]));
    }

    // --- Catalogue/circulation independence, and view/manage independence --

    #[Test]
    public function catalogue_manage_does_not_imply_circulation_manage(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['library.catalogue.manage']);

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['library.catalogue.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['library.circulation.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['library.circulation.view', $school]));
    }

    #[Test]
    public function circulation_manage_does_not_imply_catalogue_manage(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['library.circulation.manage']);

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['library.circulation.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['library.catalogue.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['library.catalogue.view', $school]));
    }

    #[Test]
    public function library_catalogue_view_does_not_imply_manage(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['library.catalogue.view']);

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['library.catalogue.view', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['library.catalogue.manage', $school]));
    }

    #[Test]
    public function library_circulation_view_does_not_imply_manage(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['library.circulation.view']);

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['library.circulation.view', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['library.circulation.manage', $school]));
    }

    // --- Tenant isolation (wrong-School actor) ------------------------------

    #[Test]
    public function a_library_capability_in_school_a_does_not_grant_access_in_school_b(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['library.catalogue.manage', $schoolA]));
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['library.circulation.manage', $schoolA]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['library.catalogue.manage', $schoolB]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['library.circulation.manage', $schoolB]));
    }

    #[Test]
    public function central_user_identity_alone_provides_no_library_access(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();

        $this->assertFalse(app(CapabilityResolver::class)->canInSchool($user, 'library.catalogue.view', $school));
        $this->assertFalse(app(CapabilityResolver::class)->canInSchool($user, 'library.circulation.manage', $school));
    }

    // --- Adding library.* must not disturb existing grants ------------------

    #[Test]
    public function existing_hr_and_student_grants_are_unaffected_by_the_new_library_capabilities(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['students.view', $school]));
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['hr.employees.view', $school]));
    }
}
