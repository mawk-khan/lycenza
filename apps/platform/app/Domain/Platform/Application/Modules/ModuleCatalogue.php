<?php

namespace App\Domain\Platform\Application\Modules;

/**
 * ORG.5 (ADR 0076 §12, §14, §31): the ONE code-owned module catalogue. A
 * module is a product area a School may be entitled to; an entitlement is
 * platform-governed tenant configuration -- never a role, a capability
 * grant, a navigation setting, a feature flag or a commercial plan.
 *
 * - Identifiers are stable architecture keys (lowercase snake case), never
 *   reused or renamed once released. The database CHECK on
 *   `school_module_entitlements.module_key` lists exactly OPTIONAL keys.
 * - CORE modules (§14) are always available and never stored or disabled.
 * - Every `school` and `guardian` capability maps to at least one module
 *   (longest matching prefix, or an explicit multi-module requirement,
 *   ORG-C5). A capability is effective only when EVERY module it requires is
 *   available. ModuleCatalogueArchitectureTest refuses an unmapped
 *   capability, an unknown or stale rule, a cycle or a core module that
 *   depends on an optional one.
 * - Dependencies are real code/product dependencies: enabling needs every
 *   optional dependency enabled; disabling is refused while an enabled
 *   module depends on it. Nothing is auto-enabled or auto-disabled.
 * - Platform and Group capabilities are outside the module model.
 */
