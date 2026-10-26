<?php

namespace Tests\Feature\Authorization\Campus;

use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
use App\Support\Authorization\Campus\CampusScopeResolver;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Authorization\ConsumerCapabilities;
use App\Support\Navigation\NavigationBuilder;
use App\Support\Tenancy\TenantContext;
use Database\Factories\SchoolFactory;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.7A (ADR 0076 §11.3-§11.7, §33): how a Campus-scoped grant resolves.
 * It never satisfies the whole-School Gate / can() / schoolCapabilities();
 * it confers only ADOPTED capabilities, only in ACTIVE Campuses, and only
 * through CampusScopeResolver -- under every other gate (enabled user, active
 * membership, module entitlement).
 */
class CampusScopeResolutionTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->a = $this->createCampus($this->school);
        $this->b = $this->createCampus($this->school);
    }

    private function member(): array
    {
        $user = $this->createUser();

        return [$user, $this->createMembership($user, $this->school)];
    }

    private function scope(User $user, string $capability = 'visitor.visits.view')
    {
        return app(CampusScopeResolver::class)->campusScope($user, $capability, $this->school);
    }

    #[Test]
    public function a_campus_grant_never_satisfies_a_whole_school_check(): void
    {
        [$user, $membership] = $this->member();
        $this->assignSchoolRole($membership, 'front_office', $this->a->id);
        $resolver = app(CapabilityResolver::class);

        // ORG.10-R1 (OD-9.7): only the two scope-independent consumer keys
        // (own Communications, the School calendar) are activated School-wide.
        $this->assertEqualsCanonicalizing(ConsumerCapabilities::SCOPE_INDEPENDENT, $resolver->schoolCapabilities($user, $this->school));
        $this->assertFalse($resolver->canInSchool($user, 'visitor.visits.view', $this->school));
        $this->assertFalse(Gate::forUser($user)->allows('capability', ['visitor.visits.view', $this->school]));
        $this->assertTrue(Gate::forUser($user)->allows('campus-capability', ['visitor.visits.view', $this->school]));

        $scope = $this->scope($user);
        $this->assertFalse($scope->isWholeSchool());
        $this->assertSame([$this->a->id], $scope->campusIds());
        $this->assertTrue($scope->covers($this->a->id));
        $this->assertFalse($scope->covers($this->b->id));
        $this->assertFalse($scope->covers(null), 'a School-wide record is never covered by a Campus scope');
    }

    #[Test]
    public function unadopted_capabilities_of_a_campus_scoped_role_confer_nothing(): void
    {
        [$user, $membership] = $this->member();
        $this->assignSchoolRole($membership, 'front_office', $this->a->id);

        // front_office carries the Visitor directory too -- not adopted.
        $this->assertFalse(CampusScopeCatalogue::isAdopted('visitor.directory.view'));
        $this->assertTrue($this->scope($user, 'visitor.directory.view')->isNone());
        $this->assertFalse(Gate::forUser($user)->allows('campus-capability', ['visitor.directory.view', $this->school]));
    }

    #[Test]
    public function grants_combine_as_a_union_and_whole_school_supersedes(): void
    {
        [$user, $membership] = $this->member();
        $this->assignSchoolRole($membership, 'front_office', $this->a->id);
        $this->assignSchoolRole($membership, 'front_office', $this->b->id);
        $this->assertEqualsCanonicalizing([$this->a->id, $this->b->id], $this->scope($user)->campusIds());

        $whole = $this->assignSchoolRole($membership, 'front_office');
        app(CapabilityResolver::class)->forgetCache($user, $this->school);
        $this->assertTrue($this->scope($user)->isWholeSchool());

        // Revoking the whole-School grant leaves the Campus grants.
        app(TenantContext::class)->withSchool($this->school, fn () => $whole->forceFill(['revoked_at' => now(), 'revocation_reason' => 'revoked'])->save());
        app(CapabilityResolver::class)->forgetCache($user, $this->school);
        $this->assertEqualsCanonicalizing([$this->a->id, $this->b->id], $this->scope($user)->campusIds());
    }

    #[Test]
    public function inactive_campus_suspended_membership_disabled_user_revocation_and_module_all_fail_closed(): void
    {
        [$user, $membership] = $this->member();
        $grant = $this->assignSchoolRole($membership, 'front_office', $this->a->id);
        $this->assertFalse($this->scope($user)->isNone());

        // Inactive Campus: immediately nothing (status re-read every call); reactivation restores.
        app(TenantContext::class)->withSchool($this->school, fn () => $this->a->forceFill(['status' => 'inactive'])->save());
        $this->assertTrue($this->scope($user)->isNone());
        app(TenantContext::class)->withSchool($this->school, fn () => $this->a->forceFill(['status' => 'active'])->save());
        $this->assertFalse($this->scope($user)->isNone());

        // Module disabled: nothing; enabled again: back.
        SchoolFactory::setOptionalModules($this->school, false, ['visitor']);
        $this->assertTrue($this->scope($user)->isNone());
        SchoolFactory::setOptionalModules($this->school, true, ['visitor']);
        $this->assertFalse($this->scope($user)->isNone());

        // Suspended membership.
        app(TenantContext::class)->withSchool($this->school, fn () => $membership->forceFill(['status' => SchoolMembership::STATUS_SUSPENDED])->save());
        app(CapabilityResolver::class)->forgetCache($user, $this->school);
        $this->assertTrue($this->scope($user)->isNone());
        app(TenantContext::class)->withSchool($this->school, fn () => $membership->forceFill(['status' => SchoolMembership::STATUS_ACTIVE])->save());
        app(CapabilityResolver::class)->forgetCache($user, $this->school);

        // Disabled user.
        $user->forceFill(['is_disabled' => true])->save();
        $this->assertTrue($this->scope($user->fresh())->isNone());
        $user->forceFill(['is_disabled' => false])->save();

        // Revocation (cache forgotten, as every revoke path does).
        app(TenantContext::class)->withSchool($this->school, fn () => $grant->forceFill(['revoked_at' => now(), 'revocation_reason' => 'revoked'])->save());
        app(CapabilityResolver::class)->forgetCache($user, $this->school);
        $this->assertTrue($this->scope($user->fresh())->isNone());
    }

    #[Test]
    public function navigation_shows_adopted_areas_only_and_authorizes_nothing(): void
    {
        [$user, $membership] = $this->member();
        $this->assignSchoolRole($membership, 'front_office', $this->a->id);

        $keys = array_map(fn ($entry) => $entry->key, app(NavigationBuilder::class)->visibleEntries($user, $this->school, false));
        $this->assertContains('visitor-visits', $keys, 'an adopted area held in a Campus is discoverable');
        $this->assertNotContains('visitor-directory', $keys, 'an unadopted area of the same role is not');
    }

    #[Test]
    public function existing_unscoped_grants_resolve_exactly_as_before(): void
    {
        [$user, $membership] = $this->member();
        $this->assignSchoolRole($membership, 'front_office');

        $this->assertContains('visitor.directory.view', app(CapabilityResolver::class)->schoolCapabilities($user, $this->school));
        $this->assertTrue($this->scope($user)->isWholeSchool());
        $this->assertSame([], app(CapabilityResolver::class)->campusCapabilities($user, $this->school));
    }
}
