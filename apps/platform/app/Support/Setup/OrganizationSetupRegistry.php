<?php

namespace App\Support\Setup;

use App\Domain\Platform\Application\Modules\ModuleCatalogue;
use App\Support\Setup\SetupCategory as C;
use App\Support\Setup\SetupScopeLevel as S;

/**
 * ORG.12A (ADR 0080): the ONE code-owned catalogue of the Organization
 * Control Plane's setup areas.
 *
 * - The Control Plane OBSERVES configuration owned by other modules and
 *   links to it. It owns no domain record, stores no "setup complete" flag
 *   and writes nothing (OrganizationSetupArchitectureTest).
 * - Not tenant-editable: there is no table, setting, request input or
 *   runtime registration path for a definition. A change is a reviewed
 *   code change.
 * - Keys are stable (`<area>.<subject>`), never renamed or reused.
 * - Order is explicit and unique; every dependency has a lower order, so
 *   evaluation is a single deterministic pass and a cycle is impossible
 *   (validate() also refuses cycles directly).
 * - Dependencies are informational setup guidance, never authorization.
 * - Readiness is derived, at read time, by the owning module's
 *   SetupReadinessProvider -- never stored.
 */
final class OrganizationSetupRegistry
{
    /**
     * The catalogue, in order. `entitlement` lists optional ModuleCatalogue
     * modules that must ALL be entitled ([] = core). `view` capabilities are
     * required besides the setup floor and are never Campus-adopted keys
     * (rule 101: readiness is a whole-School view and is not a declared
     * whole-School hub). Where a domain's only read keys are Campus-adopted
     * (Student records, Staff records, Student attendance, Admissions),
     * `view` is [] and the setup floor alone governs: those answers are
     * EXISTS facts about configuration, never a record or a count of people.
     * Such an area names those keys in `adoptedRead`, and the architecture
     * test refuses any role that holds the floor without them.
     *
     * @var list<array{key: string, title: string, description: string, owner: string, category: C, scope: S, entitlement: list<string>, route: string, view: list<string>, manage: list<string>, dependsOn: list<string>, order: int, reference: list<string>}>
     */
    public const array DEFINITIONS = [
        // -- Organization ------------------------------------------------------
        ['key' => 'organization.school_profile', 'title' => 'School profile', 'description' => 'Legal name, contact details and address of the School.', 'owner' => 'organization', 'category' => C::ORGANIZATION, 'scope' => S::SCHOOL, 'entitlement' => [], 'route' => 'app.school-setup.profile', 'view' => ['school.profile.view'], 'manage' => ['school.profile.manage'], 'dependsOn' => [], 'order' => 10, 'reference' => ['RF-02']],
        ['key' => 'organization.campuses', 'title' => 'Campuses', 'description' => 'At least one active Campus where the School operates.', 'owner' => 'organization', 'category' => C::ORGANIZATION, 'scope' => S::SCHOOL_AND_CAMPUS, 'entitlement' => [], 'route' => 'app.school-setup.campuses', 'view' => ['school.campuses.view'], 'manage' => ['school.campuses.manage'], 'dependsOn' => [], 'order' => 20, 'reference' => ['RF-03']],

        // -- Academic ----------------------------------------------------------
        ['key' => 'academic.academic_year', 'title' => 'Academic year', 'description' => 'An active academic year covering today.', 'owner' => 'academic_structure', 'category' => C::ACADEMIC, 'scope' => S::SCHOOL, 'entitlement' => [], 'route' => 'app.school-setup.academic-years', 'view' => ['academics.years.view'], 'manage' => ['academics.years.manage'], 'dependsOn' => [], 'order' => 30, 'reference' => ['RF-04']],
        ['key' => 'academic.structure', 'title' => 'Grades, sections and subjects', 'description' => 'Grade levels, subjects, and sections and subject offerings in the active academic year.', 'owner' => 'academic_structure', 'category' => C::ACADEMIC, 'scope' => S::SCHOOL_AND_CAMPUS, 'entitlement' => [], 'route' => 'app.school-setup.sections', 'view' => ['academics.structure.view', 'academics.subjects.view'], 'manage' => ['academics.structure.manage', 'academics.subjects.manage'], 'dependsOn' => ['academic.academic_year', 'organization.campuses'], 'order' => 40, 'reference' => ['RF-05']],
        ['key' => 'academic.timetable', 'title' => 'Timetable', 'description' => 'Timetable periods and a class schedule in the active academic year.', 'owner' => 'timetable', 'category' => C::ACADEMIC, 'scope' => S::SCHOOL_AND_CAMPUS, 'entitlement' => ['timetable'], 'route' => 'app.timetable-schedule.index', 'view' => ['timetable.periods.view'], 'manage' => ['timetable.periods.manage', 'timetable.schedule.manage'], 'dependsOn' => ['academic.structure'], 'order' => 50, 'reference' => ['RF-06']],

        // -- People ------------------------------------------------------------
        ['key' => 'students.student_records', 'title' => 'Student records', 'description' => 'Active Students on record, each placed in a class in the active academic year.', 'owner' => 'student_records', 'category' => C::PEOPLE, 'scope' => S::SCHOOL_AND_CAMPUS, 'entitlement' => [], 'route' => 'app.students.index', 'view' => [], 'manage' => ['students.manage', 'enrollments.manage'], 'dependsOn' => ['academic.structure'], 'order' => 60, 'reference' => [], 'adoptedRead' => ['students.view', 'enrollments.view']],
        ['key' => 'students.guardian_identity', 'title' => 'Guardians', 'description' => 'Guardian records, and a legal guardian linked to every active Student.', 'owner' => 'student_records', 'category' => C::PEOPLE, 'scope' => S::SCHOOL, 'entitlement' => [], 'route' => 'app.guardians.index', 'view' => ['guardians.view'], 'manage' => ['guardians.manage'], 'dependsOn' => ['students.student_records'], 'order' => 70, 'reference' => []],
        ['key' => 'staff.staff_records', 'title' => 'Staff records', 'description' => 'HR departments, positions and at least one current Employee.', 'owner' => 'staff_records', 'category' => C::PEOPLE, 'scope' => S::SCHOOL_AND_CAMPUS, 'entitlement' => [], 'route' => 'app.hr.index', 'view' => [], 'manage' => ['hr.employees.manage', 'hr.departments.manage', 'hr.positions.manage'], 'dependsOn' => [], 'order' => 80, 'reference' => [], 'adoptedRead' => ['hr.employees.view']],
        ['key' => 'staff.leave', 'title' => 'Staff leave', 'description' => 'A leave year covering today, leave types and policies, and the staff working week.', 'owner' => 'leave', 'category' => C::PEOPLE, 'scope' => S::SCHOOL, 'entitlement' => ['leave'], 'route' => 'app.leave.configuration', 'view' => ['hr.leave.view'], 'manage' => ['hr.leave.configure'], 'dependsOn' => ['staff.staff_records'], 'order' => 90, 'reference' => []],

        // -- Operations --------------------------------------------------------
        ['key' => 'attendance.student_attendance', 'title' => 'Student attendance', 'description' => 'Class registers are taken against the timetable; no separate attendance setup is needed.', 'owner' => 'attendance', 'category' => C::OPERATIONS, 'scope' => S::SCHOOL_AND_CAMPUS, 'entitlement' => ['attendance'], 'route' => 'app.attendance.index', 'view' => [], 'manage' => ['attendance.manage'], 'dependsOn' => ['students.student_records', 'academic.timetable'], 'order' => 100, 'reference' => ['RF-06'], 'adoptedRead' => ['attendance.view']],
        ['key' => 'attendance.staff_attendance', 'title' => 'Staff attendance', 'description' => 'Daily staff presence uses the staff working week and holidays from Leave.', 'owner' => 'staff_attendance', 'category' => C::OPERATIONS, 'scope' => S::SCHOOL, 'entitlement' => ['staff_attendance'], 'route' => 'app.staff-attendance.register', 'view' => ['hr.staff_attendance.view'], 'manage' => ['hr.staff_attendance.manage'], 'dependsOn' => ['staff.leave'], 'order' => 110, 'reference' => []],

        // -- Finance -----------------------------------------------------------
        ['key' => 'finance.fees', 'title' => 'Fees', 'description' => 'Active fee heads and an active fee structure for the active academic year.', 'owner' => 'fees', 'category' => C::FINANCE, 'scope' => S::SCHOOL_AND_CAMPUS, 'entitlement' => ['finance', 'fees'], 'route' => 'app.finance.index', 'view' => ['finance.fee_structures.view'], 'manage' => ['finance.fee_structures.manage'], 'dependsOn' => ['academic.structure'], 'order' => 120, 'reference' => ['RF-07']],

        // -- People (intake) ---------------------------------------------------
        ['key' => 'admissions.admissions', 'title' => 'Admissions', 'description' => 'Applications use the School\'s academic structure; no separate admissions setup is needed.', 'owner' => 'admissions', 'category' => C::PEOPLE, 'scope' => S::SCHOOL, 'entitlement' => ['admissions'], 'route' => 'app.admissions.index', 'view' => [], 'manage' => ['admissions.manage'], 'dependsOn' => ['academic.structure'], 'order' => 130, 'reference' => ['RF-08'], 'adoptedRead' => ['admissions.view']],

        // -- Operations --------------------------------------------------------
        ['key' => 'transport.transport', 'title' => 'Transport', 'description' => 'An active route with stops, an active vehicle and a current route assignment.', 'owner' => 'transport', 'category' => C::OPERATIONS, 'scope' => S::SCHOOL_AND_CAMPUS, 'entitlement' => ['transport'], 'route' => 'app.transport.routes.index', 'view' => ['transport.vehicles.view'], 'manage' => ['transport.routes.manage', 'transport.vehicles.manage'], 'dependsOn' => ['organization.campuses', 'staff.staff_records'], 'order' => 140, 'reference' => []],
        ['key' => 'library.library', 'title' => 'Library', 'description' => 'Catalogued titles, active copies and an active fine policy.', 'owner' => 'library', 'category' => C::OPERATIONS, 'scope' => S::SCHOOL_AND_CAMPUS, 'entitlement' => ['library'], 'route' => 'app.library.titles.index', 'view' => ['library.catalogue.view', 'library.fines.view'], 'manage' => ['library.catalogue.manage', 'library.fines.manage'], 'dependsOn' => ['organization.campuses'], 'order' => 150, 'reference' => []],
        ['key' => 'inventory.inventory', 'title' => 'Inventory', 'description' => 'Active stock locations and active items.', 'owner' => 'inventory', 'category' => C::OPERATIONS, 'scope' => S::SCHOOL_AND_CAMPUS, 'entitlement' => ['inventory'], 'route' => 'app.inventory-items.index', 'view' => ['inventory.directory.view'], 'manage' => ['inventory.directory.manage'], 'dependsOn' => [], 'order' => 160, 'reference' => []],

        // -- Communications ----------------------------------------------------
        ['key' => 'communications.communications', 'title' => 'Communications', 'description' => 'Announcements and conversations work with the School\'s safe default policies.', 'owner' => 'communications', 'category' => C::COMMUNICATIONS, 'scope' => S::SCHOOL, 'entitlement' => ['communications'], 'route' => 'app.communications.index', 'view' => ['communications.view'], 'manage' => ['communications.manage'], 'dependsOn' => [], 'order' => 170, 'reference' => ['RF-23', 'RF-24']],
        ['key' => 'communications.events', 'title' => 'School events', 'description' => 'The School events calendar needs no separate setup.', 'owner' => 'communications', 'category' => C::COMMUNICATIONS, 'scope' => S::SCHOOL, 'entitlement' => ['communications'], 'route' => 'app.communications.events.index', 'view' => ['communications.announce'], 'manage' => ['communications.announce'], 'dependsOn' => ['communications.communications'], 'order' => 180, 'reference' => []],

        // -- Portal ------------------------------------------------------------
        ['key' => 'portal.guardian_portal', 'title' => 'Guardian portal', 'description' => 'The Guardian portal is available in this environment and Guardian accounts are linked.', 'owner' => 'guardian_portal', 'category' => C::PORTAL, 'scope' => S::SCHOOL, 'entitlement' => ['guardian_portal'], 'route' => 'app.guardians.index', 'view' => ['guardians.view'], 'manage' => ['guardians.manage'], 'dependsOn' => ['students.guardian_identity'], 'order' => 190, 'reference' => ['RF-16']],

        // -- Compliance --------------------------------------------------------
        ['key' => 'compliance.audit', 'title' => 'Audit and compliance', 'description' => 'Audit recording is always on; the School audit log can be reviewed.', 'owner' => 'compliance_audit', 'category' => C::COMPLIANCE, 'scope' => S::SCHOOL, 'entitlement' => [], 'route' => 'app.compliance.audit-log', 'view' => ['school.audit.view'], 'manage' => [], 'dependsOn' => [], 'order' => 200, 'reference' => []],

        // -- Integrations ------------------------------------------------------
        ['key' => 'integrations.integrations', 'title' => 'Integrations', 'description' => 'An active partner API client or webhook endpoint. No credential is ever shown here.', 'owner' => 'integrations', 'category' => C::INTEGRATIONS, 'scope' => S::SCHOOL, 'entitlement' => ['integrations'], 'route' => 'app.integrations.api-clients.index', 'view' => ['integrations.api_clients.view', 'integrations.webhooks.view'], 'manage' => ['integrations.api_clients.manage', 'integrations.webhooks.manage'], 'dependsOn' => [], 'order' => 210, 'reference' => ['RF-26', 'RF-27', 'RF-28', 'RF-29']],
    ];

