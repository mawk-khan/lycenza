<?php

namespace Tests\Feature\Organization;

use App\Models\School;
use App\Models\User;
use App\Support\Integrations\IntegrationHubReadService;
use App\Support\Reports\SchoolControlPlaneReport;
use App\Support\Setup\OrganizationSetupRegistry;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesGuardianPortalFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Platform\Groups\GroupTestHelpers;
use Tests\TestCase;

/**
 * ORG.12H (ADR 0080 §13.2): the School Control Plane report -- one School,
 * read-only, every section from its existing owner, each section behind
 * its own authority; never a person, record, amount or another School.
 */
class OrganizationReportTest extends TestCase
{
    use CreatesGuardianPortalFixtures, CreatesTenancyFixtures, GroupTestHelpers;

    private const string PAGE = '/app/school-setup/organization-report';

    /** Measured 68 (setup readiness ~51 + integrations, presentation, access and the shell); budget 68 plus 12. */
    private const int PAGE_QUERY_BUDGET = 80;

    private function enter(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    /** @return array<string, mixed> */
    private function report(TestResponse $response): array
    {
        $response->assertOk();
        $this->assertSame('App/SchoolSetup/OrganizationReport', $response->viewData('page')['component']);

        return $response->viewData('page')['props']['report'];
    }

    #[Test]
    public function the_school_administrator_reads_every_section_derived_from_the_existing_owners(): void
    {
        $school = $this->createSchool();
        $admin = $this->schoolAdminFor($school);
        $this->enter($admin, $school);
        $report = $this->report($this->get(self::PAGE));

        $this->assertSame(['organization.setup_summary', 'organization.configuration_gaps', 'organization.integration_summary', 'organization.presentation_summary', 'organization.access_summary'], $report['reports']);

        // Setup comes from the ORG.12A registry: every applicable area is counted once.
        $registry = app(OrganizationSetupRegistry::class);
        $counted = array_sum(array_column($report['setup']['categories'], 'applicable')) + count($report['setup']['notEnabled']);
        $this->assertSame(count($registry->keys()), $counted);

        // Gaps: never a configured or not-applicable area; blocked first; actions resolved for this viewer.
        $statuses = array_column($report['gaps'], 'status');
        $this->assertNotContains('configured', $statuses);
        $this->assertNotContains('not_applicable', $statuses);
        $this->assertSame('configure', collect($report['gaps'])->firstWhere('key', 'organization.school_profile')['action']['kind']);

        $hub = app(IntegrationHubReadService::class)->forViewer($school, $admin);
        $this->assertSame($hub['overview']['counts'], $report['integrations']['counts'], 'integration figures are the ORG.12G Hub\'s own');
        $this->assertSame(['system', ['staff_web' => 0, 'guardian_portal' => 0], false], [$report['presentation']['brandSource'], $report['presentation']['navigation'], $report['presentation']['mediaAvailable']]);
        $this->assertSame(1, $report['access']['wholeSchoolAssignments']);
        $this->assertSame(0, $report['access']['campusAssignments']);
        $this->assertSame(SchoolControlPlaneReport::SENSITIVE_STATUS, $report['access']['sensitiveData']['status']);
        $this->assertFalse($report['access']['sensitiveData']['configurable'], 'the deny-only policy stays deferred (ORG.12E)');
    }

    #[Test]
    public function access_counts_are_staff_grants_only_on_active_campuses(): void
    {
        $school = $this->createSchool();
        $admin = $this->schoolAdminFor($school);
        $this->portalGuardian($school); // a linked Guardian with an active `guardian` scope grant
        $inactive = $this->createCampus($school);
        $this->assignSchoolRole($this->createMembership($this->createUser(), $school), 'cashier', $inactive->id);
        app(TenantContext::class)->withSchool($school, fn () => DB::table('campuses')->where('id', $inactive->id)->update(['status' => 'inactive']));

        $this->enter($admin, $school);
        $access = $this->report($this->get(self::PAGE))['access'];
        // The School admin and the fixture's portal administrator: never the Guardian portal grant, never the inactive-Campus grant.
        $this->assertSame([2, 0], [$access['wholeSchoolAssignments'], $access['campusAssignments']]);
    }

    #[Test]
    public function blocked_gaps_lead_and_each_gap_names_its_owner(): void
    {
        $school = $this->createSchool();
        $this->enter($this->schoolAdminFor($school), $school);
        $gaps = $this->report($this->get(self::PAGE))['gaps'];

        $rank = ['blocked' => 0, 'attention_required' => 1, 'partially_configured' => 2, 'not_configured' => 3];
        $ranks = array_map(fn (array $gap): int => $rank[$gap['status']], $gaps);
        $sorted = $ranks;
        sort($sorted);
        $this->assertSame($sorted, $ranks, 'blocked, attention, partial, not configured');
        foreach ($gaps as $gap) {
            $this->assertNotSame('', $gap['owner']);
            $this->assertNotSame('', $gap['explanation']);
        }
    }

    #[Test]
    public function sections_follow_their_own_authority(): void
    {
        $school = $this->createSchool();
        $principal = $this->createUser();
        $this->assignSchoolRole($this->createMembership($principal, $school), 'principal');
        $this->enter($principal, $school);
        $report = $this->report($this->get(self::PAGE));

        $this->assertNotNull($report['integrations'], 'the Principal holds school.settings.view');
        $this->assertNotNull($report['presentation']);
        $this->assertNull($report['access'], 'no school.roles.view: no access section');
        $this->assertNotContains('organization.access_summary', $report['reports']);

        $setupOnly = $this->createUserWithCapabilities($school, ['school.profile.view']);
        $this->enter($setupOnly, $school);
        $report = $this->report($this->get(self::PAGE));
        $this->assertSame([null, null, null], [$report['integrations'], $report['presentation'], $report['access']]);
    }

    #[Test]
    public function staff_without_the_floor_campus_only_platform_and_network_actors_are_refused(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);

        $teacher = $this->createUser();
        $this->assignSchoolRole($this->createMembership($teacher, $school), 'teacher');
        $this->enter($teacher, $school);
        $this->get(self::PAGE)->assertForbidden();

        $campusOnly = $this->createUser();
        LocalCatalogueFixtures::grantRole($this->createMembership($campusOnly, $school), $this->createFixtureRole(['school.profile.view', 'school.settings.view', 'school.roles.view']), $campus->id);
        $this->enter($campusOnly, $school);
        $this->get(self::PAGE)->assertForbidden();

        $root = $this->createPlatformRoot();
        $manager = $this->createUser();
        $this->assignPlatformRole($manager, 'platform_manager', $root);
        $group = $this->createGroup([$school]);
        $networkManager = $this->createUser();
        $this->grantGroupRole($networkManager, $group, 'network_manager', $root);
        foreach (['root' => $root, 'platform manager' => $manager, 'network manager' => $networkManager, 'group admin' => $this->groupAdmin($group)] as $who => $user) {
            $this->enter($user, $school);
            $this->assertNotSame(200, $this->get(self::PAGE)->status(), "{$who} must not open a School's report");
        }

        $this->enter($this->schoolAdminFor($school), $school);
        $school->forceFill(['status' => 'suspended'])->save();
        $this->assertNotSame(200, $this->get(self::PAGE)->status(), 'an inactive School is refused');
    }

