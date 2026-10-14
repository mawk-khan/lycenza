<?php

namespace Tests\Feature\Navigation;

use App\Models\School;
use App\Models\SchoolElevation;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Navigation\NavigationBuilder;
use App\Support\Tenancy\ElevationContext;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesSchoolDomains;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Platform\Elevation\ElevationTestHelpers;
use Tests\TestCase;

/**
 * SWC.1 (ADR 0073 sections 6, 11): the shared navigation follows the
 * actor's capabilities in the ACTIVE School, never a role; it is computed
 * per request (School switch, capability removal); an elevated session and
 * a School custom host never get entries they cannot use; and hiding a
 * link is never the authorization -- every destination still refuses.
 */
class NavigationVisibilityTest extends TestCase
{
    use CreatesSchoolDomains, CreatesTenancyFixtures, ElevationTestHelpers;

    private function activate(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    private function memberWith(array $capabilities, School $school): User
    {
        $user = $this->createUserWithCapabilities($school, $capabilities);
        $this->activate($user, $school);

        return $user;
    }

    /** @return list<string> */
    private function groupKeys(mixed $navigation): array
    {
        return collect($navigation['groups'])->pluck('key')->all();
    }

    #[Test]
    public function an_entry_follows_its_capability_and_empty_groups_are_never_sent(): void
    {
        $school = $this->createSchool();
        $user = $this->memberWith(['students.view'], $school);

        $this->actingAs($user)->get('/app')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('navigation', self::nav(shown: ['students', 'account-security'], hidden: ['guardians', 'finance', 'attendance', 'payroll']))
            ->where('navigation', fn ($nav) => $this->groupKeys($nav) === ['people'])
            ->where('navigation.school.name', $school->name));
    }

    #[Test]
    public function any_of_and_all_of_requirements_hold_exactly(): void
    {
        $school = $this->createSchool();

        $payments = $this->memberWith(['finance.payments.view'], $school);
        $this->actingAs($payments)->get('/app')->assertInertia(fn ($page) => $page->where('navigation', self::nav(shown: ['finance'])));

        $enrollmentsOnly = $this->memberWith(['enrollments.view'], $school);
        $this->actingAs($enrollmentsOnly)->get('/app')->assertInertia(fn ($page) => $page
            ->where('navigation', self::nav(shown: ['enrollments'], hidden: ['enrollment-rollovers'])));

        $both = $this->memberWith(['enrollments.view', 'enrollments.rollovers.view'], $school);
        $this->actingAs($both)->get('/app')->assertInertia(fn ($page) => $page->where('navigation', self::nav(shown: ['enrollment-rollovers'])));
    }

    #[Test]
    public function the_previously_unlinked_modules_appear_for_holders_of_their_view_capability(): void
    {
        $school = $this->createSchool();
        $user = $this->memberWith([
            'attendance.view', 'examinations.definitions.view', 'hostel.directory.view', 'inventory.directory.view',
            'library.catalogue.view', 'transport.routes.view', 'visitor.directory.view', 'syllabus.view',
            'curriculum.delivery.view', 'lms.content.view', 'lms.assignments.view',
        ], $school);

        $this->actingAs($user)->get('/app')->assertInertia(fn ($page) => $page->where('navigation', self::nav(shown: [
            'attendance', 'examinations', 'hostels', 'inventory-items', 'inventory-locations', 'library-catalogue',
            'transport-routes', 'visitor-directory', 'syllabus', 'curriculum-delivery', 'learning-content', 'assignments',
        ], hidden: ['hostel-residency', 'inventory-stock', 'library-circulation', 'transport-vehicles', 'visitor-visits'])));
    }

    #[Test]
    public function the_active_entry_is_decided_by_the_server_from_the_current_route(): void
    {
        $school = $this->createSchool();
        $user = $this->memberWith(['students.view', 'hr.leave.view', 'hr.leave.approve', 'examinations.definitions.view', 'examinations.grade_scales.view'], $school);

        $this->actingAs($user)->get('/app')->assertInertia(fn ($page) => $page
            ->where('navigation.home.active', true)->where('navigation.activeKey', null));
        $this->actingAs($user)->get('/app/students')->assertInertia(fn ($page) => $page
            ->where('navigation.home.active', false)->where('navigation.activeKey', 'students')
            ->where('navigation', fn ($nav) => collect($nav['groups'])->flatMap(fn ($g) => $g['items'])->where('active', true)->pluck('key')->all() === ['students']));
        // The most specific pattern wins: approvals, not the Leave area.
        $this->actingAs($user)->get('/app/leave/approvals')->assertInertia(fn ($page) => $page->where('navigation.activeKey', 'leave-approvals'));
        $this->actingAs($user)->get('/app/leave/requests')->assertInertia(fn ($page) => $page->where('navigation.activeKey', 'leave'));
        // A secondary page keeps its module active.
        $this->actingAs($user)->get('/app/examinations/grade-scales')->assertInertia(fn ($page) => $page->where('navigation.activeKey', 'examinations'));
    }

    #[Test]
    public function without_an_active_school_there_are_no_school_entries(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['students.view']);

