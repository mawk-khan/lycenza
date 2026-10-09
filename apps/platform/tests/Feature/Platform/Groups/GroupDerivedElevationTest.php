<?php

namespace Tests\Feature\Platform\Groups;

use App\Domain\Platform\Application\Elevation\ElevationAudit;
use App\Domain\Platform\Application\Groups\SchoolGroupGovernanceService;
use App\Http\Middleware\RequireSchoolContext;
use App\Http\Middleware\ResolvePlatformElevation;
use App\Models\GroupRoleAssignment;
use App\Models\Role;
use App\Models\SchoolElevation;
use App\Models\SchoolMembership;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0N.5 (ADR 0045 sections 6-12): the Group Admin's view, and entry
 * into ONE member School through the SAME ADR 0044 elevation -- recording
 * the authorizing Group and grant, ended the moment that authority goes,
 * never falling back to another authority, and opening no School page.
 */
class GroupDerivedElevationTest extends TestCase
{
    use CreatesTenancyFixtures, GroupTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        RouteFacade::middleware(['web', 'auth', 'school-context:elevated'])
            ->get('/__test/group-elevation-safe', fn () => response()->json([
                'school' => app(TenantContext::class)->requireSchool()->id,
                'guc' => DB::selectOne("select current_setting('app.current_school_id', true) as v")->v,
            ]));
    }

    // --- Group view ---------------------------------------------------------

    #[Test]
    public function a_group_admin_sees_only_their_groups_metadata_and_member_schools(): void
    {
        $schoolA = $this->createSchool(['name' => 'Alpha']);
        $schoolB = $this->createSchool(['name' => 'Beta', 'status' => 'suspended']);
        $this->createStudent($schoolA);
        $group = $this->createGroup([$schoolA, $schoolB], 'Own Trust');
        $other = $this->createGroup([$this->createSchool(['name' => 'Elsewhere'])], 'Other Trust');
        $admin = $this->groupAdmin($group);

        $this->actingAs($admin)->get('/app')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('groups.canViewOwn', true)
            ->where('groups.canGovern', false)
            ->where('platformElevation.canStart', false)
        );
        $this->get('/app/groups')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->has('groups', 1)
            ->where('groups.0.name', 'Own Trust')
        );

        $response = $this->get("/app/groups/{$group->id}");
        $response->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('App/Groups/Show')
            ->where('group', ['id' => $group->id, 'name' => 'Own Trust', 'status' => 'active'])
            ->has('schools', 2)
            ->where('schools.0', ['id' => $schoolA->id, 'name' => 'Alpha', 'status' => 'active', 'canEnter' => true, 'isMember' => false])
            ->where('schools.1', ['id' => $schoolB->id, 'name' => 'Beta', 'status' => 'suspended', 'canEnter' => false, 'isMember' => false])
            ->missing('grants')
        );
        $this->assertStringNotContainsString('Elsewhere', $response->getContent());

        // Group metadata never sets School context.
        $this->assertSame('', (string) DB::selectOne("select current_setting('app.current_school_id', true) as v")->v);

        $this->get("/app/groups/{$other->id}")->assertNotFound();
        $this->get('/app/groups/'.Str::uuid())->assertNotFound();
        $this->get("/app/groups/{$other->id}/elevation?school={$schoolA->id}")->assertNotFound();
        $this->get("/app/groups/{$group->id}/elevation?school={$other->schools()->value('schools.id')}")->assertNotFound();

        // No platform governance through Group authority.
        $this->get('/app/platform/groups')->assertForbidden();
        $this->post("/app/platform/groups/{$group->id}/schools", ['school' => $this->createSchool()->id])->assertForbidden();
    }

    #[Test]
    public function an_ordinary_member_of_several_schools_holds_no_group_authority(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $group = $this->createGroup([$schoolA, $schoolB]);
        $member = $this->createUser();
        $this->assignSchoolRole($this->createMembership($member, $schoolA), 'school_admin');
        $this->assignSchoolRole($this->createMembership($member, $schoolB), 'principal');
        $this->enrollActiveMfaFactor($member);

        $this->assertSame([], app(CapabilityResolver::class)->groupCapabilities($member, $group));
        $this->assertFalse(app(CapabilityResolver::class)->can($member, 'group.schools.view', $schoolA));

        $this->actingAs($member)->get('/app')->assertInertia(fn (AssertableInertia $p) => $p->where('groups.canViewOwn', false));
        $this->get('/app/groups')->assertInertia(fn (AssertableInertia $p) => $p->has('groups', 0));
        $this->get("/app/groups/{$group->id}")->assertNotFound();
        $this->post("/app/groups/{$group->id}/elevation", ['target' => $schoolA->id, 'reason_code' => 'operational_support', 'confirmed' => '1', 'code' => '000000'])
            ->assertForbidden();
        $this->assertDenied($member, 'group_grant_missing');
    }

    // --- Group-derived start --------------------------------------------------

    #[Test]
    public function a_group_admin_enters_one_member_school_with_group_provenance_and_zero_school_permissions(): void
    {
        $this->freezeSecond();
        $school = $this->createSchool(['name' => 'Member School']);
        $group = $this->createGroup([$school], 'Provenance Trust');
        $admin = $this->groupAdmin($group);
        $grant = GroupRoleAssignment::query()->where('user_id', $admin->id)->firstOrFail();

        $this->actingAs($admin)->get("/app/groups/{$group->id}/elevation?school={$school->id}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('App/Platform/EnterSchool')
            ->where('group', ['id' => $group->id, 'name' => 'Provenance Trust'])
            ->where('old.target', $school->id)
        );
        $this->post("/app/groups/{$group->id}/elevation/confirm", ['target' => $school->id, 'reason_code' => 'incident_response'])
            ->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('App/Platform/ConfirmEnterSchool')
            ->where('schoolName', 'Member School')
            ->where('group.name', 'Provenance Trust')
            );

        $elevation = $this->elevateViaGroup($admin, $group, $school, ['reason_code' => 'incident_response']);

        $this->assertSame('group', $elevation->authority_type);
        $this->assertSame($group->id, $elevation->school_group_id);
        $this->assertSame($grant->id, $elevation->group_role_assignment_id);
        $this->assertTrue($elevation->expires_at->equalTo(now()->addMinutes(30)));
        $this->assertSame($elevation->id, session(ResolvePlatformElevation::SESSION_KEY));
        $this->assertSame(0, SchoolMembership::query()->where('user_id', $admin->id)->count());

        [$started] = $this->elevationEvents($admin, ElevationAudit::STARTED);
        $this->assertEquals([
            'elevation_id' => $elevation->id,
            'reason_code' => 'incident_response',
            'expires_at' => $elevation->expires_at->toIso8601String(),
            'authority_type' => 'group',
            'school_group_id' => $group->id,
            'group_role_assignment_id' => $grant->id,
        ], $started->metadata);

        $this->get('/app')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('elevation.schoolName', 'Member School')
            ->where('elevation.groupName', 'Provenance Trust')
            ->where('platformElevation.isElevated', true)
        );

        $this->assertSame([], app(CapabilityResolver::class)->schoolCapabilities($admin, $school));

        // Every existing School route still refuses it; only an explicit
        // opt-in route gets exactly the one School.
        $refused = 0;
        foreach (app(Router::class)->getRoutes()->getRoutes() as $route) {
            if (in_array('school-context', $route->gatherMiddleware(), true) && in_array('GET', $route->methods(), true)) {
                $uri = '/'.preg_replace('/\{[^}]+\}/', (string) Str::uuid(), $route->uri());
                $this->assertSame(403, $this->getJson($uri)->getStatusCode(), $uri);
                $refused++;
            }
        }
        $this->assertGreaterThan(190, $refused);

        $this->getJson('/__test/group-elevation-safe')->assertOk()
            ->assertJsonPath('school', $school->id)
            ->assertJsonPath('guc', $school->id);

        // API stays membership-only.
        $token = $admin->createToken('device')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/schools/{$school->id}/context")->assertNotFound();
    }

    #[Test]
    public function group_authority_is_checked_at_every_step_and_refusals_are_audited(): void
    {
        $inGroup = $this->createSchool();
        $outside = $this->createSchool();
        $group = $this->createGroup([$inGroup]);
        $admin = $this->groupAdmin($group);

        $this->startGroupElevation($admin, $group, $outside)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('errors.target', 'That School cannot be entered.')
        );
        $denial = $this->assertDenied($admin, 'school_not_in_group');
        $this->assertEquals(['outcome_code' => 'school_not_in_group', 'reason_code' => 'operational_support', 'authority_type' => 'group', 'school_group_id' => $group->id], $denial->metadata);

        // A Group role without group.schools.elevate.
        $viewOnly = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => 'test_group_viewer', 'name' => 'Viewer', 'scope' => 'group', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $viewOnly->capabilities()->sync(['group.schools.view']));
        $viewer = $this->createUser();
        $this->enrollActiveMfaFactor($viewer);
        $this->grantGroupRole($viewer, $group, 'test_group_viewer');
        $this->actingAs($viewer)->post("/app/groups/{$group->id}/elevation", ['target' => $inGroup->id, 'reason_code' => 'operational_support', 'confirmed' => '1', 'code' => '000000'])
            ->assertForbidden();
        $this->assertDenied($viewer, 'group_capability_missing');
        $this->get("/app/groups/{$group->id}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('schools.0.canEnter', false));

        // A member of the target School uses ordinary selection instead.
        $this->createMembership($admin, $inGroup);
        $this->startGroupElevation($admin, $group, $inGroup)->assertOk();
        $this->assertDenied($admin, 'actor_is_member');

        // Archived Group.
        $archivedGroup = $this->createGroup([$outside]);
        $archivedAdmin = $this->groupAdmin($archivedGroup);
        DB::table('school_groups')->where('id', $archivedGroup->id)->update(['status' => 'archived']);
        $this->startGroupElevation($archivedAdmin, $archivedGroup, $outside)->assertForbidden();
        $this->assertDenied($archivedAdmin, 'group_inactive');

        $this->assertSame(0, SchoolElevation::query()->count());
    }

    #[Test]
    public function with_several_groups_the_chosen_group_is_the_only_authority_recorded(): void
    {
        $school = $this->createSchool();
        $groupA = $this->createGroup([$school], 'A');
        $groupB = $this->createGroup([$school], 'B');
        $admin = $this->groupAdmin($groupA);
        $grantB = $this->grantGroupRole($admin, $groupB);

        $elevation = $this->elevateViaGroup($admin, $groupB, $school);

        $this->assertSame($groupB->id, $elevation->school_group_id);
        $this->assertSame($grantB->id, $elevation->group_role_assignment_id);

        // Group A's authority is irrelevant: removing the School from B ends
        // it although A still contains the School and A's grant is intact.
        app(SchoolGroupGovernanceService::class)->removeSchool($this->platformAdmin(), $groupB, $school);
        $this->assertSame('school_left_group', $elevation->refresh()->end_reason);
        $this->assertSame('terminated', $elevation->status);
    }

    // --- Losing Group authority --------------------------------------------------

    #[Test]
    public function removing_the_school_revoking_the_grant_or_archiving_the_group_ends_it_immediately_and_audited(): void
    {
        $platform = $this->platformAdmin();
        $governance = app(SchoolGroupGovernanceService::class);

        $cases = [
            'school_left_group' => fn ($group, $school, $grant) => $governance->removeSchool($platform, $group, $school),
            'group_authority_revoked' => fn ($group, $school, $grant) => $governance->revoke($platform, $grant),
            'group_inactive' => fn ($group, $school, $grant) => $governance->archive($platform, $group),
        ];

        foreach ($cases as $reason => $operation) {
            $school = $this->createSchool();
            $group = $this->createGroup([$school]);
            $admin = $this->groupAdmin($group);
            $grant = GroupRoleAssignment::query()->where('user_id', $admin->id)->firstOrFail();
            $elevation = $this->elevateViaGroup($admin, $group, $school);

            $operation($group, $school, $grant);

            $elevation->refresh();
            $this->assertSame('terminated', $elevation->status, $reason);
            $this->assertSame($reason, $elevation->end_reason);
            [$terminated] = $this->elevationEvents($admin, ElevationAudit::TERMINATED);
            $this->assertEquals([
                'elevation_id' => $elevation->id,
                'end_reason' => $reason,
                'authority_type' => 'group',
                'school_group_id' => $group->id,
                'group_role_assignment_id' => $grant->id,
            ], $terminated->metadata);

            // The actor's session no longer restores anything, and learns why.
            $this->actingAs($admin)->get('/app')->assertInertia(fn (AssertableInertia $p) => $p
                ->where('elevation', null)
                ->where('platformElevation.notice', $reason)
            );
            $this->assertNull(session(ResolvePlatformElevation::SESSION_KEY));
        }
    }

    #[Test]
    public function a_change_made_outside_the_governance_service_is_still_caught_on_the_next_request(): void
    {
        $school = $this->createSchool();
        $group = $this->createGroup([$school]);
        $admin = $this->groupAdmin($group);
        $elevation = $this->elevateViaGroup($admin, $group, $school);

        // Role loses the capability (no in-app path yet): the uncached Group
        // check sees it at once.
        $groupAdmin = Role::query()->where('key', 'group_admin')->value('id');
        LocalCatalogueFixtures::asOwner(fn () => DB::table('role_capabilities')->where('role_id', $groupAdmin)
            ->where('capability_key', 'group.schools.elevate')->delete());

        $this->get('/app')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('elevation', null)
            ->where('platformElevation.notice', 'group_authority_revoked')
        );
        $this->assertSame('group_authority_revoked', $elevation->refresh()->end_reason);
    }

    #[Test]
    public function losing_group_authority_never_falls_back_to_platform_authority(): void
    {
        $school = $this->createSchool();
        $group = $this->createGroup([$school]);
        // A platform operator who ALSO holds a Group grant (given by someone else).
        $actor = $this->platformAdmin();
        $grant = $this->grantGroupRole($actor, $group);
        $elevation = $this->elevateViaGroup($actor, $group, $school);
        $this->assertSame('group', $elevation->authority_type);

        app(SchoolGroupGovernanceService::class)->revoke($this->platformAdmin(), $grant);

        $this->actingAs($actor)->get('/app')->assertInertia(fn (AssertableInertia $p) => $p->where('elevation', null));
        $this->assertSame('group_authority_revoked', $elevation->refresh()->end_reason);
        $this->assertSame(0, SchoolElevation::query()->where('actor_user_id', $actor->id)->where('status', 'active')->count());
        $this->getJson('/__test/group-elevation-safe')->assertStatus(RequireSchoolContext::STATUS);
    }

    #[Test]
    public function a_platform_derived_elevation_is_unaffected_by_group_changes(): void
    {
        $school = $this->createSchool();
        $group = $this->createGroup([$school]);
        $admin = $this->platformAdmin();
        $elevation = $this->elevate($admin, $school);

        app(SchoolGroupGovernanceService::class)->removeSchool($this->platformAdmin(), $group, $school);

        $this->get('/app')->assertInertia(fn (AssertableInertia $p) => $p->where('platformElevation.isElevated', true));
        $this->assertSame('active', $elevation->refresh()->status);
        $this->assertSame('platform', $elevation->authority_type);
    }
}
