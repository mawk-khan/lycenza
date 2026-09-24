<?php

namespace Tests\Feature\Platform\Elevation;

use App\Domain\Platform\Application\Elevation\ElevationReason;
use App\Http\Middleware\DevOnlySchoolHeaderResolver;
use App\Http\Middleware\RequireSchoolContext;
use App\Http\Middleware\ResolvePlatformElevation;
use App\Http\Middleware\ResolveSchoolContext;
use App\Support\Tenancy\ElevationContext;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0N.3 structural guards for ADR 0044 section 19. Behaviour is
 * proven elsewhere (SchoolElevation*Test, ElevatedSchoolRouteDenialTest,
 * Postgres\ElevationRlsIsolationTest); these fail on the shape of a
 * future change that would quietly widen elevation. The "no route opts
 * in" guard lives in Tenancy\SchoolContextRouteGuardTest.
 */
class ElevationArchitectureGuardTest extends TestCase
{
    #[Test]
    public function the_resolver_runs_on_web_only_after_every_school_resolver_and_before_school_context(): void
    {
        $router = app(Router::class);
        $this->assertContains(ResolvePlatformElevation::class, $router->getMiddlewareGroups()['web']);
        $this->assertNotContains(ResolvePlatformElevation::class, $router->getMiddlewareGroups()['api']);

        $route = $router->getRoutes()->getByName('app.school-setup.index');
        $sorted = array_map(fn ($m) => is_string($m) ? Str::before($m, ':') : $m::class, $router->gatherRouteMiddleware($route));
        $at = fn (string $class) => array_search($class, $sorted, true);

        $this->assertLessThan($at(ResolvePlatformElevation::class), $at(ResolveSchoolContext::class));
        $this->assertLessThan($at(ResolvePlatformElevation::class), $at(DevOnlySchoolHeaderResolver::class));
        $this->assertLessThan($at(RequireSchoolContext::class), $at(ResolvePlatformElevation::class));
    }

    #[Test]
    public function only_the_four_approved_reason_codes_exist_in_code_and_in_the_database(): void
    {
        $expected = ['operational_support', 'security_investigation', 'configuration_assistance', 'incident_response'];
        $this->assertSame($expected, array_map(fn (ElevationReason $r) => $r->value, ElevationReason::cases()));

        $definition = DB::connection('pgsql_admin')->selectOne(
            "select pg_get_constraintdef(oid) as def from pg_constraint where conname = 'school_elevations_reason_code_check'",
        )->def;
        preg_match_all("/'([a-z_]+)'::/", $definition, $m);
        $this->assertEqualsCanonicalizing($expected, $m[1]);
    }

    #[Test]
    public function the_elevation_capability_is_platform_only_and_no_school_role_holds_any_platform_capability(): void
    {
        $holders = DB::table('role_capabilities')
            ->join('roles', 'roles.id', '=', 'role_capabilities.role_id')
            ->where('role_capabilities.capability_key', 'platform.schools.elevate')
            ->pluck('roles.key')->all();
        $this->assertSame(['platform_super_admin'], $holders);

        $schoolRolesWithPlatformCaps = DB::table('role_capabilities')
            ->join('roles', 'roles.id', '=', 'role_capabilities.role_id')
            ->where('roles.scope', 'school')
            ->where('role_capabilities.capability_key', 'like', 'platform.%')
            ->count();
        $this->assertSame(0, $schoolRolesWithPlatformCaps);
    }

    #[Test]
    public function elevation_code_never_writes_memberships_or_school_roles_or_maps_platform_to_school_capabilities(): void
    {
        $files = array_merge(
            glob(app_path('Domain/Platform/Application/Elevation/*.php')) ?: [],
            [
                app_path('Http/Middleware/ResolvePlatformElevation.php'),
                app_path('Http/Controllers/App/Platform/SchoolElevationController.php'),
                app_path('Support/Tenancy/ElevationContext.php'),
                app_path('Models/SchoolElevation.php'),
            ],
        );

        foreach ($files as $file) {
            $source = file_get_contents($file);
            $this->assertStringNotContainsString('MembershipRoleAssignment', $source, $file);
            $this->assertDoesNotMatchRegularExpression('/SchoolMembership::(query\(\)->)?(create|insert|updateOrCreate|firstOrCreate)/', $source, $file);
            $this->assertStringNotContainsString('schoolCapabilities', $source, $file);
            $this->assertStringNotContainsString('BYPASSRLS', $source, $file);
            $this->assertStringNotContainsString('pgsql_admin', $source, $file);
        }

        // CapabilityResolver's School side stays membership-only.
        $resolver = file_get_contents(app_path('Support/Authorization/CapabilityResolver.php'));
        $this->assertStringNotContainsString('Elevation', $resolver);
    }

    #[Test]
    public function the_elevation_context_is_request_scoped_and_separate_from_tenant_context(): void
    {
        $first = app(ElevationContext::class);
        $this->assertSame($first, app(ElevationContext::class), 'One instance per unit of work.');
        app()->forgetScopedInstances();
        $this->assertNotSame($first, app(ElevationContext::class), 'Scoped: never carried into the next request or job.');
        $this->assertStringNotContainsString('Elevation', file_get_contents(app_path('Support/Tenancy/TenantContext.php')));
    }
}