final class ModuleCatalogue
{
    /**
     * @var array<string, array{label: string, core: bool, dependsOn: list<string>, prefixes: list<string>, description: string}>
     */
    public const MODULES = [
        // -- Core (ADR 0076 §14): always available, never disableable ---------
        'identity_access' => ['label' => 'Identity & Access', 'core' => true, 'dependsOn' => [], 'prefixes' => ['school.members.', 'school.roles.'], 'description' => 'Memberships, roles, grants, MFA and invitations.'],
        'organization' => ['label' => 'Organization', 'core' => true, 'dependsOn' => [], 'prefixes' => ['school.profile.', 'school.settings.', 'school.campuses.', 'school.domains.'], 'description' => 'School identity, settings, Campuses and domains.'],
        'academic_structure' => ['label' => 'Academic Structure', 'core' => true, 'dependsOn' => [], 'prefixes' => ['academics.', 'teaching.assignments.'], 'description' => 'Years, terms, grades, sections, subjects, offerings, rooms and teaching assignments.'],
        'student_records' => ['label' => 'Student Records', 'core' => true, 'dependsOn' => [], 'prefixes' => ['students.', 'enrollments.', 'guardians.'], 'description' => 'Students, enrollments, the processing-authorization registry and Guardians with their relationships.'],
        'staff_records' => ['label' => 'Staff Records', 'core' => true, 'dependsOn' => [], 'prefixes' => ['hr.employees.', 'hr.departments.', 'hr.positions.', 'hr.categories.'], 'description' => 'The HR employee foundation: employees, employment, assignments, departments, positions and categories.'],
        'compliance_audit' => ['label' => 'Compliance & Audit', 'core' => true, 'dependsOn' => [], 'prefixes' => ['school.audit.'], 'description' => 'The School audit-log review and audit infrastructure.'],
        'documents' => ['label' => 'Documents', 'core' => true, 'dependsOn' => [], 'prefixes' => [], 'description' => 'Shared document storage; access is decided by the owning record.'],

        // -- Optional --------------------------------------------------------
        'admissions' => ['label' => 'Admissions', 'core' => false, 'dependsOn' => [], 'prefixes' => ['admissions.'], 'description' => 'Applicants and admission applications.'],
        'timetable' => ['label' => 'Timetable', 'core' => false, 'dependsOn' => [], 'prefixes' => ['timetable.'], 'description' => 'Periods and the class schedule.'],
        'attendance' => ['label' => 'Student Attendance', 'core' => false, 'dependsOn' => ['timetable'], 'prefixes' => ['attendance.'], 'description' => 'Class registers taken against the timetable.'],
        'curriculum' => ['label' => 'Curriculum', 'core' => false, 'dependsOn' => [], 'prefixes' => ['syllabus.', 'curriculum.'], 'description' => 'Syllabus and curriculum delivery.'],
        'lms' => ['label' => 'Learning (LMS)', 'core' => false, 'dependsOn' => [], 'prefixes' => ['lms.'], 'description' => 'Learning content and assignments.'],
        'examinations' => ['label' => 'Examinations', 'core' => false, 'dependsOn' => [], 'prefixes' => ['examinations.'], 'description' => 'Examinations, papers, grade scales and marks.'],
        'finance' => ['label' => 'Finance', 'core' => false, 'dependsOn' => [], 'prefixes' => ['finance.ledger.', 'finance.accounts.', 'finance.periods.'], 'description' => 'The double-entry ledger, accounts and financial periods.'],
        'fees' => ['label' => 'Fees & Payments', 'core' => false, 'dependsOn' => ['finance'], 'prefixes' => ['finance.fee_', 'finance.charges.', 'finance.payments.'], 'description' => 'Fee setup, charges, concessions, late fees and payments.'],
        'leave' => ['label' => 'Leave', 'core' => false, 'dependsOn' => [], 'prefixes' => ['hr.leave.'], 'description' => 'Staff leave configuration, requests and approvals.'],
        'staff_attendance' => ['label' => 'Staff Attendance', 'core' => false, 'dependsOn' => ['leave'], 'prefixes' => ['hr.staff_attendance.'], 'description' => 'Daily staff presence evidence.'],
        'payroll' => ['label' => 'Payroll', 'core' => false, 'dependsOn' => ['finance'], 'prefixes' => ['payroll.'], 'description' => 'Salary structures, compensation, payroll runs and payslips.'],
        'library' => ['label' => 'Library', 'core' => false, 'dependsOn' => ['fees'], 'prefixes' => ['library.'], 'description' => 'Catalogue, circulation and fines (fines are Fees charges).'],
        'transport' => ['label' => 'Transport', 'core' => false, 'dependsOn' => [], 'prefixes' => ['transport.'], 'description' => 'Routes, vehicles, daily operations and student assignments.'],
        'hostel' => ['label' => 'Hostel', 'core' => false, 'dependsOn' => [], 'prefixes' => ['hostel.'], 'description' => 'Hostels, rooms, beds and residency.'],
        'inventory' => ['label' => 'Inventory', 'core' => false, 'dependsOn' => [], 'prefixes' => ['inventory.'], 'description' => 'Items, locations and stock.'],
        'canteen' => ['label' => 'Canteen', 'core' => false, 'dependsOn' => ['inventory', 'fees'], 'prefixes' => ['canteen.'], 'description' => 'Outlets, items and orders (orders issue stock and charge Fees).'],
        'visitor' => ['label' => 'Visitors', 'core' => false, 'dependsOn' => [], 'prefixes' => ['visitor.'], 'description' => 'Visitor directory and visits.'],
        'communications' => ['label' => 'Communications', 'core' => false, 'dependsOn' => [], 'prefixes' => ['communications.'], 'description' => 'Announcements, conversations and templates.'],
        'guardian_portal' => ['label' => 'Guardian Portal', 'core' => false, 'dependsOn' => [], 'prefixes' => ['portal.'], 'description' => 'The Guardian-facing portal (development only until POR-L1); each view also needs its source module.'],
        'analytics' => ['label' => 'Analytics', 'core' => false, 'dependsOn' => ['curriculum'], 'prefixes' => ['analytics.'], 'description' => 'Aggregate reports (today: curriculum coverage).'],
        'automation' => ['label' => 'Automation', 'core' => false, 'dependsOn' => [], 'prefixes' => ['automation.'], 'description' => 'Rule-driven operational automation (also behind the automation.rules flag).'],
        'integrations' => ['label' => 'Integrations', 'core' => false, 'dependsOn' => [], 'prefixes' => ['integrations.'], 'description' => 'Webhooks and partner API clients.'],
    ];

