<?php

namespace Tests\Feature\Platform\Groups;

use App\Domain\Platform\Application\Groups\GroupGovernanceScope;
use App\Domain\Platform\Application\Groups\SchoolGroupDirectoryReadService;
use App\Domain\Platform\Application\Groups\SchoolGroupGovernanceService;
use App\Domain\Platform\Application\Groups\SchoolGroupMembershipReason;
use App\Domain\Platform\Application\Groups\SchoolGroupRelationship;
use App\Domain\Platform\Application\Groups\SchoolGroupStructureService;
use App\Domain\Platform\Application\Modules\SchoolModuleEntitlements;
use App\Http\Middleware\RequireSchoolContext;
use App\Models\School;
use App\Models\SchoolGroup;
use App\Models\SchoolGroupRegion;
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
 * ORG.12D (ADR 0080 §9): Network / franchise governance on the existing
 * School Group model.
 *
 * - Regions and Zones are Group-owned classifications (platform-governed,
 *   `platform.school_groups.manage`); cross-Group use is refused by the
 *   service AND the database; inactivation moves no School.
 * - A membership's relationship (owned/franchise/managed/affiliate) and
 *   network reference change only with a closed reason, a fresh MFA proof
 *   and fresh authority, stale-safe; placement needs a reason.
 * - No classification grants anything: no School capability, membership,
 *   lifecycle or entitlement change, and a Group Admin still sees only
 *   platform metadata and cannot open School business pages.
 */
class NetworkGovernanceTest extends TestCase
{
    use CreatesTenancyFixtures, GroupTestHelpers;

    private function root(): User
    {
        return $this->createPlatformRoot();
    }

    private function structure(): SchoolGroupStructureService
    {
        return app(SchoolGroupStructureService::class);
    }

    private function governance(): SchoolGroupGovernanceService
    {
        return app(SchoolGroupGovernanceService::class);
    }

    private function member(SchoolGroup $group, School $school): ?object
    {
        return DB::table('school_group_members')->where('school_group_id', $group->id)->where('school_id', $school->id)->first();
    }

    /** @return list<object> */
    private function platformAudit(string $event): array
    {
        return DB::table('platform_audit_events')->where('event_type', $event)->orderBy('occurred_at')->get()->all();
    }

    #[Test]
    public function regions_and_zones_are_group_owned_coded_ordered_and_inactivated_never_deleted(): void
    {
        $root = $this->root();
        $group = $this->createGroup([], 'Iris Network');

        $south = $this->structure()->createRegion($root, $group, ['code' => ' south ', 'name' => 'South India', 'sort_order' => 2]);
        $north = $this->structure()->createRegion($root, $group, ['code' => 'NORTH', 'name' => 'North Region', 'sort_order' => 1]);
        $zone = $this->structure()->createZone($root, $group, ['code' => 'hyd-c', 'name' => 'Hyderabad Central', 'region_id' => $south->id]);
        $floating = $this->structure()->createZone($root, $group, ['code' => 'ONLINE', 'name' => 'Online']);

        $this->assertSame(['SOUTH', 'HYD-C', null], [$south->code, $zone->code, $floating->region_id]);
        $this->assertSame(['NORTH', 'SOUTH'], SchoolGroupRegion::query()->where('school_group_id', $group->id)->orderBy('sort_order')->pluck('code')->all());

        foreach ([['code' => 'south', 'name' => 'Dup'], ['code' => 'bad code!', 'name' => 'X'], ['code' => 'OK', 'name' => '']] as $bad) {
            $this->assertThrows(fn () => $this->structure()->createRegion($root, $group, $bad), ValidationException::class);
        }
        $other = $this->createGroup([], 'Other Network');
        $this->structure()->createRegion($root, $other, ['code' => 'SOUTH', 'name' => 'Another South']); // codes are per Group

        $this->structure()->updateRegion($root, $group, $south->id, ['status' => 'inactive', 'name' => 'South']);
        $this->assertSame(['inactive', 'South'], [$south->refresh()->status, $south->name]);
        $updated = $this->platformAudit('platform.school_group.region_updated');
        $this->assertStringNotContainsString('South India', $updated[0]->metadata, 'names are never copied into audit');

        $this->assertFalse((bool) DB::selectOne("select has_table_privilege('school_os_app', 'school_group_regions', 'DELETE') or has_table_privilege('school_os_app', 'school_group_zones', 'DELETE') as p")->p);
        $this->assertCount(2, $this->platformAudit('platform.school_group.zone_created'));
    }