        $this->actingAs($user)->get('/app')->assertInertia(fn ($page) => $page
            ->where('navigation', self::navExactly([]))
            ->where('navigation', self::nav(shown: ['account-security', 'api-tokens']))
            ->where('navigation.school', null));
    }

    #[Test]
    public function switching_school_recomputes_the_navigation_for_that_school_only(): void
    {
        $a = $this->createSchool(['name' => 'Alpha School']);
        $b = $this->createSchool(['name' => 'Beta School']);
        $user = $this->createUserWithCapabilities($a, ['students.view']);
        LocalCatalogueFixtures::grantRole($this->createMembership($user, $b), $this->createFixtureRole(['finance.payments.view']));

        $this->activate($user, $a);
        $this->actingAs($user)->get('/app/students')->assertInertia(fn ($page) => $page
            ->where('navigation', self::nav(shown: ['students'], hidden: ['finance']))
            ->where('navigation.school.name', 'Alpha School')->where('navigation.activeKey', 'students'));

        $this->activate($user, $b);
        $this->actingAs($user)->get('/app')->assertInertia(fn ($page) => $page
            ->where('navigation', self::nav(shown: ['finance'], hidden: ['students']))
            ->where('navigation.school.name', 'Beta School')->where('navigation.activeKey', null));
        // School A's entry is not authority in School B either.
        $this->actingAs($user)->get('/app/students')->assertForbidden();
    }

    #[Test]
    public function losing_a_capability_removes_the_entry_on_the_next_request(): void
    {
        $school = $this->createSchool();
        $role = $this->createFixtureRole(['students.view', 'guardians.view']);
        $user = $this->createUser();
        LocalCatalogueFixtures::grantRole($this->createMembership($user, $school), $role);
        $this->activate($user, $school);

        $this->actingAs($user)->get('/app')->assertInertia(fn ($page) => $page->where('navigation', self::nav(shown: ['students', 'guardians'])));

        LocalCatalogueFixtures::removeRoleCapability($role, 'students.view');
        app(CapabilityResolver::class)->forgetCache($user, $school);

        $this->actingAs($user)->get('/app')->assertInertia(fn ($page) => $page->where('navigation', self::nav(shown: ['guardians'], hidden: ['students'])));
        $this->actingAs($user)->get('/app/students')->assertForbidden();
    }

    #[Test]
    public function a_hidden_entry_is_never_authorization_and_the_destination_still_decides(): void
    {
        $school = $this->createSchool();
        $without = $this->memberWith(['guardians.view'], $school);
        $with = $this->memberWith(['students.view'], $school);

        foreach (['/app/students', '/app/attendance', '/app/library/titles', '/app/transport/routes', '/app/admissions', '/app/settings/staff'] as $uri) {
            $this->assertSame(403, $this->actingAs($without)->get($uri)->getStatusCode(), $uri);
        }
        $this->actingAs($with)->get('/app/students')->assertOk();
    }

    #[Test]
    public function a_cross_school_destination_stays_closed_whatever_the_navigation_offers(): void
    {
        $a = $this->createSchool();
        $b = $this->createSchool();
        $user = $this->memberWith(['students.view'], $a);
        $foreign = $this->createStudent($b);

        $this->actingAs($user)->get('/app')->assertInertia(fn ($page) => $page->where('navigation', self::nav(shown: ['students'])));
        $this->actingAs($user)->get("/app/students/{$foreign->id}")->assertNotFound();
        // A School the actor is not a member of can never be selected.
        $this->actingAs($user)->post("/app/schools/{$b->id}/activate");
        $this->actingAs($user)->get('/app')->assertInertia(fn ($page) => $page->where('navigation.school.name', $a->name));
    }

    #[Test]
    public function an_elevated_session_gets_no_school_entry_even_with_a_school_in_context(): void
    {
        $member = $this->createSchool();
        $target = $this->createSchool();
        $admin = $this->platformAdmin();
        $elevation = $this->elevate($admin, $target);

        $this->get('/app')->assertInertia(fn ($page) => $page
            ->where('navigation', self::nav(shown: ['platform-schools', 'platform-audit-log', 'platform-roles'], hidden: ['students', 'school-setup']))
            ->where('navigation.school', null));

        // Belt and braces: even if some route put a School into TenantContext,
        // the builder never advertises School entries under elevation.
        LocalCatalogueFixtures::grantRole($this->createMembership($admin, $member), $this->createFixtureRole(['students.view']));
        app(TenantContext::class)->set($member);
        app(ElevationContext::class)->set(SchoolElevation::query()->findOrFail($elevation->id));
        $request = Request::create('/app');
        $request->setUserResolver(fn () => $admin);

        $navigation = app(NavigationBuilder::class)->forRequest($request);
        $this->assertNull($navigation['school']);
        $this->assertNotContains('students', self::navigationKeys($navigation));
    }

    #[Test]
    public function a_school_custom_host_never_offers_platform_group_or_api_token_entries(): void
    {
        $school = $this->createSchool(['name' => 'Northfield Academy']);
        $this->createSchoolDomain($school, 'erp.northfield.org');
        $root = $this->createPlatformRoot();
        LocalCatalogueFixtures::grantRole($this->createMembership($root, $school), $this->createFixtureRole(['students.view']));

        $this->actingAs($root)->get('http://erp.northfield.org/app')->assertOk()->assertInertia(fn ($page) => $page
            ->where('navigation', self::nav(shown: ['students', 'account-security'], hidden: ['platform-schools', 'platform-groups', 'platform-audit-log', 'platform-roles', 'school-groups', 'api-tokens']))
            ->where('navigation.school.name', 'Northfield Academy')
            ->where('platformElevation.canStart', false));

        // The same account on the platform host keeps its platform entries.
        $this->actingAs($root)->get('http://localhost/app')->assertInertia(fn ($page) => $page
            ->where('navigation', self::nav(shown: ['platform-schools', 'api-tokens'])));
    }

    #[Test]
    public function guests_receive_no_navigation(): void
    {
        $this->get('/login')->assertInertia(fn ($page) => $page->where('navigation', null)->where('product.name', 'Lycenza'));
    }
}
