<?php

namespace Tests\Feature\Platform\Groups;

use App\Domain\Platform\Application\Groups\GroupGovernanceAccess;
use App\Domain\Platform\Application\Groups\NetworkAccessService;
use App\Domain\Platform\Application\Groups\NetworkRoleChangeReason;
use App\Domain\Platform\Application\Groups\SchoolGroupDirectoryReadService;
use App\Domain\Platform\Application\Groups\SchoolGroupGovernanceService;
use App\Domain\Platform\Application\Groups\SchoolGroupMembershipReason;
use App\Domain\Platform\Application\Groups\SchoolGroupRelationship;
use App\Domain\Platform\Application\Groups\SchoolGroupStructureService;
use App\Models\GroupRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolGroup;
use App\Models\SchoolGroupRegion;
use App\Models\SchoolGroupZone;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.12E (ADR 0080 §10, OD-12.13/OD-12.14): Network roles on the existing
 * Group grant.
 *
 * - The platform appoints Network Managers; a Network Manager never appoints
 *   another (service AND database trigger).
 * - A whole-Group Network Manager governs its OWN Group's structure and
 *   memberships (never adds a School), and grants/revokes read-only Network
 *   Viewers with a reason and fresh MFA, never to itself.
 * - A Network Viewer reads the whole Group, one Region (direct members and
 *   members of its Zones) or one Zone (exact) -- never unplaced memberships,
 *   never another Group; scope only on a role whose every capability is
 *   group.network.view (database).
 * - No Network role yields a School capability, membership, elevation,
 *   lifecycle or entitlement change.
 */
class NetworkRolesTest extends TestCase
{
    use CreatesTenancyFixtures, GroupTestHelpers;

    private User $root;

    private SchoolGroup $group;

    private SchoolGroupRegion $south;

    private SchoolGroupRegion $north;

    private SchoolGroupZone $hyd;

    /** @var array<string, School> */
    private array $schools = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = $this->createPlatformRoot();
        $this->group = $this->createGroup([], 'Iris Network');
        $structure = app(SchoolGroupStructureService::class);
        $this->south = $structure->createRegion($this->root, $this->group, ['code' => 'SOUTH', 'name' => 'South']);
        $this->north = $structure->createRegion($this->root, $this->group, ['code' => 'NORTH', 'name' => 'North']);
        $this->hyd = $structure->createZone($this->root, $this->group, ['code' => 'HYD', 'name' => 'Hyderabad', 'region_id' => $this->south->id]);

