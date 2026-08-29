<?php

namespace Tests\Feature\Authorization;

use App\Models\Capability;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0H (Timetable foundation): the `timetable.periods.view`/
 * `.manage` and `timetable.schedule.view`/`.manage` capability grants.
 * Mirrors InventoryCapabilityTest.php's exact pattern.
 */
class TimetableCapabilityTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const KEYS = [
        'timetable.periods.view', 'timetable.periods.manage',
        'timetable.schedule.view', 'timetable.schedule.manage',
    ];

    #[Test]
    public function the_capability_catalog_contains_each_timetable_capability_exactly_once(): void
    {
        foreach (self::KEYS as $key) {
            $this->assertSame(1, Capability::query()->where('key', $key)->count(), "Expected exactly one '{$key}' capability row.");
        }
    }

    #[Test]
    public function no_speculative_timetable_capabilities_were_added(): void
    {
        foreach (['timetable.rooms.manage', 'timetable.conflicts.view', 'timetable.publish.manage', 'timetable.templates.manage'] as $key) {
            $this->assertSame(0, Capability::query()->where('key', $key)->count(), "'{$key}' must not exist -- out of scope for Phase 0H.");
        }
    }

    #[Test]
    public function school_admin_holds_all_four_timetable_capabilities(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        foreach (self::KEYS as $key) {
            $this->assertTrue(Gate::forUser($user)->allows('capability', [$key, $school]), "school_admin should hold {$key}.");
        }
    }

    #[Test]
    public function principal_holds_all_four_timetable_capabilities(): void
    {
        [$user, $school] = $this->createSchoolAdmin('principal');

        foreach (self::KEYS as $key) {
            $this->assertTrue(Gate::forUser($user)->allows('capability', [$key, $school]), "principal should hold {$key}.");
        }
    }

    #[Test]
    public function a_member_without_any_role_is_denied_all_timetable_capabilities(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);

        foreach (self::KEYS as $key) {
            $this->assertFalse(Gate::forUser($user)->allows('capability', [$key, $school]), "unassigned member should not hold {$key}.");
        }
    }

    #[Test]
    public function an_unrelated_capability_never_accidentally_grants_timetable_access(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['communications.view', 'hr.employees.view', 'inventory.stock.manage']);

        $this->assertFalse(Gate::forUser($user)->allows('capability', ['timetable.periods.view', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['timetable.schedule.manage', $school]));
    }

    #[Test]
    public function periods_manage_does_not_imply_schedule_manage(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['timetable.periods.manage']);

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['timetable.periods.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['timetable.schedule.manage', $school]));
    }

    #[Test]
    public function schedule_manage_does_not_imply_periods_manage(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['timetable.schedule.manage']);

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['timetable.schedule.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['timetable.periods.manage', $school]));
    }

    #[Test]
    public function view_does_not_imply_manage_for_either_timetable_area(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['timetable.periods.view', 'timetable.schedule.view']);

        $this->assertFalse(Gate::forUser($user)->allows('capability', ['timetable.periods.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['timetable.schedule.manage', $school]));
    }

    #[Test]
    public function a_timetable_capability_in_school_a_does_not_grant_access_in_school_b(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['timetable.periods.manage', $schoolA]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['timetable.periods.manage', $schoolB]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['timetable.schedule.manage', $schoolB]));
    }

    #[Test]
    public function central_user_identity_alone_provides_no_timetable_access(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();

        $this->assertFalse(app(CapabilityResolver::class)->canInSchool($user, 'timetable.periods.view', $school));
        $this->assertFalse(app(CapabilityResolver::class)->canInSchool($user, 'timetable.schedule.manage', $school));
    }

    #[Test]
    public function existing_inventory_and_canteen_grants_are_unaffected_by_the_new_timetable_capabilities(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['inventory.stock.manage', $school]));
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['canteen.orders.manage', $school]));
    }
}
