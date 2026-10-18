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
    public function the_runtime_assignable_roles_are_exactly_the_auditor_and_the_manager(): void
    {
        // ORG.4 (ADR 0076 §6-§8): two runtime roles, pinned capability sets, never root-equivalent.
        $this->assertSame(['platform_auditor', 'platform_manager'], PlatformRoleGovernanceService::RUNTIME_ASSIGNABLE);
        $this->assertEqualsCanonicalizing(['platform_auditor', 'platform_manager'], DB::table('roles')->where('runtime_assignable', true)->pluck('key')->all());
        $this->assertSame(['platform_manager', 'platform_auditor'], array_keys(PlatformRoleAdminController::ROLES));
        $this->assertSame(['platform_manager' => 'platform_auditor', 'platform_auditor' => 'platform_manager'], PlatformRoleGovernanceService::EXCLUSIVE);
        $this->assertFalse((bool) DB::table('roles')->where('key', 'platform_super_admin')->value('runtime_assignable'));

        $caps = fn (string $role) => DB::table('role_capabilities')->join('roles', 'roles.id', '=', 'role_capabilities.role_id')
            ->where('roles.key', $role)->pluck('capability_key')->all();
        $this->assertEqualsCanonicalizing(['platform.audit.view', 'platform.schools.view', 'platform.school_groups.view'], $caps('platform_auditor'));
        $this->assertEqualsCanonicalizing(['platform.schools.view', 'platform.school_groups.view', 'platform.schools.onboard'], $caps('platform_manager'));

        $holders = fn (string $capability) => DB::table('role_capabilities')->join('roles', 'roles.id', '=', 'role_capabilities.role_id')
            ->where('capability_key', $capability)->pluck('roles.key')->all();
        foreach (['platform.role_grants.manage', 'platform.schools.manage', 'platform.schools.elevate', 'platform.school_groups.manage', 'platform.school_group_grants.manage', 'platform.users.mfa.reset'] as $rootOnly) {
            $this->assertSame(['platform_super_admin'], $holders($rootOnly), "{$rootOnly} stays with root");
        }
        $this->assertEqualsCanonicalizing(['platform_auditor', 'platform_super_admin'], $holders('platform.audit.view'));
        $this->assertEqualsCanonicalizing(['platform_manager', 'platform_super_admin'], $holders('platform.schools.onboard'));
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
