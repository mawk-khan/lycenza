<?php

namespace Tests\Feature\Platform\Roles;

use App\Domain\Platform\Application\Roles\PlatformRoleGovernanceService;
use App\Http\Controllers\App\Platform\PlatformRoleAdminController;
use App\Support\Audit\PlatformAuditEventEntry;
use App\Support\Audit\PlatformAuditEventReader;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClassConstant;
use Tests\TestCase;

/**
 * Phase 0N.7 structural guards for ADR 0046 section 13. Behaviour is proven
 * in PlatformRoleGovernanceTest, PlatformAuditLogReviewTest and
 * Postgres\PlatformRoleAssignmentInvariantsTest; these fail on the shape
 * of a change that would quietly widen platform authority.
 */
class PlatformAuthorityArchitectureGuardTest extends TestCase
{
    #[Test]
    public function the_only_runtime_assignable_role_is_the_auditor_with_exactly_one_capability(): void
    {
        $this->assertSame(['platform_auditor'], PlatformRoleGovernanceService::RUNTIME_ASSIGNABLE);
        $this->assertSame(['platform_auditor'], DB::table('roles')->where('runtime_assignable', true)->pluck('key')->all());
        $this->assertSame('platform_auditor', PlatformRoleAdminController::ROLE);
        $this->assertFalse((bool) DB::table('roles')->where('key', 'platform_super_admin')->value('runtime_assignable'));

        $auditorCaps = DB::table('role_capabilities')->join('roles', 'roles.id', '=', 'role_capabilities.role_id')
            ->where('roles.key', 'platform_auditor')->pluck('capability_key')->all();
        $this->assertSame(['platform.audit.view'], $auditorCaps);

        $this->assertSame(['platform_super_admin'], DB::table('role_capabilities')->join('roles', 'roles.id', '=', 'role_capabilities.role_id')
            ->where('capability_key', 'platform.role_grants.manage')->pluck('roles.key')->all());
        $this->assertEqualsCanonicalizing(['platform_auditor', 'platform_super_admin'], DB::table('role_capabilities')->join('roles', 'roles.id', '=', 'role_capabilities.role_id')
            ->where('capability_key', 'platform.audit.view')->pluck('roles.key')->all());
    }

    #[Test]
    public function no_application_code_names_the_root_role_or_reaches_other_scopes_role_tables(): void
    {
        foreach ($this->phpFiles(app_path()) as $file) {
            $this->assertStringNotContainsString("'platform_super_admin'", (string) file_get_contents($file), "{$file} must not authorize by the root role's name");
        }

        $governance = (string) file_get_contents(app_path('Domain/Platform/Application/Roles/PlatformRoleGovernanceService.php'));
        foreach (['MembershipRoleAssignment', 'GroupRoleAssignment', 'SchoolMembership', 'role_capabilities', 'Capability::'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $governance, "Platform role governance must not touch {$foreign}");
        }
    }

    #[Test]
    public function the_audit_dto_and_reader_expose_only_the_envelope(): void
    {
        $this->assertSame(['id', 'occurredAt', 'eventType', 'actorUserId', 'subjectType', 'subjectId', 'requestId'], PlatformAuditEventEntry::FIELDS);

        $columns = (new ReflectionClassConstant(PlatformAuditEventReader::class, 'COLUMNS'))->getValue();
        $this->assertSame(['id', 'occurred_at', 'actor_user_id', 'event_type', 'subject_type', 'subject_id', 'request_id'], $columns);
    }

    #[Test]
    public function the_platform_pages_are_context_neutral_capability_gated_and_absent_from_the_api(): void
    {
        $router = app(Router::class);
        $routes = $router->getRoutes();

        $audit = $routes->getByName('app.platform.audit-log');
        $this->assertContains('capability:platform.audit.view,platform', $audit->gatherMiddleware());
        $this->assertContains('capability:platform.role_grants.manage,platform', $routes->getByName('app.platform.roles.index')->gatherMiddleware());

        foreach (['app.platform.audit-log', 'app.platform.roles.index', 'app.platform.roles.grants.store', 'app.platform.roles.grants.revoke'] as $name) {
            foreach ($routes->getByName($name)->gatherMiddleware() as $middleware) {
                $this->assertFalse(is_string($middleware) && str_starts_with($middleware, 'school-context'), $name);
            }
        }

        foreach ($routes->getRoutes() as $route) {
            if (Str::startsWith($route->uri(), 'api/')) {
                $this->assertStringNotContainsString('PlatformAuditLog', (string) $route->getActionName());
                $this->assertStringNotContainsString('PlatformRole', (string) $route->getActionName());
            }
        }
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $dir): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
