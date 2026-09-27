<?php

namespace Tests\Feature\CustomDomains;

use App\Http\Middleware\ClassifyRequestHost;
use App\Http\Middleware\TrustConfiguredProxies;
use App\Providers\DomainsServiceProvider;
use App\Support\Domains\Dns\DomainDnsResolver;
use App\Support\Domains\Dns\FakeDomainDnsResolver;
use App\Support\Domains\Dns\NetDns2DomainResolver;
use App\Support\Domains\Probe\DomainProber;
use App\Support\Domains\Probe\StreamDomainProber;
use App\Support\Domains\SchoolHostSurface;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\Concerns\CreatesSchoolDomains;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0O.8A (ADR 0054 sections 8-9, 11) structural guards: where the Host
 * boundary sits, exactly which routes a custom School domain can reach, that
 * no School-facing code builds an absolute URL from a request Host, that the
 * browser contract (host-only cookies, CSRF, Sanctum, CORS, CSP) did not
 * widen, and that the DNS/TLS fakes exist only behind their double guard.
 */
class HostBoundaryArchitectureTest extends TestCase
{
    use CreatesSchoolDomains, CreatesTenancyFixtures;

    #[Test]
    public function the_host_boundary_runs_right_after_trusted_proxies_before_cors_and_everything_else(): void
    {
        $global = $this->app->make(Kernel::class)->getGlobalMiddleware();
        $proxies = array_search(TrustConfiguredProxies::class, $global, true);

        $this->assertIsInt($proxies);
        $this->assertSame(ClassifyRequestHost::class, $global[$proxies + 1], 'the Host is classified the moment it is known');
        $this->assertGreaterThan($proxies + 1, array_search(HandleCors::class, $global, true), 'a CORS preflight never bypasses it');
        $this->assertNotContains(TrustHosts::class, $global, 'exact classification, never a TrustHosts regex');
        foreach (['web', 'api'] as $group) {
            $this->assertNotContains(ClassifyRequestHost::class, $this->app['router']->getMiddlewareGroups()[$group]);
        }
    }

    /** Non-School routes a School host may serve, by name (everything else admitted must be a `school-context` route). */
    private const SCHOOL_HOST_NON_SCHOOL_ROUTES = [
        'system.status', 'login', 'login.store', 'login.mfa', 'login.mfa.store', 'logout',
        'invitations.show', 'invitations.store', 'session.handoff', 'app.dashboard', 'app.schools.activate',
        'app.account.security.show', 'app.account.security.password-confirmation', 'app.account.security.mfa.begin',
        'app.account.security.mfa.confirm', 'app.account.security.mfa.recovery-codes.regenerate', 'app.account.security.mfa.disable',
    ];

    #[Test]
    public function a_school_host_reaches_exactly_the_closed_browser_school_surface(): void
    {
        $admitted = [];

        foreach (RouteFacade::getRoutes() as $route) {
            /** @var Route $route */
            $uri = $route->uri();
            $middleware = $route->gatherMiddleware();

            if (! SchoolHostSurface::admits($uri)) {
                continue;
            }

            $admitted[] = (string) $route->getName();
            $this->assertFalse(Str::startsWith($uri, ['api/', 'sanctum/', 'storage/', 'up', '.well-known']), "{$uri} is never a School-host surface");
            foreach ($middleware as $m) {
                $this->assertFalse(is_string($m) && Str::startsWith($m, 'capability:') && Str::endsWith($m, ',platform'), "{$uri} is a platform-capability route");
            }
            $this->assertFalse(Str::startsWith($uri, ['app/platform', 'app/groups', 'app/account/admin', 'app/account/api-tokens']), $uri);

            if (! in_array('school-context', $middleware, true)) {
                $this->assertContains((string) $route->getName(), self::SCHOOL_HOST_NON_SCHOOL_ROUTES, "{$uri} is admitted on a School host but is neither a School route nor on the reviewed allowlist");
            }
        }

        // Every reviewed non-School route really is admitted (the list is not stale).
        foreach (self::SCHOOL_HOST_NON_SCHOOL_ROUTES as $name) {
            $this->assertContains($name, $admitted);
        }

        // The platform, Group, elevation and API surfaces are all outside it.
        foreach (['app.platform.elevation.create', 'app.platform.schools.index', 'app.platform.audit-log', 'app.groups.index', 'app.account.api-tokens.index', 'app.account.admin.mfa.reset', 'api.health.live', 'domains.probe'] as $name) {
            $this->assertNotContains($name, $admitted, $name);
        }
    }

