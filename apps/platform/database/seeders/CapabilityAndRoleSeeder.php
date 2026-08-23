<?php

namespace Database\Seeders;

use App\Models\Capability;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Seeds the platform capability/role catalog only (section 17, 18) --
 * NOT school/user/membership data. Idempotent (updateOrCreate/sync)
 * and safe to run in any environment: this is reference/config data,
 * not tenant or personal data. Only the minimal namespace needed to
 * prove the architecture is seeded; future modules reserve their own
 * (students.*, fees.*, attendance.*, academics.*, hr.*, transport.*, ...).
 */
class CapabilityAndRoleSeeder extends Seeder
{
    public function run(): void
    {
        $capabilities = [
            ['key' => 'platform.schools.view', 'label' => 'View schools (platform)', 'namespace' => 'platform'],
            ['key' => 'platform.schools.manage', 'label' => 'Manage schools (platform)', 'namespace' => 'platform'],
            ['key' => 'school.settings.view', 'label' => 'View school settings', 'namespace' => 'school'],
            ['key' => 'school.settings.manage', 'label' => 'Manage school settings', 'namespace' => 'school'],
            ['key' => 'school.members.view', 'label' => 'View school members', 'namespace' => 'school'],
            ['key' => 'school.members.manage', 'label' => 'Manage school members', 'namespace' => 'school'],
            ['key' => 'school.roles.view', 'label' => 'View school role assignments', 'namespace' => 'school'],
            ['key' => 'school.roles.manage', 'label' => 'Manage school role assignments', 'namespace' => 'school'],
            ['key' => 'school.audit.view', 'label' => 'View school audit log', 'namespace' => 'school'],

            // Phase 0C (section 48) -- minimal infrastructure-administration
            // namespace. Future business-module capabilities (students.*,
            // fees.*, ...) are NOT seeded here.
            ['key' => 'integrations.webhooks.view', 'label' => 'View webhook endpoints', 'namespace' => 'school'],
            ['key' => 'integrations.webhooks.manage', 'label' => 'Manage webhook endpoints', 'namespace' => 'school'],
            ['key' => 'platform.feature_flags.view', 'label' => 'View feature flags (platform)', 'namespace' => 'platform'],
            ['key' => 'platform.feature_flags.manage', 'label' => 'Manage feature flags (platform)', 'namespace' => 'platform'],
            ['key' => 'platform.service_identities.view', 'label' => 'View service identities (platform)', 'namespace' => 'platform'],
            ['key' => 'platform.service_identities.manage', 'label' => 'Manage service identities (platform)', 'namespace' => 'platform'],
            ['key' => 'ai.tools.invoke', 'label' => 'AI Gateway may invoke internal AI tool contracts', 'namespace' => 'platform'],
            ['key' => 'ai.audit.write', 'label' => 'AI Gateway may write durable audit entries to Laravel', 'namespace' => 'platform'],

            // Phase 0C.4 (section 52/53) -- cross-tenant operational
            // diagnostics. Platform-scoped only: no School role may
            // ever be assigned this (database-enforced, ADR "role scope
            // trigger"). No `.manage` variant exists yet -- nothing in
            // this checkpoint's internal diagnostics API mutates state.
            ['key' => 'platform.operations.view', 'label' => 'View cross-tenant operational diagnostics (platform)', 'namespace' => 'platform'],

            // Phase 0D (section 47) -- School profile and
            // organizational/academic-structure administration. A
            // single `academics.structure.*` pair covers GradeLevel,
            // AcademicDepartment, Room, and Section (deliberately not
            // split into one capability per entity -- section 47 warns
            // against dozens of hyper-specific permissions); Subjects
            // and Subject Offerings get their own pair since they are
            // more likely to need a narrower delegated role later
            // (e.g. an Academic Coordinator who curates Subjects but
            // not the wider Grade/Room structure).
            ['key' => 'school.profile.view', 'label' => 'View School profile', 'namespace' => 'school'],
            ['key' => 'school.profile.manage', 'label' => 'Manage School profile', 'namespace' => 'school'],
            ['key' => 'school.campuses.view', 'label' => 'View Campuses', 'namespace' => 'school'],
            ['key' => 'school.campuses.manage', 'label' => 'Manage Campuses', 'namespace' => 'school'],
            ['key' => 'academics.structure.view', 'label' => 'View academic structure (Grades, Departments, Rooms, Sections)', 'namespace' => 'school'],
            ['key' => 'academics.structure.manage', 'label' => 'Manage academic structure (Grades, Departments, Rooms, Sections)', 'namespace' => 'school'],
            ['key' => 'academics.years.view', 'label' => 'View Academic Years and Terms', 'namespace' => 'school'],
            ['key' => 'academics.years.manage', 'label' => 'Manage Academic Years and Terms', 'namespace' => 'school'],
            ['key' => 'academics.subjects.view', 'label' => 'View Subjects and Subject Offerings', 'namespace' => 'school'],
            ['key' => 'academics.subjects.manage', 'label' => 'Manage Subjects and Subject Offerings', 'namespace' => 'school'],

            // Phase 1A.4 (docs/modules/STUDENT-GUARDIAN-IDENTITY.md
            // "Authorization") -- Student and Guardian identity,
            // deliberately just two pairs rather than one capability
            // per entity: `guardians.manage` covers both Guardian
            // identity AND Guardian contact mutation (GuardianContact
            // is a Guardian-owned concept, not a separate resource an
            // administrator thinks about independently), and
            // `students.manage`/`guardians.manage` together (not a
            // dedicated `students.guardians.link` capability) gate
            // linking/unlinking/primary-Guardian changes, since that
            // operation mutates both domain identities' relationship at
            // once -- see AUTHORIZATION.md for the exact rule.
            ['key' => 'students.view', 'label' => 'View Students', 'namespace' => 'school'],
            ['key' => 'students.manage', 'label' => 'Manage Students (create, update, status, Guardian links)', 'namespace' => 'school'],
            ['key' => 'guardians.view', 'label' => 'View Guardians and their contact information', 'namespace' => 'school'],
            ['key' => 'guardians.manage', 'label' => 'Manage Guardians, Guardian contact information, and Guardian links', 'namespace' => 'school'],

            // Phase 1B.4 (docs/modules/STUDENT-ENROLLMENT.md
            // "Authorization") -- Student academic placement/enrollment,
            // deliberately its own pair rather than reusing
            // students.view/students.manage: Enrollment is a distinct
            // resource from Student identity (Phase 1A vs Phase 1B's
            // explicit identity/enrollment boundary), and a School may
            // later want to delegate Enrollment administration
            // separately from Student identity administration (e.g. a
            // future registrar-style role) without this checkpoint
            // inventing that role now. `enrollments.manage` is
            // independent of `enrollments.view` -- the CapabilityResolver
            // has no capability-inheritance mechanism (see
            // docs/security/AUTHORIZATION.md), so a role granted only
            // `.manage` would NOT implicitly gain `.view`; every role
            // that needs both must be granted both explicitly, exactly
            // like every other view/manage pair in this catalog.
            ['key' => 'enrollments.view', 'label' => 'View Student Enrollment placement and history', 'namespace' => 'school'],
            ['key' => 'enrollments.manage', 'label' => 'Manage Student Enrollment (create, complete, withdraw, cancel, transfer)', 'namespace' => 'school'],
        ];

        foreach ($capabilities as $capability) {
            Capability::query()->updateOrCreate(['key' => $capability['key']], $capability);
        }

        $roles = [
            'platform_super_admin' => [
                'name' => 'Platform Super Admin',
                'scope' => 'platform',
                'capabilities' => [
                    'platform.schools.view', 'platform.schools.manage',
                    'platform.feature_flags.view', 'platform.feature_flags.manage',
                    'platform.service_identities.view', 'platform.service_identities.manage',
                    'platform.operations.view',
                ],
            ],
            'school_admin' => [
                'name' => 'School Admin',
                'scope' => 'school',
                'capabilities' => [
                    'school.settings.view', 'school.settings.manage',
                    'school.members.view', 'school.members.manage',
                    'school.roles.view', 'school.roles.manage',
                    'school.audit.view',
                    'integrations.webhooks.view', 'integrations.webhooks.manage',
                    'school.profile.view', 'school.profile.manage',
                    'school.campuses.view', 'school.campuses.manage',
                    'academics.structure.view', 'academics.structure.manage',
                    'academics.years.view', 'academics.years.manage',
                    'academics.subjects.view', 'academics.subjects.manage',
                    'students.view', 'students.manage',
                    'guardians.view', 'guardians.manage',
                    'enrollments.view', 'enrollments.manage',
                ],
            ],
            'principal' => [
                'name' => 'Principal',
                'scope' => 'school',
                'capabilities' => [
                    'school.settings.view', 'school.members.view', 'school.audit.view',
                    // Phase 0D section 48: Principal can VIEW and
                    // MANAGE the academic structure (matches the
                    // existing product model where a Principal actively
                    // configures Grades/Sections/Subjects, but not
                    // School profile/Campus administration -- those
                    // stay School-Admin-only view/manage per section 48
                    // deliberately not extending Principal rights
                    // beyond what's already justified).
                    'school.profile.view', 'school.campuses.view',
                    'academics.structure.view', 'academics.structure.manage',
                    'academics.years.view', 'academics.years.manage',
                    'academics.subjects.view', 'academics.subjects.manage',
                    // Phase 1A.4: a Principal is the day-to-day operator
                    // of Student/Guardian records (admissions
                    // follow-up, discipline, contacting parents) in the
                    // same hands-on way they already manage the
                    // academic structure -- unlike School profile/
                    // Campus administration (view-only for Principal),
                    // Student/Guardian identity is an operational, not
                    // purely administrative, concern.
                    'students.view', 'students.manage',
                    'guardians.view', 'guardians.manage',
                    // Phase 1B.4: Enrollment/academic placement is the
                    // same kind of hands-on operational concern for a
                    // Principal as Student/Guardian identity already is
                    // (placing/withdrawing/transferring Students is a
                    // routine Principal task, not School-Admin-only
                    // administration) -- matches the identical rationale
                    // just above for students.*/guardians.*.
                    'enrollments.view', 'enrollments.manage',
                ],
            ],
        ];

        foreach ($roles as $key => $definition) {
            $role = Role::query()->updateOrCreate(
                ['key' => $key],
                ['name' => $definition['name'], 'scope' => $definition['scope'], 'is_system' => true],
            );

            $role->capabilities()->sync($definition['capabilities']);
        }
    }
}
