# Admissions

## 1. Purpose

Let a School record and manage the pre-enrollment workflow for a child
who wants to join — from a staff-entered application through a
decision, to converting an **accepted** application into a real
`Student` identity, `Guardian` relationship(s), and an initial
`StudentEnrollment` — without Admissions becoming a second owner of
any of those three aggregates. Admissions is authored here for the
first time; `docs/architecture/DOMAIN-MAP.md` has named it since Phase
0D but no design existed until this checkpoint (informally labeled
"Phase 1D" — see `docs/admissions/PHASE-1D-0-ADMISSIONS-ARCHITECTURE.md`
for why that label is informal, not a `MASTER-ROADMAP.md` phase).

This is an **architecture document only**. Nothing described here has
been implemented yet — no migration, model, service, controller,
route, capability, or Vue page exists on this branch. Every section
below is DECIDED (a real architectural commitment for the first
implementation slice), DEFERRED (explicitly out of v1, named so a
later checkpoint doesn't have to rediscover it), or OPEN QUESTION
(a real unresolved risk, named rather than silently ignored).

## 2. Ownership

- **Admissions owns**: the pre-Student application workflow —
  `Applicant` (a person applying) and `AdmissionApplication` (one
  specific application, for one academic context, with its own
  lifecycle and decision).
- **Students/SIS owns** (unchanged, never touched by this checkpoint):
  `Student` identity, `Guardian` identity, `StudentGuardianRelationship`,
  `StudentEnrollment`, `StudentSubjectEnrollment`.
- **Academic Structure owns** (unchanged): `AcademicYear`, `Campus`,
  `GradeLevel`, `Section`, `Subject`, `SubjectOffering`.

**Dependency direction (matches `docs/architecture/DOMAIN-MAP.md`
line 75 exactly, confirmed unchanged by this checkpoint):** Admissions
depends on Academic Structure, Schools/Tenancy, and Students/SIS's
Application-layer service contracts. **Students/SIS never depends on
Admissions** — `StudentController::store()`'s direct-creation path
(Phase 1A) remains the only *other* way a Student is created and is
completely unaffected; Admissions is an additional front door, never
the only one.

## 3. Aggregate model

**DECIDED: two aggregates, not one.**

- **`Applicant`** — the child/person applying. Owned entirely by
  Admissions; not yet a `Student`. Never represented as a `Student`
  merely because an application exists (root instruction, preserved).
- **`AdmissionApplication`** — one specific application: one
  `Applicant`, applying to one School, for one academic context
  (AcademicYear + Campus + GradeLevel), with its own status/decision/
  conversion state.

**Why two aggregates, not one:** an `Applicant` may legitimately have
more than one `AdmissionApplication` over time — reapplication after a
prior rejection or withdrawal, or applying to a different intake year.
Collapsing them into one mutable row would force either losing that
history or awkwardly re-using one row's status field to mean two
different things across two application attempts. Two aggregates
preserve history correctly with the same discipline `StudentEnrollment`
already uses for placement history (a new row per period, never
mutated into a different period).

There is **no separate `Inquiry`/`AdmissionLead` aggregate in v1**
(§13 of the companion checkpoint doc explains why) — `MASTER-ROADMAP.md`
Phase 0F's mention of a future `AdmissionLeadCreated` *domain event* is
a naming precedent for a possible later pre-application lead-tracking
feature, not evidence that v1 needs a distinct Lead aggregate today.
`AdmissionApplication.status = 'draft'` already covers "someone started
an application, staff are gathering details" without a second aggregate.

There is **no separate `AdmissionDecision` entity in v1** — a decision
(accepted/rejected) is a status transition on `AdmissionApplication`
plus an optional `decision_note`, not a separate row. Nothing in this
repository's evidence requires multiple decisions, appeals, or a
committee-decision history; the append-only audit log already preserves
transition history without a parallel event-sourced model.

## 4. Applicant — minimal v1 fields

Mirrors `Student`'s own identity field shape exactly (Phase 1A), since
these are the literal facts copied verbatim into `Student` at
conversion:

- `id`, `school_id`
- `first_name` (required)
- `middle_name` (nullable)
- `last_name` (nullable)
- `date_of_birth` (required)

**DEFERRED, same reasoning `Student` already defers them**: gender,
photo, nationality, language, medical data, government ID
(StudentIdentifier). No Admissions-specific PII expansion beyond what
`Student` itself already carries — see
`docs/modules/STUDENT-GUARDIAN-IDENTITY.md` lines 411-443.

**DEFERRED: Address.** No reusable `Address`/`Addressable`
architecture exists anywhere in this codebase yet
(`STUDENT-GUARDIAN-IDENTITY.md` lines 415-417); Admissions does not
invent one. If a future Admissions checkpoint genuinely needs a mailing
address (e.g. for offer letters), that becomes the first real consumer
that justifies designing `Address` properly — not built speculatively
here.

## 5. AdmissionApplication — minimal v1 fields

- `id`, `school_id`
- `applicant_id` (composite FK → `applicants(id, school_id)`)
- `academic_year_id`, `campus_id`, `grade_level_id` (composite FKs →
  their respective Academic Structure tables, same-School)
- `status` (see §6)
- `decision_note` (nullable text — internal, staff-only, never in a
  broad/public API response; see §11)
- `applicant_guardian_name`, `applicant_guardian_email`,
  `applicant_guardian_phone` (all nullable plain strings — see §9,
  this is deliberately NOT the canonical encrypted/searchable
  `GuardianContact` pattern)
- `converted_student_id`, `converted_student_enrollment_id` (nullable,
  composite FKs, restrict-on-delete — see §8)
- `converted_at` (nullable timestamp)
- `timestamps`

**DECIDED: no `Section` on `AdmissionApplication`.** An application
commits to AcademicYear + Campus + GradeLevel only. Section is chosen
by staff **at conversion time**, alongside `roll_number` (both required
arguments of the existing `StudentEnrollmentService::enroll()` call) —
this is the same "Section is the placement-time decision, never
pre-committed earlier" discipline `docs/modules/STUDENT-ENROLLMENT.md`
already establishes for `StudentEnrollment` itself.

**DEFERRED: `application_number`.** No repository evidence forces a
sequential human-facing number the way `student_number` is actively
written on physical documents today. Staff-facing lists can order by
`created_at`/`applicant_id` in v1; a human-facing number is a
candidate fast-follow if real usage demands it, not built speculatively.

## 6. Lifecycle

**Statuses**: `draft`, `submitted`, `accepted`, `rejected`, `withdrawn`,
`converted`.

**Legal transitions:**
```
draft      -> submitted
draft      -> (hard-deleted; see below — not a status transition)
submitted  -> accepted
submitted  -> rejected
submitted  -> withdrawn
accepted   -> withdrawn      (family declines after acceptance, before conversion)
accepted   -> converted      (the ONLY path into converted)
```

**Terminal**: `rejected`, `withdrawn`, `converted`. None of these
transition further — re-applying after `rejected`/`withdrawn` creates
a **new** `AdmissionApplication` row, it never un-terminates the old
one (same "history is a new row, not a mutation" discipline as
`StudentEnrollment`/`StudentSubjectEnrollment`).

**Mutability by state:**
- `draft` — broadly editable (staff still gathering information).
- `submitted`/`accepted` — identity fields (`applicant_*`, academic
  context) editable through a controlled update path, but `status`
  changes ONLY through the dedicated transition actions below, never a
  generic field update — mirrors `StudentEnrollmentService`'s
  established `enroll()`/`complete()`/`withdraw()`/`cancel()` shape
  (dedicated methods per transition, never one generic `update(status:)`).
- `rejected`/`withdrawn` — the decision itself is immutable;
  `decision_note` may still be corrected administratively after the
  fact (record-keeping, not re-litigating the decision).
- `converted` — frozen except administrative metadata (matches the
  "once converted, `Student` becomes canonical current identity, the
  application remains a historical snapshot" principle in §8).

**Deletion**: only a `draft` application may be hard-deleted (no
meaningful workflow began). `submitted` and later are never
hard-deleted — `withdrawn` is the correct action once a real workflow
has started, exactly matching the "prefer lifecycle status over
deletion for submitted records" principle already used throughout this
codebase.

## 7. Accepted is not converted

**DECIDED, explicit boundary:** `accepted` is a staff **decision**.
`converted` is a separate **conversion action** — a single,
staff-triggered command (`ConvertAcceptedAdmission`, name TBD at
implementation time) that only an already-`accepted` application may
invoke. Accepting an application never implies a `Student` row exists;
it only permits the conversion action to be taken next.

## 8. Conversion contract

`ConvertAcceptedAdmission` is a **new Admissions-owned Application
service** that composes four **existing, unmodified** Students/SIS
services, in this order, inside one outer `DB::transaction()` (see
§11 for why one outer transaction is required):

1. `App\Domain\Students\Application\StudentService::create(School $school, array $attributes, ?User $actor): Student`
   — `$attributes` requires `student_number` (see §9 — **staff-supplied**,
   not auto-generated; Phase 1A has no allocator), `first_name`,
   `middle_name`, `last_name`, `date_of_birth` — the last four map
   directly from `Applicant`.
2. `App\Domain\Guardians\Application\GuardianService::create(School $school, array $attributes, ?User $actor): Guardian`
   — only if a canonical Guardian doesn't already exist (see §9's
   create-vs-link decision).
3. `App\Domain\Guardians\Application\GuardianContactService::create(Guardian $guardian, ContactType $type, string $rawValue, array $attributes, ?User $actor): GuardianContact`
   — only when creating a new Guardian; requires the Guardian to
   already exist, so it always runs after step 2.
4. `App\Domain\Guardians\Application\StudentGuardianRelationshipService::link(Student $student, Guardian $guardian, RelationshipType $type, array $attributes, ?User $actor): StudentGuardianRelationship`
   — requires both the Student (step 1) and Guardian (step 2/existing)
   to already exist.
5. `App\Domain\Students\Application\StudentEnrollmentService::enroll(Student $student, Section $section, string $rollNumber, string $startsOn, ?User $actor): StudentEnrollment`
   — `$section`/`$rollNumber` are chosen by staff at conversion time
   (§5), never pre-stored on the application.

**Missing seams (reported, not fixed in this checkpoint):**
- No composite "create Student + link Guardian + enroll" orchestration
  exists today — expected; `ConvertAcceptedAdmission` is exactly that
  composition, and composing existing services is not the same as
  modifying them.
- No `StudentNumberAllocator` exists for Students/SIS (HR has
  `EmployeeNumberAllocator`; Students/SIS does not) — Phase 1A's
  `StudentService::create()` requires a caller-supplied
  `student_number`, validated only for per-School uniqueness
  (`DuplicateStudentNumberException`). Conversion therefore requires a
  staff-entered Student Number at the moment of conversion. **OPEN
  QUESTION** for a future checkpoint: if Admissions conversion volume
  makes manual entry a bottleneck, a `StudentNumberAllocator` would be
  a Students/SIS-owned change, out of Admissions' own boundary — not
  decided here.

## 9. Guardian strategy (avoiding a second identity system)

**DECIDED: Pattern A, adapted.** Guardian/contact information entered
during the application (`applicant_guardian_name`/`_email`/`_phone` on
`AdmissionApplication`, §5) is a **draft, non-canonical, application-
scoped snapshot** — plain nullable columns, deliberately **not** run
through the encrypted/hashed searchable-PII pipeline
(`GuardianContact`'s `encrypted_value`/`lookup_hash`/`lookup_key_version`
pattern, ADR 0028) in v1, because it is not yet a permanent identity
and is not required to be searchable across applications by contact
value (staff search by Applicant name/application context instead —
§14). If this draft data is ever promoted to a cross-application
searchable requirement, it must upgrade to the full `GuardianContact`
pattern at that point, not grow ad hoc encryption of its own.

**Conversion-time create-vs-link (avoiding duplicate Guardian
personas, §21's explicit "no fuzzy matching, no silent auto-link"):**
before creating a new `Guardian`, `ConvertAcceptedAdmission` calls the
**already-existing**
`GuardianContactService::findCandidatesBySchool(School $school, ContactType $type, string $rawValue): Collection`
against the draft `applicant_guardian_email`/`_phone` (using the same
exact-match HMAC lookup Phase 1A already built for this exact purpose)
and surfaces any candidates to staff. Staff make an **explicit**
create-vs-link decision — the conversion command never auto-links by
name or fuzzy contact match. If staff chooses "link", step 2/3 above
are skipped and the existing `Guardian`/`GuardianContact` is reused
directly in step 4.

**DEFERRED:** guardian capture as a first-class, canonical, searchable
Admissions concept (i.e., promoting draft fields into real
`GuardianContact` rows before conversion, rather than only at
conversion) — a real future enhancement, not required for v1's core
loop (application → decision → conversion) to be useful.

## 10. Existing Student / duplicate identity

**DECIDED for v1: `ConvertAcceptedAdmission` always creates a brand
new `Student`.** It does not attempt to detect or link to a
pre-existing `Student` (unlike the Guardian create-vs-link flow in
§9, which reuses an existing, proven lookup mechanism). Re-admission
(a former Student re-enrolling) is **explicitly deferred** to a future
checkpoint — implementing it now would require designing Student-level
matching without the same exact-match-hash infrastructure Guardian
contact lookup already has (Student has no comparable searchable
field), which is real, undecided design work out of scope here.

**OPEN QUESTION (named, not solved):** a returning Student who is not
recognized by staff before conversion could receive a second `Student`
identity. In the interim this is a **procedural** risk (staff should
check for an existing Student before starting a new application for a
known returning child), not an architectural guarantee — a future
re-admission checkpoint should close this gap properly rather than
this checkpoint attempting a partial, unproven answer.

## 11. Conversion idempotency and transaction boundary

**Idempotency (root CLAUDE.md rules 29-33's discipline, applied at the
Application-layer even before any HTTP endpoint is designed):**
`converted_student_id`/`converted_student_enrollment_id`/`converted_at`
(§5) are the provenance record. `ConvertAcceptedAdmission` checks
`converted_student_id !== null` **before** doing anything, and refuses
(returns the existing linkage rather than re-running) if conversion
already happened — defense-in-depth beneath whatever HTTP-level
`Idempotency-Key` review a later API-layer checkpoint applies (CLAUDE.md
rule 29 requires that review explicitly when the actual endpoint is
built; not decided here).

**Transaction boundary (a real, load-bearing decision):** each of the
five existing services in §8 already wraps its own single-table write
in `TenantContext::withSchool()` + its own `DB::transaction()`
internally. Calling all five bare in sequence would NOT be atomic — a
failure on step 5 (`StudentEnrollmentService::enroll()`) after steps
1-4 already committed would leave an orphaned `Student`+`Guardian`
with no enrollment. **`ConvertAcceptedAdmission` MUST wrap the entire
five-step sequence in one outer `DB::transaction()`** (Postgres nests
these as savepoints without conflict) inside a single
`TenantContext::withSchool()` block. A future implementation checkpoint
must prove this with a real forced-failure integration test — inject a
failure on the *last* step and assert **zero** Student/Guardian/
relationship rows persist — mirroring this codebase's established
"prove atomicity with a real forced-failure test" discipline (e.g.
Phase 1B's transfer-rollback-on-conflict test).

## 12. Tenancy / RLS

Both new tables (`applicants`, `admission_applications`) follow the
exact, non-negotiable pattern every other tenant-owned table in this
codebase uses — no exceptions, no hand-rolled equivalent:
- `App\Support\Tenancy\BelongsToSchool` (Layer 1 `SchoolScope` +
  auto-filled `school_id` on create)
- `App\Support\Identifiers\GeneratesUuidV7`
- Composite same-School FKs: `applicant_id+school_id` →
  `applicants(id, school_id)`; `academic_year_id+school_id`,
  `campus_id+school_id`, `grade_level_id+school_id` → their Academic
  Structure tables; `converted_student_id+school_id`,
  `converted_student_enrollment_id+school_id` → `students`/
  `student_enrollments` (restrict-on-delete — these are audit-trail
  references, and `Student`/`StudentEnrollment` rows are never
  hard-deleted anyway, so restrict is purely defensive).
- `App\Support\Tenancy\TenantRls::enable('applicants')` and
  `::enable('admission_applications')` in each creating migration —
  never hand-written `ENABLE ROW LEVEL SECURITY`/`CREATE POLICY` SQL.
- ENABLE + FORCE RLS on both, proven with the standard raw-SQL
  cross-School isolation test pattern
  (`tests/Feature/Postgres/RawIsolationTest.php`'s shape) at
  implementation time.

No School-spanning Applicant identity in v1 (§13's "same real-world
child applying to two Schools = two independent tenant-scoped rows" —
no global person-matching).

## 13. Authorization

**Proposed capabilities** (namespace `school`, following the exact
label convention `CapabilityAndRoleSeeder.php` already uses for
`students.*`/`guardians.*`):
- `admissions.view` — "View Admission applications"
- `admissions.manage` — "Manage Admission applications (create,
  update, decide, convert)"

**No new authorization model beyond the existing capability pattern**
— `Gate::authorize('capability', [$key, $school])` /
`AuthorizesCapability` trait, exactly like every other module. No
role-name checks. Capabilities are **not seeded by this checkpoint** —
that happens when the actual migration/service checkpoint adds them to
`CapabilityAndRoleSeeder.php`, alongside a real authorization test
(allow + deny cases, per root CLAUDE.md rule 13).

**Conversion authorization note for the future implementation
checkpoint**: converting an application touches Students/SIS data
(`Student`, `Guardian`, `StudentEnrollment`) but the conversion
*action* itself should be gated by `admissions.manage` only — mirroring
`SubjectOfferingRosterReadService`'s "academic authorization is not
transitively required" precedent (Phase 1C/5C) — an Admissions staff
member authorized to convert an application should not additionally
need `students.manage`/`enrollments.manage` just because the
conversion command happens to call into those services internally.

## 14. Search / PII

**Searchable in v1** (staff-facing list/filter, not yet implemented,
just scoped): Applicant name, `AdmissionApplication` status, academic
year/campus/grade level, `created_at` ordering.

**Not searchable by contact value in v1**: `applicant_guardian_email`/
`_phone` are plain draft fields (§9), not run through the encrypted+
hashed lookup pipeline — no wildcard/plaintext scan is needed or
built, because search-by-contact isn't a v1 requirement (unlike
`GuardianContactService::findCandidatesBySchool()`, which is an
internal conversion-time lookup, not a staff-facing search feature).

**Never stored**: DOB beyond what mirrors `Student` (§4), any field
`Student`/`Guardian` themselves don't carry, application-fee/payment
data (§16), documents/attachments (§16).

## 15. Audit

Every meaningful transition gets an audit event, following
`AuditRecorder`'s existing `$audit->school($school, 'event.name',
actor:, subject:, metadata:)` shape (ADR 0017), metadata limited to
IDs/status/academic-context IDs — **never** name, DOB, or draft
guardian contact values (mirrors `StudentService`'s own "audit
metadata never carries a Student's name/date_of_birth" rule, applied
identically here since `Applicant` carries the same sensitivity):
- `admission_application.created`
- `admission_application.submitted`
- `admission_application.accepted`
- `admission_application.rejected`
- `admission_application.withdrawn`
- `admission_application.converted` (metadata includes
  `converted_student_id`/`converted_student_enrollment_id` — IDs only)

A rejected or withdrawn transition never emits a false-success
`.converted` event, and a failed conversion attempt (transaction
rolled back per §11) emits no `.converted` event at all — matching the
"no success audit on a rejected/failed action" discipline already
established for Phase 1C's subject enrollment guards.

## 16. Deferred v1 scope

- **Documents/attachments** (birth certificates, ID scans, prior-school
  records) — Phase 0E (Documents foundation) does not exist on
  `origin/main`; Admissions does not build a bespoke document
  subsystem as a workaround (unlike HR's narrow scoped table for
  Employee Documents, which was justified by HR's own urgent need —
  Admissions v1 has no comparably urgent need to attach files).
- **Application fees / payment** — belongs to a future Finance/Fees
  module; no `fee_paid`/`payment_status`/`transaction_id` fields exist
  on `AdmissionApplication`.
- **Public/self-service applicant portal, Applicant/Guardian login,
  email verification, OTP** — Phase 1A already deferred Student/
  Guardian portal login repo-wide; Admissions v1 is a staff-operated
  administrative workflow only.
- **Address** — see §4.
- **StudentIdentifier / government IDs** — see §4.
- **Teacher assignment, Timetable/Attendance/Exams integration** — no
  evidence any of these need to reach into Admissions; excluded per
  the same "later operational module" boundary Phase 1C already drew
  for SubjectOffering.
- **Communications notifications** (acknowledgement/decision emails) —
  no provider work in this checkpoint; a future checkpoint may treat
  `admission_application.submitted`/`.accepted`/`.rejected` as
  candidate Communications trigger points, not built now.
- **Domain events** (`AdmissionApplicationSubmitted`, `AdmissionAccepted`,
  `AdmissionRejected`, `AdmissionConverted`) — candidate names only,
  no outbox row, no `WebhookEventRegistry` entry; no current consumer
  justifies building them yet (mirrors Phase 1C's own discipline of
  not adding speculative events).
- **Inquiry/Lead aggregate** — see §3.
- **`application_number`** — see §5.
- **Re-admission / existing-Student linking** — see §10.
- **Rejection reason taxonomy (`reason_code`)** — see §5's
  `decision_note`; free text only in v1.

## 17. Implementation slices

See `docs/admissions/PHASE-1D-0-ADMISSIONS-ARCHITECTURE.md` §"Implementation
slices" for the proposed 1D.1-1D.6 breakdown and the exact recommended
next checkpoint.