    #[Test]
    public function optional_modules_not_enabled_are_listed_never_counted_as_gaps(): void
    {
        $school = School::factory()->withCoreModulesOnly()->create();
        $this->enter($this->schoolAdminFor($school), $school);
        $report = $this->report($this->get(self::PAGE));

        $this->assertNotEmpty($report['setup']['notEnabled']);
        $gapOwners = array_column($report['gaps'], 'title');
        foreach ($report['setup']['notEnabled'] as $title) {
            $this->assertNotContains($title, $gapOwners, "{$title} is not enabled, so it is never a gap");
        }
        $this->assertSame(0, $report['setup']['overview']['optional']['total']);
    }

    #[Test]
    public function a_failing_section_is_reported_unavailable_and_the_page_still_renders(): void
    {
        $school = $this->createSchool();
        $this->partialMock(IntegrationHubReadService::class, fn ($mock) => $mock->shouldReceive('forViewer')->andThrow(new \RuntimeException('quarvex-hub-leak')));
        $this->enter($this->schoolAdminFor($school), $school);
        $response = $this->get(self::PAGE);
        $report = $this->report($response);

        $this->assertSame(['available' => false], $report['integrations']);
        $this->assertTrue($report['presentation']['available']);
        $this->assertTrue($report['access']['available']);
        $this->assertStringNotContainsString('quarvex-hub-leak', (string) $response->getContent());
    }

    #[Test]
    public function each_school_reports_only_itself_and_a_shared_user_follows_the_selected_school(): void
    {
        $a = $this->createSchool();
        $b = School::factory()->withCoreModulesOnly()->create();
        $shared = $this->schoolAdminFor($a);
        $this->assignSchoolRole($this->createMembership($shared, $b), 'school_admin');
        $this->assignSchoolRole($this->createMembership($this->createUser(), $a), 'teacher');

        $this->enter($shared, $a);
        $inA = $this->report($this->get(self::PAGE));
        $this->enter($shared, $b);
        $inB = $this->report($this->get(self::PAGE));

        $this->assertSame([$a->name, 2], [$inA['school']['name'], $inA['access']['wholeSchoolAssignments']]);
        $this->assertSame([$b->name, 1], [$inB['school']['name'], $inB['access']['wholeSchoolAssignments']]);
        $this->assertSame([], $inA['setup']['notEnabled']);
        $this->assertNotSame([], $inB['setup']['notEnabled'], 'entitlement is per School');
    }

    #[Test]
    public function the_page_carries_no_person_writes_nothing_and_stays_within_its_budget(): void
    {
        $school = $this->createSchool();
        $admin = $this->schoolAdminFor($school);
        $admin->forceFill(['name' => 'Quarvex Adminperson'])->save();
        $this->createStudent($school, ['first_name' => 'Xanthippe', 'last_name' => 'Quorvald']);
        $this->enter($admin, $school);
        $this->get(self::PAGE)->assertOk();
        $ledgers = fn (): array => [DB::table('school_audit_events')->count(), DB::table('platform_audit_events')->count(), DB::table('domain_event_outbox')->count()];
        $before = $ledgers();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->get(self::PAGE)->assertOk();
        $statements = array_map(fn (array $q): string => strtolower(ltrim($q['query'])), DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame([], array_values(array_filter($statements, fn (string $sql): bool => preg_match('/^(insert|update|delete)\b/', $sql) === 1)));
        $this->assertSame($before, $ledgers());
        $this->assertLessThanOrEqual(self::PAGE_QUERY_BUDGET, count($statements), 'used '.count($statements).' queries');
        $props = json_encode($response->viewData('page')['props']['report']);
        foreach (['Xanthippe', 'Quorvald', $admin->email] as $absent) {
            $this->assertStringNotContainsString($absent, (string) $props);
        }
        $this->assertLessThan(60_000, strlen((string) $props));
    }
}
