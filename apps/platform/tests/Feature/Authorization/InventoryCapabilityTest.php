<?php

namespace Tests\Feature\Authorization;

use App\Models\Capability;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10E: the `inventory.directory.view`/`.manage` and
 * `inventory.stock.view`/`.manage` capability grants
 * (docs/modules/INVENTORY.md "Capabilities"). Mirrors
 * HostelCapabilityTest.php's exact pattern.
 */
class InventoryCapabilityTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const KEYS = [
        'inventory.directory.view', 'inventory.directory.manage',
        'inventory.stock.view', 'inventory.stock.manage',
    ];

    #[Test]
    public function the_capability_catalog_contains_each_inventory_capability_exactly_once(): void
    {
        foreach (self::KEYS as $key) {
            $this->assertSame(1, Capability::query()->where('key', $key)->count(), "Expected exactly one '{$key}' capability row.");
        }
    }

    #[Test]
    public function no_speculative_inventory_capabilities_were_added(): void
    {
        foreach (['inventory.procurement.manage', 'inventory.suppliers.view', 'inventory.costing.manage', 'inventory.assets.manage'] as $key) {
            $this->assertSame(0, Capability::query()->where('key', $key)->count(), "'{$key}' must not exist -- out of scope for Phase 10E.");
        }
    }

    #[Test]
    public function school_admin_holds_all_four_inventory_capabilities(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        foreach (self::KEYS as $key) {
            $this->assertTrue(Gate::forUser($user)->allows('capability', [$key, $school]), "school_admin should hold {$key}.");
        }
    }

    #[Test]
    public function principal_holds_all_four_inventory_capabilities(): void
    {
        [$user, $school] = $this->createSchoolAdmin('principal');

        foreach (self::KEYS as $key) {
            $this->assertTrue(Gate::forUser($user)->allows('capability', [$key, $school]), "principal should hold {$key}.");
        }
    }

    #[Test]
    public function a_member_without_any_role_is_denied_all_inventory_capabilities(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);

        foreach (self::KEYS as $key) {
            $this->assertFalse(Gate::forUser($user)->allows('capability', [$key, $school]), "unassigned member should not hold {$key}.");
        }
    }

    #[Test]
    public function an_unrelated_capability_never_accidentally_grants_inventory_access(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['communications.view', 'hr.employees.view', 'hostel.residency.manage']);

        $this->assertFalse(Gate::forUser($user)->allows('capability', ['inventory.directory.view', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['inventory.stock.manage', $school]));
    }

    #[Test]
    public function directory_manage_does_not_imply_stock_manage(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['inventory.directory.manage']);

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['inventory.directory.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['inventory.stock.manage', $school]));
    }

    #[Test]
    public function stock_manage_does_not_imply_directory_manage(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['inventory.stock.manage']);

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['inventory.stock.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['inventory.directory.manage', $school]));
    }

    #[Test]
    public function view_does_not_imply_manage_for_either_inventory_area(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['inventory.directory.view', 'inventory.stock.view']);

        $this->assertFalse(Gate::forUser($user)->allows('capability', ['inventory.directory.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['inventory.stock.manage', $school]));
    }

    #[Test]
    public function an_inventory_capability_in_school_a_does_not_grant_access_in_school_b(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['inventory.directory.manage', $schoolA]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['inventory.directory.manage', $schoolB]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['inventory.stock.manage', $schoolB]));
    }

    #[Test]
    public function central_user_identity_alone_provides_no_inventory_access(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();

        $this->assertFalse(app(CapabilityResolver::class)->canInSchool($user, 'inventory.directory.view', $school));
        $this->assertFalse(app(CapabilityResolver::class)->canInSchool($user, 'inventory.stock.manage', $school));
    }

    #[Test]
    public function existing_hostel_and_visitor_grants_are_unaffected_by_the_new_inventory_capabilities(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['hostel.residency.manage', $school]));
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['visitor.visits.manage', $school]));
    }
}
