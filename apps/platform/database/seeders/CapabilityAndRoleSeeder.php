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

            // Phase 5A.1 -- Communication Hub foundation. A single
            // `communications.manage` capability covers thread/
            // participant administration (deliberately not split
            // further, matching section 47's warning against a huge
            // permission matrix in this checkpoint); `.send`/`.reply`
            // are separate from `.view` since a role may be able to
            // read a thread without being allowed to post into it (or
            // vice versa is never needed, so no write-without-read
            // case exists yet).
            ['key' => 'communications.view', 'label' => 'View Communication Hub threads and messages', 'namespace' => 'school'],
            ['key' => 'communications.send', 'label' => 'Start Communication Hub threads', 'namespace' => 'school'],
            ['key' => 'communications.reply', 'label' => 'Reply within Communication Hub threads', 'namespace' => 'school'],
            ['key' => 'communications.manage', 'label' => 'Manage Communication Hub threads and participants', 'namespace' => 'school'],
            ['key' => 'communications.audit.view', 'label' => 'View Communication Hub audit trail', 'namespace' => 'school'],

            // Phase 5A.2 -- Announcement & Audience Resolution
            // foundation. `communications.send` (above) starts a
            // Thread with an EXPLICIT, bounded participant list the
            // sender chose themselves; publishing an Announcement
            // targets a COMPUTED, potentially School-wide audience --
            // a materially larger blast radius that warrants its own
            // capability rather than being folded into `.send`
            // (docs/communication-hub/PHASE-5A-2-ANNOUNCEMENTS-AUDIENCES.md
            // documents this reasoning). Granted to the same roles as
            // `.send` today (school_admin, principal) -- ordinary
            // members never receive it (brief §22).
            ['key' => 'communications.announce', 'label' => 'Publish Communication Hub announcements', 'namespace' => 'school'],

            // Phase 5A.4 -- Communication Templates & Scheduling
            // foundation. Template administration (create/edit/
            // activate/deactivate) is its own capability, distinct
            // from `communications.announce`: any authorized announcer
            // can USE an existing active template (gated by the same
            // `.announce` check the composer already requires), but
            // AUTHORING reusable source content that other senders will
            // see and pick from warrants the narrower, separately
            // grantable right (brief §11). Scheduling/rescheduling/
            // cancelling a scheduled Announcement reuses
            // `communications.announce`/`.manage` exactly like
            // publish()/cancel() already do -- no new capability for
            // those (brief §36).
            ['key' => 'communications.templates.manage', 'label' => 'Create and manage Communication Hub templates', 'namespace' => 'school'],

            // Phase 5A.10 -- Emergency Communication Policy foundation.
            // A distinct, elevated capability from `communications.announce`
            // -- Emergency mode can (only where the School has also
            // separately opted in per channel) bypass the School's own
            // configured quiet hours, a materially different blast
            // radius/urgency than an ordinary announcement (brief §11).
            // Granted ONLY to the single narrowest school-scoped role,
            // `school_admin` -- `principal` already lacks
            // `communications.manage`/`.audit.view` in this seeder, so
            // withholding this capability from `principal` too is
            // consistent with the existing trust boundary between the
            // two roles, not a new one.
            ['key' => 'communications.emergency', 'label' => 'Declare Communication Hub announcements as Emergency', 'namespace' => 'school'],

            // Phase 5A.12 -- Approval Workflow foundation. A distinct
            // capability from `communications.announce`/`.manage`
            // (brief §12): the ability to REVIEW and decide someone
            // else's submitted communication is not implied by the
            // ability to send one's own. Granted to BOTH senior
            // school-scoped roles (`school_admin`, `principal`) --
            // unlike `communications.emergency`, approval review is
            // exactly the kind of governance action a Principal is
            // expected to perform over communications submitted by
            // less-privileged senders, and separation of duties
            // (brief §13, enforced in
            // App\Domain\Communications\Application\Approval\CommunicationApprovalService::decide())
            // already prevents a requester from approving their own
            // submission regardless of which role granted them this
            // capability.
            ['key' => 'communications.approve', 'label' => 'Approve or reject Communication Hub approval requests', 'namespace' => 'school'],
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
                    'communications.view', 'communications.send', 'communications.reply',
                    'communications.manage', 'communications.audit.view', 'communications.announce',
                    'communications.templates.manage', 'communications.emergency', 'communications.approve',
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
                    'communications.view', 'communications.send', 'communications.reply',
                    'communications.announce', 'communications.templates.manage', 'communications.approve',
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
