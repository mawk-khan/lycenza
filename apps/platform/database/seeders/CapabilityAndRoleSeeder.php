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

            // Phase 8A.10 (docs/modules/HR.md "Authorization design",
            // first drafted in 8A.0 and finalized here) -- HR/Employee
            // Records capabilities. `hr.employees.view`/`.manage` gate
            // Directory-tier (Internal) fields only. `.personal.*`
            // gates Restricted-tier personal data (DOB, personal
            // contact, addresses, emergency contacts).
            // `.assignments.*` gates Employment/Assignment history and
            // reporting-manager changes. `.qualifications.*` gates
            // Qualification/Experience/Certification records
            // (including verify/reject -- no separate verify
            // capability is introduced, see HR.md's 8A.10 as-built
            // section for why). `.documents.*` gates Restricted
            // EmployeeDocument metadata only; `.sensitive.*` is the
            // SEPARATE, non-negotiable boundary for
            // `classification_tier = highly_sensitive` metadata and
            // for any classification transition into/out of that tier
            // -- never satisfied by `.documents.manage` alone. `.notes.*`
            // is registered now (no `employee_notes` table exists yet,
            // matching the same "capability exists, data does not yet"
            // precedent `.sensitive.*` itself already established in
            // 8A.0) so a future checkpoint has a landing spot without a
            // mid-flight capability-family change. `hr.departments.*`/
            // `hr.positions.*` gate HR organizational reference-data
            // administration, structurally unrelated to the
            // `academics.*` capabilities above (HR Department/Position
            // are a different domain than Academic Structure, see
            // HR.md's terminology table).
            ['key' => 'hr.employees.view', 'label' => 'View Employee Directory (Internal-tier fields)', 'namespace' => 'school'],
            ['key' => 'hr.employees.manage', 'label' => 'Create and manage Employee core records', 'namespace' => 'school'],
            ['key' => 'hr.employees.personal.view', 'label' => 'View Employee personal details, contacts and addresses (Restricted)', 'namespace' => 'school'],
            ['key' => 'hr.employees.personal.manage', 'label' => 'Manage Employee personal details, contacts and addresses (Restricted)', 'namespace' => 'school'],
            ['key' => 'hr.employees.assignments.view', 'label' => 'View Employee employment/assignment history and reporting lines', 'namespace' => 'school'],
            ['key' => 'hr.employees.assignments.manage', 'label' => 'Manage Employee employment/assignment history and reporting lines', 'namespace' => 'school'],
            ['key' => 'hr.employees.qualifications.view', 'label' => 'View Employee qualifications, experience and certifications', 'namespace' => 'school'],
            ['key' => 'hr.employees.qualifications.manage', 'label' => 'Manage Employee qualifications, experience and certifications, including verification', 'namespace' => 'school'],
            ['key' => 'hr.employees.documents.view', 'label' => 'View Employee document metadata (Restricted tier only)', 'namespace' => 'school'],
            ['key' => 'hr.employees.documents.manage', 'label' => 'Manage Employee document metadata (Restricted tier only)', 'namespace' => 'school'],
            ['key' => 'hr.employees.notes.view', 'label' => 'View Employee HR notes (reserved; not yet modeled)', 'namespace' => 'school'],
            ['key' => 'hr.employees.notes.manage', 'label' => 'Manage Employee HR notes (reserved; not yet modeled)', 'namespace' => 'school'],
            ['key' => 'hr.employees.sensitive.view', 'label' => 'View Highly Sensitive Employee data (e.g. highly_sensitive documents)', 'namespace' => 'school'],
            ['key' => 'hr.employees.sensitive.manage', 'label' => 'Manage Highly Sensitive Employee data and classification transitions', 'namespace' => 'school'],
            ['key' => 'hr.departments.view', 'label' => 'View HR Departments', 'namespace' => 'school'],
            ['key' => 'hr.departments.manage', 'label' => 'Manage HR Departments', 'namespace' => 'school'],
            ['key' => 'hr.positions.view', 'label' => 'View Positions', 'namespace' => 'school'],
            ['key' => 'hr.positions.manage', 'label' => 'Manage Positions', 'namespace' => 'school'],

            // Phase 1B.7E (docs/modules/STUDENT-ENROLLMENT.md
            // "Rollover Authorization & Administrative HTTP/API") --
            // three-segment `enrollments.rollovers.*` naming mirrors
            // `integrations.webhooks.*`'s existing sub-resource-of-a-
            // domain precedent (rollover is a sub-resource of
            // Enrollment the same way webhooks are a sub-resource of
            // integrations) more closely than a squashed single-word
            // `enrollment_rollovers.*` would -- no other capability key
            // in this catalog uses an underscore. Deliberately its OWN
            // pair, required IN ADDITION TO (never instead of)
            // `enrollments.view`/`enrollments.manage` -- a bulk rollover
            // can mutate hundreds/thousands of next-year Enrollments at
            // once, a materially higher blast radius than any single
            // Enrollment mutation `enrollments.manage` alone gates, so
            // it earns its own explicit grant rather than being implied
            // by the base Enrollment capability (CapabilityResolver has
            // no capability-inheritance mechanism at all -- see
            // `enrollments.*`'s own docblock above).
            ['key' => 'enrollments.rollovers.view', 'label' => 'View Enrollment Rollover plans, mappings, and results', 'namespace' => 'school'],
            ['key' => 'enrollments.rollovers.manage', 'label' => 'Manage Enrollment Rollover plans (configure, validate, start, resume execution)', 'namespace' => 'school'],

            // Phase 0G.3 (docs/modules/FINANCE.md "Authorization
            // design") -- Finance ledger authorization. A single
            // `finance.ledger.view` covers every current administrative
            // READ surface (ledger account directory, journal history,
            // journal detail) rather than one capability per DTO
            // (section 9's guidance, mirroring `hr.employees.view`'s
            // single Directory-tier read grant). `.post` and `.reverse`
            // are deliberately SEPARATE from `.view` AND from each
            // other: reversal creates a new, permanent ledger fact that
            // corrects a prior one -- a materially higher-risk financial
            // correction action than an ordinary posting, the same
            // "distinct authority for a materially higher blast radius
            // action" reasoning already established by
            // `communications.emergency` (kept separate from
            // `.announce`) and `enrollments.rollovers.manage` (kept
            // separate from `enrollments.manage`). No
            // `finance.accounts.manage` is registered here -- 0G.3
            // implements no Ledger Account create/update/deactivate;
            // that capability is deferred to whichever future
            // checkpoint actually adds Chart of Accounts administration
            // (FINANCE.md 0G.3 as-built, "Deferred: account CRUD").
            ['key' => 'finance.ledger.view', 'label' => 'View Finance ledger accounts and journal entries', 'namespace' => 'school'],
            ['key' => 'finance.ledger.post', 'label' => 'Post Finance journal entries', 'namespace' => 'school'],
            ['key' => 'finance.ledger.reverse', 'label' => 'Reverse posted Finance journal entries', 'namespace' => 'school'],

            // Phase 0G.4 (docs/modules/FINANCE.md "Authorization
            // architecture", already named this exact conceptual pair
            // in 0G.0/0G.3): Fees/Receivables authorization, deliberately
            // SEPARATE from `finance.ledger.*` -- assessing/cancelling a
            // Student's charge is a distinct administrative
            // responsibility from raw ledger administration
            // (App\Domain\Fees\Application\ChargeAdministrationService
            // never requires `finance.ledger.post`/`.reverse`, and vice
            // versa). A single `finance.charges.manage` covers BOTH
            // assessment and cancellation -- FINANCE.md's own
            // conceptual family lists one `view`/`manage` pair for
            // charges, not a finer split like Ledger's `post`/`reverse`
            // (0G.3 judged posting vs. reversal separately blast-radius-
            // worthy for the raw ledger; 0G.4 does not invent an
            // equivalent split for charges that FINANCE.md never asked
            // for).
            ['key' => 'finance.charges.view', 'label' => 'View Fees charges', 'namespace' => 'school'],
            ['key' => 'finance.charges.manage', 'label' => 'Assess and cancel Fees charges', 'namespace' => 'school'],
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
                    'enrollments.view', 'enrollments.manage',
                    // Phase 8A.10, HR.md "Authorization design": ONLY
                    // Directory-tier view/manage + Restricted personal
                    // VIEW are granted by default -- deliberately NOT
                    // `.personal.manage`, `.assignments.*`,
                    // `.qualifications.*`, `.documents.*`,
                    // `.sensitive.*`, `.notes.*`, `hr.departments.*`,
                    // `hr.positions.*`. See the security register's
                    // explicit P1 finding this closes: default HR
                    // capability grants must not silently broaden to
                    // every School Admin -- a School's own role
                    // configuration must explicitly add whichever of
                    // these an actual "HR Staff" role needs.
                    'hr.employees.view', 'hr.employees.manage', 'hr.employees.personal.view',
                    'enrollments.rollovers.view', 'enrollments.rollovers.manage',
                    // Phase 0G.3: Finance is a new, money-moving domain
                    // -- granted in full (view/post/reverse) to School
                    // Admin, the seeded catalog's top school-scoped
                    // administrative role, which already holds every
                    // other domain's most privileged pair (webhooks,
                    // campuses, academic structure, students/guardians,
                    // communications, enrollments + rollovers). NOT
                    // granted to Principal below -- unlike Students/
                    // Guardians/Enrollments/Academics, which Principal
                    // already operates day-to-day, this catalog has no
                    // established precedent of Principal handling
                    // ledger postings or reversals. A School wanting a
                    // dedicated Accountant-style role can configure one
                    // itself without this checkpoint inventing it now
                    // (section 44's "no surprising broad default
                    // assignment").
                    'finance.ledger.view', 'finance.ledger.post', 'finance.ledger.reverse',
                    // Phase 0G.4: same "School Admin holds this
                    // catalog's most privileged pair by default" logic
                    // as Ledger above, extended to Fees/Receivables.
                    // NOT granted to Principal below, for the identical
                    // reason ledger.* is not: no established precedent
                    // of Principal assessing/cancelling Student fees in
                    // this product.
                    'finance.charges.view', 'finance.charges.manage',
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
                    // Phase 1B.4: Enrollment/academic placement is the
                    // same kind of hands-on operational concern for a
                    // Principal as Student/Guardian identity already is
                    // (placing/withdrawing/transferring Students is a
                    // routine Principal task, not School-Admin-only
                    // administration) -- matches the identical rationale
                    // just above for students.*/guardians.*.
                    'enrollments.view', 'enrollments.manage',
                    // Phase 8A.10: same default-grant boundary as
                    // school_admin above -- view/manage/personal.view
                    // only, nothing else by default.
                    'hr.employees.view', 'hr.employees.manage', 'hr.employees.personal.view',
                    // Phase 1B.7E: deliberately VIEW ONLY, breaking from
                    // the "Principal gets full parity with School Admin"
                    // pattern every other pair on this role just
                    // followed. A Principal may legitimately inspect/
                    // review academic rollover planning, but bulk
                    // EXECUTION can mutate hundreds/thousands of
                    // next-year Enrollments in one action -- a
                    // materially higher blast radius than any single
                    // enrollments.manage operation, so it stays
                    // School-Admin-only by default (least privilege),
                    // matching this role's own existing view-only
                    // treatment of School profile/Campus administration
                    // above for the identical "administrative, not
                    // day-to-day operational" reasoning.
                    'enrollments.rollovers.view',
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
