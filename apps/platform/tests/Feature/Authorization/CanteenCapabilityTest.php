<?php

namespace Tests\Feature\Authorization;

use App\Models\Capability;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10F: the `canteen.directory.view`/`.manage`,
 * `canteen.orders.view`/`.manage`, and `canteen.settings.view`/`.manage`
 * capability grants. Mirrors InventoryCapabilityTest.php's exact
 * pattern, extended for the third (`settings`) pair which -- unlike
 * `directory`/`orders` -- is deliberately NOT granted to Principal
 * (mirrors `finance.charges.*`'s existing precedent).
 */
class CanteenCapabilityTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const KEYS = [
        'canteen.directory.view', 'canteen.directory.manage',
        'canteen.orders.view', 'canteen.orders.manage',
        'canteen.settings.view', 'canteen.settings.manage',
    ];

    private const SCHOOL_ADMIN_KEYS = self::KEYS;

    private const PRINCIPAL_KEYS = [
        'canteen.directory.view', 'canteen.directory.manage',
        'canteen.orders.view', 'canteen.orders.manage',
    ];

    #[Test]
    public function the_capability_catalog_contains_each_canteen_capability_exactly_once(): void
    {
        foreach (self::KEYS as $key) {
            $this->assertSame(1, Capability::query()->where('key', $key)->count(), "Expected exactly one '{$key}' capability row.");
        }
    }

    #[Test]
    public function no_speculative_canteen_capabilities_were_added(): void
    {
        foreach (['canteen.recipes.manage', 'canteen.reports.view', 'canteen.suppliers.manage', 'canteen.orders.refund'] as $key) {
            $this->assertSame(0, Capability::query()->where('key', $key)->count(), "'{$key}' must not exist -- out of scope for Phase 10F.");
        }
    }

    #[Test]
    public function school_admin_holds_all_six_canteen_capabilities(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        foreach (self::SCHOOL_ADMIN_KEYS as $key) {
            $this->assertTrue(Gate::forUser($user)->allows('capability', [$key, $school]), "school_admin should hold {$key}.");
        }
    }

    #[Test]
    public function principal_holds_directory_and_orders_but_not_settings(): void
    {
        [$user, $school] = $this->createSchoolAdmin('principal');

        foreach (self::PRINCIPAL_KEYS as $key) {
            $this->assertTrue(Gate::forUser($user)->allows('capability', [$key, $school]), "principal should hold {$key}.");
        }

        $this->assertFalse(Gate::forUser($user)->allows('capability', ['canteen.settings.view', $school]), 'principal should NOT hold canteen.settings.view.');
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['canteen.settings.manage', $school]), 'principal should NOT hold canteen.settings.manage.');
    }

    #[Test]
    public function a_member_without_any_role_is_denied_all_canteen_capabilities(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);

        foreach (self::KEYS as $key) {
            $this->assertFalse(Gate::forUser($user)->allows('capability', [$key, $school]), "unassigned member should not hold {$key}.");
        }
    }

    #[Test]
    public function an_unrelated_capability_never_accidentally_grants_canteen_access(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['communications.view', 'hr.employees.view', 'inventory.stock.manage']);

        $this->assertFalse(Gate::forUser($user)->allows('capability', ['canteen.directory.view', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['canteen.orders.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['canteen.settings.manage', $school]));
    }

    #[Test]
    public function directory_manage_does_not_imply_orders_manage(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['canteen.directory.manage']);

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['canteen.directory.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['canteen.orders.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['canteen.settings.manage', $school]));
    }

    #[Test]
    public function orders_manage_alone_is_sufficient_and_does_not_imply_directory_or_settings_manage(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['canteen.orders.manage']);

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['canteen.orders.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['canteen.directory.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['canteen.settings.manage', $school]));
    }

    #[Test]
    public function settings_manage_does_not_imply_directory_or_orders_manage(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['canteen.settings.manage']);

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['canteen.settings.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['canteen.directory.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['canteen.orders.manage', $school]));
    }

    #[Test]
    public function view_does_not_imply_manage_for_any_canteen_area(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['canteen.directory.view', 'canteen.orders.view', 'canteen.settings.view']);

        $this->assertFalse(Gate::forUser($user)->allows('capability', ['canteen.directory.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['canteen.orders.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['canteen.settings.manage', $school]));
    }

    #[Test]
    public function a_canteen_capability_in_school_a_does_not_grant_access_in_school_b(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['canteen.orders.manage', $schoolA]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['canteen.orders.manage', $schoolB]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['canteen.settings.manage', $schoolB]));
    }

    #[Test]
    public function central_user_identity_alone_provides_no_canteen_access(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();

        $this->assertFalse(app(CapabilityResolver::class)->canInSchool($user, 'canteen.directory.view', $school));
        $this->assertFalse(app(CapabilityResolver::class)->canInSchool($user, 'canteen.orders.manage', $school));
    }

    #[Test]
    public function existing_inventory_and_finance_grants_are_unaffected_by_the_new_canteen_capabilities(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['inventory.stock.manage', $school]));
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['finance.charges.manage', $school]));
    }
}
