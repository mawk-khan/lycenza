<?php

namespace Tests\Feature\Platform\Groups;

use App\Domain\Platform\Application\Groups\SchoolGroupGovernanceService;
use App\Models\GroupRoleAssignment;
use App\Models\PlatformAuditEvent;
use App\Models\SchoolGroup;
use App\Models\SchoolMembership;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Concerns\CreatesSchoolDomains;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0N.5 (ADR 0045 sections 5, 8, 11): the platform-governed School
 * Group layer -- every operation, its capability gate, its audit event,
 * and that nothing here touches School memberships or tenant data.
 */
class SchoolGroupGovernanceTest extends TestCase
{
    use CreatesSchoolDomains, CreatesTenancyFixtures, GroupTestHelpers;

    private function governance(): SchoolGroupGovernanceService
    {
        return app(SchoolGroupGovernanceService::class);
    }

    /**
     * @return list<array{0: string, 1: array<string, mixed>|null, 2: string|null}>
     */
    private function events(string $prefix): array
    {
        return PlatformAuditEvent::query()->where('event_type', 'like', $prefix.'%')->orderBy('occurred_at')->orderBy('id')->get()
            ->map(fn (PlatformAuditEvent $e) => [$e->event_type, $e->metadata, $e->subject_id])->all();
    }

    #[Test]
    public function a_platform_admin_creates_renames_and_populates_a_group_by_exact_identifiers_with_audit(): void
    {
        $admin = $this->platformAdmin();
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $this->createSchoolDomain($schoolB, 'erp.b-school.org');

        $group = $this->governance()->create($admin, 'North Trust', 'north-trust');
        $this->governance()->rename($admin, $group, 'North Education Trust');
        $this->governance()->addSchool($admin, $group, $schoolA->id);
        $this->governance()->addSchool($admin, $group, 'erp.b-school.org');

        $this->assertSame('North Education Trust', $group->fresh()->name);
        $this->assertSame('active', $group->fresh()->status);
        $this->assertEqualsCanonicalizing([$schoolA->id, $schoolB->id], $group->schools()->pluck('schools.id')->all());

        $this->assertEquals([
            ['platform.school_group.created', [], $group->id],
            ['platform.school_group.renamed', [], $group->id],
            ['platform.school_group.school_added', ['school_id' => $schoolA->id], $group->id],
            ['platform.school_group.school_added', ['school_id' => $schoolB->id], $group->id],
        ], $this->events('platform.school_group.'));

        foreach (PlatformAuditEvent::query()->where('event_type', 'like', 'platform.school_group%')->get() as $event) {
            $this->assertStringNotContainsString('North', json_encode($event->metadata), 'Group names are never copied into audit.');
        }
    }

    #[Test]
    public function exact_identifiers_only_and_no_duplicate_pairs_but_a_school_may_be_in_several_groups(): void
    {
        $admin = $this->platformAdmin();
        $school = $this->createSchool(['name' => 'Exact School']);
        $groupA = $this->governance()->create($admin, 'A', 'a-trust');
        $groupB = $this->governance()->create($admin, 'B', 'b-trust');

        foreach (['Exact School', 'exact', (string) Str::uuid()] as $wrong) {
            try {
                $this->governance()->addSchool($admin, $groupA, $wrong);
                $this->fail("{$wrong} must not resolve");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('school', $e->errors());
            }
        }

        $this->governance()->addSchool($admin, $groupA, $school->id);
        $this->governance()->addSchool($admin, $groupB, $school->id);

        $this->expectException(ValidationException::class);
        $this->governance()->addSchool($admin, $groupA, $school->id);
    }

    #[Test]
    public function grants_are_by_exact_person_never_to_oneself_and_one_active_per_role(): void
    {
        $admin = $this->platformAdmin();
        $person = $this->createUser(['email' => 'trust.lead@example.test']);
        $group = $this->governance()->create($admin, 'G', 'g-trust');

        try {
            $this->governance()->grant($admin, $group, $admin->email);
            $this->fail('A self-grant must be refused.');
        } catch (ValidationException $e) {
            $this->assertSame(['You cannot grant Group authority to yourself.'], $e->errors()['user']);
        }

        try {
            $this->governance()->grant($admin, $group, 'trust.lead');
            $this->fail('A partial email must not resolve.');
        } catch (ValidationException) {
        }

        $grant = $this->governance()->grant($admin, $group, 'TRUST.LEAD@example.test');
        $this->assertSame($person->id, $grant->user_id);
        $this->assertSame($admin->id, $grant->granted_by_user_id);

        try {
            $this->governance()->grant($admin, $group, $person->id);
            $this->fail('A second active grant of the same role must be refused.');
        } catch (ValidationException) {
        }

        [$granted] = $this->events('platform.school_group_grant.granted');
        $this->assertEquals(['school_group_id' => $group->id, 'user_id' => $person->id, 'role_key' => 'group_admin'], $granted[1]);
        $this->assertSame($grant->id, $granted[2]);

        // Revoke keeps history; a new grant is a new row.
        $this->governance()->revoke($admin, $grant);
        $this->assertNotNull($grant->fresh()->revoked_at);
        $this->assertSame($admin->id, $grant->fresh()->revoked_by_user_id);
        $again = $this->governance()->grant($admin, $group, $person->email);
        $this->assertNotSame($grant->id, $again->id);
        $this->assertSame(2, GroupRoleAssignment::query()->where('user_id', $person->id)->count());

        $this->expectException(ValidationException::class);
        $this->governance()->revoke($admin, $grant->fresh());
    }

