<?php

namespace Tests\Feature\CustomDomains;

use App\Http\Middleware\ClassifyRequestHost;
use App\Models\School;
use App\Models\SchoolDomain;
use App\Support\Domains\DomainDirectory;
use App\Support\Domains\DomainState;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesSchoolDomains;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0O.8A (ADR 0054 section 8.1): the Host boundary. Every request Host
 * is platform, an ACTIVE School domain, an alias, internal, a probe, a
 * health request -- or refused with ONE fixed 421 before any session, cookie,
 * CSRF, School or URL logic. Absolute URLs are always given here: a relative
 * URL in a test would silently reuse the previous request's host.
 */
class HostBoundaryTest extends TestCase
{
    use CreatesSchoolDomains, CreatesTenancyFixtures;

    private function assertMisdirected(TestResponse $response): void
    {
        $response->assertStatus(421);
        $this->assertSame(ClassifyRequestHost::MISDIRECTED_BODY, $response->getContent());
        $this->assertSame([], $response->headers->getCookies(), 'no session or XSRF cookie before the Host is known');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function an_arbitrary_host_is_refused_before_any_session_logic(): void
    {
        foreach (['http://attacker.invalid-host.example.net/login', 'http://attacker.example.net/app', 'http://10.1.2.3/login', 'http://evil.localhost.attacker.net/'] as $url) {
            $this->assertMisdirected($this->get($url));
        }

        $this->assertMisdirected($this->post('http://attacker.example.net/login', ['email' => 'x@example.com', 'password' => 'x']));
        $this->assertMisdirected($this->getJson('http://attacker.example.net/api/v1/me'));
    }

    #[Test]
    public function every_non_active_domain_looks_exactly_like_an_unknown_host(): void
    {
        foreach ([DomainState::PendingVerification, DomainState::Verified, DomainState::TlsPending, DomainState::Suspended, DomainState::Revoked, DomainState::Expired] as $i => $state) {
            $this->createSchoolDomain($this->createSchool(), str_replace('_', '-', $state->value)."-{$i}.northfield.org", $state);
        }

        $bodies = [];
        foreach (DomainState::cases() as $i => $state) {
            if ($state === DomainState::Active) {
                continue;
            }
            $response = $this->get('http://'.str_replace('_', '-', $state->value)."-{$i}.northfield.org/login");
            $this->assertMisdirected($response);
            $bodies[] = $response->getContent().'|'.json_encode($response->headers->all('content-type'));
        }
        $bodies[] = $this->get('http://never-registered.northfield.org/login')->getContent().'|'.json_encode($this->get('http://never-registered.northfield.org/login')->headers->all('content-type'));

        $this->assertCount(1, array_unique($bodies), 'no disclosure of registration or lifecycle');
    }

    #[Test]
    public function an_active_domain_of_a_non_active_school_is_misdirected_and_disabled_custom_domains_resolve_nothing(): void
    {
        $school = $this->createSchool();
        $this->createSchoolDomain($school, 'erp.northfield.org');
        $this->get('http://erp.northfield.org/login')->assertOk();

        DB::table('schools')->where('id', $school->id)->update(['status' => 'suspended']);
        app(DomainDirectory::class)->forgetSchool($school->id);
        $this->assertMisdirected($this->get('http://erp.northfield.org/login'));
        $this->assertSame(DomainState::Active, SchoolDomain::query()->where('hostname', 'erp.northfield.org')->first()->state, 'untouched by School lifecycle');

        DB::table('schools')->where('id', $school->id)->update(['status' => 'active']);
        app(DomainDirectory::class)->forgetSchool($school->id);
        $this->get('http://erp.northfield.org/login')->assertOk();

        config(['domains.enabled' => false]);
        $this->assertMisdirected($this->get('http://erp.northfield.org/login'));
        $this->get('http://localhost/login')->assertOk();
    }

    #[Test]
    public function an_active_alias_redirects_to_the_stored_primary_and_nothing_else_does(): void
    {
        $school = $this->createSchool();
        $this->createSchoolDomain($school, 'erp.northfield.org');
        $this->createSchoolDomain($school, 'www.northfield.org');
        $this->createSchoolDomain($school, 'old.northfield.org', DomainState::Suspended);

        $response = $this->get('http://WWW.northfield.org./app/students?page=2&q=x');
        $response->assertStatus(308);
        $this->assertSame('https://erp.northfield.org/app/students?page=2&q=x', $response->headers->get('Location'));
        $this->assertSame([], $response->headers->getCookies());

        $this->post('http://www.northfield.org/login')->assertStatus(308);
        $this->assertMisdirected($this->get('http://old.northfield.org/app'));
    }

    #[Test]
    public function health_is_served_on_platform_internal_and_ip_hosts_without_a_domain_lookup(): void
    {
        $school = $this->createSchool();
        $this->createSchoolDomain($school, 'erp.northfield.org');
        config(['domains.internal_hosts' => ['platform-internal.lycenza-ops.net']]);

        $this->getJson('http://localhost/api/health/live')->assertOk();
        $this->getJson('http://10.20.30.40/api/health/live')->assertOk();
        $this->getJson('http://platform-internal.lycenza-ops.net/api/health/live')->assertOk();

        foreach (['http://erp.northfield.org/api/health/live', 'http://erp.northfield.org/up', 'http://anything.example.net/api/health/ready'] as $url) {
            $this->get($url)->assertNotFound();
        }

        // The named-host health refusal never touches PostgreSQL (rule 55).
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        $this->get('http://anything.example.net/api/health/live')->assertNotFound();
        $this->assertSame([], DB::connection()->getQueryLog());
    }

    #[Test]
    public function the_internal_host_serves_only_internal_ai_routes_and_health(): void
    {
        config(['domains.internal_hosts' => ['platform-internal.lycenza-ops.net'], 'app.url' => 'https://app.lycenza-platform.com', 'domains.allow_development_hosts' => false]);

        $this->get('http://platform-internal.lycenza-ops.net/login')->assertNotFound();
        $this->get('http://platform-internal.lycenza-ops.net/app')->assertNotFound();
        $this->postJson('http://platform-internal.lycenza-ops.net/api/internal/ai/completions/authorize')->assertStatus(401);

        // On the (non-development) platform host the internal routes do not exist.
        $this->postJson('https://app.lycenza-platform.com/api/internal/ai/completions/authorize')->assertNotFound();
        $this->get('https://app.lycenza-platform.com/login')->assertOk();
    }

    #[Test]
    public function development_hosts_exist_only_behind_the_double_guard(): void
    {
        $this->get('http://localhost/login')->assertOk();
        $this->get('http://lycenza.ddev.site/login')->assertOk();
        $this->get('http://127.0.0.1/login')->assertOk();

        config(['domains.allow_development_hosts' => false, 'app.url' => 'https://app.lycenza-platform.com']);
        $this->assertMisdirected($this->get('http://lycenza.ddev.site/login'));
        $this->assertMisdirected($this->get('http://localhost/login'));
        $this->get('https://app.lycenza-platform.com/login')->assertOk();

        config(['domains.allow_development_hosts' => true]);
        $this->app['env'] = 'production';
        try {
            $this->assertMisdirected($this->get('http://lycenza.ddev.site/login'));
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    #[Test]
    public function untrusted_forwarded_headers_never_select_a_host_and_a_trusted_proxy_is_still_classified(): void
    {
        $school = $this->createSchool();
        $this->createSchoolDomain($school, 'erp.northfield.org');
        $spoof = [
            'X-Forwarded-Host' => 'erp.northfield.org',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Port' => '443',
            'Forwarded' => 'for=203.0.113.9;host=erp.northfield.org;proto=https',
        ];
        Route::middleware('web')->get('/__host-probe', fn () => response()->json(['host' => request()->getHost(), 'url' => url('/x')]));

        // An untrusted peer's forwarded headers are ignored: the platform Host stands.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])->withHeaders($spoof)
            ->getJson('http://localhost/__host-probe')->assertExactJson(['host' => 'localhost', 'url' => 'http://localhost/x']);
        $this->flushHeaders();

        // ... and cannot smuggle an unknown Host past the boundary either.
        $this->assertMisdirected($this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])->withHeaders(['X-Forwarded-Host' => 'localhost'])->get('http://attacker.example.net/login'));
        $this->flushHeaders();

        // A configured proxy is believed -- and what it forwards is classified.
        config(['trustedproxy.proxies' => ['10.0.0.0/8']]);
        $this->assertMisdirected($this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->withHeaders(['X-Forwarded-Host' => 'attacker.example.net'])->get('http://localhost/login'));
        $this->flushHeaders();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->withHeaders(['X-Forwarded-Host' => 'erp.northfield.org', 'X-Forwarded-Proto' => 'https'])
            ->get('http://localhost/login')->assertOk()->assertInertia(fn ($page) => $page->where('hostSchool.name', $school->name));
    }

    #[Test]
    public function an_invalid_host_header_is_misdirected_not_an_error(): void
    {
        $request = Request::create('http://localhost/login');
        $request->headers->set('HOST', 'bad_host!name');
        $request->server->set('HTTP_HOST', 'bad_host!name');

        $this->assertMisdirected(TestResponse::fromBaseResponse($this->app->make(Kernel::class)->handle($request)));
    }

    private function schoolHost(School $school): string
    {
        $this->createSchoolDomain($school, 'erp.northfield.org');

        return 'http://erp.northfield.org';
    }

    #[Test]
    public function a_school_host_serves_only_the_browser_school_surface(): void
    {
        $school = $this->createSchool();
        $host = $this->schoolHost($school);

        foreach (['/', '/login'] as $path) {
            $this->get($host.$path)->assertSuccessful();
        }
        $this->get($host.'/app')->assertRedirect(); // to sign-in: a School page, not refused

        foreach ([
            '/app/platform/elevation', '/app/platform/schools', '/app/platform/audit-log', '/app/platform/roles',
            '/app/platform/groups', '/app/groups', '/app/account/api-tokens', '/app/account/admin/users/x/mfa/reset',
            '/api/v1/me', '/api/internal/ai/completions/authorize', '/api/internal/operations/status',
            '/api/health/live', '/up', '/storage/anything', '/sanctum/csrf-cookie', '/internal/mfa-demo/ping',
            '/.env', '/whatever-new-surface',
        ] as $path) {
            $response = $this->get($host.$path);
            $response->assertNotFound();
            $this->assertSame(ClassifyRequestHost::NOT_FOUND_BODY, $response->getContent(), $path);
            $this->assertSame([], $response->headers->getCookies(), $path);
        }

        // Invitations only for this host's own School.
        $other = $this->createSchool();
        $this->get("{$host}/invitations/{$other->id}/".str_repeat('a', 64))->assertNotFound();
        $this->get("{$host}/invitations/{$school->id}/".str_repeat('a', 64))->assertOk()->assertInertia(fn ($page) => $page->component('Invitations/Accept'));
    }

    #[Test]
    public function the_platform_admin_surface_is_absent_on_a_school_host_signed_in_or_not(): void
    {
        $school = $this->createSchool();
        $host = $this->schoolHost($school);
        $root = $this->createPlatformRoot();
        $this->createMembership($root, $school);

        $this->get($host.'/app/platform/schools')->assertNotFound();
        $this->actingAs($root)->get($host.'/app/platform/schools')->assertNotFound();
        $this->actingAs($root)->get($host.'/app/platform/elevation')->assertNotFound();
        $this->actingAs($root)->post($host.'/app/platform/elevation/confirm', ['target' => $school->id])->assertNotFound();
        $this->assertNotSame(404, $this->actingAs($root)->get('http://localhost/app/platform/schools')->getStatusCode(), 'present on the platform host');
    }
}
