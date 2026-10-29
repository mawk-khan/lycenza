<?php

namespace App\Domain\Identity\Application\Access;

use App\Domain\Identity\Application\Staff\StaffRoleCatalog;
use App\Domain\Platform\Application\Modules\ModuleCatalogue;
use App\Models\Role;
use App\Support\Approval\ApprovalWorkflowRegistry;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
use App\Support\Authorization\CapabilityClasses;
use Illuminate\Support\Facades\DB;

/**
 * ORG.12E (ADR 0080 §10.7): the read-only explanation of Lycenza's
 * code-owned School access model -- role templates, the capabilities they
 * carry grouped by module, and the role × module matrix.
 *
 * - Read from the code-owned catalogue (two queries: roles with their
 *   capabilities, capability labels); everything else is in-memory metadata
 *   (ModuleCatalogue, CapabilityClasses, CampusScopeCatalogue,
 *   ApprovalWorkflowRegistry). No School data, no person.
 * - Explanatory only: flags (read, manage, sensitive, approval, assurance,
 *   Campus scope) describe existing rules and are never an authorization
 *   input. Nothing here creates or edits a role or capability (rule 93).
 */
final class AccessCatalogueReader
{
    private const ADMIN_AUTHORITY = [StaffRoleCatalog::MEMBERS, StaffRoleCatalog::ROLES, ...CapabilityClasses::GRANT_RIGHT_KEYS];

    /**
     * @return array{roles: list<array<string, mixed>>, modules: list<array<string, mixed>>, matrix: list<array<string, mixed>>, sensitive: array<string, mixed>}
     */
    public function catalogue(): array
    {
        $roles = Role::query()->where('scope', Role::SCOPE_SCHOOL)->where('is_system', true)->whereNull('retired_at')
            ->with('capabilities:key')->get();
        $labels = DB::table('capabilities')->where('namespace', 'school')->pluck('label', 'key')->all();
        $capabilities = array_keys($labels);
        sort($capabilities);

        $modules = [];
        foreach (ModuleCatalogue::MODULES as $moduleKey => $module) {
            $keys = ModuleCatalogue::capabilitiesOf($moduleKey, $capabilities);
            if ($keys === []) {
                continue;
            }
            $modules[] = [
                'key' => $moduleKey,
                'label' => $module['label'],
                'core' => $module['core'],
                'description' => $module['description'],
                'capabilities' => array_map(fn (string $key): array => $this->describe($key, $labels[$key] ?? $key), $keys),
            ];
        }

        $presented = $roles->map(function (Role $role) use ($labels): array {
            $keys = $role->capabilities->pluck('key')->all();
            $presentation = StaffRoleCatalog::presentationOf($role->key, $keys);
            $byModule = [];
            foreach ($keys as $key) {
                foreach (ModuleCatalogue::modulesFor($key) as $module) {
                    $byModule[$module][] = $this->describe($key, $labels[$key] ?? $key);
                }
            }
            ksort($byModule);

            return [
                'key' => $role->key,
                'name' => $role->name,
                'purpose' => $presentation['purpose'],
                'group' => $presentation['groupLabel'],
                'addOn' => $presentation['addOn'],
                'sensitivity' => $presentation['sensitivity'],
                'capabilityCount' => count($keys),
                'modules' => array_map(fn (string $m): string => ModuleCatalogue::MODULES[$m]['label'] ?? $m, array_keys($byModule)),
                'campusScopable' => StaffRoleCatalog::campusScopable($role),
                'campusAreas' => CampusScopeCatalogue::areasOf($keys),
                'adminAuthority' => array_intersect($keys, self::ADMIN_AUTHORITY) !== [],
                'sensitiveFamilies' => SensitiveDataCatalogue::reachedBy($keys),
                'capabilities' => array_map(fn (string $module, array $caps): array => [
                    'module' => ModuleCatalogue::MODULES[$module]['label'] ?? $module,
                    'capabilities' => $caps,
                ], array_keys($byModule), $byModule),
                '_order' => $presentation['order'],
                '_keys' => $keys,
            ];
        })->sortBy([['_order', 'asc'], ['name', 'asc']])->values();

        // Rows: role templates; columns: modules -- read / manage / none (never menu entries).
        $matrix = $presented->map(fn (array $role): array => [
            'role' => $role['key'],
            'name' => $role['name'],
            'cells' => array_map(function (array $module) use ($role): string {
                $held = array_intersect(array_column($module['capabilities'], 'key'), $role['_keys']);
                if ($held === []) {
                    return 'none';
                }

                return array_filter($module['capabilities'], fn (array $c): bool => in_array($c['key'], $held, true) && ! $c['read']) !== [] ? 'manage' : 'read';
            }, $modules),
        ])->all();

        return [
            'roles' => $presented->map(function (array $role): array {
                unset($role['_order'], $role['_keys']);

                return $role;
            })->all(),
            'modules' => $modules,
            'matrix' => $matrix,
            'sensitive' => [
                'families' => SensitiveDataCatalogue::present(),
                'neverExposed' => SensitiveDataCatalogue::NEVER_EXPOSED,
                'notStored' => SensitiveDataCatalogue::NOT_STORED,
            ],
        ];
    }

    /** @return array{key: string, label: string, read: bool, sensitive: bool, approval: bool, assurance: bool, campus: bool, classes: list<string>} */
    private function describe(string $key, string $label): array
    {
        $classes = CapabilityClasses::CLASSES[$key] ?? [];

        return [
            'key' => $key,
            'label' => $label,
            'read' => str_ends_with($key, '.view'),
            'sensitive' => array_intersect($classes, ['sensitive', 'children', 'financial', 'hr', 'hr-sensitive', 'payroll-sensitive', 'legal-gated']) !== [],
            'approval' => in_array($key, self::approvalCapabilities(), true),
            'assurance' => in_array($key, self::assuredCapabilities(), true),
            'campus' => CampusScopeCatalogue::isAdopted($key),
            'classes' => $classes,
        ];
    }

    /** @return list<string> decision capabilities of the registered request/decide workflows */
    private static function approvalCapabilities(): array
    {
        static $keys = null;

        return $keys ??= array_values(array_unique(array_merge(...array_map(fn (array $w): array => $w['decide'], array_values(ApprovalWorkflowRegistry::WORKFLOWS)))));
    }

    /** @return list<string> capabilities whose consequential actions re-verify MFA (assured decisions and direct-assured actions) */
    private static function assuredCapabilities(): array
    {
        static $keys = null;

        return $keys ??= array_values(array_unique([
            ...array_merge(...array_map(fn (array $w): array => $w['freshMfa'] !== [] ? $w['decide'] : [], array_values(ApprovalWorkflowRegistry::WORKFLOWS))),
            ...array_column(array_values(ApprovalWorkflowRegistry::DIRECT_ASSURED), 'capability'),
        ]));
    }
}
