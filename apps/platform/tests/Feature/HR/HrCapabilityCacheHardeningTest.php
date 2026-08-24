<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeDirectoryQuery;
use App\Domain\HR\Application\EmployeeDirectoryService;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\SchoolMembership;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.15 -- REQUIRED CapabilityResolver P3 deep-review evidence
 * (checkpoint brief sections 18-23/68). This is HR's exposure of a
 * SHARED, pre-existing platform finding (docs/security/AUTHORIZATION.md's
 * own documented 60-second cache TTL) -- not a new HR bug, and not
 * something HR can fix in isolation.
 *
 * Conclusion reached, with evidence gathered below and via repo-wide
 * inspection: **P3 IS RETAINED, not closed**. `CapabilityResolver::forgetCache()`
 * already exists and already works correctly (proven here) -- the
 * missing piece is a PRODUCTION mutation path to invoke it FROM. A
 * repo-wide search found no controller/service anywhere that writes
 * `membership_role_assignments`, changes `school_memberships.status`,
 * or mutates `role_capabilities` -- `docs/security/AUTHORIZATION.md`'s
 * own "What is NOT yet implemented" section confirms this explicitly
 * ("a UI for managing role assignments... only the data model + a
 * seeded system catalog exist"), and
 * `tests/Feature/HR/HrMutationAuthorizationTest.php`'s own
 * `capability_revocation_takes_effect_on_the_next_check()` test already
 * documents, in its own comment, that "this repository has no
 * automatic revoke-triggered invalidation hook yet, so a real caller
 * doing a role change must call this itself." Building a full
 * role/membership-management mutation subsystem to close this would be
 * a broad Identity & Access redesign entirely outside Phase 8A's HR
 * scope, and squarely the kind of feature expansion this hardening
 * checkpoint's own non-negotiables forbid.
 */
class HrCapabilityCacheHardeningTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function cache_keys_are_isolated_per_school_and_per_user_no_cross_school_bleed(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $user = $this->createUser();
        $membershipA = $this->createMembership($user, $schoolA);
        $this->createMembership($user, $schoolB);

        $role = Role::query()->create([
            'key' => 'test.hr_view.'.Str::uuid(), 'name' => 'Test HR View', 'scope' => 'school', 'is_system' => false,
        ]);
        $role->capabilities()->sync(['hr.employees.view']);
        $this->assignSchoolRole($membershipA, $role->key);

        $resolver = app(CapabilityResolver::class);

        $this->assertTrue($resolver->canInSchool($user, 'hr.employees.view', $schoolA));
        $this->assertFalse($resolver->canInSchool($user, 'hr.employees.view', $schoolB), 'A capability granted in School A must never be resolvable in School B for the SAME User -- no cache-key collision.');
    }

    #[Test]
    public function a_granted_capability_is_cached_the_second_check_issues_no_new_query(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        $role = Role::query()->create([
            'key' => 'test.hr_view.'.Str::uuid(), 'name' => 'Test HR View', 'scope' => 'school', 'is_system' => false,
        ]);
        $role->capabilities()->sync(['hr.employees.view']);
        $this->assignSchoolRole($membership, $role->key);

        $resolver = app(CapabilityResolver::class);
        $resolver->canInSchool($user, 'hr.employees.view', $school); // warms the cache

        DB::enableQueryLog();
        $second = $resolver->canInSchool($user, 'hr.employees.view', $school);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertTrue($second);
        $this->assertSame(0, $queryCount, 'A cached capability check must issue zero database queries.');
    }

    #[Test]
    public function revoking_a_role_assignment_without_invalidation_is_the_documented_retained_gap(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.view']);

        // Warm the cache exactly as a real HR API request would.
        app(EmployeeDirectoryService::class)->search($school, new EmployeeDirectoryQuery, $actor);

        // Revoke via the ONLY mechanism this codebase currently has for
        // it -- direct model mutation (there is no production
        // controller/service to call instead; see this class's
        // docblock). Deliberately do NOT call forgetCache() here --
        // this test measures the CURRENT, undocumented-until-now,
        // retained gap itself.
        app(TenantContext::class)->withSchool($school, function () use ($actor, $school) {
            MembershipRoleAssignment::query()
                ->whereHas('membership', fn ($q) => $q->where('user_id', $actor->id)->where('school_id', $school->id))
                ->delete();
        });

        // The stale grant is still served from cache -- this IS the
        // retained P3, demonstrated directly rather than merely
        // asserted.
        $stillCached = app(CapabilityResolver::class)->canInSchool($actor, 'hr.employees.view', $school);
        $this->assertTrue($stillCached, 'Documents the retained P3: without an invalidation call, a revoked grant remains cached for up to 60 seconds.');
    }

    #[Test]
    public function calling_the_existing_forgetcache_after_revocation_denies_immediately(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.view']);

        app(EmployeeDirectoryService::class)->search($school, new EmployeeDirectoryQuery, $actor);

        app(TenantContext::class)->withSchool($school, function () use ($actor, $school) {
            MembershipRoleAssignment::query()
                ->whereHas('membership', fn ($q) => $q->where('user_id', $actor->id)->where('school_id', $school->id))
                ->delete();
        });

        // The mechanism ITSELF is correct and ready -- proves
        // forgetCache() is not broken, only unreached by any
        // production mutation path.
        app(CapabilityResolver::class)->forgetCache($actor, $school);

        $this->expectException(AuthorizationException::class);
        app(EmployeeDirectoryService::class)->search($school, new EmployeeDirectoryQuery, $actor);
    }

    #[Test]
    public function membership_suspension_without_invalidation_is_also_the_retained_gap(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.view']);

        app(EmployeeDirectoryService::class)->search($school, new EmployeeDirectoryQuery, $actor);

        app(TenantContext::class)->withSchool($school, function () use ($actor, $school) {
            SchoolMembership::query()
                ->where('user_id', $actor->id)->where('school_id', $school->id)
                ->update(['status' => 'suspended']);
        });

        $stillCached = app(CapabilityResolver::class)->canInSchool($actor, 'hr.employees.view', $school);
        $this->assertTrue($stillCached, 'Documents the retained P3 for membership suspension too -- the same 60-second window applies.');

        // forgetCache() closes it immediately once invoked, same as
        // the role-revocation case above.
        app(CapabilityResolver::class)->forgetCache($actor, $school);
        $this->assertFalse(app(CapabilityResolver::class)->canInSchool($actor, 'hr.employees.view', $school));
    }

    #[Test]
    public function regrant_after_forgetcache_correctly_resolves_the_new_capability(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $membership = $this->createMembership($actor, $school);
        $resolver = app(CapabilityResolver::class);

        $this->assertFalse($resolver->canInSchool($actor, 'hr.employees.view', $school));

        $role = Role::query()->create([
            'key' => 'test.hr_view.'.Str::uuid(), 'name' => 'Test HR View', 'scope' => 'school', 'is_system' => false,
        ]);
        $role->capabilities()->sync(['hr.employees.view']);
        $this->assignSchoolRole($membership, $role->key);
        $resolver->forgetCache($actor, $school);

        $this->assertTrue($resolver->canInSchool($actor, 'hr.employees.view', $school), 'A regrant, followed by the existing invalidation call, resolves correctly -- the mechanism works in both directions.');
    }
}
