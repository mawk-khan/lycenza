<?php

namespace Tests\Feature\Demo;

use App\Domain\Platform\Application\Groups\GroupGovernanceAccess;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolGroup;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use Database\Seeders\Demo\DemoDataBuilder;
use Database\Seeders\Demo\DemoEnvironmentGuard;
use Database\Seeders\Demo\DemoOrganizationData;
use Database\Seeders\Demo\DemoOrganizationVerifier;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\TestCase;

/**
 * ORG.12E-D1: the canonical Organization demo world, built against
 * school_os_test inside the suite's rolled-back transaction (never a
 * universal fixture -- only the Demo tests build it).
 *
 * - The verifier passes on a fresh build (topology, relationships,
 *   standalone customers, persona visibility through the real resolvers).
 * - The demo can run only in local DDEV (or this verified test database):
 *   production and staging are refused before anything is written.
 * - It adds no role, capability, policy table or Group outside the demo
 *   world, and Network personas hold no School capability.
 */
class DemoOrganizationDataTest extends TestCase
{
    use ProvidesSensitiveActionMfa;

    #[Test]
    public function the_canonical_organization_world_builds_and_verifies(): void
    {
        $rolesBefore = Role::query()->count();
        $capabilitiesBefore = DB::table('capabilities')->count();

        app(DemoDataBuilder::class)->build();
        $verifier = new DemoOrganizationVerifier;

        $this->assertSame([], $verifier->problems());
        $this->assertSame([
            'network' => DemoOrganizationData::NETWORK_NAME,
            'regions' => 2,
            'zones' => 3,
            'networkSchools' => 6,
            'schools' => 8,
            'groups' => 2,
            'networkGrants' => 5,
        ], $verifier->summary());

        // Only the existing demo-only statutory role is added; no product role or capability for the demo world.
        $this->assertSame($rolesBefore + 1, Role::query()->count());
        $this->assertSame($capabilitiesBefore, DB::table('capabilities')->count());
        $this->assertSame([], DB::table('information_schema.tables')->whereRaw('table_schema = current_schema()')->where('table_name', 'like', '%sensitive%')->pluck('table_name')->all());
    }