    /**
     * ORG-C5: capabilities that require more than one module. A Guardian
     * portal view needs the portal AND the module whose data it shows.
     *
     * @var array<string, list<string>>
     */
    public const MULTI_MODULE = [
        'portal.communications.view' => ['guardian_portal', 'communications'],
        'portal.communications.reply' => ['guardian_portal', 'communications'],
        'portal.attendance.view' => ['guardian_portal', 'attendance'],
        'portal.fees.view' => ['guardian_portal', 'fees'],
    ];

    /**
     * The optional modules with background execution entry points (a queued
     * job or outbox consumer implementing ExecutesModuleWork). Keys only --
     * Platform never names another module's classes.
     * AsyncModuleContractArchitectureTest keeps this exact.
     *
     * @var list<string>
     */
    public const ASYNC_MODULES = ['automation', 'communications', 'fees', 'integrations'];

    /**
     * Diagnostics only: legal or environment gates that stay authoritative
     * whatever the entitlement (ADR 0076 §12.7(5)). An entitlement never
     * opens them; they are enforced in code where they live.
     *
     * @var array<string, string>
     */
    public const RESTRICTIONS = [
        'guardian_portal' => 'Production-refused until POR-L1 (PortalAvailability, ADR 0070).',
        'examinations' => 'Mark entry is production-refused in code (ADR 0068 §27).',
        'attendance' => 'Teacher attendance in production needs the TCH-L1 conditions (ADR 0063 §42).',
        'automation' => 'Also behind the automation.rules feature flag; both are required.',
    ];

    /** @var array<string, list<string>> */
    private static array $resolved = [];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::MODULES);
    }

    /** @return list<string> */
    public static function coreKeys(): array
    {
        return array_keys(array_filter(self::MODULES, fn (array $m): bool => $m['core']));
    }

    /** @return list<string> */
    public static function optionalKeys(): array
    {
        return array_keys(array_filter(self::MODULES, fn (array $m): bool => ! $m['core']));
    }

    public static function exists(string $module): bool
    {
        return isset(self::MODULES[$module]);
    }

    public static function isCore(string $module): bool
    {
        return (self::MODULES[$module]['core'] ?? false) === true;
    }

    /** @return list<string> */
    public static function dependenciesOf(string $module): array
    {
        return self::MODULES[$module]['dependsOn'] ?? [];
    }

    /** @return list<string> the modules that declare a dependency on $module */
    public static function dependentsOf(string $module): array
    {
        return array_keys(array_filter(self::MODULES, fn (array $m): bool => in_array($module, $m['dependsOn'], true)));
    }

    /**
     * The modules a capability requires (all of them must be available), or
     * [] for a capability outside the module model (platform, Group) or an
     * unmapped one -- which CapabilityResolver treats as unavailable for a
     * School (fail closed) and the architecture test refuses.
     *
     * @return list<string>
     */
    public static function modulesFor(string $capability): array
    {
        if (isset(self::$resolved[$capability])) {
            return self::$resolved[$capability];
        }
        if (isset(self::MULTI_MODULE[$capability])) {
            return self::$resolved[$capability] = self::MULTI_MODULE[$capability];
        }

        $best = null;
        $bestLength = 0;
        foreach (self::MODULES as $key => $module) {
            foreach ($module['prefixes'] as $prefix) {
                if (str_starts_with($capability, $prefix) && strlen($prefix) > $bestLength) {
                    $best = $key;
                    $bestLength = strlen($prefix);
                }
            }
        }

        return self::$resolved[$capability] = $best === null ? [] : [$best];
    }

    /** @return list<string> the capabilities (of $catalogue) that require $module */
    public static function capabilitiesOf(string $module, array $catalogue): array
    {
        return array_values(array_filter($catalogue, fn (string $capability): bool => in_array($module, self::modulesFor($capability), true)));
    }
}