    #[Test]
    public function archiving_revokes_every_grant_blocks_new_grants_and_members_and_keeps_the_group(): void
    {
        $admin = $this->platformAdmin();
        $school = $this->createSchool();
        $group = $this->createGroup([$school]);
        $grantA = $this->grantGroupRole($this->createUser(), $group);
        $grantB = $this->grantGroupRole($this->createUser(), $group);

        $this->governance()->archive($admin, $group);

        $this->assertSame('archived', $group->fresh()->status);
        $this->assertNotNull($grantA->fresh()->revoked_at);
        $this->assertNotNull($grantB->fresh()->revoked_at);
        $this->assertTrue(SchoolGroup::query()->whereKey($group->id)->exists());
        $this->assertSame([$school->id], $group->schools()->pluck('schools.id')->all(), 'Membership history is not erased.');

        [$archived] = $this->events('platform.school_group.archived');
        $this->assertEquals(['revoked_grant_count' => 2, 'terminated_elevation_count' => 0], $archived[1]);
        $this->assertCount(2, $this->events('platform.school_group_grant.revoked'));

        foreach ([
            fn () => $this->governance()->grant($admin, $group, $this->createUser()->email),
            fn () => $this->governance()->addSchool($admin, $group, $this->createSchool()->id),
            fn () => $this->governance()->rename($admin, $group, 'X'),
            fn () => $this->governance()->archive($admin, $group),
        ] as $operation) {
            try {
                $operation();
                $this->fail('An archived Group accepts no change.');
            } catch (ValidationException) {
            }
        }
    }

    #[Test]
    public function removing_a_school_touches_no_membership_role_or_other_group(): void
    {
        $admin = $this->platformAdmin();
        $school = $this->createSchool();
        $member = $this->createUser();
        $this->createMembership($member, $school);
        $groupA = $this->createGroup([$school]);
        $groupB = $this->createGroup([$school]);

        $this->governance()->removeSchool($admin, $groupA, $school);

        $this->assertSame([], $groupA->schools()->pluck('schools.id')->all());
        $this->assertSame([$school->id], $groupB->schools()->pluck('schools.id')->all());
        $this->assertSame(1, SchoolMembership::query()->where('user_id', $member->id)->where('school_id', $school->id)->count());
        [$removed] = $this->events('platform.school_group.school_removed');
        $this->assertEquals(['school_id' => $school->id, 'terminated_elevation_count' => 0], $removed[1]);

        $this->expectException(ValidationException::class);
        $this->governance()->removeSchool($admin, $groupA, $school);
    }

    #[Test]
    public function only_the_platform_governance_capabilities_reach_any_operation(): void
    {
        $school = $this->createSchool();
        $group = $this->createGroup([$school]);
        $groupAdmin = $this->groupAdmin($group);
        [$schoolAdmin] = $this->createSchoolAdmin('school_admin');

        foreach ([$groupAdmin, $schoolAdmin, $this->createUser()] as $actor) {
            foreach ([
                fn () => $this->governance()->create($actor, 'X', 'x-trust'),
                fn () => $this->governance()->rename($actor, $group, 'X'),
                fn () => $this->governance()->archive($actor, $group),
                fn () => $this->governance()->addSchool($actor, $group, $this->createSchool()->id),
                fn () => $this->governance()->removeSchool($actor, $group, $school),
                fn () => $this->governance()->grant($actor, $group, $this->createUser()->email),
                fn () => $this->governance()->revoke($actor, GroupRoleAssignment::query()->firstOrFail()),
            ] as $operation) {
                try {
                    $operation();
                    $this->fail('Refused without the platform capability.');
                } catch (AccessDeniedHttpException) {
                }
            }

            // And over HTTP.
            $this->actingAs($actor);
            $this->get('/app/platform/groups')->assertForbidden();
            $this->post('/app/platform/groups', ['name' => 'X', 'slug' => 'x-http'])->assertForbidden();
            $this->post("/app/platform/groups/{$group->id}/schools", ['school' => $this->createSchool()->id])->assertForbidden();
            $this->delete("/app/platform/groups/{$group->id}/schools/{$school->id}")->assertForbidden();
            $this->post("/app/platform/groups/{$group->id}/grants", ['user' => $this->createUser()->email])->assertForbidden();
        }

        $this->assertSame([$school->id], $group->schools()->pluck('schools.id')->all());
        $this->assertSame(1, GroupRoleAssignment::query()->where('school_group_id', $group->id)->count());
    }

    #[Test]
    public function the_platform_pages_show_grants_only_to_grant_managers_and_do_not_make_the_operator_a_group_admin(): void
    {
        $admin = $this->platformAdmin();
        $school = $this->createSchool(['name' => 'Listed School']);
        $group = $this->createGroup([$school], 'Listed Trust');
        $this->grantGroupRole($this->createUser(['email' => 'holder@example.test']), $group, grantor: $admin);

        $this->actingAs($admin)->get('/app/platform/groups')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('App/Platform/Groups/Index')
            ->where('groups.0.name', 'Listed Trust')
            ->where('canManage', true)
        );
        $this->get("/app/platform/groups/{$group->id}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('schools.0.name', 'Listed School')
            ->where('grants.0.userEmail', 'holder@example.test')
            ->where('canManageGrants', true)
        );

        // A viewer without grant management sees no grant list.
        DB::table('role_capabilities')->where('capability_key', 'platform.school_group_grants.manage')->delete();
        app(CapabilityResolver::class)->forgetCache($admin);
        $this->get("/app/platform/groups/{$group->id}")->assertInertia(fn (AssertableInertia $p) => $p->where('grants', null));

        // Platform governance is not Group authority.
        $this->assertSame([], app(CapabilityResolver::class)->groupCapabilities($admin, $group));
        $this->get("/app/groups/{$group->id}")->assertNotFound();
    }
}
