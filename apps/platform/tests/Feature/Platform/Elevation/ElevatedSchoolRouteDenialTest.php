<?php

namespace Tests\Feature\Platform\Elevation;

use App\Http\Middleware\RequireSchoolContext;
use App\Http\Middleware\ResolvePlatformElevation;
use App\Models\SchoolAuditEvent;
use App\Support\Ai\AiGatewayAuthorizationException;
use App\Support\Ai\AiGatewayClient;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesSchoolDomains;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0N.3 (ADR 0044 sections 7-8, 17; D7/D8): a valid elevation opens
 * NO existing School page, API or AI path. Only a route that explicitly
 * opted in (`school-context:elevated` -- a test-only route here; none in
 * production) gets the elevation's one School in TenantContext.
 */
class ElevatedSchoolRouteDenialTest extends TestCase
{
    use CreatesSchoolDomains, CreatesTenancyFixtures, ElevationTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        RouteFacade::middleware(['web', 'auth', 'school-context:elevated'])
            ->match(['GET', 'POST'], '/__test/elevation-safe', function () {
                $school = app(TenantContext::class)->requireSchool();
                app(AuditRecorder::class)->school($school, 'test.elevation_safe_probe');

                return response()->json(['school' => $school->id]);
            });
    }

    /**
     * Every GET School route (parameterised ones with a random id: the
     * refusal comes before binding) and every School mutation.
     */
    #[Test]
    public function every_existing_school_route_refuses_a_valid_elevation_with_403(): void
    {
        $school = $this->createSchool();
        $admin = $this->platformAdmin();
        $this->elevate($admin, $school);

        $checked = 0;

        foreach (app(Router::class)->getRoutes()->getRoutes() as $route) {
            if (! in_array('school-context', $route->gatherMiddleware(), true)) {
                continue;
            }

            $uri = '/'.preg_replace('/\{[^}]+\}/', (string) Str::uuid(), $route->uri());

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $response = $this->json($method, $uri);

                $this->assertSame(403, $response->getStatusCode(), "{$method} {$uri} under elevation");
                $this->assertSame(RequireSchoolContext::ELEVATION_NOT_PERMITTED, $response->json('error.code'), "{$method} {$uri}");
                $checked++;
            }
        }

        $this->assertGreaterThan(300, $checked);
    }

    #[Test]
    public function representative_source_module_pages_stay_closed_including_membership_only_pages(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $admin = $this->platformAdmin();
        $this->elevate($admin, $school);

        foreach ([
            '/app/school-setup',                 // no capability check at all
            '/app/communications/preferences',   // membership-derived
            '/app/finance', '/app/hr', '/app/payroll', '/app/payroll/statutory',
            '/app/students', "/app/students/{$student->id}", '/app/guardians',
            '/app/compliance/audit-log', '/app/analytics/curriculum-coverage', '/app/automation',
            '/app/learning-content', '/app/assignments', '/app/communications',
            '/app/settings', '/app/attendance', '/app/examinations', '/app/library/titles',
            '/app/transport/routes', '/app/visitor/directory', '/app/hostels', '/app/inventory-stock',
            '/app/canteen-orders', '/app/timetable-schedule', '/app/enrollments', '/app/admissions',
        ] as $uri) {
            $response = $this->get($uri);

            $this->assertSame(403, $response->getStatusCode(), $uri);
            $this->assertStringNotContainsString($student->id, $response->getContent(), $uri);
        }

        // An Inertia visit too.
        $this->get('/app/school-setup', ['X-Inertia' => 'true'])->assertForbidden();
    }

    #[Test]
    public function elevation_grants_no_school_capability(): void
    {
        $school = $this->createSchool();
        $admin = $this->platformAdmin();
        $this->elevate($admin, $school);

        $this->assertSame([], app(CapabilityResolver::class)->schoolCapabilities($admin, $school));
        $this->assertFalse(app(CapabilityResolver::class)->can($admin, 'students.view', $school));

        $this->get('/app')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('nav.canViewStudents', false)
            ->where('nav.canViewSchoolSettings', false)
            ->where('nav.canViewFinance', false)
            ->where('activeSchool', null)
        );
    }

    #[Test]
    public function only_an_explicitly_opted_in_route_gets_exactly_the_elevations_school_and_stamps_its_audit_rows(): void
    {
        $school = $this->createSchool();
        $other = $this->createSchool();
        $admin = $this->platformAdmin();
        $elevation = $this->elevate($admin, $school);

        $this->getJson('/__test/elevation-safe')->assertOk()->assertJsonPath('school', $school->id);
        $this->assertNotSame($other->id, $school->id);

        $row = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()->where('event_type', 'test.elevation_safe_probe')->firstOrFail());
        $this->assertSame($elevation->id, $row->elevation_id);
        $this->assertSame($admin->id, $row->actor_user_id);

        // An ordinary member on the same opted-in route: no elevation id.
        $member = $this->createUser();
        $this->createMembership($member, $other);
        $this->actingAs($member)->withSession(['platform_elevation_id' => null]);
        $this->post("/app/schools/{$other->id}/activate");
        $this->getJson('/__test/elevation-safe')->assertOk()->assertJsonPath('school', $other->id);
        $ordinary = app(TenantContext::class)->withSchool($other, fn () => SchoolAuditEvent::query()->where('event_type', 'test.elevation_safe_probe')->firstOrFail());
        $this->assertNull($ordinary->elevation_id);
    }

    #[Test]
    public function without_an_elevation_a_platform_admin_still_lands_on_app_as_in_phase_0n1(): void
    {
        $admin = $this->platformAdmin();
        $this->actingAs($admin);

        $this->get('/app/school-setup')->assertRedirect('/app');
        $this->get('/__test/elevation-safe')->assertRedirect('/app');
    }

    #[Test]
    public function the_local_header_naming_another_school_blocks_school_context_and_no_custom_domain_serves_elevation(): void
    {
        $school = $this->createSchool();
        $other = $this->createSchool();
        $admin = $this->platformAdmin();
        $this->createMembership($admin, $other);
        $this->elevate($admin, $school);

        // Local/testing header for a School the admin is a member of.
        $this->getJson('/__test/elevation-safe', ['X-School-Id' => $other->id])
            ->assertForbidden()
            ->assertJsonPath('error.code', RequireSchoolContext::ELEVATION_NOT_PERMITTED);

        // Phase 0O.8A (ADR 0054 sections 8.2, 8.5): a custom School domain is
        // never an elevation surface -- not the other School's, and not the
        // elevation target's own: the Host boundary answers before any School
        // or elevation logic runs.
        // On the platform host the elevation resolves exactly its School.
        $this->getJson('/__test/elevation-safe')->assertOk()->assertJsonPath('school', $school->id);

        $this->createSchoolDomain($other, 'erp.other-elevation-school.org');
        $this->createSchoolDomain($school, 'erp.target-elevation-school.org');
        $this->getJson('http://erp.other-elevation-school.org/__test/elevation-safe')->assertNotFound();
        $this->getJson('http://erp.target-elevation-school.org/__test/elevation-safe')->assertNotFound();
    }

    #[Test]
    public function the_api_stays_membership_only_for_an_elevated_platform_admin(): void
    {
        $school = $this->createSchool();
        $admin = $this->platformAdmin();
        $this->elevate($admin, $school);
        $token = $admin->createToken('device')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/schools/{$school->id}/context")
            ->assertNotFound();

        // No api route ever runs the elevation resolver or accepts elevation.
        foreach (app(Router::class)->getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            if (Str::startsWith($route->uri(), 'api/')) {
                $this->assertNotContains(ResolvePlatformElevation::class, app(Router::class)->gatherRouteMiddleware($route), $route->uri());
            }
        }
    }

    #[Test]
    public function no_ai_context_token_can_be_minted_from_an_elevation(): void
    {
        Http::fake();
        $school = $this->createSchool();
        $admin = $this->platformAdmin();
        $this->elevate($admin, $school);

        try {
            app(AiGatewayClient::class)->invokeTool($admin, $school, 'students.view', 'agent', 'tool');
            $this->fail('An elevated platform admin must not obtain an AI context token.');
        } catch (AiGatewayAuthorizationException) {
            Http::assertNothingSent();
        }
    }
}
