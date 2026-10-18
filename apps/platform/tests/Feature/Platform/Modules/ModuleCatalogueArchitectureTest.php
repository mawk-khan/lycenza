<?php

namespace Tests\Feature\Platform\Modules;

use App\Domain\Platform\Application\Modules\ModuleCatalogue;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ORG.5 (ADR 0076 §12, §14, §31): the code-owned module catalogue stays
 * complete, acyclic and in step with the database.
 */
class ModuleCatalogueArchitectureTest extends TestCase
{
    /** @return list<string> */
    private function capabilities(array $namespaces): array
    {
        return DB::table('capabilities')->whereIn('namespace', $namespaces)->orderBy('key')->pluck('key')->all();
    }

    #[Test]
    public function every_school_and_guardian_capability_maps_to_at_least_one_known_module(): void
    {
        $unmapped = [];
        foreach ($this->capabilities(['school', 'guardian']) as $capability) {
            $modules = ModuleCatalogue::modulesFor($capability);
            if ($modules === []) {
                $unmapped[] = $capability;
            }
            foreach ($modules as $module) {
                $this->assertTrue(ModuleCatalogue::exists($module), "{$capability} maps to unknown module {$module}");
            }
        }

        $this->assertSame([], $unmapped, 'Every School/Guardian capability must map to a module (CLAUDE.md rule 100).');
    }

    #[Test]
    public function platform_and_group_capabilities_are_outside_the_module_model(): void
    {
        foreach ($this->capabilities(['platform', 'group']) as $capability) {
            $this->assertSame([], ModuleCatalogue::modulesFor($capability), "{$capability} must not map to a School module");
        }
    }

    #[Test]
    public function every_prefix_and_multi_module_rule_matches_a_real_capability(): void
    {
        $catalogue = $this->capabilities(['school', 'guardian']);

        foreach (ModuleCatalogue::MODULES as $key => $module) {
            foreach ($module['prefixes'] as $prefix) {
                $matches = array_filter($catalogue, fn (string $c): bool => str_starts_with($c, $prefix));
                $this->assertNotEmpty($matches, "Stale prefix {$prefix} on module {$key}");
            }
        }

        foreach (ModuleCatalogue::MULTI_MODULE as $capability => $modules) {
            $this->assertContains($capability, $catalogue, "Stale multi-module rule {$capability}");
            $this->assertGreaterThanOrEqual(2, count($modules));
            foreach ($modules as $module) {
                $this->assertTrue(ModuleCatalogue::exists($module));
            }
        }
    }

    #[Test]
    public function dependencies_are_known_acyclic_and_never_make_core_depend_on_optional(): void
    {
        foreach (ModuleCatalogue::MODULES as $key => $module) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $key);
            $this->assertNotContains($key, $module['dependsOn'], "{$key} depends on itself");
            foreach ($module['dependsOn'] as $dependency) {
                $this->assertTrue(ModuleCatalogue::exists($dependency), "{$key} depends on unknown {$dependency}");
                if ($module['core']) {
                    $this->assertTrue(ModuleCatalogue::isCore($dependency), "Core {$key} depends on optional {$dependency}");
                }
            }
        }

        // Depth-first cycle detection.
        $state = [];
        $visit = function (string $module, array $path) use (&$visit, &$state): void {
            if (($state[$module] ?? null) === 'done') {
                return;
            }
            $this->assertNotContains($module, $path, 'Dependency cycle: '.implode(' -> ', [...$path, $module]));
            foreach (ModuleCatalogue::dependenciesOf($module) as $dependency) {
                $visit($dependency, [...$path, $module]);
            }
            $state[$module] = 'done';
        };
        foreach (ModuleCatalogue::keys() as $module) {
            $visit($module, []);
        }
    }

    #[Test]
    public function the_database_check_admits_exactly_the_optional_modules(): void
    {
        $definition = DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conrelid = 'school_module_entitlements'::regclass AND conname = 'school_module_entitlements_module_key_check'")->def;
        preg_match_all("/'([a-z_]+)'::/", $definition, $m);

        $this->assertEqualsCanonicalizing(ModuleCatalogue::optionalKeys(), $m[1]);
        foreach (ModuleCatalogue::coreKeys() as $core) {
            $this->assertNotContains($core, $m[1]);
        }
    }

    #[Test]
    public function the_core_set_is_the_adr_section_14_set(): void
    {
        $this->assertSame(
            ['identity_access', 'organization', 'academic_structure', 'student_records', 'staff_records', 'compliance_audit', 'documents'],
            ModuleCatalogue::coreKeys(),
        );
    }
}
