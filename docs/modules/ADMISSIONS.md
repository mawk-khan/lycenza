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

**HARDENED (1D.0A): no `email`/`phone` on `Applicant` either.** See §9
for the full reasoning — Admissions stores no contact data of any kind
in v1, for the Applicant or for a guardian.

## 5. AdmissionApplication — minimal v1 fields

- `id`, `school_id`
- `applicant_id` (composite FK → `applicants(id, school_id)`)
- `academic_year_id`, `campus_id`, `grade_level_id` (composite FKs →
  their respective Academic Structure tables, same-School)
- `status` (see §6)
- `decision_note` (nullable text — internal, staff-only, never in a
  broad/public API response; see §11)
- `converted_student_id`, `converted_student_enrollment_id` (nullable,
  composite FKs, restrict-on-delete — see §8)
- `converted_at` (nullable timestamp)
- `timestamps`

**HARDENED (1D.0A): no guardian/contact columns of any kind on
`AdmissionApplication` in v1** — not even a plain draft name or email/
phone. See §9 for why, and why this is a schema-simplifying, not
schema-complicating, decision.

**Proposed DB invariant (CHECK, not yet migrated — see §11):**
```sql
CHECK (
  (status = 'converted' AND converted_student_id IS NOT NULL
    AND converted_student_enrollment_id IS NOT NULL AND converted_at IS NOT NULL)
  OR
  (status <> 'converted' AND converted_student_id IS NULL
    AND converted_student_enrollment_id IS NULL AND converted_at IS NULL)
)
```
This is the one CHECK this table needs — a genuine cross-column
consistency invariant, the same category as
`student_subject_enrollments_date_range_check`. `status`'s own
*allowed values* are deliberately **not** a CHECK constraint — see §6A.

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

## 6A. Status constraint: application-level, not a DB CHECK (1D.0A)

**HARDENED, resolved with direct precedent:** `AdmissionApplication.status`'s
*allowed values* are enforced by an **application-level guard**
(a dedicated exception, mirroring `InvalidStudentStatusException`),
**not** a database CHECK constraint. This matches the exact,
consistent precedent every comparable lifecycle-status column in this
codebase already uses — confirmed by reading the actual migrations,
not assumed:
- `students` migration: `status` has no CHECK constraint;
  `StudentService`/`InvalidStudentStatusException` guard the two
  allowed values in application code.
- `student_enrollments` migration
  (`2026_08_23_130000_create_student_enrollments_table.php:92`):
  `$table->string('status')->default('active'); // active|completed|withdrawn|transferred|cancelled`
  — a comment, not a CHECK.
- `student_subject_enrollments` migration
  (`2026_08_24_100000_create_student_subject_enrollments_table.php:96`):
  identical shape — comment only, no value CHECK (its actual CHECK
  constraints, lines 120-123, are the date-range check and the
  partial-unique-index, both cross-column/cross-row invariants, never
  a status-value list).

