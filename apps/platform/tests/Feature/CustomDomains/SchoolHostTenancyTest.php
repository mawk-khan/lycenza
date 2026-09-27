<?php

namespace Tests\Feature\CustomDomains;

use App\Http\Middleware\RequireSchoolContext;
use App\Http\Middleware\ResolveSchoolContext;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesSchoolDomains;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Platform\Groups\GroupTestHelpers;
use Tests\TestCase;

/**
 * Phase 0O.8A (ADR 0054 sections 8.4-8.6): on a School's ACTIVE domain the
 * Host names the School, but it is intent, never authority -- TenantContext
 * only for a signed-in, active member; a stale session naming another
 * School is refused, never run ambiguously; platform-only and Group-only
 * accounts get the fixed "no access" page; the API never takes a School
 * from a Host; and on the platform host a domain never overrides the
 * session selection (the ADR 0054 finding 3).
 */
class SchoolHostTenancyTest extends TestCase
{
    use CreatesSchoolDomains, CreatesTenancyFixtures, GroupTestHelpers;

    private const HOST = 'http://erp.northfield.org';

    private string $seen = 'unset';

    private ?string $seenIntent = null;

    protected function setUp(): void
    {
        parent::setUp();

        // A School page that reports the School it ran under.
        Route::middleware(['web', 'auth', 'school-context'])->get('/app/__probe', function () {
            $this->seen = (string) app(TenantContext::class)->schoolId();

            return response('ok');
        });
        // A guest page that reports what it saw before sign-in.
        Route::middleware('web')->get('/login/__probe', function () {
            $this->seen = (string) (app(TenantContext::class)->schoolId() ?? 'none');
            $this->seenIntent = request()->attributes->get(ResolveSchoolContext::HOST_SCHOOL_ATTRIBUTE);

            return response('ok');
        });
    }

    private function school(): School
    {
        $school = $this->createSchool(['name' => 'Northfield Academy']);
        $this->createSchoolDomain($school, 'erp.northfield.org');

        return $school;
    }

    #[Test]
    public function before_sign_in_the_host_is_intent_and_branding_never_tenant_context(): void
    {
        $school = $this->school();

        $this->get(self::HOST.'/login')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Auth/Login')->where('hostSchool', ['name' => 'Northfield Academy']));
        $this->get('http://localhost/login')->assertOk()->assertInertia(fn ($page) => $page->where('hostSchool', null));

        $this->get('http://erp.northfield.org/login/__probe')->assertNotFound(); // not in the School surface
        $this->get(self::HOST.'/app/__probe')->assertRedirect(); // signed out: to sign-in, never a School page
        $this->assertSame('unset', $this->seen);
    }

    #[Test]
    public function after_sign_in_an_active_member_runs_under_the_hosts_school_and_the_session_follows_it(): void
    {
        $school = $this->school();
        [$member, $otherSchool] = $this->createSchoolAdmin();
        $this->createMembership($member, $school);

        $this->actingAs($member)->get(self::HOST.'/app/__probe')->assertOk();
        $this->assertSame($school->id, $this->seen);
        $this->assertSame($school->id, session(RequireSchoolContext::SESSION_KEY), 'the host-only session records the host School');
        $this->assertNotSame($otherSchool->id, $this->seen);
    }

    #[Test]
    public function a_session_naming_another_school_on_this_host_is_refused_and_forgotten(): void
    {
        $school = $this->school();
        $user = $this->createUser();
        $other = $this->createSchool();
        $this->createMembership($user, $school);
        $this->createMembership($user, $other);

        $this->actingAs($user)->withSession([RequireSchoolContext::SESSION_KEY => $other->id])
            ->get(self::HOST.'/app/__probe')->assertRedirect(route('app.dashboard'));
        $this->assertSame('unset', $this->seen, 'never run for either School while they disagree');
        $this->assertNull(session(RequireSchoolContext::SESSION_KEY));

        $this->actingAs($user)->withSession([RequireSchoolContext::SESSION_KEY => $other->id])
            ->postJson(self::HOST.'/app/__probe-write')->assertStatus(404); // unknown route, still no School
        $this->actingAs($user)->withSession([RequireSchoolContext::SESSION_KEY => $other->id])
            ->getJson(self::HOST.'/app/__probe')->assertStatus(409)->assertJsonPath('error.code', 'school_context_required');

        // The next request, with no stale selection, is the host School.
        $this->actingAs($user)->get(self::HOST.'/app/__probe')->assertOk();
        $this->assertSame($school->id, $this->seen);
    }

