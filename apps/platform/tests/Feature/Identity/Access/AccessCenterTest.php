<?php

namespace Tests\Feature\Identity\Access;

use App\Domain\Identity\Application\Access\AccessCatalogueReader;
use App\Domain\Identity\Application\Access\SensitiveDataCatalogue;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Platform\Groups\GroupTestHelpers;
use Tests\TestCase;

/**
 * ORG.12E (ADR 0080 §10.7-§10.8): the read-only School Access Center.
 *
 * - Catalogue (role templates, capabilities by module, role matrix,
 *   sensitive-data catalogue): school.members.view or school.roles.view.
 * - Assignments and effective access: school.roles.view, THIS School only.
 * - Effective access is the canonical resolver's answer, explained by
 *   assignment; never another School, never Group or platform authority.
 * - Nothing mutates; the sensitive-data catalogue offers no switch.
 */
class AccessCenterTest extends TestCase
{
    use CreatesTenancyFixtures, GroupTestHelpers;

    private function enter(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    private function member(School $school, array $roles, array $campusRoles = []): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        foreach ($roles as $role) {
            $this->assignSchoolRole($membership, $role);
        }
        foreach ($campusRoles as [$role, $campusId]) {
            $this->assignSchoolRole($membership, $role, $campusId);
        }

        return $user;
    }

    private function membershipId(User $user, School $school): string
    {
        return (string) DB::table('school_memberships')->where('user_id', $user->id)->where('school_id', $school->id)->value('id');
    }

    #[Test]
    public function the_catalogue_is_open_to_role_and_member_viewers_and_closed_to_others(): void
    {
        $school = $this->createSchool();
        $admin = $this->schoolAdminFor($school);
        $principal = $this->member($school, ['principal']);
        $teacher = $this->member($school, ['teacher']);

        $this->enter($admin, $school);
        $this->get('/app/settings/access')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('App/AccessCenter/Index')
            ->where('canViewAssignments', true)
            ->has('roles', Role::query()->where('scope', 'school')->where('is_system', true)->whereNull('retired_at')->count())
            ->has('matrix')->has('modules')->has('sensitive.families', count(SensitiveDataCatalogue::FAMILIES)));
        $this->get('/app/settings/access/users')->assertOk();

