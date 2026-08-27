<?php

namespace Tests\Feature\Authorization;

use App\Models\Capability;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10B: the `transport.routes.view`/`.manage`,
 * `transport.vehicles.view`/`.manage`, and
 * `transport.assignments.view`/`.manage` capability grants
 * (docs/modules/TRANSPORT.md "Capabilities"). Mirrors
 * LibraryCapabilityTest.php's exact pattern.
 */
class TransportCapabilityTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const KEYS = [
        'transport.routes.view', 'transport.routes.manage',
        'transport.vehicles.view', 'transport.vehicles.manage',
        'transport.assignments.view', 'transport.assignments.manage',
    ];

    // --- Catalog integrity ------------------------------------------------

    #[Test]
    public function the_capability_catalog_contains_each_transport_capability_exactly_once(): void
    {
        foreach (self::KEYS as $key) {
            $this->assertSame(1, Capability::query()->where('key', $key)->count(), "Expected exactly one '{$key}' capability row.");
        }
    }

    #[Test]
    public function no_speculative_transport_capabilities_were_added(): void
    {
        foreach (['transport.fees.manage', 'transport.gps.view', 'transport.maintenance.manage', 'transport.attendance.manage'] as $key) {
            $this->assertSame(0, Capability::query()->where('key', $key)->count(), "'{$key}' must not exist -- out of scope for Phase 10B.");
        }
    }

    // --- Default role grants -----------------------------------------------

    #[Test]
    public function school_admin_holds_all_six_transport_capabilities(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        foreach (self::KEYS as $key) {
            $this->assertTrue(Gate::forUser($user)->allows('capability', [$key, $school]), "school_admin should hold {$key}.");
        }
    }

    #[Test]
    public function principal_holds_all_six_transport_capabilities(): void
    {
        [$user, $school] = $this->createSchoolAdmin('principal');

        foreach (self::KEYS as $key) {
            $this->assertTrue(Gate::forUser($user)->allows('capability', [$key, $school]), "principal should hold {$key}.");
        }
    }

    #[Test]
    public function a_member_without_any_role_is_denied_all_transport_capabilities(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);

        foreach (self::KEYS as $key) {
            $this->assertFalse(Gate::forUser($user)->allows('capability', [$key, $school]), "unassigned member should not hold {$key}.");
        }
    }

    #[Test]
    public function an_unrelated_capability_never_accidentally_grants_transport_access(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['communications.view', 'hr.employees.view', 'library.circulation.manage']);

        $this->assertFalse(Gate::forUser($user)->allows('capability', ['transport.routes.view', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['transport.vehicles.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['transport.assignments.manage', $school]));
    }

    // --- Area/view-manage independence --------------------------------------

    #[Test]
    public function routes_manage_does_not_imply_vehicles_or_assignments_manage(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['transport.routes.manage']);

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['transport.routes.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['transport.vehicles.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['transport.assignments.manage', $school]));
    }

    #[Test]
    public function vehicles_manage_does_not_imply_routes_or_assignments_manage(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['transport.vehicles.manage']);

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['transport.vehicles.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['transport.routes.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['transport.assignments.manage', $school]));
    }

    #[Test]
    public function assignments_manage_does_not_imply_routes_or_vehicles_manage(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['transport.assignments.manage']);

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['transport.assignments.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['transport.routes.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['transport.vehicles.manage', $school]));
    }

    #[Test]
    public function view_does_not_imply_manage_for_any_transport_area(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['transport.routes.view', 'transport.vehicles.view', 'transport.assignments.view']);

        $this->assertFalse(Gate::forUser($user)->allows('capability', ['transport.routes.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['transport.vehicles.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['transport.assignments.manage', $school]));
    }

    // --- Tenant isolation (wrong-School actor) ------------------------------

    #[Test]
    public function a_transport_capability_in_school_a_does_not_grant_access_in_school_b(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['transport.routes.manage', $schoolA]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['transport.routes.manage', $schoolB]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['transport.vehicles.manage', $schoolB]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['transport.assignments.manage', $schoolB]));
    }

    #[Test]
    public function central_user_identity_alone_provides_no_transport_access(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();

        $this->assertFalse(app(CapabilityResolver::class)->canInSchool($user, 'transport.routes.view', $school));
        $this->assertFalse(app(CapabilityResolver::class)->canInSchool($user, 'transport.assignments.manage', $school));
    }

    // --- Adding transport.* must not disturb existing grants ----------------

    #[Test]
    public function existing_library_and_hr_grants_are_unaffected_by_the_new_transport_capabilities(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['library.circulation.manage', $school]));
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['hr.employees.view', $school]));
    }
}