    #[Test]
    public function another_groups_region_or_zone_is_refused_by_the_service_and_the_database(): void
    {
        $root = $this->root();
        $school = $this->createSchool();
        $a = $this->createGroup([$school], 'A');
        $b = $this->createGroup([], 'B');
        $regionB = $this->structure()->createRegion($root, $b, ['code' => 'RB', 'name' => 'Region B']);
        $zoneB = $this->structure()->createZone($root, $b, ['code' => 'ZB', 'name' => 'Zone B', 'region_id' => $regionB->id]);
        $expected = ['region_id' => null, 'zone_id' => null];

        $this->assertThrows(fn () => $this->governance()->placeMembership($root, $a, $school, $regionB->id, null, $expected, SchoolGroupMembershipReason::OPERATIONAL_REORGANIZATION), ValidationException::class);
        $this->assertThrows(fn () => $this->governance()->placeMembership($root, $a, $school, null, $zoneB->id, $expected, SchoolGroupMembershipReason::OPERATIONAL_REORGANIZATION), ValidationException::class);
        $this->assertThrows(fn () => $this->structure()->createZone($root, $a, ['code' => 'Z', 'name' => 'Z', 'region_id' => $regionB->id]), ValidationException::class);

        $raw = fn (array $set) => DB::transaction(fn () => DB::table('school_group_members')->where('school_group_id', $a->id)->update($set));
        $this->assertThrows(fn () => $raw(['region_id' => $regionB->id]), QueryException::class);
        $this->assertThrows(fn () => $raw(['zone_id' => $zoneB->id, 'region_id' => $regionB->id]), QueryException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('school_group_zones')->insert(['id' => (string) Str::uuid7(), 'school_group_id' => $a->id, 'region_id' => $regionB->id, 'code' => 'X', 'name' => 'X', 'created_at' => now(), 'updated_at' => now()])), QueryException::class);

