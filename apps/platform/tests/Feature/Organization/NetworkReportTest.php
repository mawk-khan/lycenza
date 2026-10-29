<?php

namespace Tests\Feature\Organization;

use App\Domain\Platform\Application\Groups\GroupGovernanceScope;
use App\Domain\Platform\Application\Groups\NetworkAccessService;
use App\Domain\Platform\Application\Groups\NetworkReportReadService;
use App\Domain\Platform\Application\Groups\NetworkRoleChangeReason;
use App\Domain\Platform\Application\Groups\SchoolGroupDirectoryReadService;
use App\Domain\Platform\Application\Groups\SchoolGroupGovernanceService;
use App\Domain\Platform\Application\Groups\SchoolGroupMembershipReason;
use App\Domain\Platform\Application\Groups\SchoolGroupRelationship;
use App\Domain\Platform\Application\Groups\SchoolGroupStructureService;
use App\Models\School;
use App\Models\SchoolGroup;
use App\Models\SchoolGroupRegion;
use App\Models\SchoolGroupZone;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Platform\Groups\GroupTestHelpers;
use Tests\TestCase;

/**
 * ORG.12H (ADR 0080 §13.3): the Network MIS. Platform tables only, within
 * the viewer's Network scope (whole Group, Region or Zone); never a tenant
 * table, a School context or a link into a School; bounded queries.
 */
class NetworkReportTest extends TestCase
{
    use CreatesTenancyFixtures, GroupTestHelpers;

    /** Tenant tables a Network report must never touch (rule 84). */
    private const array TENANT_TABLES = ['students', 'student_enrollments', 'guardians', 'employees', 'employments', 'payroll_runs', 'charges', 'payments', 'attendance_records', 'staff_attendance_records', 'campuses', 'admission_applications', 'library_loans', 'inventory_stock_balances', 'transport_rider_assignments', 'communication_recipients', 'school_memberships', 'email_messages', 'webhook_endpoints', 'api_clients'];

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
        $this->group = $this->createGroup([], 'Quarvex Network');
        $structure = app(SchoolGroupStructureService::class);
        $this->south = $structure->createRegion($this->root, $this->group, ['code' => 'SOUTH', 'name' => 'South']);
        $this->north = $structure->createRegion($this->root, $this->group, ['code' => 'NORTH', 'name' => 'North']);
        $this->hyd = $structure->createZone($this->root, $this->group, ['code' => 'HYD', 'name' => 'Hyderabad', 'region_id' => $this->south->id]);