    /** @var list<SetupDefinition> */
    private readonly array $definitions;

    /** @var array<string, SetupDefinition> */
    private readonly array $byKey;

    /**
     * @param  list<SetupDefinition>|null  $definitions  tests only; production always uses DEFINITIONS
     */
    public function __construct(?array $definitions = null)
    {
        $definitions ??= array_map(SetupDefinition::fromArray(...), self::DEFINITIONS);
        self::validate($definitions);
        usort($definitions, fn (SetupDefinition $a, SetupDefinition $b): int => [$a->order, $a->key] <=> [$b->order, $b->key]);
        $this->definitions = $definitions;
        $byKey = [];
        foreach ($definitions as $definition) {
            $byKey[$definition->key] = $definition;
        }
        $this->byKey = $byKey;
    }

    /** @return list<SetupDefinition> every definition, in explicit order */
    public function all(): array
    {
        return $this->definitions;
    }

    public function find(string $key): ?SetupDefinition
    {
        return $this->byKey[$key] ?? null;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->byKey);
    }

    /**
     * Refuses a catalogue that is not a valid, acyclic, deterministic
     * setup graph consistent with the module catalogue.
     *
     * @param  list<SetupDefinition>  $definitions
     *
     * @throws \LogicException
     */
    public static function validate(array $definitions): void
    {
        $byKey = [];
        $orders = [];
        foreach ($definitions as $definition) {
            if (isset($byKey[$definition->key])) {
                throw new \LogicException("Setup key {$definition->key} is defined twice.");
            }
            if (isset($orders[$definition->order])) {
                throw new \LogicException("Setup order {$definition->order} is used by both {$orders[$definition->order]} and {$definition->key}.");
            }
            $byKey[$definition->key] = $definition;
            $orders[$definition->order] = $definition->key;

            if ($definition->scopeLevel->isReserved()) {
                throw new \LogicException("{$definition->key} uses the reserved scope {$definition->scopeLevel->value}; Network and Platform setup are not part of ORG.12A.");
            }
            if (! ModuleCatalogue::exists($definition->ownerModule)) {
                throw new \LogicException("{$definition->key} names unknown owner module {$definition->ownerModule}.");
            }
            foreach ($definition->entitlementModules as $module) {
                if (! ModuleCatalogue::exists($module) || ModuleCatalogue::isCore($module)) {
                    throw new \LogicException("{$definition->key} entitlement module {$module} must be an optional catalogue module.");
                }
            }
            if (ModuleCatalogue::isCore($definition->ownerModule) !== $definition->isCore()) {
                throw new \LogicException("{$definition->key} must be core exactly when its owner module is core.");
            }
            if (! $definition->isCore() && ! in_array($definition->ownerModule, $definition->entitlementModules, true)) {
                throw new \LogicException("{$definition->key} must require its own optional owner module.");
            }
        }

        foreach ($definitions as $definition) {
            if (count(array_unique($definition->dependsOn)) !== count($definition->dependsOn)) {
                throw new \LogicException("{$definition->key} lists a dependency twice.");
            }
            foreach ($definition->dependsOn as $dependency) {
                if ($dependency === $definition->key) {
                    throw new \LogicException("{$definition->key} depends on itself.");
                }
                if (! isset($byKey[$dependency])) {
                    throw new \LogicException("{$definition->key} depends on unknown setup key {$dependency}.");
                }
            }
        }

        self::refuseCycles($byKey);

        foreach ($definitions as $definition) {
            foreach ($definition->dependsOn as $dependency) {
                if ($byKey[$dependency]->order >= $definition->order) {
                    throw new \LogicException("{$definition->key} must be ordered after its dependency {$dependency}.");
                }
            }
        }
    }

    /**
     * Iterative depth-first search, bounded by the catalogue size: each key
     * is finished at most once, so even a hostile graph costs O(V + E).
     *
     * @param  array<string, SetupDefinition>  $byKey
     */
    private static function refuseCycles(array $byKey): void
    {
        $state = []; // key => 1 visiting, 2 done
        foreach (array_keys($byKey) as $root) {
            if (isset($state[$root])) {
                continue;
            }
            $stack = [[$root, 0]];
            $state[$root] = 1;
            while ($stack !== []) {
                [$key, $index] = $stack[count($stack) - 1];
                $dependencies = $byKey[$key]->dependsOn;
                if ($index >= count($dependencies)) {
                    $state[$key] = 2;
                    array_pop($stack);

                    continue;
                }
                $stack[count($stack) - 1][1] = $index + 1;
                $next = $dependencies[$index];
                if (($state[$next] ?? 0) === 1) {
                    throw new \LogicException("Setup dependency cycle through {$next}.");
                }
                if (! isset($state[$next])) {
                    $state[$next] = 1;
                    $stack[] = [$next, 0];
                }
            }
        }
    }
}