    #[Test]
    public function personas_see_exactly_their_context_and_the_unclassified_school_is_attention(): void
    {
        app(DemoDataBuilder::class)->build();
        $network = SchoolGroup::query()->where('slug', DemoOrganizationData::NETWORK_SLUG)->firstOrFail();
        $user = fn (string $email): User => User::query()->where('email', $email)->firstOrFail();

        $this->actingAs($user('network.viewer@example.test'))->get("/app/groups/{$network->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->where('overview.attention.unclassified', 1)->where('directory.total', 6)->where('canManage', false));
        $this->actingAs($user('network.zone@example.test'))->get("/app/groups/{$network->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->where('directory.total', 2)->where('overview.attention.unclassified', 0));
        $this->actingAs($user('network.manager@example.test'))->get("/app/groups/{$network->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->where('canManage', true)->where('canManageOperators', true));

        // The shared identity: Gachibowli Principal, nothing in Secunderabad, Karnataka-only Network view.
        $shared = $user('network.shared@example.test');
        $gachibowli = School::query()->where('slug', 'northstar-gachibowli')->firstOrFail();
        $secunderabad = School::query()->where('slug', 'northstar-secunderabad')->firstOrFail();
        $this->assertNotSame([], app(CapabilityResolver::class)->schoolCapabilities($shared, $gachibowli));
        $this->assertSame([], app(CapabilityResolver::class)->schoolCapabilities($shared, $secunderabad));
        $this->assertFalse(app(GroupGovernanceAccess::class)->mayManage($shared, $network));
    }

    #[Test]
    public function the_integration_hub_shows_each_demo_state_and_never_a_secret(): void
    {
        app(DemoDataBuilder::class)->build();
        $user = fn (string $email): User => User::query()->where('email', $email)->firstOrFail();
        $school = fn (string $slug): School => School::query()->where('slug', $slug)->firstOrFail();
        $statuses = function (string $email, string $slug) use ($user, $school): array {
            $this->actingAs($user($email))->post("/app/schools/{$school($slug)->id}/activate");
            $this->withMfaAssurance($user($email)); // API client status needs current MFA, as its own page does
            $response = $this->get('/app/school-setup/integrations')->assertOk();
            $cards = [];
            foreach ($response->viewData('page')['props']['hub']['sections'] as $section) {
                foreach ($section['items'] as $card) {
                    $cards[$card['key']] = $card['status'];
                }
            }

            return [$cards, (string) $response->getContent()];
        };

        [$gachibowli, $html] = $statuses('gachibowli.admin@example.test', 'northstar-gachibowli');
        $this->assertSame(['healthy', 'configured', 'not_available', 'not_available'], [$gachibowli['integrations.webhooks'], $gachibowli['integrations.api_clients'], $gachibowli['communications.sms'], $gachibowli['transport.vts']]);
        foreach (['hooks.gachibowli.northstar', 'Northstar reporting feed', 'Northstar timetable sync', 'partner.probe.read'] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }
        [$horizon] = $statuses('horizon.admin@example.test', 'horizon-academy');
        $this->assertSame(['not_configured', 'attention_required'], [$horizon['integrations.webhooks'], $horizon['integrations.api_clients']]);
        [$greenfield] = $statuses('greenfield.admin@example.test', 'greenfield-public');
        $this->assertSame(['not_applicable', 'not_applicable', 'not_available'], [$greenfield['integrations.webhooks'], $greenfield['integrations.api_clients'], $greenfield['payments.gateway']]);

        // A Network Manager governs the Network, never a member School's integrations.
        $this->actingAs($user('network.manager@example.test'))->post("/app/schools/{$school('northstar-gachibowli')->id}/activate");
        $this->assertNotSame(200, $this->get('/app/school-setup/integrations')->status());
    }

    #[Test]
    public function the_organization_reports_show_each_demo_scope_and_state(): void
    {
        app(DemoDataBuilder::class)->build();
        $network = SchoolGroup::query()->where('slug', DemoOrganizationData::NETWORK_SLUG)->firstOrFail();
        $user = fn (string $email): User => User::query()->where('email', $email)->firstOrFail();
        $networkReport = fn (string $email): array => $this->actingAs($user($email))->get("/app/groups/{$network->id}/reports/network")->assertOk()->viewData('page')['props'];

        $whole = $networkReport('network.viewer@example.test');
        $this->assertSame([6, 1, 1], [$whole['overview']['schools']['total'], $whole['overview']['relationships']['unclassified'], $whole['distribution']['unplaced']]);
        $this->assertContains('NHS-MYS', array_column($whole['directory']['data'], 'code'), 'the whole-Network viewer sees unclassified Mysuru');
        $region = $networkReport('network.region@example.test');
        $this->assertSame([4, ['TELANGANA'], null], [$region['overview']['schools']['total'], array_column($region['distribution']['regions'], 'code'), $region['distribution']['unplaced']]);
        $zone = $networkReport('network.zone@example.test');
        $this->assertEqualsCanonicalizing(['NAC-SEC', 'NEL-JBH'], array_column($zone['directory']['data'], 'code'));

        $schoolReport = function (string $email, string $slug) use ($user): array {
            $school = School::query()->where('slug', $slug)->firstOrFail();
            $this->actingAs($user($email))->post("/app/schools/{$school->id}/activate");

            return $this->get('/app/school-setup/organization-report')->assertOk()->viewData('page')['props']['report'];
        };
        $gachibowli = $schoolReport('gachibowli.admin@example.test', 'northstar-gachibowli');
        $this->assertNotSame([], $gachibowli['gaps'], 'Gachibowli is partial / blocked');
        $this->assertSame('network', $gachibowli['presentation']['brandSource']);
        $this->assertSame(1, $gachibowli['access']['campusAssignments'], 'the Campus Cashier');
        $this->assertSame(3, $gachibowli['access']['wholeSchoolAssignments'], 'admin, accountant, shared Principal -- never the disabled former member');
        $greenfield = $schoolReport('greenfield.admin@example.test', 'greenfield-public');
        $this->assertNotSame([], $greenfield['setup']['notEnabled']);
        $this->assertSame('system', $greenfield['presentation']['brandSource']);
        $this->assertSame('school', $schoolReport('jubilee.admin@example.test', 'northstar-jubilee-hills')['presentation']['brandSource']);
        $demo = $schoolReport('school.admin@example.test', DemoDataBuilder::SCHOOL_SLUG);
        $this->assertContains($demo['setup']['overview']['state'], ['complete', 'core_complete'], 'the Demo School is configured');

        // A Network role never opens a member School's report.
        $this->actingAs($user('network.manager@example.test'))->post("/app/schools/{$network->schools()->first()->id}/activate");
        $this->assertNotSame(200, $this->get('/app/school-setup/organization-report')->status());
    }

    #[Test]
    public function the_demo_world_is_refused_outside_local_ddev(): void
    {
        foreach (['production', 'staging'] as $environment) {
            try {
                DemoEnvironmentGuard::assertLocalDdevOrTesting($environment, 'true', ['pgsql' => ['host' => 'db', 'database' => 'db'], 'pgsql_admin' => ['host' => 'db', 'database' => 'db']], 'school_os_test');
                $this->fail("{$environment} was not refused");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('refused', $e->getMessage());
            }
        }
        // A direct call into the Organization world is guarded too (review L1):
        // with the database identity check failing, nothing is written.
        config(['database.testing_database' => 'not_the_test_database']);
        $schools = School::query()->count();
        try {
            (new DemoOrganizationData(app(DemoDataBuilder::class)))->build();
            $this->fail('the Organization world was built outside the guard');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('refused', $e->getMessage());
        }
        $this->assertSame($schools, School::query()->count());

        // The Organization world is only reachable through the guarded builder.
        $source = (string) file_get_contents(base_path('database/seeders/Demo/DemoOrganizationData.php'));
        $this->assertStringNotContainsString('Http::', $source);
        $this->assertStringNotContainsString('Mail::', $source);
        $this->assertStringNotContainsString('FreshMfaProof', $source, 'the demo never fabricates an MFA proof');
    }
}