        $governance = app(SchoolGroupGovernanceService::class);
        foreach (['direct' => [$this->south->id, null, SchoolGroupRelationship::OWNED], 'hyd' => [null, $this->hyd->id, SchoolGroupRelationship::FRANCHISE], 'north' => [$this->north->id, null, SchoolGroupRelationship::MANAGED], 'unplaced' => [null, null, SchoolGroupRelationship::AFFILIATE]] as $key => [$region, $zone, $relationship]) {
            $school = $this->createSchool(['name' => 'Quarvex '.ucfirst($key), 'code' => 'QX-'.strtoupper($key)]);
            $governance->addSchool($this->root, $this->group, $school->id, $relationship, SchoolGroupMembershipReason::NETWORK_JOIN, $this->freshProof($this->root));
            if ($region !== null || $zone !== null) {
                $governance->placeMembership($this->root, $this->group, $school, $region, $zone, ['region_id' => null, 'zone_id' => null], SchoolGroupMembershipReason::INITIAL_CLASSIFICATION);
            }
            $this->schools[$key] = $school;
        }
        DB::table('school_group_members')->where('school_id', $this->schools['north']->id)->update(['network_reference' => 'NQ-REF-77']);
    }

    private function viewer(string $role = 'network_viewer', ?string $region = null, ?string $zone = null): User
    {
        $user = $this->createUser();
        app(NetworkAccessService::class)->grant($this->root, $this->group, $user->email, $role, $region, $zone, NetworkRoleChangeReason::APPOINTMENT, $this->freshProof($this->root));

        return $user;
    }

    private function report(User $user, string $query = ''): TestResponse
    {
        return $this->actingAs($user)->get("/app/groups/{$this->group->id}/reports/network".$query);
    }

    /** @return array<string, mixed> */
    private function props(User $user, string $query = ''): array
    {
        $response = $this->report($user, $query)->assertOk();
        $this->assertSame('App/Groups/NetworkReport', $response->viewData('page')['component']);

        return $response->viewData('page')['props'];
    }

    /** @return list<string> */
    private function codes(array $props): array
    {
        return array_column($props['directory']['data'], 'code');
    }

    #[Test]
    public function a_whole_network_viewer_sees_every_membership_including_unplaced_with_structural_metrics_only(): void
    {
        $props = $this->props($this->viewer());

        $this->assertSame(4, $props['overview']['schools']['total']);
        $this->assertSame(['provisioning' => 0, 'active' => 4, 'suspended' => 0, 'archived' => 0], $props['lifecycle']);
        $this->assertSame(['owned' => 1, 'franchise' => 1, 'managed' => 1, 'affiliate' => 1, 'unclassified' => 0], $props['overview']['relationships']);
        $south = collect($props['distribution']['regions'])->firstWhere('code', 'SOUTH');
        $this->assertSame([2, 1, [['code' => 'HYD', 'name' => 'Hyderabad', 'status' => 'active', 'schools' => 1]]], [$south['schools'], $south['regionOnly'], $south['zones']]);
        $this->assertSame(1, collect($props['distribution']['regions'])->firstWhere('code', 'NORTH')['schools']);
        $this->assertSame(1, $props['distribution']['unplaced']);
        $this->assertEqualsCanonicalizing(['QX-DIRECT', 'QX-HYD', 'QX-NORTH', 'QX-UNPLACED'], $this->codes($props));
        $this->assertSame(['network.overview', 'network.school_directory', 'network.governance_distribution', 'network.structure_attention'], $props['reports']);
    }

    #[Test]
    public function a_region_viewer_sees_only_that_region_and_its_zones_never_unplaced_and_a_filter_never_widens(): void
    {
        $viewer = $this->viewer(region: $this->south->id);
        $props = $this->props($viewer);

        $this->assertSame(2, $props['overview']['schools']['total']);
        $this->assertEqualsCanonicalizing(['QX-DIRECT', 'QX-HYD'], $this->codes($props));
        $this->assertSame(['SOUTH'], array_column($props['distribution']['regions'], 'code'));
        $this->assertNull($props['distribution']['unplaced'], 'unplaced memberships are whole-Group only');
        $this->assertSame(2, array_sum($props['lifecycle']));

        $this->assertSame([], $this->codes($this->props($viewer, "?region_id={$this->north->id}")), 'a filter only narrows the scope');
        $this->assertSame([], $this->codes($this->props($viewer, '?q=NQ-REF')), 'search stays inside the scope');
    }

    #[Test]
    public function a_zone_viewer_sees_exactly_that_zone(): void
    {
        $props = $this->props($this->viewer(zone: $this->hyd->id));

        $this->assertSame(['QX-HYD'], $this->codes($props));
        $this->assertSame(1, $props['overview']['schools']['total']);
        $south = $props['distribution']['regions'][0];
        $this->assertSame(['SOUTH', 1, 0, ['HYD']], [$south['code'], $south['schools'], $south['regionOnly'], array_column($south['zones'], 'code')]);
        $this->assertNull($props['distribution']['unplaced']);
    }

    #[Test]
    public function the_network_manager_reads_the_same_report_and_nobody_without_a_network_scope_does(): void
    {
        $this->assertSame(4, $this->props($this->viewer('network_manager'))['overview']['schools']['total']);

        $manager = $this->createUser();
        $this->assignPlatformRole($manager, 'platform_manager', $this->root);
        $schoolAdmin = $this->schoolAdminFor($this->schools['hyd']);
        $otherGroupViewer = $this->createUser();
        $this->grantGroupRole($otherGroupViewer, $this->createGroup([$this->schools['hyd']], 'Other Trust'), 'network_viewer', $this->root);

        foreach (['platform root' => $this->root, 'platform manager' => $manager, 'member School admin' => $schoolAdmin, 'another Group\'s viewer' => $otherGroupViewer] as $who => $user) {
            $this->report($user)->assertNotFound();
            $this->addToAssertionCount(1);
        }
    }

    #[Test]
    public function search_covers_name_code_and_network_reference_and_sort_and_filters_are_closed_lists(): void
    {
        $viewer = $this->viewer();
        $this->assertSame(['QX-NORTH'], $this->codes($this->props($viewer, '?q=NQ-REF-77')));
        $this->assertSame(['QX-HYD'], $this->codes($this->props($viewer, '?q=qx-hyd')));
        $this->assertSame(['QX-DIRECT', 'QX-HYD', 'QX-NORTH', 'QX-UNPLACED'], $this->codes($this->props($viewer, '?sort=name')));
        $this->assertSame(['QX-NORTH', 'QX-HYD', 'QX-DIRECT', 'QX-UNPLACED'], $this->codes($this->props($viewer, '?sort=region')), 'Region code, then Zone code (none last); unplaced last');
        $this->assertSame(['QX-DIRECT', 'QX-HYD', 'QX-NORTH', 'QX-UNPLACED'], $this->codes($this->props($viewer, '?sort=s.id;drop')), 'an unknown sort falls back to name');
        $this->assertSame(['QX-DIRECT', 'QX-HYD', 'QX-NORTH', 'QX-UNPLACED'], $this->codes($this->props($viewer, '?sort[]=x&attention[]=y')), 'non-string filters are ignored');
    }

    #[Test]
    public function governance_attention_follows_only_the_existing_rules(): void
    {
        DB::table('school_group_members')->where('school_id', $this->schools['unplaced']->id)->update(['relationship_type' => null]);
        app(SchoolGroupStructureService::class)->updateZone($this->root, $this->group, $this->hyd->id, ['name' => 'Hyderabad', 'status' => 'inactive']);
        $viewer = $this->viewer();

        $props = $this->props($viewer);
        $this->assertSame(['unclassified' => 1, 'inInactiveStructure' => 1, 'schoolsNotActive' => 0], $props['overview']['attention']);
        $this->assertSame(['QX-UNPLACED'], $this->codes($this->props($viewer, '?attention=unclassified')));
        $this->assertSame(['QX-HYD'], $this->codes($this->props($viewer, '?attention=inactive_structure')));
        $this->assertSame([], $this->codes($this->props($viewer, '?attention=not_active')));
        $this->assertEqualsCanonicalizing(['QX-HYD', 'QX-UNPLACED'], $this->codes($this->props($viewer, '?attention=any')));
    }

    #[Test]
    public function the_report_never_reads_a_tenant_table_sets_school_context_writes_or_links_into_a_school(): void
    {
        $viewer = $this->viewer();
        $this->report($viewer)->assertOk();
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });
        $response = $this->report($viewer)->assertOk();

        foreach ($statements as $sql) {
            foreach (self::TENANT_TABLES as $table) {
                $this->assertDoesNotMatchRegularExpression('/\b(from|join)\s+"?'.$table.'"?\b/', $sql, "the Network report read {$table}");
            }
            // The request lifecycle's defensive RESET is fine; nothing ever SETS a School.
            $this->assertStringNotContainsString('set_config(', $sql, 'never a School context (TenantContext sets it with set_config)');
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete)\b/', $sql);
        }
        $html = (string) $response->getContent();
        foreach (['/elevation', '/activate', 'canEnter'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, 'the report offers no way into a School');
        }
    }

    #[Test]
    public function query_count_does_not_grow_with_the_number_of_member_schools(): void
    {
        $counts = [];
        foreach ([1, 10, 100] as $size) {
            $group = $this->createGroup(array_map(fn () => $this->createSchool(), range(1, $size)), "Scale {$size}");
            $viewer = $this->createUser();
            $this->grantGroupRole($viewer, $group, 'network_viewer', $this->root);
            $this->actingAs($viewer)->get("/app/groups/{$group->id}/reports/network")->assertOk();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($viewer)->get("/app/groups/{$group->id}/reports/network")->assertOk();
            $counts[$size] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }
        $perRun = $counts;
        $this->assertSame($perRun[1], $perRun[10], 'no per-School query (1 vs 10): '.json_encode($counts));
        $this->assertSame($perRun[1], $perRun[100], 'no per-School query (1 vs 100): '.json_encode($counts));
        $this->assertLessThanOrEqual(20, $perRun[1], 'whole page, shell included (measured 14)');
    }

    #[Test]
    public function the_read_model_handles_five_hundred_memberships_in_a_fixed_number_of_queries(): void
    {
        $group = $this->createGroup([], 'Five hundred');
        $rows = [];
        foreach (range(1, 500) as $i) {
            $school = School::factory()->create();
            $rows[] = ['id' => (string) Str::uuid7(), 'school_group_id' => $group->id, 'school_id' => $school->id, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('school_group_members')->insert($rows);
        $service = app(NetworkReportReadService::class);
        $directory = app(SchoolGroupDirectoryReadService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $scope = GroupGovernanceScope::whole();
        $lifecycle = $service->lifecycle($group, $scope);
        $distribution = $service->distribution($group, $scope);
        $page = $directory->directory($group, [], 1, $scope);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(500, array_sum($lifecycle));
        $this->assertSame(500, $distribution['unplaced']);
        $this->assertSame([25, 500], [count($page->items()), $page->total()]);
        $this->assertLessThanOrEqual(6, $n, "{$n} queries for 500 memberships");
    }
}
