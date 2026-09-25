<?php

namespace Tests\Feature\Platform\Groups;

use App\Support\Authorization\CapabilityResolver;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Phase 0N.5 structural guards for ADR 0045 section 15. Behaviour is
 * proven in the other Groups tests and Postgres\GroupAuthorityDatabaseInvariantsTest;
 * these fail on the shape of a future change that would quietly merge
 * scopes or widen Group authority.
 */
class GroupArchitectureGuardTest extends TestCase
{
    private function methodSource(string $method): string
    {
        $reflection = new ReflectionMethod(CapabilityResolver::class, $method);
        $lines = file($reflection->getFileName());

        return $this->codeOnly('<?php '.implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1)));
    }

    /** The source without comments, so documentation never trips a guard. */
    private function codeOnly(string $source): string
    {
        return implode('', array_map(
            fn ($token) => is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]) : $token,
            token_get_all($source),
        ));
    }

    #[Test]
    public function the_three_capability_paths_read_only_their_own_assignments(): void
    {
        foreach (['platformCapabilities', 'schoolCapabilities'] as $method) {
            $this->assertStringNotContainsString('GroupRoleAssignment', $this->methodSource($method), $method);
            $this->assertStringNotContainsString('group_role_assignments', $this->methodSource($method), $method);
        }

        $group = $this->methodSource('groupCapabilities').$this->methodSource('groupsWith');
        foreach (['PlatformRoleAssignment', 'platformRoleAssignments', 'MembershipRoleAssignment', 'SchoolMembership', 'schoolCapabilities', 'platformCapabilities'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $group);
        }

        // can() -- the path every School and platform check uses -- never
        // answers a Group capability.
        $this->assertStringContainsString("str_starts_with(\$capability, 'group.')", $this->methodSource('can'));
    }

    #[Test]
    public function group_code_never_touches_memberships_school_roles_tenant_context_or_the_database_bypass(): void
    {
        $files = array_merge(
            glob(app_path('Domain/Platform/Application/Groups/*.php')) ?: [],
            [
                app_path('Http/Controllers/App/Groups/SchoolGroupController.php'),
                app_path('Http/Controllers/App/Groups/GroupReportController.php'),
                app_path('Http/Controllers/App/Platform/SchoolGroupAdminController.php'),
                app_path('Models/SchoolGroup.php'),
                app_path('Models/GroupRoleAssignment.php'),
            ],
        );

        foreach ($files as $file) {
            $source = $this->codeOnly((string) file_get_contents($file));
            $this->assertStringNotContainsString('TenantContext', $source, $file);
            $this->assertStringNotContainsString('MembershipRoleAssignment', $source, $file);
            $this->assertDoesNotMatchRegularExpression('/SchoolMembership::(query\(\)->)?(create|insert|update|delete)/', $source, $file);
            $this->assertStringNotContainsString('pgsql_admin', $source, $file);
            $this->assertStringNotContainsString('withoutGlobalScope', $source, $file);
        }

        // Phase 0N.11 (ADR 0048): the Group report may only CHECK that no
        // School context is set and clear it defensively -- entering a
        // School is Analytics' Group-safe gate's job, one School at a time.
        foreach (glob(app_path('Domain/Platform/Application/Groups/Reporting/*.php')) ?: [] as $file) {
            $source = $this->codeOnly((string) file_get_contents($file));
            foreach (['->set(', 'withSchool(', 'MembershipRoleAssignment', 'pgsql_admin', 'withoutGlobalScope', 'Cache::', 'cache('] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, "{$file}: {$forbidden}");
            }
            $this->assertDoesNotMatchRegularExpression('/SchoolMembership::(query\(\)->)?(create|insert|update|delete)/', $source, $file);
        }
    }

    #[Test]
    public function the_group_capability_catalog_is_exactly_three_and_has_no_management_power(): void
    {
        // ADR 0045 section 4 plus ADR 0048 section 3 (group.reporting.view).
        $this->assertSame(
            ['group.reporting.view', 'group.schools.elevate', 'group.schools.view'],
            DB::table('capabilities')->where('namespace', 'group')->orderBy('key')->pluck('key')->all(),
        );
        $this->assertSame(['group_admin'], DB::table('roles')->where('scope', 'group')->pluck('key')->all());
        $this->assertSame(
            ['platform.school_group_grants.manage', 'platform.school_groups.manage', 'platform.school_groups.view'],
            DB::table('capabilities')->where('key', 'like', 'platform.school_group%')->orderBy('key')->pluck('key')->all(),
        );
    }

    #[Test]
    public function governance_routes_are_platform_gated_and_no_api_route_knows_about_groups(): void
    {
        $router = app(Router::class);

        foreach ($router->getRoutes()->getRoutes() as $route) {
            $name = (string) $route->getName();

            if (str_starts_with($name, 'app.platform.groups.')) {
                $this->assertContains('capability:platform.school_groups.view,platform', $route->gatherMiddleware(), $name);
            }

            if (Str::startsWith($route->uri(), 'api/')) {
                $this->assertStringNotContainsString('group', strtolower($route->uri()), $route->uri());
                $this->assertStringNotContainsString('Group', (string) $route->getActionName(), $route->uri());
            }

            if (str_starts_with($name, 'app.groups.') || str_starts_with($name, 'app.platform.groups.')) {
                foreach ($route->gatherMiddleware() as $middleware) {
                    $this->assertFalse(is_string($middleware) && str_starts_with($middleware, 'school-context'), "{$name} must never be School context");
                }
            }
        }
    }
}
