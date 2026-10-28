<?php

namespace Tests\Feature\Setup;

use App\Domain\Platform\Application\Modules\ModuleCatalogue;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
use App\Support\Setup\OrganizationSetupReadService;
use App\Support\Setup\OrganizationSetupRegistry;
use App\Support\Setup\SetupCategory;
use App\Support\Setup\SetupReadinessProvider;
use App\Support\Setup\SetupReadinessProviders;
use App\Support\Setup\SetupScopeLevel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ORG.12A (ADR 0080 §12): the Organization Control Plane stays an observer.
 *
 * - The setup catalogue is code-owned: a const, no table, no runtime
 *   registration, no SQL, no tenant input.
 * - Every definition is consistent with the module catalogue, the route
 *   table and the capability catalogue, and never needs a Campus-adopted key
 *   checked School-wide (CampusScopeCatalogue stays untouched, rule 101).
 * - Every provider lives in the module that owns its configuration and is
 *   read-only; a provider outside Students never reads the Student model.
 * - No parallel tenant (organization/tenant id) and no Branch entity exists,
 *   and navigation never couples to the setup registry (menu != security).
 */
class OrganizationSetupArchitectureTest extends TestCase
{
    private const array WRITES = [
        '->update(', '->save(', '->create(', '->delete(', '->insert(', '->forceFill(', '->increment(', '->decrement(',
        'DB::statement', 'DB::unprepared', 'DB::transaction', 'lockForUpdate', 'sharedLock', '->upsert(', 'AuditRecorder', 'audit_events', 'Outbox',
        'Cache::', '->truncate(', '->updateOrCreate(', '->firstOrCreate(', 'dispatch(', 'event(',
    ];

    /** The shape every school-facing setup key takes, frozen: a change here is a reviewed catalogue change. */
    private const array KEYS = [
        'organization.school_profile', 'organization.campuses', 'academic.academic_year', 'academic.structure',
        'academic.timetable', 'students.student_records', 'students.guardian_identity', 'staff.staff_records',
        'staff.leave', 'attendance.student_attendance', 'attendance.staff_attendance', 'finance.fees',
        'admissions.admissions', 'transport.transport', 'library.library', 'inventory.inventory',
        'communications.communications', 'communications.events', 'portal.guardian_portal', 'compliance.audit',
        'integrations.integrations',
    ];

    private function codeOnly(string $path): string
    {
        return (string) preg_replace('/^\s*(\*|\/\/|\/\*).*$/m', '', (string) file_get_contents($path));
    }

    #[Test]
    public function the_catalogue_is_exactly_the_reviewed_key_set_in_order(): void
    {
        $this->assertSame(self::KEYS, app(OrganizationSetupRegistry::class)->keys());
    }

    #[Test]
    public function every_definition_is_consistent_with_routes_capabilities_and_modules(): void
    {
        $catalogue = DB::table('capabilities')->pluck('namespace', 'key')->all();
        $mapKeys = $this->referenceMapFamilies();

        foreach (app(OrganizationSetupRegistry::class)->all() as $definition) {
            $key = $definition->key;
            $this->assertTrue(Route::has($definition->route), "{$key}: route {$definition->route} exists");
            $this->assertNotSame(SetupScopeLevel::NETWORK_FUTURE, $definition->scopeLevel);
            $this->assertNotSame(SetupScopeLevel::PLATFORM_FUTURE, $definition->scopeLevel);
            $this->assertContains($definition->category, SetupCategory::cases());

            $allowedModules = [$definition->ownerModule, ...$definition->entitlementModules, ...ModuleCatalogue::coreKeys()];
            foreach ([...$definition->viewCapabilities, ...$definition->manageCapabilities] as $capability) {
                $this->assertSame('school', $catalogue[$capability] ?? null, "{$key}: {$capability} is an existing School capability");
                $modules = ModuleCatalogue::modulesFor($capability);
                $this->assertNotSame([], $modules, "{$key}: {$capability} maps to a module");
                $this->assertSame([], array_diff($modules, $allowedModules), "{$key}: {$capability} belongs to the area's own modules");
            }
            foreach ($definition->viewCapabilities as $capability) {
                $this->assertFalse(CampusScopeCatalogue::isAdopted($capability), "{$key}: readiness never checks the Campus-adopted {$capability} School-wide");
            }
            foreach ($definition->referenceFamilies as $family) {
                $this->assertContains($family, $mapKeys, "{$key}: {$family} is a family of the ORG-12 reference map");
            }
        }
    }