        $this->enter($principal, $school);
        $this->get('/app/settings/access')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('canViewAssignments', false));
        $this->get('/app/settings/access/users')->assertForbidden();
        $this->get('/app/settings/access/users/'.$this->membershipId($admin, $school))->assertForbidden();

        $this->enter($teacher, $school);
        $this->get('/app/settings/access')->assertForbidden();
    }

    #[Test]
    public function the_access_center_has_no_write_route_and_no_platform_or_group_role(): void
    {
        foreach (app(Router::class)->getRoutes()->getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'app.settings.access.')) {
                $this->assertSame(['GET', 'HEAD'], $route->methods(), (string) $route->getName());
            }
        }
        $catalogue = app(AccessCatalogueReader::class)->catalogue();
        $this->assertSame([], array_values(array_filter(array_column($catalogue['roles'], 'key'), fn (string $k) => str_starts_with($k, 'platform') || str_starts_with($k, 'group') || str_starts_with($k, 'network') || $k === 'guardian')));
        // Matrix rows are role templates, columns are modules -- never menu entries.
        $this->assertCount(count($catalogue['modules']), $catalogue['matrix'][0]['cells']);
        $this->assertSame([], array_values(array_filter(array_column($catalogue['sensitive']['families'], 'configurable'))), 'no family is switchable yet');
    }

    #[Test]
    public function assignments_list_only_this_schools_staff_and_search_stays_inside_it(): void
    {
        $a = $this->createSchool();
        $b = $this->createSchool();
        $admin = $this->schoolAdminFor($a);
        $this->member($a, ['accountant'])->forceFill(['name' => 'Quillon Arbeck'])->save();
        $outsider = $this->member($b, ['accountant']);
        $outsider->forceFill(['name' => 'Quillon Outsider'])->save();

        $this->enter($admin, $a);
        $this->get('/app/settings/access/users?q=quillon')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('members.total', 1)->where('members.data.0.name', 'Quillon Arbeck')
            ->where('members.data.0.assignments.0.scope', 'Whole School'));
        $this->get('/app/settings/access/users?q[]=x')->assertOk();
        $this->get('/app/settings/access/users/'.$this->membershipId($outsider, $b))->assertNotFound();
        $this->get('/app/settings/access/users/'.Str::uuid7())->assertNotFound();
        // A membership that never held a School role (e.g. a Guardian's) is not staff: 404.
        $nonStaff = $this->createUser();
        $this->createMembership($nonStaff, $a);
        $this->get('/app/settings/access/users/'.$this->membershipId($nonStaff, $a))->assertNotFound();
    }

    #[Test]
    public function effective_access_explains_whole_school_campus_and_module_layers(): void
    {
        $school = School::factory()->withCoreModulesOnly()->create();
        $campus = $this->createCampus($school, ['name' => 'Main Campus']);
        $admin = $this->schoolAdminFor($school);
        // Whole-School Accountant (Finance not enabled) + Campus-scoped Cashier.
        $person = $this->member($school, ['principal'], [['cashier', $campus->id]]);

        $this->enter($admin, $school);
        $page = $this->get('/app/settings/access/users/'.$this->membershipId($person, $school))->assertOk();
        $props = $page->viewData('page')['props'];
        $this->assertSame(['Cashier — Campus: Main Campus', 'Principal — Whole School'], collect($props['assignments'])->map(fn ($a) => $a['roleName'].' — '.$a['scope'])->sort()->values()->all());

        $caps = collect($props['modules'])->flatMap(fn ($m) => $m['capabilities'])->keyBy('key');
        $this->assertSame('whole_school', $caps['students.view']['effective']);
        $this->assertSame(['Principal — Whole School'], $caps['students.view']['sources']);
        $this->assertSame('not_effective', $caps['finance.payments.view']['effective']);
        $this->assertContains('module_not_enabled', $caps['finance.payments.view']['reasons'], 'Finance is not enabled for this School');
        $this->assertSame(['Cashier — Campus: Main Campus'], $caps['finance.payments.view']['sources']);
        $this->assertNotContains('sensitive_policy', $caps['finance.payments.view']['reasons']);
        $this->assertSame(count(array_unique(app(CapabilityResolver::class)->schoolCapabilities($person, $school))), $props['summary']['wholeSchool']);
    }

    #[Test]
    public function disabled_accounts_and_suspended_memberships_hold_nothing(): void
    {
        $school = $this->createSchool();
        $admin = $this->schoolAdminFor($school);
        $disabled = $this->member($school, ['accountant']);
        $disabled->forceFill(['is_disabled' => true])->save();
        $suspended = $this->member($school, ['librarian']);
        DB::table('school_memberships')->where('user_id', $suspended->id)->update(['status' => 'suspended']);

        $this->enter($admin, $school);
        foreach ([[$disabled, 'account_disabled'], [$suspended, 'membership_inactive']] as [$user, $reason]) {
            $props = $this->get('/app/settings/access/users/'.$this->membershipId($user, $school))->assertOk()->viewData('page')['props'];
            $this->assertSame(0, $props['summary']['wholeSchool']);
            $first = $props['modules'][0]['capabilities'][0];
            $this->assertSame('not_effective', $first['effective']);
            $this->assertContains($reason, $first['reasons']);
        }
    }

    #[Test]
    public function a_shared_user_is_computed_per_school_and_group_authority_never_appears(): void
    {
        $a = $this->createSchool();
        $b = $this->createSchool();
        $person = $this->member($a, ['accountant']);
        $membershipB = $this->createMembership($person, $b);
        $this->assignSchoolRole($membershipB, 'librarian');
        $group = $this->createGroup([$a, $b]);
        $this->grantGroupRole($person, $group);

        $adminA = $this->schoolAdminFor($a);
        $this->enter($adminA, $a);
        $props = $this->get('/app/settings/access/users/'.$this->membershipId($person, $a))->assertOk()->viewData('page')['props'];
        $this->assertSame(['accountant'], array_column($props['assignments'], 'roleKey'), 'School B\'s role never appears in School A');
        $json = json_encode($props);
        foreach (['librarian', 'group_admin', 'group.schools', 'platform.'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $json);
        }
    }

    #[Test]
    public function the_payloads_carry_no_credentials_or_hidden_personal_data(): void
    {
        $school = $this->createSchool();
        $admin = $this->schoolAdminFor($school);
        $person = $this->member($school, ['hr_officer']);
        $this->enter($admin, $school);

        $json = json_encode($this->get('/app/settings/access/users/'.$this->membershipId($person, $school))->viewData('page')['props'])
            .json_encode($this->get('/app/settings/access/users')->viewData('page')['props'])
            .json_encode($this->get('/app/settings/access')->viewData('page')['props']);
        // Field names as payload keys, and credential values -- never present.
        foreach (['"password":', '"remember_token":', '"mfa_secret":', '"recovery_codes":', '"token":', '"credential_version":', '"date_of_birth":', '$2y$'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, $secret);
        }
        $hash = (string) DB::table('users')->where('id', $person->id)->value('password');
        if ($hash !== '') {
            $this->assertStringNotContainsString($hash, $json);
        }
    }

    #[Test]
    public function effective_access_and_the_catalogue_use_a_bounded_number_of_queries(): void
    {
        $school = $this->createSchool();
        $campuses = [$this->createCampus($school), $this->createCampus($school), $this->createCampus($school)];
        $admin = $this->schoolAdminFor($school);
        $simple = $this->member($school, ['teacher']);
        $multiRole = $this->member($school, ['principal', 'accountant', 'librarian', 'hr_officer']);
        $multiCampus = $this->member($school, [], array_map(fn ($c) => ['cashier', $c->id], $campuses));
        $groupAndSchool = $this->member($school, ['accountant']);
        $this->grantGroupRole($groupAndSchool, $this->createGroup([$school]));
        $this->enter($admin, $school);

        $counts = [];
        $n = 0;
        DB::listen(function () use (&$n): void {
            $n++;
        });
        foreach (['simple' => $simple, 'multiRole' => $multiRole, 'multiCampus' => $multiCampus, 'groupAndSchool' => $groupAndSchool] as $label => $user) {
            $n = 0;
            $this->get('/app/settings/access/users/'.$this->membershipId($user, $school))->assertOk();
            $counts[$label] = $n;
        }
        $n = 0;
        $this->get('/app/settings/access')->assertOk();
        $counts['catalogue'] = $n;

        $this->assertLessThanOrEqual($counts['simple'] + 3, $counts['multiRole'], json_encode($counts));
        $this->assertLessThanOrEqual($counts['simple'] + 3, $counts['multiCampus'], json_encode($counts));
        $this->assertLessThanOrEqual(40, max($counts), json_encode($counts));
    }
}
