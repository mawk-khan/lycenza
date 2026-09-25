<?php

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\DevOnlySchoolHeaderResolver;
use App\Http\Middleware\EnsureCapability;
use App\Http\Middleware\EnsureIdempotent;
use App\Http\Middleware\RequireMfa;
use App\Http\Middleware\RequireSchoolContext;
use App\Http\Middleware\ResolveSchoolContext;
use App\Models\User;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0N.1 route guard (docs/architecture/PHASE-0N-READINESS.md
 * section 17 item 3): every signed-in web route either carries
 * `school-context` (App\Http\Middleware\RequireSchoolContext) or is on
 * the small explicit context-neutral allowlist below. A new School page
 * added outside routes/web.php's School group fails here instead of
 * surfacing as a TenantContextRequiredException 500 for every user who
 * has not selected a School.
 */
class SchoolContextRouteGuardTest extends TestCase
{
    use CreatesTenancyFixtures;

    /**
     * Signed-in web routes that deliberately need no School context.
     * Keep this small: adding a name here is a tenancy decision, not a
     * way to make this test pass.
     */
    private const CONTEXT_NEUTRAL = [
        'logout',
        'app.dashboard',                          // the /app landing (D9(a))
        'app.schools.activate',                   // choosing a School
        'app.account.security.show',              // the User's own account
        'app.account.security.password-confirmation',
        'app.account.security.mfa.begin',
        'app.account.security.mfa.confirm',
        'app.account.security.mfa.recovery-codes.regenerate',
        'app.account.api-tokens.index',           // Phase 0O.3: the User's own API tokens (ADR 0049)
        'app.account.api-tokens.store',
        'app.account.api-tokens.destroy',
        'app.account.security.mfa.disable',
        'app.account.admin.mfa.reset',            // platform-scoped action
        'internal.mfa-demo.ping',                 // platform-scoped, local/testing only
        'app.platform.elevation.create',          // Phase 0N.3: entering and
        'app.platform.elevation.confirm',         // exiting platform elevation
        'app.platform.elevation.confirm.show',    // (ADR 0044) is how an
        'app.platform.elevation.store',           // elevated context begins
        'app.platform.elevation.exit',            // and ends
        'app.platform.groups.index',              // Phase 0N.5: platform
        'app.platform.groups.store',              // governance of School
        'app.platform.groups.show',               // Groups (ADR 0045) --
        'app.platform.groups.rename',             // platform scope, never
        'app.platform.groups.archive',            // School context
        'app.platform.groups.schools.store',
        'app.platform.groups.schools.destroy',
        'app.platform.groups.grants.store',
        'app.platform.groups.grants.revoke',
        'app.groups.index',                       // Phase 0N.5: the Group
        'app.groups.show',                        // Admin's own Groups and
        'app.groups.elevation.create',            // Group-derived entry --
        'app.groups.elevation.confirm',           // Group scope, never
        'app.groups.elevation.store',             // School context
        'app.groups.reports.curriculum-coverage', // Phase 0N.11: Group report (ADR 0048) -- its reads enter one School at a time internally
        'app.platform.audit-log',                 // Phase 0N.7: platform
        'app.platform.roles.index',               // audit review and
        'app.platform.roles.grants.store',        // platform-role
        'app.platform.roles.grants.revoke',       // governance (ADR 0046)
        'app.platform.schools.index',             // Phase 0N.9: School
        'app.platform.schools.create',            // lifecycle (ADR 0047) --
        'app.platform.schools.store',             // platform scope, never
        'app.platform.schools.show',              // School context
        'app.platform.schools.review',
        'app.platform.schools.perform',
    ];

    #[Test]
    public function every_signed_in_web_route_requires_a_school_or_is_explicitly_context_neutral(): void
    {
        $missing = [];

        foreach ($this->webRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            $signedIn = in_array('auth', $middleware, true);
            $underApp = Str::startsWith($route->uri(), 'app');

            if (! $signedIn && ! $underApp) {
                continue;
            }

            if (in_array($route->getName(), self::CONTEXT_NEUTRAL, true)) {
                continue;
            }

            if (! in_array('school-context', $middleware, true) || ! $signedIn) {
                $missing[] = implode('|', $route->methods()).' '.$route->uri().' ('.($route->getName() ?? 'unnamed').')';
            }
        }

        $this->assertSame([], $missing, "School-scoped web routes without 'auth' + 'school-context' -- move them into routes/web.php's School group:\n".implode("\n", $missing));
    }