    /**
     * Security review L-2: a floor-only area (its domain read keys are
     * Campus-adopted, so readiness cannot check them School-wide) must never
     * tell a floor holder more than that holder's own roles already could.
     * A future catalogue release giving `school.profile.view` to a role
     * without those keys fails here.
     */
    #[Test]
    public function every_role_holding_the_setup_floor_also_holds_each_floor_only_areas_read_keys(): void
    {
        $floorOnly = array_filter(app(OrganizationSetupRegistry::class)->all(), fn ($d): bool => $d->viewCapabilities === []);
        $this->assertNotEmpty($floorOnly);

        $roleCapabilities = DB::table('roles')->join('role_capabilities', 'role_capabilities.role_id', '=', 'roles.id')
            ->where('roles.scope', 'school')->where('roles.is_system', true)
            ->get(['roles.key as role', 'role_capabilities.capability_key as capability'])
            ->groupBy('role')->map(fn ($rows) => $rows->pluck('capability')->all());
        $floorRoles = $roleCapabilities->filter(fn (array $capabilities): bool => in_array(OrganizationSetupReadService::FLOOR_CAPABILITY, $capabilities, true));
        $this->assertNotEmpty($floorRoles);

        foreach ($floorOnly as $definition) {
            foreach ($definition->adoptedReadCapabilities as $capability) {
                $this->assertTrue(CampusScopeCatalogue::isAdopted($capability), "{$definition->key}: {$capability} is the adopted key that forced floor-only");
                $this->assertSame([], array_diff(ModuleCatalogue::modulesFor($capability), [$definition->ownerModule, ...$definition->entitlementModules]), "{$definition->key}: {$capability} is the area's own read key");
                foreach ($floorRoles as $role => $capabilities) {
                    $this->assertContains($capability, $capabilities, "{$role} holds the setup floor, so it must also hold {$capability} ({$definition->key})");
                }
            }
        }
    }

    #[Test]
    public function every_key_has_exactly_one_domain_owned_read_only_provider(): void
    {
        $providers = app(SetupReadinessProviders::class);
        $this->assertEqualsCanonicalizing(self::KEYS, $providers->keys());

        $owners = [
            'organization' => ['Schools', 'Campuses'], 'academic_structure' => ['AcademicStructure'], 'timetable' => ['Timetable'],
            'student_records' => ['Students', 'Guardians'], 'staff_records' => ['HR'], 'leave' => ['Leave'], 'attendance' => ['Attendance'],
            'staff_attendance' => ['StaffAttendance'], 'fees' => ['Fees'], 'admissions' => ['Admissions'], 'transport' => ['Transport'],
            'library' => ['Library'], 'inventory' => ['Inventory'], 'communications' => ['Communications'], 'guardian_portal' => ['Identity'],
            'compliance_audit' => ['Support\\Audit'], 'integrations' => ['Support\\ApiClients'],
        ];

        foreach (app(OrganizationSetupRegistry::class)->all() as $definition) {
            $provider = $providers->for($definition->key);
            $this->assertInstanceOf(SetupReadinessProvider::class, $provider);
            $class = new ReflectionClass($provider);
            $name = $class->getName();
            $homes = array_map(fn (string $home): string => str_starts_with($home, 'Support\\') ? "App\\{$home}\\" : "App\\Domain\\{$home}\\Application\\", $owners[$definition->ownerModule]);
            $this->assertTrue(
                array_filter($homes, fn (string $home): bool => str_starts_with($name, $home)) !== [],
                "{$definition->key}: {$name} lives in its owning module",
            );

            $source = $this->codeOnly((string) $class->getFileName());
            foreach (self::WRITES as $write) {
                $this->assertStringNotContainsString($write, $source, "{$name} is read-only ({$write})");
            }
            if (! str_starts_with($name, 'App\\Domain\\Students\\')) {
                foreach (['student_enrollments', 'StudentEnrollment::', 'Student::query', "'students'"] as $foreign) {
                    $this->assertStringNotContainsString($foreign, $source, "{$name} reads Students data only through a Students read service");
                }
            }
            if (preg_match_all('/^use App\\\\Domain\\\\(\w+)\\\\Infrastructure\\\\/m', $source, $m) > 0) {
                $module = explode('\\', $name)[2];
                $this->assertSame([$module], array_values(array_unique($m[1])), "{$name} reads only its own module's models");
            }
        }
    }