    #[Test]
    public function without_an_active_membership_the_school_host_is_a_fixed_no_access_page(): void
    {
        $school = $this->school();
        $stranger = $this->createUser();
        $root = $this->createPlatformRoot();
        $group = $this->createGroup([$school]);
        $groupAdmin = $this->groupAdmin($group, withMfa: false);
        $suspendedMember = $this->createUser();
        $this->createMembership($suspendedMember, $school, 'suspended');
        $disabled = $this->createUser();
        $this->createMembership($disabled, $school);
        DB::table('users')->where('id', $disabled->id)->update(['is_disabled' => true]);

        foreach (['stranger' => $stranger, 'platform root' => $root, 'group admin' => $groupAdmin, 'suspended member' => $suspendedMember, 'disabled user' => $disabled->refresh()] as $who => $user) {
            $this->actingAs($user)->get(self::HOST.'/app')->assertForbidden()->assertInertia(fn ($page) => $page->component('App/SchoolHostNoAccess'));
            $this->actingAs($user)->get(self::HOST.'/app/__probe')->assertForbidden();
            $this->actingAs($user)->getJson(self::HOST.'/app/__probe')->assertForbidden()->assertJsonPath('error.code', 'school_host_access_denied');
            $this->assertSame('unset', $this->seen, $who);
        }

        // Signing out still works on that host.
        $this->actingAs($stranger)->post(self::HOST.'/logout')->assertRedirect();
        $this->assertGuest();
    }

    #[Test]
    public function the_platform_host_keeps_the_session_selection_and_a_domain_never_overrides_it(): void
    {
        $domainSchool = $this->school();
        $user = $this->createUser();
        $sessionSchool = $this->createSchool();
        $this->createMembership($user, $domainSchool);
        $this->createMembership($user, $sessionSchool);

        $this->actingAs($user)->post("http://localhost/app/schools/{$sessionSchool->id}/activate")->assertRedirect('/app');
        $this->actingAs($user)->get('http://localhost/app/__probe')->assertOk();
        $this->assertSame($sessionSchool->id, $this->seen);
    }

    #[Test]
    public function the_api_never_takes_a_school_from_a_host(): void
    {
        $domainSchool = $this->school();
        [$user, $apiSchool] = $this->createSchoolAdmin();
        $this->createMembership($user, $domainSchool);
        $token = $user->createToken('device')->plainTextToken;

        // On the School host the API does not exist at all.
        $this->withHeader('Authorization', "Bearer {$token}")->getJson(self::HOST."/api/v1/schools/{$apiSchool->id}/context")->assertNotFound();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        // On the platform host the URL names the School; no Host pre-sets it.
        $seen = 'unset';
        Route::middleware('api')->get('/api/v1/__probe', function () use (&$seen) {
            $seen = app(TenantContext::class)->schoolId();

            return response()->json([]);
        });
        $this->getJson('http://localhost/api/v1/__probe')->assertOk();
        $this->assertNull($seen, 'the api group never derives TenantContext from a Host');

        $this->withHeader('Authorization', "Bearer {$token}")->getJson("http://localhost/api/v1/schools/{$apiSchool->id}/context")
            ->assertOk()->assertJsonPath('data.school.id', $apiSchool->id);
    }

    #[Test]
    public function elevation_never_resolves_on_a_school_host(): void
    {
        $school = $this->school();
        $user = $this->createUser();
        $this->createMembership($user, $school);

        // A (forged) elevation pointer in this host's session is simply ignored.
        $this->actingAs($user)->withSession(['platform_elevation_id' => (string) Str::uuid()])
            ->get(self::HOST.'/app/__probe')->assertOk();
        $this->assertSame($school->id, $this->seen);
        $this->assertSame(0, DB::table('platform_audit_events')->where('event_type', 'like', 'platform.school_elevation%')->count(), 'no elevation logic ran at all');
    }
}