    /**
     * Phase 0N.3 (ADR 0044 sections 7-8): elevation is refused on every
     * School route unless the route opts in with `school-context:elevated`,
     * and each opt-in needs its own ADR. None exists yet -- so no route may
     * carry it, and none may accept elevation any other way.
     */
    #[Test]
    public function no_route_accepts_platform_elevation_in_phase_0n3(): void
    {
        $optedIn = [];

        foreach (app(Router::class)->getRoutes()->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'school-context:')) {
                    $optedIn[] = $route->uri().' ['.$middleware.']';
                }
            }
        }

        $this->assertSame([], $optedIn, 'A route opted in to platform elevation without its own ADR (ADR 0044 section 8).');
    }

    #[Test]
    public function the_context_neutral_allowlist_names_real_routes_that_do_not_require_a_school(): void
    {
        $routes = app(Router::class)->getRoutes();

        foreach (self::CONTEXT_NEUTRAL as $name) {
            $route = $routes->getByName($name);

            $this->assertNotNull($route, "Allowlisted route [{$name}] no longer exists -- remove it from the allowlist.");
            $this->assertContains('auth', $route->gatherMiddleware(), "[{$name}] must still require sign-in.");
            $this->assertNotContains('school-context', $route->gatherMiddleware(), "[{$name}] is context-neutral.");
        }
    }

    #[Test]
    public function the_school_group_is_large_and_the_api_never_uses_the_web_school_context_step(): void
    {
        $schoolRoutes = array_filter($this->webRoutes(), fn (Route $r) => in_array('school-context', $r->gatherMiddleware(), true));

        // A sanity floor, not an exact count: the School group must not
        // have been emptied by a refactor.
        $this->assertGreaterThan(300, count($schoolRoutes));

        foreach (app(Router::class)->getRoutes()->getRoutes() as $route) {
            if (Str::startsWith($route->uri(), 'api/')) {
                $this->assertNotContains('school-context', $route->gatherMiddleware(), $route->uri());
            }
        }
    }

    #[Test]
    public function the_school_context_step_runs_after_sign_in_and_school_resolution_and_before_binding_capability_and_mfa(): void
    {
        $router = app(Router::class);

        foreach ($this->webRoutes() as $route) {
            if (! in_array('school-context', $route->gatherMiddleware(), true)) {
                continue;
            }

            $sorted = array_map(fn ($m) => is_string($m) ? Str::before($m, ':') : get_class($m), $router->gatherRouteMiddleware($route));
            $position = array_search(RequireSchoolContext::class, $sorted, true);
            $label = $route->uri();

            $this->assertIsInt($position, $label);

            foreach ([StartSession::class, ResolveSchoolContext::class, DevOnlySchoolHeaderResolver::class, Authenticate::class] as $before) {
                $this->assertLessThan($position, array_search($before, $sorted, true), "{$before} must run before school-context on {$label}");
            }

            foreach ($sorted as $index => $middleware) {
                if ($index > $position) {
                    continue;
                }

                $this->assertNotContains($middleware, [
                    SubstituteBindings::class,
                    EnsureCapability::class,
                    RequireMfa::class,
                    EnsureIdempotent::class,
                    ThrottleRequests::class,
                ], "{$middleware} must run after school-context on {$label}");
            }
        }
    }

    /**
     * The readiness audit's no-School page probe (section 11: 135 of 141
     * pages were a 500), made permanent and extended to every School GET
     * route, parameterised ones included (a random id: the step runs
     * before binding). Signed in with no School, every one returns to the
     * landing -- for a School member and for a Platform Super Admin.
     */
    #[Test]
    public function every_school_page_returns_to_the_landing_when_no_school_is_selected(): void
    {
        $member = $this->createUser();
        $this->createMembership($member, $this->createSchool());
        $platformAdmin = $this->createPlatformRoot();

        $probed = 0;

        foreach ([$member, $platformAdmin] as $user) {
            foreach ($this->schoolGetUris() as $uri) {
                $status = $this->probe($user, $uri);

                $this->assertSame(302, $status, "GET /{$uri} without a School");
                $probed++;
            }
        }

        $this->assertGreaterThan(200, $probed);
    }

    private function probe(User $user, string $uri): int
    {
        $response = $this->actingAs($user)->get('/'.$uri);

        if ($response->getStatusCode() === 302) {
            $response->assertRedirect('/app');
        }

        return $response->getStatusCode();
    }

    /**
     * @return list<string>
     */
    private function schoolGetUris(): array
    {
        $uris = [];

        foreach ($this->webRoutes() as $route) {
            if (in_array('GET', $route->methods(), true) && in_array('school-context', $route->gatherMiddleware(), true)) {
                $uris[] = preg_replace('/\{[^}]+\}/', (string) Str::uuid(), $route->uri());
            }
        }

        return $uris;
    }

    /**
     * @return list<Route>
     */
    private function webRoutes(): array
    {
        return array_values(array_filter(
            app(Router::class)->getRoutes()->getRoutes(),
            fn (Route $route) => in_array('web', $route->gatherMiddleware(), true),
        ));
    }
}
