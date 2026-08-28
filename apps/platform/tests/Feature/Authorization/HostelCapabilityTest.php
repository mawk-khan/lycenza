<?php

namespace Tests\Feature\Authorization;

use App\Models\Capability;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10D: the `hostel.directory.view`/`.manage` and
 * `hostel.residency.view`/`.manage` capability grants
 * (docs/modules/HOSTEL.md "Capabilities"). Mirrors
 * VisitorCapabilityTest.php's exact pattern.
 */
class HostelCapabilityTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const KEYS = [
        'hostel.directory.view', 'hostel.directory.manage',
        'hostel.residency.view', 'hostel.residency.manage',
    ];

    #[Test]
    public function the_capability_catalog_contains_each_hostel_capability_exactly_once(): void
    {
        foreach (self::KEYS as $key) {
            $this->assertSame(1, Capability::query()->where('key', $key)->count(), "Expected exactly one '{$key}' capability row.");
        }
    }

    #[Test]
    public function no_speculative_hostel_capabilities_were_added(): void
    {
        foreach (['hostel.fees.manage', 'hostel.billing.view', 'hostel.warden.manage', 'hostel.meal_plan.manage'] as $key) {
            $this->assertSame(0, Capability::query()->where('key', $key)->count(), "'{$key}' must not exist -- out of scope for Phase 10D.");
        }
    }

    #[Test]
    public function school_admin_holds_all_four_hostel_capabilities(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        foreach (self::KEYS as $key) {
            $this->assertTrue(Gate::forUser($user)->allows('capability', [$key, $school]), "school_admin should hold {$key}.");
        }
    }

    #[Test]
    public function principal_holds_all_four_hostel_capabilities(): void
    {
        [$user, $school] = $this->createSchoolAdmin('principal');

        foreach (self::KEYS as $key) {
            $this->assertTrue(Gate::forUser($user)->allows('capability', [$key, $school]), "principal should hold {$key}.");
        }
    }

    #[Test]
    public function a_member_without_any_role_is_denied_all_hostel_capabilities(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);

        foreach (self::KEYS as $key) {
            $this->assertFalse(Gate::forUser($user)->allows('capability', [$key, $school]), "unassigned member should not hold {$key}.");
        }
    }

    #[Test]
    public function an_unrelated_capability_never_accidentally_grants_hostel_access(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['communications.view', 'hr.employees.view', 'visitor.visits.manage']);

        $this->assertFalse(Gate::forUser($user)->allows('capability', ['hostel.directory.view', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['hostel.residency.manage', $school]));
    }

    #[Test]
    public function directory_manage_does_not_imply_residency_manage(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['hostel.directory.manage']);

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['hostel.directory.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['hostel.residency.manage', $school]));
    }

    #[Test]
    public function residency_manage_does_not_imply_directory_manage(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['hostel.residency.manage']);

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['hostel.residency.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['hostel.directory.manage', $school]));
    }

    #[Test]
    public function view_does_not_imply_manage_for_either_hostel_area(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['hostel.directory.view', 'hostel.residency.view']);

        $this->assertFalse(Gate::forUser($user)->allows('capability', ['hostel.directory.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['hostel.residency.manage', $school]));
    }

    #[Test]
    public function a_hostel_capability_in_school_a_does_not_grant_access_in_school_b(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['hostel.directory.manage', $schoolA]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['hostel.directory.manage', $schoolB]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['hostel.residency.manage', $schoolB]));
    }

    #[Test]
    public function central_user_identity_alone_provides_no_hostel_access(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();

        $this->assertFalse(app(CapabilityResolver::class)->canInSchool($user, 'hostel.directory.view', $school));
        $this->assertFalse(app(CapabilityResolver::class)->canInSchool($user, 'hostel.residency.manage', $school));
    }

    #[Test]
    public function existing_visitor_and_transport_grants_are_unaffected_by_the_new_hostel_capabilities(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['visitor.visits.manage', $school]));
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['transport.assignments.manage', $school]));
    }
}
