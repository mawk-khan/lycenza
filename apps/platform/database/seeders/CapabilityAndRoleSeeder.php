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

            // Phase 5D.1 §17 -- an active StudentGuardianAccountLink
            // only proves authenticated reachability (brief §5); it is
            // never, by itself, authorization for a staff member to
            // start a private conversation with that Guardian/Student.
            // Deliberately separate from `communications.send` (which
            // only ever targeted other SchoolMemberships directly) and
            // from `guardians.view`/`students.manage` (an unrelated
            // module's capability is never inferred as authority here,
            // root CLAUDE.md rule 24) -- see
            // App\Domain\Communications\Application\ConversationParticipantAuthorizationService.
            // `.students` is deliberately granted to NO system role
            // below (brief §15's stricter safeguarding default); a
            // School must explicitly create/extend a role with it, and
            // even then
            // App\Domain\Communications\Application\Policy\CommunicationConversationPolicyService's
            // own school-level toggle defaults Student conversations to
            // disabled independently of this capability.
            ['key' => 'communications.conversations.guardians', 'label' => 'Start private Communication Hub conversations with linked Guardians', 'namespace' => 'school'],
            ['key' => 'communications.conversations.students', 'label' => 'Start private Communication Hub conversations with linked Students', 'namespace' => 'school'],

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
            // was originally registered in 8A.0 ahead of the
            // `employee_notes` table it was meant to gate; the Phase 8A
            // closure correction built that table
            // (App\Domain\HR\Infrastructure\EmployeeNote) and this pair
            // now gates it for real -- labels updated accordingly.
            // `hr.departments.*`/`hr.positions.*` gate HR organizational
            // reference-data administration, structurally unrelated to
            // the `academics.*` capabilities above (HR Department/
            // Position are a different domain than Academic Structure,
            // see HR.md's terminology table). `hr.categories.*` (added
            // in the same closure correction) gates EmployeeCategory
            // reference data on the identical view/manage shape as
            // `hr.positions.*`.
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
            ['key' => 'hr.employees.notes.view', 'label' => 'View Employee HR notes (Restricted/Confidential, HR-authored)', 'namespace' => 'school'],
            ['key' => 'hr.employees.notes.manage', 'label' => 'Manage Employee HR notes (Restricted/Confidential, HR-authored)', 'namespace' => 'school'],
            ['key' => 'hr.employees.sensitive.view', 'label' => 'View Highly Sensitive Employee data (e.g. highly_sensitive documents)', 'namespace' => 'school'],
            ['key' => 'hr.employees.sensitive.manage', 'label' => 'Manage Highly Sensitive Employee data and classification transitions', 'namespace' => 'school'],
            ['key' => 'hr.departments.view', 'label' => 'View HR Departments', 'namespace' => 'school'],
            ['key' => 'hr.departments.manage', 'label' => 'Manage HR Departments', 'namespace' => 'school'],
            ['key' => 'hr.positions.view', 'label' => 'View Positions', 'namespace' => 'school'],
            ['key' => 'hr.positions.manage', 'label' => 'Manage Positions', 'namespace' => 'school'],
            ['key' => 'hr.categories.view', 'label' => 'View Employee Categories', 'namespace' => 'school'],
            ['key' => 'hr.categories.manage', 'label' => 'Manage Employee Categories', 'namespace' => 'school'],

            // Phase 10A (docs/modules/LIBRARY.md "Capabilities") --
            // catalogue (Title/Copy reference data) and circulation
            // (Loan lifecycle) are independently gateable, mirroring
            // the `academics.structure.*` vs `academics.subjects.*`
            // split and `hr.employees.*` vs `hr.departments.*`: a
            // School may want a Librarian who can check items in/out
            // without letting them re-catalogue the collection, or vice
            // versa.
            ['key' => 'library.catalogue.view', 'label' => 'View the Library catalogue (Titles/Copies)', 'namespace' => 'school'],
            ['key' => 'library.catalogue.manage', 'label' => 'Manage the Library catalogue (Titles/Copies)', 'namespace' => 'school'],
            ['key' => 'library.circulation.view', 'label' => 'View Library loans', 'namespace' => 'school'],
            ['key' => 'library.circulation.manage', 'label' => 'Check Library items out and in', 'namespace' => 'school'],

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

            // Phase 1D.4 (docs/modules/ADMISSIONS.md §13) -- Admissions
            // pre-Student application workflow (Applicant,
            // AdmissionApplication). A single pair, matching
            // `enrollments.*`'s own reasoning for staying one pair
            // rather than one capability per lifecycle action
            // (`admissions.create`/`.accept`/`.convert` are deliberately
            // NOT separate capabilities -- every lifecycle transition,
            // including conversion, is gated by the same
            // `admissions.manage`, mirroring `enrollments.manage`
            // covering create/complete/withdraw/cancel/transfer as one
            // capability rather than five). No capability-inheritance
            // exists in this codebase (`CapabilityResolver` has no
            // implication mechanism -- see `enrollments.*`'s own
            // docblock above) -- `admissions.manage` does NOT imply
            // `admissions.view`; every role needing both is granted both
            // explicitly below.
            ['key' => 'admissions.view', 'label' => 'View Admission Applicants and Applications', 'namespace' => 'school'],
            ['key' => 'admissions.manage', 'label' => 'Manage Admission Applicants and Applications (create, update, decide, convert)', 'namespace' => 'school'],

            // Phase 10B (docs/modules/TRANSPORT.md "Capabilities") --
            // three independently gateable areas, mirroring Library's
            // catalogue/circulation split: a School may want Transport
            // office staff who can manage Routes/Stops/Vehicles
            // without letting them touch individual Student
            // assignments, or vice versa. `.routes.*` covers Routes and
            // Stops together (a Stop has no independent meaning outside
            // its Route, so it does not earn its own capability pair,
            // matching Library's Copy-under-Title precedent).
            // `.vehicles.*` covers the Vehicle fleet AND the Route
            // Vehicle/Driver operational assignment (assigning a
            // Vehicle/Driver to a Route is fleet-operations work, not
            // Student-facing work). `.assignments.*` covers Student
            // Transport assignment only.
            ['key' => 'transport.routes.view', 'label' => 'View Transport Routes and Stops', 'namespace' => 'school'],
            ['key' => 'transport.routes.manage', 'label' => 'Manage Transport Routes and Stops', 'namespace' => 'school'],
            ['key' => 'transport.vehicles.view', 'label' => 'View Transport Vehicles and Route operational (Vehicle/Driver) assignments', 'namespace' => 'school'],
            ['key' => 'transport.vehicles.manage', 'label' => 'Manage Transport Vehicles and Route operational (Vehicle/Driver) assignments', 'namespace' => 'school'],
            ['key' => 'transport.assignments.view', 'label' => 'View Student Transport assignments', 'namespace' => 'school'],
            ['key' => 'transport.assignments.manage', 'label' => 'Manage Student Transport assignments', 'namespace' => 'school'],

            // Phase 10C (docs/modules/VISITOR.md "Capabilities") --
            // mirrors Library's catalogue/circulation split and
            // Transport's routes/vehicles/assignments split: a School
            // may want front-desk staff who can run check-in/check-out
            // without letting them edit the Visitor directory itself
            // (or vice versa). `.directory.*` covers Visitor reference
            // records; `.visits.*` covers the check-in/check-out Visit
            // lifecycle only.
            ['key' => 'visitor.directory.view', 'label' => 'View the Visitor directory', 'namespace' => 'school'],
            ['key' => 'visitor.directory.manage', 'label' => 'Manage the Visitor directory', 'namespace' => 'school'],
            ['key' => 'visitor.visits.view', 'label' => 'View Visitor check-in/check-out Visits', 'namespace' => 'school'],
            ['key' => 'visitor.visits.manage', 'label' => 'Manage Visitor check-in/check-out Visits', 'namespace' => 'school'],

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

            // Phase 0G.5: `finance.payments.view` gates
            // `App\Domain\Payments\Application\PaymentReadService` only.
            // Deliberately NO `finance.payments.manage` -- 0G.5 has no
            // human-triggered "record a payment" action to gate; the
            // only write path (`PaymentProviderEventService::recordSettlement()`)
            // is a trusted SYSTEM boundary (a future provider/HTTP
            // adapter), never reached through a human capability check
            // (rule 54 of the 0G.5 brief). Registering an unused
            // `.manage` capability now would be exactly the speculative
            // capability registration rule 53 warns against -- add it
            // only once a real human-facing "record a manual payment"
            // or similar action actually exists.
            ['key' => 'finance.payments.view', 'label' => 'View Payments', 'namespace' => 'school'],

            // Phase 10D (docs/modules/HOSTEL.md "Capabilities") --
            // mirrors Visitor's directory/visits split exactly:
            // `.directory.*` covers Hostel/Room/Bed reference records
            // together (a Room/Bed has no independent meaning outside
            // its Hostel, matching Library's Copy-under-Title
            // precedent -- no separate `hostel.rooms.*`/`hostel.beds.*`
            // pairs); `.residency.*` covers the Student residency
            // assignment lifecycle only.
            ['key' => 'hostel.directory.view', 'label' => 'View the Hostel/Room/Bed directory', 'namespace' => 'school'],
            ['key' => 'hostel.directory.manage', 'label' => 'Manage the Hostel/Room/Bed directory', 'namespace' => 'school'],
            ['key' => 'hostel.residency.view', 'label' => 'View Student Hostel residency assignments', 'namespace' => 'school'],
            ['key' => 'hostel.residency.manage', 'label' => 'Manage Student Hostel residency assignments', 'namespace' => 'school'],
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
                    // Phase 5D.1 §14: School Admin is the day-to-day
                    // operator of Guardian contact/communication (same
                    // hands-on rationale as guardians.manage above) --
                    // deliberately NOT `.conversations.students` (brief
                    // §15's stricter safeguarding default withholds it
                    // from every system role; a School must explicitly
                    // grant it).
                    'communications.conversations.guardians',
                    'enrollments.view', 'enrollments.manage',
                    // Phase 8A.10, HR.md "Authorization design": ONLY
                    // Directory-tier view/manage + Restricted personal
                    // VIEW are granted by default -- deliberately NOT
                    // `.personal.manage`, `.assignments.*`,
                    // `.qualifications.*`, `.documents.*`,
                    // `.sensitive.*`, `.notes.*`, `hr.departments.*`,
                    // `hr.positions.*`, `hr.categories.*` (added in the
                    // Phase 8A closure correction, same reference-data
                    // shape as `hr.positions.*` -- deliberately excluded
                    // from this default grant for the identical reason).
                    // See the security register's explicit P1 finding
                    // this closes: default HR capability grants must not
                    // silently broaden to every School Admin -- a
                    // School's own role configuration must explicitly
                    // add whichever of these an actual "HR Staff" role
                    // needs.
                    'hr.employees.view', 'hr.employees.manage', 'hr.employees.personal.view',
                    // Phase 10A: same "hands-on, day-to-day operational
                    // concern" reasoning already justifying full
                    // view+manage parity for students.*/guardians.*/
                    // enrollments.* on this role -- checking a book out
                    // to a Student is routine administrative work, not
                    // a rare/high-blast-radius action.
                    'library.catalogue.view', 'library.catalogue.manage',
                    'library.circulation.view', 'library.circulation.manage',
                    'enrollments.rollovers.view', 'enrollments.rollovers.manage',
                    // Phase 1D.4: same full view+manage parity already
                    // granted for students.*/guardians.*/enrollments.*
                    // above -- Admissions is the identical kind of
                    // hands-on, day-to-day operational concern for a
                    // School Admin (reviewing applications, deciding,
                    // and converting an accepted Applicant into a
                    // Student is routine administrative work, not a
                    // rare/high-blast-radius action the way bulk
                    // Enrollment Rollover execution is -- conversion
                    // creates exactly one Student/Enrollment per call,
                    // matching a single enrollments.manage operation's
                    // scope, never enrollments.rollovers.manage's
                    // hundreds-of-records-at-once scope).
                    'admissions.view', 'admissions.manage',
                    // Phase 10B: same "hands-on, day-to-day operational
                    // concern" reasoning already justifying full
                    // view+manage parity for library.*/students.*/
                    // guardians.*/enrollments.* on this role -- running
                    // Transport (routes, vehicles, driver assignment,
                    // Student assignment) is routine administrative
                    // work, not a rare/high-blast-radius action.
                    'transport.routes.view', 'transport.routes.manage',
                    'transport.vehicles.view', 'transport.vehicles.manage',
                    'transport.assignments.view', 'transport.assignments.manage',
                    // Phase 10C: same day-to-day operational parity
                    // reasoning as Transport/Library above -- running
                    // front-desk Visitor check-in/check-out is routine
                    // administrative work.
                    'visitor.directory.view', 'visitor.directory.manage',
                    'visitor.visits.view', 'visitor.visits.manage',
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
                    // Phase 0G.5: same default-grant logic as Ledger/
                    // Charges above. NOT granted to Principal below, for
                    // the identical reason.
                    'finance.payments.view',
                    // Phase 10D: same day-to-day operational parity
                    // reasoning as Visitor/Transport/Library above --
                    // managing Hostel structure and Student residency
                    // is routine administrative work.
                    'hostel.directory.view', 'hostel.directory.manage',
                    'hostel.residency.view', 'hostel.residency.manage',
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
                    // Phase 5D.1 §14: same rationale as school_admin
                    // above -- a Principal routinely contacts parents,
                    // matching this role's existing guardians.manage
                    // grant. `.conversations.students` withheld, same
                    // reasoning as school_admin.
                    'communications.conversations.guardians',
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
                    // Phase 10A: same day-to-day operational parity
                    // reasoning as school_admin above.
                    'library.catalogue.view', 'library.catalogue.manage',
                    'library.circulation.view', 'library.circulation.manage',
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
                    // Phase 1D.4: same reasoning as school_admin above --
                    // reviewing/deciding/converting Admission
                    // Applications is the identical hands-on operational
                    // concern already justifying full parity for
                    // students.*/guardians.*/enrollments.* on this role,
                    // not the "administrative, not day-to-day" case that
                    // keeps Principal at view-only for School profile/
                    // Campus/enrollments.rollovers.
                    'admissions.view', 'admissions.manage',
                    // Phase 10B: same day-to-day operational parity
                    // reasoning as school_admin above.
                    'transport.routes.view', 'transport.routes.manage',
                    'transport.vehicles.view', 'transport.vehicles.manage',
                    'transport.assignments.view', 'transport.assignments.manage',
                    // Phase 10C: same day-to-day operational parity
                    // reasoning as school_admin above.
                    'visitor.directory.view', 'visitor.directory.manage',
                    'visitor.visits.view', 'visitor.visits.manage',
                    // Phase 10D: same day-to-day operational parity
                    // reasoning as school_admin above.
                    'hostel.directory.view', 'hostel.directory.manage',
                    'hostel.residency.view', 'hostel.residency.manage',
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