        // direct-south (Region only), hyd (Zone under South), north, unplaced.
        foreach (['direct' => [$this->south->id, null], 'hyd' => [null, $this->hyd->id], 'north' => [$this->north->id, null], 'unplaced' => [null, null]] as $key => [$region, $zone]) {
            $school = $this->createSchool(['name' => 'Iris '.ucfirst($key)]);
            $this->governance()->addSchool($this->root, $this->group, $school->id, SchoolGroupRelationship::FRANCHISE, SchoolGroupMembershipReason::NETWORK_JOIN, $this->freshProof($this->root));
            if ($region !== null || $zone !== null) {
                $this->governance()->placeMembership($this->root, $this->group, $school, $region, $zone, ['region_id' => null, 'zone_id' => null], SchoolGroupMembershipReason::INITIAL_CLASSIFICATION);
            }
            $this->schools[$key] = $school;
        }
    }

    private function governance(): SchoolGroupGovernanceService
    {
        return app(SchoolGroupGovernanceService::class);
    }

    private function network(): NetworkAccessService
    {
        return app(NetworkAccessService::class);
    }

    private function appoint(string $role, ?string $region = null, ?string $zone = null, ?SchoolGroup $group = null): User
    {
        $user = $this->createUser();
        $this->network()->grant($this->root, $group ?? $this->group, $user->email, $role, $region, $zone, NetworkRoleChangeReason::APPOINTMENT, $this->freshProof($this->root));

        return $user;
    }

    /** @return list<string> */
    private function visibleNames(User $user): array
    {
        $scope = app(GroupGovernanceAccess::class)->viewScope($user, $this->group);
        if ($scope === null) {
            return [];
        }

        return collect(app(SchoolGroupDirectoryReadService::class)->directory($this->group, [], 1, $scope)->items())
            ->pluck('name')->sort()->values()->all();
    }

    #[Test]
    public function region_and_zone_scopes_show_exactly_their_members_and_never_unplaced_ones(): void
    {
        $whole = $this->appoint(NetworkAccessService::VIEWER);
        $region = $this->appoint(NetworkAccessService::VIEWER, $this->south->id);
        $zone = $this->appoint(NetworkAccessService::VIEWER, null, $this->hyd->id);

        $this->assertSame(['Iris Direct', 'Iris Hyd', 'Iris North', 'Iris Unplaced'], $this->visibleNames($whole));
        $this->assertSame(['Iris Direct', 'Iris Hyd'], $this->visibleNames($region), 'a Region includes its direct members and its Zones');
        $this->assertSame(['Iris Hyd'], $this->visibleNames($zone), 'a Zone is exact');

        $overview = app(SchoolGroupDirectoryReadService::class)
            ->overview($this->group, app(GroupGovernanceAccess::class)->viewScope($zone, $this->group));
        $this->assertSame(1, $overview['schools']['total'], 'overview counts stay inside the scope');
        $this->assertSame(['HYD'], array_column($overview['zones'], 'code'));
        $this->assertSame(['SOUTH'], array_column($overview['regions'], 'code'), 'only the Zone\'s own Region label');

        // Over HTTP too, and filters never widen the scope.
        $this->actingAs($zone)->get("/app/groups/{$this->group->id}?region_id={$this->north->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->where('directory.data', [])->where('scope.type', 'scoped')->where('canManage', false));
    }

    #[Test]
    public function a_scope_from_one_group_never_activates_in_another(): void
    {
        $other = $this->createGroup([$this->createSchool()], 'Other Network');
        $viewer = $this->appoint(NetworkAccessService::VIEWER, $this->south->id);

        $this->assertNull(app(GroupGovernanceAccess::class)->viewScope($viewer, $other));
        $this->actingAs($viewer)->get("/app/groups/{$other->id}")->assertNotFound();
        $this->actingAs($viewer)->get("/app/groups/{$other->id}/access")->assertNotFound();

        // A Region of Group A cannot scope a grant in Group B (service and composite key).
        $this->assertThrows(fn () => $this->appoint(NetworkAccessService::VIEWER, $this->south->id, null, $other), ValidationException::class);
        $role = Role::query()->where('key', NetworkAccessService::VIEWER)->firstOrFail();
        $this->assertThrows(fn () => DB::transaction(fn () => GroupRoleAssignment::query()->create([
            'user_id' => $this->createUser()->id, 'school_group_id' => $other->id, 'role_id' => $role->id,
            'granted_by_user_id' => $this->root->id, 'granted_at' => now(), 'region_id' => $this->south->id,
        ])), QueryException::class);
    }

    #[Test]
    public function only_a_read_only_role_can_be_scoped_and_a_scope_never_changes(): void
    {
        $manager = Role::query()->where('key', NetworkAccessService::MANAGER)->firstOrFail();
        $groupAdmin = Role::query()->where('key', 'group_admin')->firstOrFail();
        foreach ([$manager, $groupAdmin] as $role) {
            $this->assertThrows(fn () => DB::transaction(fn () => GroupRoleAssignment::query()->create([
                'user_id' => $this->createUser()->id, 'school_group_id' => $this->group->id, 'role_id' => $role->id,
                'granted_by_user_id' => $this->root->id, 'granted_at' => now(), 'zone_id' => $this->hyd->id,
            ])), QueryException::class);
        }
        $this->assertThrows(fn () => $this->appoint(NetworkAccessService::MANAGER, $this->south->id), ValidationException::class);
        $this->assertThrows(fn () => $this->appoint(NetworkAccessService::VIEWER, $this->south->id, $this->hyd->id), ValidationException::class);

        $viewer = $this->appoint(NetworkAccessService::VIEWER, $this->south->id);
        $grant = GroupRoleAssignment::query()->where('user_id', $viewer->id)->firstOrFail();
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('group_role_assignments')->where('id', $grant->id)->update(['region_id' => $this->north->id])), QueryException::class);
        // One active grant per person and role: re-scoping is revoke + grant.
        $this->assertThrows(fn () => $this->network()->grant($this->root, $this->group, $viewer->email, NetworkAccessService::VIEWER, $this->north->id, null, NetworkRoleChangeReason::SCOPE_CHANGE, $this->freshProof($this->root)), ValidationException::class);
    }

    #[Test]
    public function the_platform_appoints_network_managers_and_a_manager_never_appoints_one(): void
    {
        $manager = $this->appoint(NetworkAccessService::MANAGER);
        $audit = DB::table('platform_audit_events')->where('event_type', 'platform.school_group_grant.granted')->orderByDesc('occurred_at')->first();
        $metadata = json_decode($audit->metadata, true);
        $this->assertSame(['network_manager', 'whole_group', 'appointment', 'platform'], [$metadata['role_key'], $metadata['scope_type'], $metadata['reason_code'], $metadata['authority']]);
        $this->assertStringNotContainsString('@', $audit->metadata, 'no email in audit');

        foreach ([NetworkAccessService::MANAGER, 'group_admin'] as $role) {
            $this->assertThrows(fn () => $this->network()->grant($manager, $this->group, $this->createUser()->email, $role, null, null, NetworkRoleChangeReason::APPOINTMENT, $this->freshProof($manager)), $role === 'group_admin' ? ValidationException::class : AccessDeniedHttpException::class);
        }
        // The database refuses it even if the service were bypassed.
        $role = Role::query()->where('key', NetworkAccessService::MANAGER)->firstOrFail();
        $this->assertThrows(fn () => DB::transaction(fn () => GroupRoleAssignment::query()->create([
            'user_id' => $this->createUser()->id, 'school_group_id' => $this->group->id, 'role_id' => $role->id,
            'granted_by_user_id' => $manager->id, 'granted_at' => now(),
        ])), QueryException::class);
    }

    #[Test]
    public function a_network_manager_grants_and_revokes_read_only_viewers_with_mfa_reason_and_audit(): void
    {
        $manager = $this->appoint(NetworkAccessService::MANAGER);
        $target = $this->createUser();

        $this->assertThrows(fn () => $this->network()->grant($manager, $this->group, $target->email, NetworkAccessService::VIEWER, null, $this->hyd->id, NetworkRoleChangeReason::OPERATIONAL_NEED, $this->freshProof($this->root)), AuthorizationException::class);
        $this->assertThrows(fn () => $this->network()->grant($manager, $this->group, $manager->email, NetworkAccessService::VIEWER, null, null, NetworkRoleChangeReason::OPERATIONAL_NEED, $this->freshProof($manager)), ValidationException::class);

        $grant = $this->network()->grant($manager, $this->group, $target->email, NetworkAccessService::VIEWER, null, $this->hyd->id, NetworkRoleChangeReason::OPERATIONAL_NEED, $this->freshProof($manager));
        $this->assertSame(['Iris Hyd'], $this->visibleNames($target));
        $granted = json_decode(DB::table('platform_audit_events')->where('subject_id', $grant->id)->where('event_type', 'platform.school_group_grant.granted')->value('metadata'), true);
        $this->assertSame(['zone', $this->hyd->id, 'network'], [$granted['scope_type'], $granted['zone_id'], $granted['authority']]);

        // A Network Manager cannot revoke a Network Manager.
        $other = $this->appoint(NetworkAccessService::MANAGER);
        $otherGrant = GroupRoleAssignment::query()->where('user_id', $other->id)->firstOrFail();
        $this->assertThrows(fn () => $this->network()->revoke($manager, $this->group, $otherGrant, NetworkRoleChangeReason::ROLE_ENDED, $this->freshProof($manager)), AccessDeniedHttpException::class);

        $this->network()->revoke($manager, $this->group, $grant, NetworkRoleChangeReason::ROLE_ENDED, $this->freshProof($manager));
        $this->assertSame([], $this->visibleNames($target));
        $this->assertThrows(fn () => $this->network()->revoke($manager, $this->group, $grant, NetworkRoleChangeReason::ROLE_ENDED, $this->freshProof($manager)), ValidationException::class);
        $this->assertSame(1, DB::table('platform_audit_events')->where('subject_id', $grant->id)->where('event_type', 'platform.school_group_grant.revoked')->count());

        // Only its OWN Group.
        $elsewhere = $this->createGroup([], 'Elsewhere');
        $this->assertThrows(fn () => $this->network()->grant($manager, $elsewhere, $this->createUser()->email, NetworkAccessService::VIEWER, null, null, NetworkRoleChangeReason::OPERATIONAL_NEED, $this->freshProof($manager)), AccessDeniedHttpException::class);
    }

    #[Test]
    public function a_network_manager_governs_its_own_groups_structure_but_never_adds_a_school(): void
    {
        $manager = $this->appoint(NetworkAccessService::MANAGER);
        $zone = app(SchoolGroupStructureService::class)->createZone($manager, $this->group, ['code' => 'VIZ', 'name' => 'Vizag', 'region_id' => $this->south->id]);
        $this->governance()->placeMembership($manager, $this->group, $this->schools['unplaced'], null, $zone->id, ['region_id' => null, 'zone_id' => null], SchoolGroupMembershipReason::OPERATIONAL_REORGANIZATION);
        $this->governance()->classifyMembership($manager, $this->group, $this->schools['north'], SchoolGroupRelationship::OWNED, null, ['relationship_type' => 'franchise', 'network_reference' => null], SchoolGroupMembershipReason::OWNERSHIP_CHANGE, $this->freshProof($manager));
        $this->governance()->removeSchool($manager, $this->group, $this->schools['north'], SchoolGroupMembershipReason::NETWORK_EXIT, $this->freshProof($manager));
        $placed = json_decode(DB::table('platform_audit_events')->where('event_type', 'platform.school_group.membership_placed')->where('actor_user_id', $manager->id)->value('metadata'), true);
        $this->assertSame('network', $placed['authority']);

        $this->assertThrows(fn () => $this->governance()->addSchool($manager, $this->group, $this->createSchool()->id, SchoolGroupRelationship::OWNED, SchoolGroupMembershipReason::NETWORK_JOIN, $this->freshProof($manager)), AccessDeniedHttpException::class);

        // Another Group: nothing.
        $elsewhere = $this->createGroup([$this->createSchool()], 'Elsewhere');
        $this->assertThrows(fn () => app(SchoolGroupStructureService::class)->createRegion($manager, $elsewhere, ['code' => 'X', 'name' => 'X']), AuthorizationException::class);

        // A viewer (even whole-Group) and a Group Admin manage nothing.
        foreach ([$this->appoint(NetworkAccessService::VIEWER), $this->groupAdmin($this->group)] as $readOnly) {
            $this->assertThrows(fn () => app(SchoolGroupStructureService::class)->createRegion($readOnly, $this->group, ['code' => 'Y', 'name' => 'Y']), AuthorizationException::class);
            $this->actingAs($readOnly)->post("/app/groups/{$this->group->id}/network/regions", ['code' => 'Z', 'name' => 'Z'])->assertForbidden();
        }
    }

    #[Test]
    public function revoking_the_managers_grant_or_disabling_the_account_ends_authority_at_once(): void
    {
        $manager = $this->appoint(NetworkAccessService::MANAGER);
        $grant = GroupRoleAssignment::query()->where('user_id', $manager->id)->firstOrFail();
        $this->network()->revoke($this->root, $this->group, $grant, NetworkRoleChangeReason::ROLE_ENDED, $this->freshProof($this->root));
        $this->assertThrows(fn () => app(SchoolGroupStructureService::class)->createRegion($manager, $this->group, ['code' => 'R1', 'name' => 'R1']), AuthorizationException::class);
        $this->assertNull(app(GroupGovernanceAccess::class)->viewScope($manager, $this->group));

        $viewer = $this->appoint(NetworkAccessService::VIEWER);
        $viewer->forceFill(['is_disabled' => true])->save();
        $this->assertNull(app(GroupGovernanceAccess::class)->viewScope($viewer->refresh(), $this->group));
    }

    #[Test]
    public function no_network_role_yields_any_school_capability_membership_or_entry(): void
    {
        $manager = $this->appoint(NetworkAccessService::MANAGER);
        $viewer = $this->appoint(NetworkAccessService::VIEWER, $this->south->id);
        $memberships = DB::table('school_memberships')->count();

        foreach ([$manager, $viewer] as $actor) {
            foreach ($this->schools as $school) {
                $this->assertSame([], app(CapabilityResolver::class)->schoolCapabilities($actor, $school));
            }
            $this->actingAs($actor)->get("/app/groups/{$this->group->id}/elevation?school={$this->schools['hyd']->id}")->assertNotFound();
            $this->post("/app/schools/{$this->schools['hyd']->id}/activate")->assertSessionHasErrors('school');
            foreach (['/app/students', '/app/finance', '/app/hr'] as $page) {
                $this->assertNotSame(200, $this->get($page)->status(), $page);
            }
            $this->post("/app/platform/groups/{$this->group->id}/schools", ['school' => $this->createSchool()->id])->assertForbidden();
            $this->get('/app/platform/schools')->assertForbidden();
        }
        $this->assertSame($memberships, DB::table('school_memberships')->count(), 'no School membership was created');
    }

    #[Test]
    public function the_network_access_page_shows_holders_only_to_operator_managers(): void
    {
        $manager = $this->appoint(NetworkAccessService::MANAGER);
        $viewer = $this->appoint(NetworkAccessService::VIEWER, null, $this->hyd->id);

        $this->actingAs($manager)->get("/app/groups/{$this->group->id}/access")->assertOk()
            ->assertInertia(fn ($p) => $p->component('App/Groups/Access')->where('canManageOperators', true)->has('assignments', 2));
        $this->actingAs($viewer)->get("/app/groups/{$this->group->id}/access")->assertOk()
            ->assertInertia(fn ($p) => $p->where('canManageOperators', false)->where('assignments', null)->where('own.scope.type', 'scoped'));
        $this->actingAs($viewer)->post("/app/groups/{$this->group->id}/access/operators", ['user' => $this->createUser()->email, 'reason_code' => 'operational_need', 'mfa_code' => '123456'])->assertForbidden();

        // A Group Admin keeps its ADR 0045 semantics: whole Group, elevation, no management.
        $groupAdmin = $this->groupAdmin($this->group);
        $this->actingAs($groupAdmin)->get("/app/groups/{$this->group->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->where('scope.type', 'whole_group')->where('canManage', false)->where('directory.total', 4));
    }

    #[Test]
    public function the_operator_routes_need_a_valid_fresh_code_and_a_reason(): void
    {
        $manager = $this->appoint(NetworkAccessService::MANAGER);
        $this->enrollActiveMfaFactor($manager);
        $codes = $this->issueRecoveryCodes($manager, 2);
        $target = $this->createUser();
        $url = "/app/groups/{$this->group->id}/access/operators";

        $this->actingAs($manager)->post($url, ['user' => $target->email, 'zone_id' => $this->hyd->id, 'reason_code' => 'operational_need', 'mfa_code' => '000000'])->assertSessionHasErrors('mfa_code');
        $this->actingAs($manager)->post($url, ['user' => $target->email, 'zone_id' => $this->hyd->id, 'mfa_code' => $codes[0]])->assertSessionHasErrors('reason_code');
        $this->assertNull(app(GroupGovernanceAccess::class)->viewScope($target, $this->group));
        $this->actingAs($manager)->post($url, ['user' => $target->email, 'zone_id' => $this->hyd->id, 'reason_code' => 'operational_need', 'mfa_code' => $codes[0]])->assertSessionHasNoErrors();
        $this->assertSame(['Iris Hyd'], $this->visibleNames($target));
        $this->assertTrue(Str::isUuid((string) GroupRoleAssignment::query()->where('user_id', $target->id)->value('zone_id')));
    }

    #[Test]
    public function review_hardening_paths_stay_separate_and_rollback_fails_closed(): void
    {
        // The legacy Group Admin path refuses Network roles (grant and revoke).
        $this->assertThrows(fn () => $this->governance()->grant($this->root, $this->group, $this->createUser()->email, NetworkAccessService::MANAGER), ValidationException::class);
        $viewer = $this->appoint(NetworkAccessService::VIEWER, $this->south->id);
        $grant = GroupRoleAssignment::query()->where('user_id', $viewer->id)->firstOrFail();
        $this->assertThrows(fn () => $this->governance()->revoke($this->root, $grant), ValidationException::class);

        // The Network Access list never names the Group Admins.
        $this->groupAdmin($this->group);
        $this->assertSame(['network_viewer'], $this->network()->assignments($this->group)->pluck('role')->all());

        // Rolling the scope migration back would widen this viewer: refused.
        $migration = require base_path('database/migrations/2027_01_14_090000_add_network_scope_to_group_role_assignments.php');
        $this->assertThrows(fn () => $migration->down(), \RuntimeException::class);
        $this->assertNotNull(GroupRoleAssignment::query()->whereKey($grant->id)->value('region_id'));
    }
}
