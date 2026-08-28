<?php

namespace Tests\Feature\Authorization;

use App\Models\Capability;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10C: the `visitor.directory.view`/`.manage` and
 * `visitor.visits.view`/`.manage` capability grants
 * (docs/modules/VISITOR.md "Capabilities"). Mirrors
 * TransportCapabilityTest.php's exact pattern.
 */
class VisitorCapabilityTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const KEYS = [
        'visitor.directory.view', 'visitor.directory.manage',
        'visitor.visits.view', 'visitor.visits.manage',
    ];

    #[Test]
    public function the_capability_catalog_contains_each_visitor_capability_exactly_once(): void
    {
        foreach (self::KEYS as $key) {
            $this->assertSame(1, Capability::query()->where('key', $key)->count(), "Expected exactly one '{$key}' capability row.");
        }
    }

    #[Test]
    public function no_speculative_visitor_capabilities_were_added(): void
    {
        foreach (['visitor.blocklist.manage', 'visitor.watchlist.view', 'visitor.risk.manage', 'visitor.safety.manage'] as $key) {
            $this->assertSame(0, Capability::query()->where('key', $key)->count(), "'{$key}' must not exist -- out of scope for Phase 10C.");
        }
    }

    #[Test]
    public function school_admin_holds_all_four_visitor_capabilities(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        foreach (self::KEYS as $key) {
            $this->assertTrue(Gate::forUser($user)->allows('capability', [$key, $school]), "school_admin should hold {$key}.");
        }
    }

    #[Test]
    public function principal_holds_all_four_visitor_capabilities(): void
    {
        [$user, $school] = $this->createSchoolAdmin('principal');

        foreach (self::KEYS as $key) {
            $this->assertTrue(Gate::forUser($user)->allows('capability', [$key, $school]), "principal should hold {$key}.");
        }
    }

    #[Test]
    public function a_member_without_any_role_is_denied_all_visitor_capabilities(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);

        foreach (self::KEYS as $key) {
            $this->assertFalse(Gate::forUser($user)->allows('capability', [$key, $school]), "unassigned member should not hold {$key}.");
        }
    }

    #[Test]
    public function an_unrelated_capability_never_accidentally_grants_visitor_access(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['communications.view', 'hr.employees.view', 'transport.assignments.manage']);

        $this->assertFalse(Gate::forUser($user)->allows('capability', ['visitor.directory.view', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['visitor.visits.manage', $school]));
    }

    #[Test]
    public function directory_manage_does_not_imply_visits_manage(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['visitor.directory.manage']);

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['visitor.directory.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['visitor.visits.manage', $school]));
    }

    #[Test]
    public function visits_manage_does_not_imply_directory_manage(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['visitor.visits.manage']);

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['visitor.visits.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['visitor.directory.manage', $school]));
    }

    #[Test]
    public function view_does_not_imply_manage_for_either_visitor_area(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['visitor.directory.view', 'visitor.visits.view']);

        $this->assertFalse(Gate::forUser($user)->allows('capability', ['visitor.directory.manage', $school]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['visitor.visits.manage', $school]));
    }

    #[Test]
    public function a_visitor_capability_in_school_a_does_not_grant_access_in_school_b(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['visitor.directory.manage', $schoolA]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['visitor.directory.manage', $schoolB]));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['visitor.visits.manage', $schoolB]));
    }

    #[Test]
    public function central_user_identity_alone_provides_no_visitor_access(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();

        $this->assertFalse(app(CapabilityResolver::class)->canInSchool($user, 'visitor.directory.view', $school));
        $this->assertFalse(app(CapabilityResolver::class)->canInSchool($user, 'visitor.visits.manage', $school));
    }

    #[Test]
    public function existing_transport_and_library_grants_are_unaffected_by_the_new_visitor_capabilities(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $this->assertTrue(Gate::forUser($user)->allows('capability', ['transport.assignments.manage', $school]));
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['library.circulation.manage', $school]));
    }
}
