<?php

namespace Tests\Feature\Platform\Groups;

use App\Domain\Platform\Application\Groups\SchoolGroupDirectoryReadService;
use App\Domain\Platform\Application\Groups\SchoolGroupRelationship;
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
                app_path('Models/SchoolGroupMember.php'),
                app_path('Models/SchoolGroupRegion.php'),
                app_path('Models/SchoolGroupZone.php'),
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
    public function the_group_capability_catalog_is_pinned_and_group_admin_is_unchanged(): void
    {
        // ADR 0045 section 4 plus ADR 0048 section 3 (group.reporting.view),
        // plus ORG.12E (ADR 0080 §10) Network governance.
        $this->assertSame(
            ['group.network.manage', 'group.network.operators.manage', 'group.network.view', 'group.reporting.view', 'group.schools.elevate', 'group.schools.view'],
            DB::table('capabilities')->where('namespace', 'group')->orderBy('key')->pluck('key')->all(),
        );
        $roles = DB::table('roles')->where('scope', 'group')->orderBy('key')->get(['id', 'key'])->mapWithKeys(fn ($r) => [
            $r->key => DB::table('role_capabilities')->where('role_id', $r->id)->orderBy('capability_key')->pluck('capability_key')->all(),
        ])->all();
        $this->assertSame([
            // OD-12.13: group_admin keeps exactly its ADR 0045/0048 semantics -- no management.
            'group_admin' => ['group.reporting.view', 'group.schools.elevate', 'group.schools.view'],
            // Never elevation, Group reporting or anything outside the Group namespace.
            'network_manager' => ['group.network.manage', 'group.network.operators.manage', 'group.network.view'],
            'network_viewer' => ['group.network.view'],
        ], $roles);
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

    #[Test]
    public function the_network_structure_is_group_owned_platform_metadata_never_a_tenant_or_an_authority_scope(): void
    {
        // ORG.12D (ADR 0080 §9, OD-12.8/OD-12.10): no Network/Organization
        // entity, no hierarchy engine, and Region/Zone exist ONLY on the
        // Group layer -- no role assignment, membership, capability or
        // tenant table can carry one, so nothing can be zone-scoped.
        $columns = DB::table('information_schema.columns')->whereRaw('table_schema = current_schema()')
            ->whereIn('column_name', ['region_id', 'zone_id', 'network_id', 'organization_id', 'school_group_region_id', 'school_group_zone_id'])
            ->orderBy('table_name')->orderBy('column_name')->get()->map(fn ($c) => "{$c->table_name}.{$c->column_name}")->all();
        // ORG.12E: a read-only Network grant may name a Region or Zone -- still the Group layer only.
        $this->assertSame(['group_role_assignments.region_id', 'group_role_assignments.zone_id', 'school_group_members.region_id', 'school_group_members.zone_id', 'school_group_zones.region_id'], $columns);

        foreach (['school_group_regions', 'school_group_zones'] as $table) {
            $this->assertFalse(DB::getSchemaBuilder()->hasColumn($table, 'school_id'), "{$table} is not tenant-owned");
            $this->assertFalse(DB::getSchemaBuilder()->hasColumn($table, 'parent_id'), "{$table}: no recursive hierarchy");
            $this->assertFalse((bool) DB::selectOne('select relrowsecurity from pg_class where oid = ?::regclass', [$table])->relrowsecurity, "{$table} is a platform table");
        }
        $this->assertSame([], DB::table('information_schema.tables')->whereRaw('table_schema = current_schema()')
            ->whereIn('table_name', ['networks', 'school_networks', 'organizations', 'franchises', 'regions', 'zones'])->pluck('table_name')->all());
    }

    #[Test]
    public function the_relationship_catalogue_is_code_owned_and_matches_the_database(): void
    {
        $definition = (string) DB::selectOne("select pg_get_constraintdef(oid) as d from pg_constraint where conname = 'school_group_members_relationship_check'")->d;
        preg_match_all("/'([a-z_]+)'::/", $definition, $values);

        $this->assertSame(array_column(SchoolGroupRelationship::cases(), 'value'), $values[1]);
        $this->assertSame(['owned', 'franchise', 'managed', 'affiliate'], array_column(SchoolGroupRelationship::cases(), 'value'));
    }

    #[Test]
    public function the_group_directory_reads_only_platform_tables_and_nothing_inside_a_school(): void
    {
        $source = $this->codeOnly((string) file_get_contents((new \ReflectionClass(SchoolGroupDirectoryReadService::class))->getFileName()));
        preg_match_all("/DB::table\\('([a-z_]+)(?: as [a-z]+)?'\\)|[jJ]oin\\('([a-z_]+)(?: as [a-z]+)?'/", $source, $matches);
        $tables = array_values(array_unique(array_filter(array_merge($matches[1], $matches[2]))));
        sort($tables);

        $this->assertSame(['school_group_members', 'school_group_regions', 'school_group_zones', 'schools'], $tables);
        foreach (['App\\Models\\Student', 'App\\Models\\Employee', 'App\\Models\\Guardian', 'App\\Models\\Campus', 'withSchool', 'Cache::', 'cache(', 'App\\Domain\\Students', 'App\\Domain\\HR', 'App\\Domain\\Payments', 'App\\Domain\\Fees'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, $forbidden);
        }
    }
}