        // Inside one Group, a member's Region must be its Zone's Region (trigger).
        $regionA = $this->structure()->createRegion($root, $a, ['code' => 'RA', 'name' => 'Region A']);
        $regionA2 = $this->structure()->createRegion($root, $a, ['code' => 'RA2', 'name' => 'Region A2']);
        $zoneA = $this->structure()->createZone($root, $a, ['code' => 'ZA', 'name' => 'Zone A', 'region_id' => $regionA->id]);
        $this->assertThrows(fn () => $raw(['zone_id' => $zoneA->id, 'region_id' => $regionA2->id]), QueryException::class);
        $this->assertThrows(fn () => $raw(['zone_id' => $zoneA->id, 'region_id' => null]), QueryException::class);
    }

    #[Test]
    public function placement_follows_the_zone_needs_a_reason_and_inactivation_moves_no_school(): void
    {
        $root = $this->root();
        $school = $this->createSchool();
        $group = $this->createGroup([$school]);
        $region = $this->structure()->createRegion($root, $group, ['code' => 'SOUTH', 'name' => 'South']);
        $zone = $this->structure()->createZone($root, $group, ['code' => 'HYD', 'name' => 'Hyderabad', 'region_id' => $region->id]);

        $changed = $this->governance()->placeMembership($root, $group, $school, null, $zone->id, ['region_id' => null, 'zone_id' => null], SchoolGroupMembershipReason::INITIAL_CLASSIFICATION);
        $this->assertEqualsCanonicalizing(['region_id', 'zone_id'], $changed);
        $this->assertSame([$region->id, $zone->id], [$this->member($group, $school)->region_id, $this->member($group, $school)->zone_id], 'a Zone sets its Region');

        // A stale form never overwrites; an identical submit writes nothing.
        $this->assertThrows(fn () => $this->governance()->placeMembership($root, $group, $school, null, null, ['region_id' => null, 'zone_id' => null], SchoolGroupMembershipReason::DATA_CORRECTION), ValidationException::class);
        $this->assertSame([], $this->governance()->placeMembership($root, $group, $school, $region->id, $zone->id, ['region_id' => $region->id, 'zone_id' => $zone->id], SchoolGroupMembershipReason::DATA_CORRECTION));
        $this->assertCount(1, $this->platformAudit('platform.school_group.membership_placed'));

        // Inactivating keeps the placement, refuses NEW placements into it, and is surfaced.
        $this->structure()->updateZone($root, $group, $zone->id, ['status' => 'inactive']);
        $this->assertSame($zone->id, $this->member($group, $school)->zone_id, 'no silent reassignment');
        $other = $this->createSchool();
        $this->governance()->addSchool($root, $group, $other->id, SchoolGroupRelationship::MANAGED, SchoolGroupMembershipReason::NETWORK_JOIN, $this->freshProof($root));
        $this->assertThrows(fn () => $this->governance()->placeMembership($root, $group, $other, null, $zone->id, ['region_id' => null, 'zone_id' => null], SchoolGroupMembershipReason::OPERATIONAL_REORGANIZATION), ValidationException::class);
        $this->assertSame(1, app(SchoolGroupDirectoryReadService::class)->overview($group, GroupGovernanceScope::whole())['attention']['inInactiveStructure']);

        // A Zone with Schools cannot silently change Region; its code is frozen once used.
        $region2 = $this->structure()->createRegion($root, $group, ['code' => 'WEST', 'name' => 'West']);
        $this->assertThrows(fn () => $this->structure()->updateZone($root, $group, $zone->id, ['region_id' => $region2->id]), ValidationException::class);
        $this->assertThrows(fn () => $this->structure()->updateZone($root, $group, $zone->id, ['code' => 'NEWCODE']), ValidationException::class);
        $this->assertThrows(fn () => $this->structure()->updateRegion($root, $group, $region->id, ['code' => 'NEWCODE']), ValidationException::class);
    }

    #[Test]
    public function classification_needs_fresh_authority_a_reason_and_a_fresh_mfa_proof_and_is_stale_safe(): void
    {
        $root = $this->root();
        $school = $this->createSchool();
        $group = $this->createGroup([$school]);
        $legacy = $this->member($group, $school);
        $this->assertNull($legacy->relationship_type, 'a legacy membership stays unclassified');
        $expected = ['relationship_type' => null, 'network_reference' => null];

        // Proof bound to another person: refused.
        $this->assertThrows(fn () => $this->governance()->classifyMembership($root, $group, $school, SchoolGroupRelationship::FRANCHISE, 'iris-hyd-014', $expected, SchoolGroupMembershipReason::INITIAL_CLASSIFICATION, $this->freshProof($this->createUser())), AuthorizationException::class);
        // Platform Manager / Auditor (view only) with a valid proof: refused before anything.
        $manager = $this->createUser();
        $this->assignPlatformRole($manager, 'platform_manager', $root);
        $this->assertThrows(fn () => $this->governance()->classifyMembership($manager, $group, $school, SchoolGroupRelationship::OWNED, null, $expected, SchoolGroupMembershipReason::INITIAL_CLASSIFICATION, $this->freshProof($manager)), AccessDeniedHttpException::class);

        $changed = $this->governance()->classifyMembership($root, $group, $school, SchoolGroupRelationship::FRANCHISE, 'iris-hyd-014', $expected, SchoolGroupMembershipReason::INITIAL_CLASSIFICATION, $this->freshProof($root));
        $this->assertEqualsCanonicalizing(['relationship_type', 'network_reference'], $changed);
        $this->assertSame(['franchise', 'IRIS-HYD-014'], [$this->member($group, $school)->relationship_type, $this->member($group, $school)->network_reference]);

        // Stale: the form opened before that change.
        $this->assertThrows(fn () => $this->governance()->classifyMembership($root, $group, $school, SchoolGroupRelationship::OWNED, null, $expected, SchoolGroupMembershipReason::OWNERSHIP_CHANGE, $this->freshProof($root)), ValidationException::class);
        $this->assertSame('franchise', $this->member($group, $school)->relationship_type);

        $audit = $this->platformAudit('platform.school_group.membership_classified');
        $this->assertCount(1, $audit);
        $metadata = json_decode($audit[0]->metadata, true);
        $this->assertSame([null, 'franchise', 'initial_classification'], [$metadata['relationship_before'], $metadata['relationship_after'], $metadata['reason_code']]);
        $this->assertArrayHasKey('mfa_verified_at', $metadata);

        // A network reference is unique within the Group.
        $second = $this->createSchool();
        $this->governance()->addSchool($root, $group, $second->id, SchoolGroupRelationship::OWNED, SchoolGroupMembershipReason::NETWORK_JOIN, $this->freshProof($root));
        $this->assertThrows(fn () => $this->governance()->classifyMembership($root, $group, $second, SchoolGroupRelationship::OWNED, 'IRIS-HYD-014', ['relationship_type' => 'owned', 'network_reference' => null], SchoolGroupMembershipReason::DATA_CORRECTION, $this->freshProof($root)), ValidationException::class);
    }

    #[Test]
    public function the_web_routes_check_the_capability_before_spending_a_code_and_need_a_valid_fresh_code(): void
    {
        $root = $this->root();
        $this->enrollActiveMfaFactor($root);
        $codes = $this->issueRecoveryCodes($root, 3);
        $school = $this->createSchool();
        $group = $this->createGroup([$school]);
        $url = "/app/platform/groups/{$group->id}/schools/{$school->id}/classification";
        $body = ['relationship_type' => 'owned', 'network_reference' => '', 'expected_relationship_type' => null, 'expected_network_reference' => null, 'reason_code' => 'initial_classification'];

        $this->actingAs($root)->put($url, [...$body, 'mfa_code' => '000000'])->assertSessionHasErrors('mfa_code');
        $this->actingAs($root)->put($url, [...$body, 'reason_code' => '', 'mfa_code' => $codes[0]])->assertSessionHasErrors('reason_code');
        $this->assertNull($this->member($group, $school)->relationship_type);
        $this->actingAs($root)->put($url, [...$body, 'mfa_code' => $codes[0]])->assertSessionHasNoErrors();
        $this->assertSame('owned', $this->member($group, $school)->relationship_type);

        // A new membership must be classified, and adding needs a fresh code too.
        $new = $this->createSchool();
        $this->actingAs($root)->post("/app/platform/groups/{$group->id}/schools", ['school' => $new->id, 'reason_code' => 'network_join', 'mfa_code' => $codes[1]])->assertSessionHasErrors('relationship_type');
        $this->actingAs($root)->post("/app/platform/groups/{$group->id}/schools", ['school' => $new->id, 'relationship_type' => 'affiliate', 'reason_code' => 'network_join', 'mfa_code' => 'nope'])->assertSessionHasErrors('mfa_code');
        $this->assertNull($this->member($group, $new));

        $groupAdmin = $this->groupAdmin($group);
        $this->actingAs($groupAdmin)->put($url, [...$body, 'mfa_code' => '123456'])->assertForbidden();
        $this->actingAs($groupAdmin)->post("/app/platform/groups/{$group->id}/regions", ['code' => 'X', 'name' => 'X'])->assertForbidden();
    }

    #[Test]
    public function no_relationship_type_grants_anything_in_the_school(): void
    {
        $root = $this->root();
        $schools = ['owned' => $this->createSchool(), 'franchise' => $this->createSchool(), 'managed' => $this->createSchool(), 'affiliate' => $this->createSchool()];
        $group = $this->createGroup([]);
        $groupAdmin = $this->groupAdmin($group);
        $before = [];
        foreach ($schools as $school) {
            $before[$school->id] = [
                $school->refresh()->status,
                app(SchoolModuleEntitlements::class)->entitledModules($school),
                DB::table('school_memberships')->where('school_id', $school->id)->count(),
                app(CapabilityResolver::class)->schoolCapabilities($groupAdmin, $school),
            ];
        }
        foreach ($schools as $type => $school) {
            $this->governance()->addSchool($root, $group, $school->id, SchoolGroupRelationship::from($type), SchoolGroupMembershipReason::NETWORK_JOIN, $this->freshProof($root));
        }

        foreach ($schools as $type => $school) {
            app(SchoolModuleEntitlements::class)->forget($school->id);
            $this->assertSame($before[$school->id], [
                $school->refresh()->status,
                app(SchoolModuleEntitlements::class)->entitledModules($school),
                DB::table('school_memberships')->where('school_id', $school->id)->count(),
                app(CapabilityResolver::class)->schoolCapabilities($groupAdmin, $school),
            ], "{$type}: no lifecycle, entitlement, membership or capability change");
            $this->assertSame([], app(CapabilityResolver::class)->schoolCapabilities($groupAdmin, $school), "{$type}: the Group Admin holds no School capability");

            $this->actingAs($groupAdmin)->post("/app/schools/{$school->id}/activate");
            foreach (['/app/students', '/app/finance', '/app/hr', '/app/school-setup/campuses', '/app/school-setup/profile'] as $page) {
                $this->assertNotSame(200, $this->get($page)->status(), "{$type}: {$page} stays closed to Group authority");
            }
        }
    }

    #[Test]
    public function a_group_and_school_a_user_still_cannot_open_school_b_of_the_same_group(): void
    {
        $root = $this->root();
        $a = $this->createSchool();
        $b = $this->createSchool();
        $group = $this->createGroup([]);
        foreach ([$a, $b] as $school) {
            $this->governance()->addSchool($root, $group, $school->id, SchoolGroupRelationship::OWNED, SchoolGroupMembershipReason::NETWORK_JOIN, $this->freshProof($root));
        }
        $person = $this->groupAdmin($group);
        $this->assignSchoolRole($this->createMembership($person, $a), 'school_admin');

        $this->actingAs($person)->post("/app/schools/{$a->id}/activate");
        $this->get('/app/school-setup/campuses')->assertOk();

        // School B of the same Group cannot be selected; the session stays on A.
        $this->actingAs($person)->post("/app/schools/{$b->id}/activate")->assertSessionHasErrors('school');
        $this->assertSame($a->id, session(RequireSchoolContext::SESSION_KEY));
        $this->assertSame([], app(CapabilityResolver::class)->schoolCapabilities($person, $b));
    }

    #[Test]
    public function the_group_directory_shows_platform_metadata_only_and_filters_and_searches(): void
    {
        $root = $this->root();
        $alpha = $this->createSchool(['name' => 'Iris Alpha', 'code' => 'IA1']);
        $beta = $this->createSchool(['name' => 'Iris Beta', 'code' => 'IB2']);
        $group = $this->createGroup([]);
        $region = $this->structure()->createRegion($root, $group, ['code' => 'SOUTH', 'name' => 'South']);
        $this->governance()->addSchool($root, $group, $alpha->id, SchoolGroupRelationship::OWNED, SchoolGroupMembershipReason::NETWORK_JOIN, $this->freshProof($root));
        $this->governance()->addSchool($root, $group, $beta->id, SchoolGroupRelationship::FRANCHISE, SchoolGroupMembershipReason::NETWORK_JOIN, $this->freshProof($root));
        $this->governance()->placeMembership($root, $group, $beta, $region->id, null, ['region_id' => null, 'zone_id' => null], SchoolGroupMembershipReason::INITIAL_CLASSIFICATION);

        // Planted tenant data that must never surface.
        $this->createStudent($beta, ['first_name' => 'Xanthippe', 'last_name' => 'Quorvald']);
        $this->createEmployee($beta, ['full_name' => 'Ottoline Vreeswijk']);
        $this->createGuardian($beta, ['first_name' => 'Ulrikka', 'last_name' => 'Brannoch']);

        $this->actingAs($this->groupAdmin($group));
        $page = fn (string $q = '') => $this->get("/app/groups/{$group->id}{$q}")->assertOk();
        $props = $page()->viewData('page')['props'];
        $this->assertSame(['total' => 2, 'active' => 2, 'notActive' => 0], $props['overview']['schools']);
        $this->assertSame(1, $props['overview']['relationships']['franchise']);
        $this->assertSame(['id', 'name', 'code', 'status', 'relationship', 'relationshipLabel', 'networkReference', 'region', 'zone', 'canEnter', 'isMember'], array_keys($props['directory']['data'][0]));

        $this->assertSame(['Iris Beta'], array_column($page('?relationship=franchise')->viewData('page')['props']['directory']['data'], 'name'));
        $this->assertSame(['Iris Beta'], array_column($page("?region_id={$region->id}")->viewData('page')['props']['directory']['data'], 'name'));
        $this->assertSame(['Iris Alpha'], array_column($page('?q=ia1')->viewData('page')['props']['directory']['data'], 'name'));
        $this->assertSame([], $page('?q=Xanthippe')->viewData('page')['props']['directory']['data'], 'search never reaches tenant data');

        $html = (string) $page()->getContent();
        foreach (['Xanthippe', 'Quorvald', 'Ottoline', 'Vreeswijk', 'Ulrikka', 'Brannoch'] as $planted) {
            $this->assertStringNotContainsString($planted, $html);
        }
        $this->assertSame('', (string) DB::selectOne("select current_setting('app.current_school_id', true) as v")->v, 'no School context is ever set');
    }

    #[Test]
    public function the_directory_query_count_is_constant_from_one_to_a_hundred_schools(): void
    {
        $root = $this->root();
        $group = $this->createGroup([]);
        $region = $this->structure()->createRegion($root, $group, ['code' => 'R', 'name' => 'R']);
        $zone = $this->structure()->createZone($root, $group, ['code' => 'Z', 'name' => 'Z', 'region_id' => $region->id]);
        $admin = $this->groupAdmin($group);
        $counts = [];
        $n = 0;
        DB::listen(function () use (&$n): void {
            $n++;
        });
        foreach ([1, 10, 100] as $size) {
            while (DB::table('school_group_members')->where('school_group_id', $group->id)->count() < $size) {
                $school = School::factory()->withCoreModulesOnly()->create();
                DB::table('school_group_members')->insert(['id' => (string) Str::uuid7(), 'school_group_id' => $group->id, 'school_id' => $school->id,
                    'relationship_type' => 'franchise', 'region_id' => $region->id, 'zone_id' => $zone->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->actingAs($admin)->get("/app/groups/{$group->id}")->assertOk();
            $n = 0;
            $this->actingAs($admin)->get("/app/groups/{$group->id}")->assertOk();
            $counts[$size] = $n;
            $n = 0;
            $this->actingAs($root)->get("/app/platform/groups/{$group->id}")->assertOk();
            $counts["platform-{$size}"] = $n;
        }

        $this->assertSame($counts[1], $counts[100], 'Group page queries: '.json_encode($counts));
        $this->assertSame($counts['platform-1'], $counts['platform-100']);
        $this->assertLessThanOrEqual(25, $counts[100]);
    }

    #[Test]
    public function a_standalone_school_is_untouched_by_network_governance(): void
    {
        $school = $this->createSchool();
        $admin = $this->schoolAdminFor($school);
        $this->actingAs($admin)->post("/app/schools/{$school->id}/activate");

        $this->get('/app/school-setup/readiness')->assertOk();
        $this->get('/app/school-setup/campuses')->assertOk();
        $this->assertSame(0, DB::table('school_group_members')->where('school_id', $school->id)->count());
        $this->assertArrayNotHasKey('group', $this->get('/app/school-setup/readiness')->viewData('page')['props']['center']['overview']);
    }

    #[Test]
    public function relationships_and_placement_are_independent_per_group_membership(): void
    {
        $root = $this->root();
        $school = $this->createSchool();
        $a = $this->createGroup([]);
        $b = $this->createGroup([]);
        $this->governance()->addSchool($root, $a, $school->id, SchoolGroupRelationship::FRANCHISE, SchoolGroupMembershipReason::NETWORK_JOIN, $this->freshProof($root), 'A-001');
        $this->governance()->addSchool($root, $b, $school->id, SchoolGroupRelationship::AFFILIATE, SchoolGroupMembershipReason::NETWORK_JOIN, $this->freshProof($root), 'A-001');
        $regionA = $this->structure()->createRegion($root, $a, ['code' => 'R', 'name' => 'R']);
        $this->governance()->placeMembership($root, $a, $school, $regionA->id, null, ['region_id' => null, 'zone_id' => null], SchoolGroupMembershipReason::INITIAL_CLASSIFICATION);

        $this->assertSame(['franchise', $regionA->id, 'A-001'], [$this->member($a, $school)->relationship_type, $this->member($a, $school)->region_id, $this->member($a, $school)->network_reference]);
        $this->assertSame(['affiliate', null, 'A-001'], [$this->member($b, $school)->relationship_type, $this->member($b, $school)->region_id, $this->member($b, $school)->network_reference], 'nothing bleeds between memberships');

        $this->governance()->removeSchool($root, $a, $school, SchoolGroupMembershipReason::NETWORK_EXIT, $this->freshProof($root));
        $this->assertNull($this->member($a, $school));
        $this->assertNotNull($this->member($b, $school));
        $this->assertSame('active', $school->refresh()->status, 'leaving a Group touches nothing in the School');
        $removed = json_decode($this->platformAudit('platform.school_group.school_removed')[0]->metadata, true);
        $this->assertSame('network_exit', $removed['reason_code']);
    }

    #[Test]
    public function review_hardening_duplicate_messages_array_filters_uuid_case_and_archived_groups(): void
    {
        $root = $this->root();
        $school = $this->createSchool();
        $group = $this->createGroup([]);
        $this->governance()->addSchool($root, $group, $school->id, SchoolGroupRelationship::OWNED, SchoolGroupMembershipReason::NETWORK_JOIN, $this->freshProof($root), 'REF-1');

        // L1: a duplicate School is reported as such, a duplicate reference as such.
        try {
            $this->governance()->addSchool($root, $group, $school->id, SchoolGroupRelationship::OWNED, SchoolGroupMembershipReason::NETWORK_JOIN, $this->freshProof($root));
            $this->fail('duplicate School accepted');
        } catch (ValidationException $e) {
            $this->assertSame(['school'], array_keys($e->errors()));
        }
        try {
            $this->governance()->addSchool($root, $group, $this->createSchool()->id, SchoolGroupRelationship::OWNED, SchoolGroupMembershipReason::NETWORK_JOIN, $this->freshProof($root), 'ref-1');
            $this->fail('duplicate reference accepted');
        } catch (ValidationException $e) {
            $this->assertSame(['network_reference'], array_keys($e->errors()));
        }

        // L2: array-shaped query filters are ignored, never a 500.
        $this->actingAs($this->groupAdmin($group))->get("/app/groups/{$group->id}?relationship[]=owned&q[]=x&region_id[]=y")->assertOk();
        $this->actingAs($root)->get("/app/platform/groups/{$group->id}?relationship[]=owned&q[]=x")->assertOk();

        // L3: an upper-case id of the current placement is the same placement (no stale refusal, no write).
        $zone = $this->structure()->createZone($root, $group, ['code' => 'Z', 'name' => 'Z']);
        $this->governance()->placeMembership($root, $group, $school, null, $zone->id, ['region_id' => null, 'zone_id' => null], SchoolGroupMembershipReason::INITIAL_CLASSIFICATION);
        $this->assertSame([], $this->governance()->placeMembership($root, $group, $school, null, strtoupper($zone->id), ['region_id' => null, 'zone_id' => strtoupper($zone->id)], SchoolGroupMembershipReason::DATA_CORRECTION));
        $this->assertCount(1, $this->platformAudit('platform.school_group.membership_placed'));

        // L4: an archived Group takes no structure or classification write.
        $this->governance()->archive($root, $group);
        $this->assertThrows(fn () => $this->structure()->createRegion($root, $group->refresh(), ['code' => 'R', 'name' => 'R']), ValidationException::class);
        $this->assertThrows(fn () => $this->governance()->placeMembership($root, $group, $school, null, null, ['region_id' => null, 'zone_id' => $zone->id], SchoolGroupMembershipReason::DATA_CORRECTION), ValidationException::class);
    }
}