    #[Test]
    public function the_control_plane_itself_is_code_owned_read_only_and_sql_free(): void
    {
        foreach (Finder::create()->files()->in(app_path('Support/Setup'))->name('*.php') as $file) {
            $source = $this->codeOnly($file->getRealPath());
            foreach ([...self::WRITES, 'DB::', '::query(', 'selectRaw', 'whereRaw', 'Schema::', 'request(', 'Request '] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, "{$file->getFilename()} holds no query, write or request input ({$forbidden})");
            }
        }

        $registry = new ReflectionClass(OrganizationSetupRegistry::class);
        $this->assertTrue($registry->isFinal());
        $this->assertTrue($registry->hasConstant('DEFINITIONS'));
        foreach ($registry->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $this->assertDoesNotMatchRegularExpression('/^(register|add|set|put|remove|delete|update|save|store)/i', $method->getName(), 'the catalogue has no runtime registration path');
        }
        $this->assertStringNotContainsString('select ', strtolower(var_export(OrganizationSetupRegistry::DEFINITIONS, true)), 'no definition carries SQL');
    }

    #[Test]
    public function no_setup_state_table_parallel_tenant_or_branch_entity_exists(): void
    {
        $tables = collect(DB::select("select table_name from information_schema.tables where table_schema = 'public'"))->pluck('table_name');
        foreach ($tables as $table) {
            $this->assertDoesNotMatchRegularExpression('/^(organi[sz]ations?|tenants?|branch(es)?|.*setup_(state|status|progress|completion).*)$/', $table, "no {$table} table");
        }
        $columns = DB::table('information_schema.columns')->where('table_schema', 'public')
            ->whereIn('column_name', ['tenant_id', 'organization_id', 'organisation_id', 'branch_id', 'setup_complete'])
            ->pluck('table_name', 'column_name')->all();
        $this->assertSame([], $columns, 'School is the only tenant key; Campus is the only Branch; readiness is never stored');

        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            $this->assertDoesNotMatchRegularExpression('/\b(class|enum|interface)\s+(Branch|Organi[sz]ation|Tenant)\b/', $file->getContents(), "{$file->getRelativePathname()} declares no Branch/Organization/Tenant entity");
        }
    }

    #[Test]
    public function navigation_never_couples_to_the_setup_registry(): void
    {
        foreach (Finder::create()->files()->in(app_path('Support/Navigation'))->name('*.php') as $file) {
            $this->assertStringNotContainsString('Support\\Setup', $file->getContents(), 'navigation follows capabilities, never setup state');
        }
        foreach (Finder::create()->files()->in(app_path('Support/Setup'))->name('*.php') as $file) {
            $this->assertStringNotContainsString('Navigation', $file->getContents(), 'setup readiness is not menu configuration');
        }
    }

    /** @return list<string> the RF-nn family ids of docs/organization/ORG-12-REFERENCE-CAPABILITY-MAP.md */
    private function referenceMapFamilies(): array
    {
        $path = dirname(base_path(), 2).'/docs/organization/ORG-12-REFERENCE-CAPABILITY-MAP.md';
        $this->assertFileExists($path);
        preg_match_all('/^\| (RF-\d{2}) \|/m', (string) file_get_contents($path), $m);
        $families = array_values(array_unique($m[1]));
        $this->assertCount(38, $families, 'the map classifies 38 reference families');

        return $families;
    }
}