    /**
     * School-facing code never builds an absolute URL from a request Host;
     * links come from App\Support\Domains\CanonicalOrigin (ADR 0054 section 8.9).
     */
    #[Test]
    public function no_school_facing_code_builds_absolute_urls_from_the_request_host(): void
    {
        $hostReaders = ['->getHttpHost(', 'getSchemeAndHttpHost(', "['HTTP_HOST']", 'HTTP_X_FORWARDED_HOST', 'request()->root(', '$request->root(', 'getUriForPath('];
        // Only these may even look at the request Host -- to classify or compare it, never to build a URL.
        $hostComparers = [
            'app/Support/Domains/HostClassifier.php', 'app/Http/Middleware/ClassifyRequestHost.php',
            'app/Http/Controllers/Auth/SessionHandoffController.php', 'app/Http/Controllers/App/SchoolSwitchController.php',
        ];

        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            $relative = 'app/'.$file->getRelativePathname();
            $source = $file->getContents();

            foreach ($hostReaders as $reader) {
                $this->assertStringNotContainsString($reader, $source, "{$relative} reads the request Host ({$reader})");
            }

            if (! in_array($relative, $hostComparers, true)) {
                $this->assertStringNotContainsString('->getHost()', $source, "{$relative}: only the Host boundary classifies Hosts");
            }

            // Mail, notifications, jobs and Application services never use url()
            // or route() for an absolute link.
            if (preg_match('#^app/(Mail|Notifications|Jobs|Domain/[^/]+/(Mail|Notifications|Application))/#', $relative) === 1) {
                $this->assertDoesNotMatchRegularExpression('/(?<![\w>:$])url\(/', $source, "{$relative} builds an absolute URL with url()");
                $this->assertDoesNotMatchRegularExpression('/(?<![\w>:$])route\(/', $source, "{$relative} builds an absolute URL with route()");
            }
        }

        // Phase 0O.9A: the invitation link is built where its email is sealed.
        $invitation = (string) file_get_contents(app_path('Domain/Identity/Application/AccountInvitationService.php'));
        $this->assertStringContainsString('CanonicalOrigin $origins', $invitation);
        $this->assertStringContainsString('$this->origins->schoolUrl(', $invitation);
    }

    #[Test]
    public function the_browser_contract_did_not_widen(): void
    {
        $this->assertNull(config('session.domain'), 'host-only cookies');
        $this->assertSame('lax', config('session.same_site'));
        $this->assertTrue(config('session.http_only'));
        $this->assertSame(['api/*'], config('cors.paths'));
        $this->assertSame([], config('cors.allowed_origins_patterns'));
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertFalse(config('cors.supports_credentials'));
        $this->assertNotContains(EnsureFrontendRequestsAreStateful::class, $this->app['router']->getMiddlewareGroups()['api'], 'Sanctum stays bearer-only');

        $school = $this->createSchool();
        $this->createSchoolDomain($school, 'erp.northfield.org');
        $this->assertNotContains('erp.northfield.org', config('sanctum.stateful'));

        $platform = $this->get('http://localhost/login');
        $custom = $this->get('http://erp.northfield.org/login');

        foreach ([$platform, $custom] as $response) {
            $response->assertOk();
            $this->assertNotEmpty($response->headers->getCookies());
            foreach ($response->headers->getCookies() as $cookie) {
                $this->assertNull($cookie->getDomain(), "{$cookie->getName()} is host-only");
                $this->assertSame('lax', $cookie->getSameSite());
            }
            $csp = (string) $response->headers->get('Content-Security-Policy');
            $this->assertStringContainsString("default-src 'self'", $csp);
            $this->assertStringContainsString("frame-ancestors 'none'", $csp);
            $this->assertStringNotContainsString('northfield', $csp, 'no per-domain CSP list');
            $this->assertStringNotContainsString('*', $csp);
        }
        $this->assertSame($platform->headers->get('Content-Security-Policy'), $custom->headers->get('Content-Security-Policy'));

        $session = collect($custom->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));
        $this->assertTrue($session->isHttpOnly());

        // CSRF stays the framework's same-origin session token, with no
        // exception list and no origin allowlist to widen.
        $csrf = new \ReflectionClass(PreventRequestForgery::class);
        $this->assertSame([], $csrf->getStaticPropertyValue('neverVerify'));
        $this->assertSame([], $csrf->getDefaultProperties()['except']);
    }

    #[Test]
    public function the_dns_and_tls_fakes_exist_only_behind_the_double_guard(): void
    {
        $this->assertInstanceOf(FakeDomainDnsResolver::class, app(DomainDnsResolver::class));

        config(['domains.fakes' => false]);
        $this->assertInstanceOf(NetDns2DomainResolver::class, app(DomainDnsResolver::class));
        $this->assertInstanceOf(StreamDomainProber::class, app(DomainProber::class));
        $this->assertSame(1, Artisan::call('platform:domain-fake-dns', ['hostname' => 'erp.northfield.org', 'action' => 'reset']));

        config(['domains.fakes' => true]);
        $this->app['env'] = 'production';
        try {
            $this->assertFalse(DomainsServiceProvider::fakesEnabled($this->app));
            $this->assertInstanceOf(NetDns2DomainResolver::class, app(DomainDnsResolver::class));
            $this->assertInstanceOf(StreamDomainProber::class, app(DomainProber::class));
        } finally {
            $this->app['env'] = 'testing';
        }

        // No source outside the fakes and tests may relax TLS verification.
        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            $source = $file->getContents();
            $this->assertStringNotContainsString("'verify_peer' => false", $source, $file->getRelativePathname());
            $this->assertStringNotContainsString("'verify_peer_name' => false", $source, $file->getRelativePathname());
            $this->assertStringNotContainsString("'allow_self_signed' => true", $source, $file->getRelativePathname());
        }
    }
}