The repository's consistent pattern is: **status *values* are an
application concern (they change — new transitions get added over
time, and an app-level exception gives a clean domain error); status
*consistency with other columns* is a database concern (it must never
be violated even by a bug, so it's a CHECK).** `AdmissionApplication`
follows this exactly: no value-list CHECK on `status` itself, but the
real CHECK from §5 enforcing `status = 'converted' ⇔` provenance is
complete.

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
   — **optional** (§11), and only if a canonical Guardian doesn't
   already exist (see §9's create-vs-link decision). `$attributes`
   (name) comes as **direct input to the conversion command itself**
   — Admissions never stores it beforehand (§9).
3. `App\Domain\Guardians\Application\GuardianContactService::create(Guardian $guardian, ContactType $type, string $rawValue, array $attributes, ?User $actor): GuardianContact`
   — only when creating a new Guardian; requires the Guardian to
   already exist, so it always runs after step 2. `$rawValue` (email/
   phone) is likewise **direct conversion-time input**, going straight
   from the staff-facing conversion form/request into this call —
   never staged in an Admissions-owned column first (§9).
4. `App\Domain\Guardians\Application\StudentGuardianRelationshipService::link(Student $student, Guardian $guardian, RelationshipType $type, array $attributes, ?User $actor): StudentGuardianRelationship`
   — requires both the Student (step 1) and Guardian (step 2/existing)
   to already exist; skipped entirely if no Guardian is supplied (§11).
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

## 9. Guardian strategy (avoiding a second identity system) — HARDENED (1D.0A)

**REVISED DECISION, replacing the original Pattern-A draft-column
design: Admissions stores NO guardian/applicant contact data at all,
ever, in v1 — not even a plain, non-canonical draft column.**

### 9.1 Why the original draft-column design was rejected

The original Phase 1D.0 draft proposed plain `applicant_guardian_email`/
`_phone` columns, explicitly *not* run through the encrypted/hashed
pipeline, reasoning that pre-canonical data didn't need the same
protection. That reasoning was wrong: **pre-canonical PII is still
PII.** A plain-text email/phone column — searchable in the trivial
`LIKE`/`=` sense even if not indexed for it — is exactly the kind of
column `GuardianContact` was built specifically to avoid (`docs/modules/
STUDENT-GUARDIAN-IDENTITY.md`'s "Searchable PII" section, ADR 0041).
Storing it in Admissions instead of Students/SIS does not change its
sensitivity classification.

The alternative — giving Admissions its **own** encrypted+hashed
contact storage, reusing the Phase 1A cryptographic pattern — was also
rejected for v1, for a concrete, code-grounded reason: `App\Support\
Privacy\ContactLookupHasher::hash()` builds its HMAC domain string as
`"guardian-contact|{$schoolId}|{$contactType}|{$normalizedValue}"` —
`'guardian-contact'` is a **fixed literal prefix, deliberately not
parameterized** (its own docblock: "if a second, unrelated exact-
match-lookup need arises later... that is the point to decide whether
to generalize this class or give the new need its own"). Standing up
an Admissions-owned encrypted/searchable contact store correctly would
require either generalizing `ContactLookupHasher` (a shared Support-
layer change) or building a parallel hasher — real PHP changes, which
this documentation-only checkpoint has no business quietly
presupposing. That decision belongs to whichever future checkpoint
actually implements it, not this one.

### 9.2 The v1 design: contact data is never persisted by Admissions — only by the canonical services, at the moment they receive it

`ConvertAcceptedAdmission` (§8) accepts guardian name/email/phone as
**direct input to the conversion command itself** — a staff member
supplies it (via whatever administrative UI/API a later checkpoint
builds) at the moment of conversion, and it flows straight into
`GuardianService::create()`/`GuardianContactService::create()`, which
already implement the correct encrypted+hashed handling from the
start. **Admissions never has its own copy of this data to protect,
because it never stores it.** This fully resolves the "pre-canonical
PII is still PII" problem by eliminating the pre-canonical storage
step entirely, rather than trying to secure it.

**Conversion-time create-vs-link (avoiding duplicate Guardian
personas — no fuzzy matching, no silent auto-link):** before creating
a new `Guardian`, `ConvertAcceptedAdmission` calls the
**already-existing**
`GuardianContactService::findCandidatesBySchool(School $school, ContactType $type, string $rawValue): Collection`
against the staff-supplied conversion-time email/phone (using the same
exact-match HMAC lookup Phase 1A already built for this exact purpose)
and surfaces any candidates to staff **before** creating anything.
Staff make an **explicit** create-vs-link decision — the conversion
command never auto-links by name or fuzzy contact match, and never
auto-creates when a candidate exists without staff confirmation. If
staff chooses "link", steps 2/3 in §8 are skipped and the existing
`Guardian`/`GuardianContact` is reused directly in step 4.

### 9.3 What this means for staff workflow (named honestly, not hidden)

Because nothing is stored until conversion, staff cannot see "the
guardian's email" while an application is merely `draft`/`submitted`/
`accepted` — that information exists only in whatever the future
conversion-time form captures, entered once, at conversion. If a
future checkpoint's real usage shows this is actually a workflow
problem (e.g. staff need to email an applicant's family *before*
deciding), that becomes the concrete, evidenced justification for a
**dedicated future checkpoint** — informally "Admissions Guardian
Draft / Conversion Preparation" — to design a real Admissions-owned
encrypted contact store properly (resolving the `ContactLookupHasher`
generalization question deliberately, not as a side effect of this
architecture-only gate). Not speculatively built now.

### 9.4 Deferred

- Guardian/contact capture in **any** form before conversion (draft or
  canonical) — see §9.3.
- Applicant's own contact (email/phone) — same reasoning; no evidence
  a staff-operated v1 needs the *child's* contact information at all,
  separate from whatever guardian contact conversion eventually
  collects.

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

**HARDENED (1D.0A) — the honest truth about what deterministic
protection actually exists today, stated explicitly rather than
implied:**
- **Already-converted provenance** (`converted_student_id IS NOT NULL`,
  §11) deterministically prevents *the same `AdmissionApplication`*
  from converting twice.
- **`DuplicateStudentNumberException`** deterministically prevents two
  Students sharing the identical `student_number` — but only catches
  the case where staff happen to *reuse* an existing number, not the
  general case of the same real person receiving two different
  numbers.
- **There is no deterministic cross-person identity key for `Student`
  today** (`Student` carries no email/phone/government-ID the way
  `Guardian` does via `GuardianContact`'s hash-lookup) — so, unlike
  the Guardian create-vs-link flow in §9, Admissions has **no
  mechanism** to detect "this Applicant is the same real child as an
  existing Student" even if it wanted to in v1. This is a genuine gap,
  not a design choice papering over one — it will remain open until a
  future re-admission checkpoint deliberately designs a Student-level
  matching key (which is itself a Students/SIS-owned decision, likely
  requiring `StudentIdentifier`, §4, to exist first).

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

**Transaction boundary (a real, load-bearing decision; wording
corrected in 1D.0A — see below):** each of the five services in §8
does wrap its own single-table write in `TenantContext::withSchool()`
+ its own `DB::transaction()` internally — directly confirmed by
reading `StudentService::create()`, `GuardianService::create()`,
`GuardianContactService::create()`, `StudentGuardianRelationshipService::link()`,
and `StudentEnrollmentService::enroll()`'s actual bodies (not assumed
from the general pattern). **The required invariant:**
`ConvertAcceptedAdmission` must wrap the entire sequence in one outer
`DB::transaction()` inside a single `TenantContext::withSchool()`
block, so a failure on a later step rolls back everything already
committed by earlier steps (Postgres nests `DB::transaction()` calls
as savepoints, and each service's own `try`/`catch` blocks only
translate specific exceptions to a friendlier type — none of them
swallow an exception in a way that would suppress the outer
transaction's rollback).

**HARDENED (1D.0A):** the original wording above stated this
composition "would NOT be atomic" without one outer transaction as if
already proven safe *with* one. That overclaimed certainty this
architecture-only checkpoint cannot actually establish — nested-
transaction/savepoint composition across five independently-authored
services has not been exercised together anywhere in this codebase
yet. **1D.3 (the implementation checkpoint that builds
`ConvertAcceptedAdmission`) MUST prove this holds** with a real
forced-failure integration test — inject a failure on the *last* step
and assert **zero** Student/Guardian/relationship rows persist —
mirroring this codebase's established "prove atomicity with a real
forced-failure test" discipline (e.g. Phase 1B's transfer-rollback-on-
conflict test). Until that test exists and passes, atomicity is a
**documented requirement**, not a proven property.

**Guardian optionality:** `StudentGuardianRelationshipService::link()`
is a separate call from `StudentService::create()` — nothing in
`Student`'s schema or `StudentService` requires a
`StudentGuardianRelationship` to exist. A Guardian is therefore
**optional** at conversion: if staff supply no guardian information,
`ConvertAcceptedAdmission` skips steps 2-4 in §8 entirely and still
successfully creates the Student + Enrollment from steps 1 and 5 alone.

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

No School-spanning Applicant identity in v1: the same real-world child
applying to two Schools is represented as two fully independent,
tenant-scoped `Applicant` rows — no global person-matching across
Schools.

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

**Not searchable by contact value in v1**: Admissions stores no
contact value at all (§9, hardened in 1D.0A), so there is nothing to
search — `GuardianContactService::findCandidatesBySchool()` remains an
internal, conversion-time-only lookup against staff-supplied input,
never a staff-facing search feature or an Admissions-owned column.

**Never stored**: any guardian/applicant contact value in any form
(§9), DOB beyond what mirrors `Student` (§4), any field `Student`/
`Guardian` themselves don't carry, application-fee/payment data (§16),
documents/attachments (§16).

## 15. Audit

Every meaningful transition gets an audit event, following
`AuditRecorder`'s existing `$audit->school($school, 'event.name',
actor:, subject:, metadata:)` shape (ADR 0017), metadata limited to
IDs/status/academic-context IDs — **never** name, DOB, or (at
conversion) the guardian contact value staff supplied as command input
(mirrors `StudentService`'s own "audit metadata never carries a
Student's name/date_of_birth" rule, applied identically here since
`Applicant` carries the same sensitivity):
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
- **Guardian/Applicant contact capture in any form** (draft, canonical,
  or otherwise) before conversion time — see §9 (hardened in 1D.0A;
  supersedes the original Phase 1D.0 draft-column proposal).

## 17. Implementation slices

See `docs/admissions/PHASE-1D-0-ADMISSIONS-ARCHITECTURE.md` §"Implementation
slices" for the proposed 1D.1-1D.6 breakdown and the exact recommended
next checkpoint.

## Retention (E21.3B, 2026-10-02)

- A **converted** application (and its applicant) is supporting history of
  the Student it became (E21.2G AD1). It is kept with that Student's core
  record and deleted in the same unit, 25 calendar years after the
  Student's final exit, by `platform:student-retention-prune` through
  `App\Domain\Admissions\Application\Retention\ConvertedApplicationRetentionService`.
  It is never aged from its creation or conversion date. The linkage is
  `converted_student_id` (set with the conversion,
  `admission_applications_conversion_provenance_check`).
- The **applicant** goes too only once no application of it remains.
- **Rejected and withdrawn** applications are kept: their 1-year period
  needs a durable decision timestamp (E21.3C), and `updated_at` is never
  used. Draft, submitted and accepted applications are live working state.

Project-adopted, pending legal ratification (`docs/security/E21-RETENTION-DETERMINATION.md`
§5.6). Holds (`RETENTION_HOLD_SCHOOL_IDS`) keep everything; `--dry-run`
counts with the same rule.
