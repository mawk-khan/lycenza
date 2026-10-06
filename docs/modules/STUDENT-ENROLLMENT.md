# Student Enrollment (Phase 1B.1 / 1B.2 / 1B.3 / 1B.4 / 1B.4A / 1B.5 / 1B.6 / 1B.7 / 1B.7A / 1B.7B)

Status: **schema + sanctioned write path + lifecycle transitions +
authorization + read foundation + administrative HTTP/API + administrative
UI + rollover/promotion architecture decision + rollover schema/domain
foundation + rollover dry-run/eligibility/conflict engine (no rollover
execution/API/UI yet).** Phase 1B.1
shipped the schema/model/RLS/composite-FK foundation;
Phase 1B.2 added `StudentEnrollmentService::enroll()`, the only
sanctioned way to create a StudentEnrollment; Phase 1B.3 added the four
terminal lifecycle transitions (`complete()`/`withdraw()`/`cancel()`)
and the atomic same-Academic-Year placement transfer
(`transferPlacement()`); Phase 1B.4 added the `enrollments.view`/
`enrollments.manage` capabilities and `StudentEnrollmentReadService`,
the canonical read layer; Phase 1B.4A hardened cross-cutting
TenantContext cleanup; Phase 1B.5 exposed all of the above through the
repository's existing authenticated School administrative `/api/v1`
surface (see "Administrative HTTP boundary (Phase 1B.5)" below); Phase
1B.6 added the session-authenticated Vue/Inertia administrative UI over
the SAME domain (see "Administrative UI (Phase 1B.6)" below); Phase
1B.7 was an ARCHITECTURE-ONLY gate (see "Academic-Year Rollover &
Promotion — Architecture Decision (Phase 1B.7)" below) that decided the
safe model for cross-year promotion; Phase 1B.7A implemented the
durable plan/mapping/item schema and
`EnrollmentRolloverPlanService::createDraft()` (see "Schema & Domain
Foundation (Phase 1B.7A)" below); Phase 1B.7B implemented the
persistent dry-run/eligibility/conflict-detection engine
(`EnrollmentRolloverDryRunService`) plus `upsertMapping()`/
`setItemDecision()` on the plan service (see "Dry-Run, Eligibility &
Conflict Engine (Phase 1B.7B)" below) — still zero Enrollment mutation,
no execution, no HTTP/API, and no UI for rollover (Phase 1B.7C onward).

## Identity vs enrollment boundary

Phase 1A established `Student` as a **permanent, School-level identity**
(`App\Domain\Students\Infrastructure\Student`) — deliberately
independent of admission/enrollment/grade/section/attendance/fee-
account/portal-login state. Phase 1B answers a different question:

> Where, when, and in what academic context is this Student enrolled?

`App\Domain\Students\Infrastructure\StudentEnrollment` is the answer —
a **time-varying academic placement record**, separate from identity by
construction:

| Belongs to Student identity (Phase 1A) | Belongs to Enrollment (Phase 1B) |
|---|---|
| `student_number` | `roll_number` |
| `first_name`/`middle_name`/`last_name` | `academic_year_id`/`campus_id`/`grade_level_id`/`section_id` |
| `date_of_birth` | `status` (enrollment lifecycle) |
| `Student.status` (active/inactive identity) | `starts_on`/`ends_on` |

A Student's `student_number` never changes because of a promotion,
section move, campus transfer, year rollover, withdrawal, transfer, or
re-enrollment — proven in
`StudentEnrollmentTest::creating_an_enrollment_never_changes_the_students_permanent_student_number`.

## Existing Academic Structure (as of Phase 1B.1's reconnaissance)

```text
School
 ├── Campus                          (school-wide facility, Phase 0B)
 ├── AcademicYear                    (school-wide, one active at a time, Phase 0D)
 │    └── AcademicTerm
 ├── GradeLevel                      (school-wide reference data, NOT campus/year-scoped)
 └── Section                         (AcademicYear + Campus + GradeLevel scoped;
                                       belongs to exactly ONE AcademicYear —
                                       "Grade 5 A" in 2026-27 and 2027-28 are
                                       distinct rows)
```

There is no separate `Class`/`Cohort`/`Stream` model in this codebase —
`Section` (Phase 0D) already IS the AcademicYear+Campus+GradeLevel-scoped
instructional group. `SubjectOffering` is deliberately NOT attached to
Section (a Subject is offered to a GradeLevel within a Campus/
AcademicYear and inherited by that Grade's Sections). StudentEnrollment
follows the same "reference the existing structure, do not invent a
parallel one" rule.

## Schema

```text
student_enrollments
  id                uuid, PK (UUIDv7)
  school_id         uuid, FK -> schools, cascade
  student_id        uuid, composite FK -> students(id, school_id), cascade
  academic_year_id  uuid, composite FK -> academic_years(id, school_id), restrict
  campus_id         uuid, composite FK -> campuses(id, school_id), restrict
  grade_level_id    uuid, composite FK -> grade_levels(id, school_id), restrict
  section_id        uuid, composite FK -> sections(id, school_id), restrict
  roll_number       string
  status            string, default 'active'
  starts_on         date
  ends_on           date, nullable
  created_at/updated_at
```

`academic_year_id`/`campus_id`/`grade_level_id` are denormalized copies
of what `section_id` already implies (a Section belongs to exactly one
AcademicYear/Campus/GradeLevel). This is deliberate, for two reasons:

1. The "one active Enrollment per Student per AcademicYear" invariant
   (below) is a PostgreSQL **partial unique index** — a partial index
   cannot reference a joined table's column, so `academic_year_id` must
   be a real column on this table.
2. Each parent reference gets its own composite-FK protection against
   `school_id`, matching the `Section`/`SubjectOffering` precedent
   exactly (CLAUDE.md rule 70) — never rely on RLS alone to prevent a
   cross-School reference at INSERT time.

Consistency between `section_id` and the other three denormalized
columns is **not** a database constraint (PostgreSQL cannot cheaply
express "these columns must equal another row's columns" without a
trigger). It is guaranteed by construction: `StudentEnrollmentService`
(Phase 1B.2, below) is the only sanctioned write path and always
derives `academic_year_id`/`campus_id`/`grade_level_id` **from** the
caller-chosen `section_id` server-side — the same trust model already
used everywhere else in this codebase (a client never supplies
`school_id`; here, a client never independently supplies the three
denormalized ancestor ids either — only `section_id`). Phase 1B.1's
test fixture (`CreatesTenancyFixtures::createStudentEnrollment()`)
already enforced this by construction before the service existed, so
no test could accidentally construct an inconsistent state that real
code could not produce.

## Sanctioned Enrollment creation path (Phase 1B.2)

`App\Domain\Students\Application\StudentEnrollmentService::enroll()`
is the ONLY sanctioned way to create a StudentEnrollment — never
`StudentEnrollment::create()` directly from a future controller/
import. Signature (repository convention, not necessarily the final
HTTP-facing shape):

```php
enroll(Student $student, Section $section, string $rollNumber, string $startsOn, ?User $actor = null): StudentEnrollment
```

**Section is the sole placement input.** The method signature has no
parameter through which a caller could independently supply
`academic_year_id`/`campus_id`/`grade_level_id`/`section_id` — they
are structurally unreachable, not merely unused/overwritten. The
service reads `$section->academic_year_id`/`campus_id`/`grade_level_id`
directly and writes those exact values, so "Section: Grade 5 / Campus A
/ 2026-27, but Enrollment: Grade 6 / Campus B / 2027-28" cannot be
expressed by a normal caller at all. Proven in
`StudentEnrollmentServiceTest::the_service_derives_academic_year_campus_and_grade_level_from_the_section_alone`.

**Same-School check (defense-in-depth).** Before touching the
database, the service compares `$student->school_id` against
`$section->school_id` and throws `CrossSchoolEnrollmentException` on a
mismatch — a cheap in-memory check ahead of the composite FKs, which
remain the authoritative guarantee (this check is a UX/fail-fast
convenience, not a replacement; RLS and the composite FKs from Phase
1B.1 are what actually make a cross-School row impossible even if this
check were ever bypassed).

**Transaction boundary.** `TenantContext::withSchool($student->school,
...)` establishes School context from the Student's own School (never
an assumed ambient context); inside that closure,
`DB::transaction(...)` wraps the actual `StudentEnrollment::create()` +
audit write. This mirrors `StudentGuardianRelationshipService::link()`'s
exact shape. The inner `DB::transaction()` is what makes an expected
constraint violation (roll-number or active-enrollment conflict) safe:
Laravel's transaction wrapper catches the `UniqueConstraintViolationException`,
issues `ROLLBACK TO SAVEPOINT`, and re-throws — so by the time
`withSchool()`'s own `finally`-block `RESET app.current_school_id` runs,
the connection is already back to a clean, non-aborted state. Proven
directly in
`StudentEnrollmentServiceTest::tenant_context_exits_cleanly_after_an_active_enrollment_conflict_and_the_next_operation_succeeds`
and the equivalent roll-number-conflict test — a real subsequent write,
in a fresh `TenantContext`, for a different Student, succeeds
immediately after the expected exception. No change was made to
`TenantContext` itself; this is the same "wrap the mutation in its own
transaction" pattern every other write service in this codebase already
uses (see the P3 note in the Phase 1B.1 startup report — a service that
skips this wrapping remains a latent footgun, not fixed by this
checkpoint, but not applicable to Enrollment's own sanctioned path).

**Roll number normalization.** Surrounding whitespace is trimmed
(`trim()`); a blank result (empty or whitespace-only) throws
`InvalidEnrollmentRollNumberException` before any database write.
Leading zeroes are preserved exactly — `"007"` is stored and returned
as `"007"`, never cast to an integer. No case transformation is
applied (unlike `code` columns via `NormalizesCode`) — Roll Number is
not a `code` column and this codebase's normalization convention does
not extend to it.

**Expected-conflict translation.** The service catches
`UniqueConstraintViolationException` and disambiguates by
`$exception->index` (Laravel's own unique-index-name property — no
message-string parsing):

| Postgres index | Domain exception |
|---|---|
| `student_enrollments_one_active_per_student_year` | `ActiveEnrollmentConflictException` |
| `student_enrollments_school_id_academic_year_id_section_id_roll_` | `DuplicateEnrollmentRollNumberException` |
| (any other/unexpected index) | original exception re-thrown, never mislabeled |

The database constraints remain authoritative — this is a translation
layer only, matching `DuplicateStudentNumberException`/
`DuplicateRelationshipException`'s established precedent. Neither
constraint was removed or weakened.

**Historical placement immutability.** This checkpoint implements
Enrollment *creation* only. There is no `update()` method that can
rewrite `academic_year_id`/`campus_id`/`grade_level_id`/`section_id`
on an existing row — placement movement is always a new row, never an
edit, matching the historical-record principle above. A future
transfer/promotion checkpoint (1B.3+) will implement "close old
Enrollment, create new Enrollment" as an explicit two-step operation
inside one transaction, never a placement-column `UPDATE`.

**Deferred lifecycle status decision (AcademicYear/Section/Campus/
GradeLevel state).** The existing Academic Structure domain (Section,
AcademicYear, Campus, GradeLevel) has no existing precedent anywhere
in this codebase for blocking a *write* into an inactive/closed/
archived reference row — no other Phase 0D module (e.g.
`SubjectOfferingService`) checks a parent's `status` before attaching
a child to it. Phase 1B.2 deliberately does **not** invent such a
check for Enrollment either (inventing a first-of-its-kind cross-entity
status rule here, un-requested by any existing pattern, would be
exactly the kind of hidden product assumption CLAUDE.md rule 2 warns
against). This also deliberately leaves room for legitimate
pre-enrollment into a configured upcoming (non-yet-`active`)
AcademicYear, which the product may need later. If a real requirement
emerges, it belongs in a dedicated checkpoint that reasons about the
change across every Academic Structure child, not a one-off addition
buried in Enrollment's write path.

**Deferred Student status eligibility.** `Student.status` only has two
values today (`active`/`inactive` — see `StudentService::changeStatus()`);
there is no `withdrawn`/`transferred`/`graduated` Student status in this
codebase. No existing rule anywhere restricts which Student statuses
may receive a new Enrollment. Phase 1B.2 does not invent one — inventing
"an inactive Student cannot be enrolled" here would be a hidden product
assumption with no existing precedent to justify it (CLAUDE.md rule 2).
Deferred to whichever future checkpoint actually defines richer Student
lifecycle semantics.

**Authorization boundary.** `StudentEnrollmentService` is deliberately
authorization-neutral, matching every other Application service in this
codebase (`AcademicYearService`, `StudentGuardianRelationshipService`,
...). No capability check exists inside it. A future controller
(Phase 1B.5) must call `Gate::authorize`/`authorizeCapability` with
whatever capability family that checkpoint introduces before ever
reaching this service — no such controller exists yet, so no capability
was added in 1B.1 or 1B.2 (CLAUDE.md rule 24 still applies once that
controller exists).

**Audit.** Every successful `enroll()` call records a `SchoolAuditEvent`
via `AuditRecorder::school()`:

```text
event_type: student_enrollment.created
subject:    the new StudentEnrollment row (subject_type/subject_id
            captured automatically by AuditRecorder)
metadata:   studentId, academicYearId, campusId, gradeLevelId, sectionId
```

`roll_number` is deliberately **excluded** from audit metadata — it
carries no operational value the other ids don't already provide, and
keeping the metadata minimal matches every other Student/Guardian audit
event's "ids and structural facts only" design. No Student name,
date of birth, or Guardian PII is ever included (`docs/security/DATA-CLASSIFICATION.md`).
Lifecycle audit events (`student_enrollment.completed`/`.withdrawn`/
`.transferred`) belong to a future lifecycle checkpoint, not this one.

## Historical record principle

Enrollment is historical data. Promoting a Student **never** overwrites
last year's Enrollment row — it creates a new one. Both remain
queryable:

```text
Student: Aarav Sharma

2026-27  Grade 5  Section A  Roll 12  COMPLETED
2027-28  Grade 6  Section B  Roll 07  ACTIVE
```

Proven in
`StudentEnrollmentTest::a_student_retains_prior_enrollment_history_when_a_new_enrollment_is_created`.
This is what makes future Attendance/Exams/Fees/Transcript history
possible without a later redesign — the same rationale Section's own
"belongs to exactly one AcademicYear" rule already established.

## Active enrollment invariant

**Chosen invariant: at most one `active` Enrollment per Student per
AcademicYear** (not per Campus+AcademicYear).

Rationale: in this codebase's academic model, a Student is placed in
exactly one Section, which already implies exactly one Campus. Nothing
in the existing product model supports a Student being concurrently,
legitimately enrolled at two Campuses within the same AcademicYear —
Campus is a facility a Student physically attends, not an independent
enrollment dimension. Scoping the invariant to AcademicYear alone
(rather than Campus+AcademicYear) is therefore both simpler and
correct for the actual domain, and mirrors `AcademicYear`'s own
"one active per School" partial-unique-index precedent exactly:

```sql
CREATE UNIQUE INDEX student_enrollments_one_active_per_student_year
  ON student_enrollments (student_id, academic_year_id)
  WHERE status = 'active'
```

This is a PostgreSQL-enforced concurrency guarantee, not an
application check-then-insert — two concurrent attempts to create a
second active Enrollment for the same Student/AcademicYear cannot both
commit. Proven in
`StudentEnrollmentTest::a_student_cannot_have_two_overlapping_active_enrollments_in_the_same_academic_year`
and
`StudentEnrollmentTest::a_second_active_enrollment_is_allowed_once_the_first_is_no_longer_active`.

## Roll number

Roll Number is an **Enrollment** attribute, never Student permanent
identity — it is not a replacement for `student_number` and may repeat
across different Students in different Sections/years.

**Chosen uniqueness scope:** `(school_id, academic_year_id, section_id,
roll_number)` — unique via `unique(['school_id', 'academic_year_id',
'section_id', 'roll_number'])`. Never School-wide, never global.
Proven in `StudentEnrollmentTest::roll_number_is_unique_within_the_same_academic_year_and_section`
and `StudentEnrollmentTest::the_same_roll_number_is_allowed_again_in_a_different_section`.

`section_id` is `NOT NULL` in this first slice — every Enrollment has a
concrete Section, matching the chosen Roll Number scope above. A future
"sectionless small School" checkpoint could relax this if a real
product need emerges; it is not invented speculatively here (CLAUDE.md
rule 2).

## Temporal integrity

- `starts_on` is required.
- `ends_on` is nullable (an active Enrollment has no end date yet).
- A CHECK constraint enforces `ends_on IS NULL OR ends_on >= starts_on`
  at the database level (same-day withdrawal is valid, unlike
  `AcademicYear` which needs a genuine multi-day duration). Proven in
  `StudentEnrollmentTest::an_end_date_before_the_start_date_is_rejected_at_the_database_level`.
- No PostgreSQL exclusion/range constraint (`EXCLUDE USING gist`) is
  used for overlap prevention in this first slice — the active-per-year
  partial unique index above already prevents the one overlap scenario
  that matters (two simultaneously `active` rows for the same Student/
  AcademicYear). A richer temporal-overlap model is deferred until a
  real product need justifies the added complexity (CLAUDE.md rule 2;
  matches `AcademicYear`'s own documented "overlap enforced at the
  application layer, not a range constraint" precedent).

## Same-School integrity (RLS + composite FKs)

`student_enrollments` uses `App\Support\Tenancy\TenantRls::enable()`
exactly like every other tenant-owned table (RLS enabled AND forced).
Every one of the five parent references (`student_id`, `academic_year_id`,
`campus_id`, `grade_level_id`, `section_id`) is composite-FK-protected
against `(id, school_id)` on its parent table — a School A Enrollment
can never reference a School B Student, AcademicYear, Campus,
GradeLevel, or Section, enforced by PostgreSQL itself, not application
validation alone. Proven at the raw-SQL level, under the unprivileged
`school_os_app` runtime role, in
`tests/Feature/Postgres/StudentEnrollmentIntegrityTest.php` (RLS
enabled/forced, no-context fails closed, cross-School read/write
blocked, and a dedicated composite-FK-violation test per parent
reference).

## Delete behavior

- `student_id` → `students`: **cascade**. Mirrors
  `student_guardian_relationships`'s precedent — a hard-deleted Student
  removes only its own Enrollment history. Students have no delete
  endpoint today (deactivated via `status`, never deleted); this is
  defensive completeness, not an expected normal operation.
- `academic_year_id`/`campus_id`/`grade_level_id`/`section_id` →
  their respective tables: **restrict**. Matches `Section`'s own FK
  behavior toward those same parents. None of those reference tables
  expose a delete endpoint either (CLAUDE.md rule 73), so this is
  likewise defensive-only.

## Lifecycle transitions (Phase 1B.3)

No generic status setter exists (no
`changeStatus($enrollment, $status)`) — every transition is its own
method on `StudentEnrollmentService` with its own eligibility and date
rules, matching `AcademicYearService::activate()`/`close()`'s
established shape rather than an open-ended workflow framework.

**Transition matrix:**

```text
ACTIVE
 ├── COMPLETED    (complete())
 ├── WITHDRAWN    (withdraw())
 ├── CANCELLED    (cancel())
 └── TRANSFERRED  (transferPlacement(), + a new ACTIVE row elsewhere)
```

`completed`/`withdrawn`/`cancelled`/`transferred` are all **terminal**
— none of them transitions again through the sanctioned service,
including back to `active`. Re-enrollment is always a brand-new
StudentEnrollment row, never a reactivated old one (the historical-
record principle above). Every lifecycle method reloads and
`lockForUpdate()`s the authoritative row inside its own transaction
before evaluating the transition — it never trusts a possibly-stale
`$enrollment->status` the caller already holds — then performs a
conditional `WHERE status = 'active'` UPDATE and checks the
affected-row count, exactly mirroring `AcademicYearService::activate()`'s
"reload, lock, conditionally transition, detect a lost race" pattern.
Proven directly in
`StudentEnrollmentLifecycleTest::the_service_reloads_the_authoritative_row_rather_than_trusting_a_stale_in_memory_status`.
An invalid transition (including a terminal status attempting to
transition again) throws `InvalidEnrollmentTransitionException`.

### Completion

`complete(StudentEnrollment $enrollment, string $endedOn, ?User $actor = null)`
— normal end of an Enrollment's teaching period. Requires the
Enrollment to currently be `active`; `$endedOn` must not precede
`starts_on` (checked before the write, same invariant the database's
own CHECK constraint enforces). Sets `status = 'completed'` and
`ends_on = $endedOn`; every academic placement field
(`academic_year_id`/`campus_id`/`grade_level_id`/`section_id`) and
every Student identity field are left untouched. Records
`student_enrollment.completed`. Deliberately does **not** create the
next AcademicYear's Enrollment, change GradeLevel, allocate a Section,
allocate a roll number, or touch `Student.status` — that orchestration
belongs to a future dedicated rollover checkpoint.

### Withdrawal

`withdraw(StudentEnrollment $enrollment, string $endedOn, ?User $actor = null)`
— the Student left this placement before it ran its normal course.
Same eligibility/date/placement-preservation rules as `complete()`.
Never touches `Student.status` or any Guardian relationship — the
current architectural default is `Student identity != Enrollment
status`; no already-accepted Student-domain rule requires otherwise
today (`Student.status` only has `active`/`inactive`, established in
Phase 1A — there is no `withdrawn`/`transferred`/`graduated` Student
status in this codebase to even set). The row is never deleted.
Records `student_enrollment.withdrawn`.

### Cancellation

`cancel(StudentEnrollment $enrollment, string $endedOn, ?User $actor = null)`
— identical mechanics to `withdraw()`, distinguished only by intent.
No existing code or documentation anywhere in this repository defines
`cancelled` vs `withdrawn` semantics, so this checkpoint adopts the
narrow interpretation below rather than inventing an elaborate
cancellation-reason/approval workflow:

- **withdrawn**: the Enrollment placement WAS operational, and the
  Student subsequently left it.
- **cancelled**: the Enrollment should not continue as an operational
  placement; retained purely as an administrative historical record.

Both require `active → cancelled`/`withdrawn` only, both preserve
placement and Student identity, and neither deletes the row. Records
`student_enrollment.cancelled`.

### Transfer (same-Academic-Year placement move)

`transferPlacement(StudentEnrollment $sourceEnrollment, Section $targetSection, string $rollNumber, string $effectiveDate, ?User $actor = null)`
— moves a Student from their current `active` Enrollment into a
different Section, atomically, as TWO historical rows: the source row
is marked `transferred` (its own `academic_year_id`/`campus_id`/
`grade_level_id`/`section_id` are never rewritten), and a brand-new
`active` row is created for the target Section using the exact same
trusted placement-derivation rule `enroll()` uses (the target Section
is the sole placement input — a caller cannot independently supply a
different AcademicYear/Campus/GradeLevel for the new row than what the
target Section actually has).

**Source/target requirements**, checked before any write:

- **Same School** (`CrossSchoolEnrollmentException` otherwise) — the
  composite FKs/RLS remain the authoritative backstop regardless; this
  is a fail-fast, in-memory check ahead of them, matching `enroll()`'s
  own cross-School check.
- **Same AcademicYear** (`CrossAcademicYearTransferException`
  otherwise) — Phase 1B.3's transfer is an intra-year placement move
  only. A different AcademicYear is promotion/rollover/re-enrollment,
  deliberately deferred (see "Deferred" below) — never a disguised
  transfer.
- **Same GradeLevel** (`IntraYearGradeChangeException` otherwise) —
  this checkpoint's chosen safer default. No existing Academic
  Structure rule anywhere in this codebase either permits or forbids
  intra-year GradeLevel movement, so this checkpoint does not invent
  one; a same-year transfer may change Section/Campus but must never
  silently become an academic promotion/reclassification. A genuine
  GradeLevel change is deferred to a future promotion/reclassification
  checkpoint.
- **Campus**: a same-School, same-year, same-GradeLevel transfer to a
  Section on a *different* Campus IS allowed — there is no separate
  campus-transfer check or path, because the target Section already
  authoritatively owns its Campus; the new row simply derives whatever
  Campus that Section has, exactly like `enroll()` already does for a
  fresh Enrollment.

**Roll number**: normalized (trimmed, blank rejected) exactly like
`enroll()`; a conflict with an existing roll number in the target
Section/AcademicYear is translated to
`DuplicateEnrollmentRollNumberException` via the same
`UniqueConstraintViolationException::$index` disambiguation Phase
1B.2 established.

**Date semantics** — `starts_on`/`ends_on` are both **inclusive**:
`starts_on` is the first calendar date a placement is effective,
`ends_on` is the *last* calendar date it is effective (never the day
it stops). An intra-year transfer with `$effectiveDate` as the first
date the NEW placement is effective therefore sets:

```text
old Enrollment.ends_on   = $effectiveDate minus one calendar day
new Enrollment.starts_on = $effectiveDate
```

so the two placements never overlap and there is no gap between them.
If `$effectiveDate` is on or before the source Enrollment's own
`starts_on` (which would compute a source `ends_on` earlier than its
own `starts_on` — an impossible historical interval), the operation is
rejected with `InvalidEnrollmentDateRangeException` before any write,
the same invariant the database's `ends_on >= starts_on` CHECK
constraint enforces at the storage layer.

**Atomicity**: the entire operation — locking and reloading the source
row, verifying eligibility, computing/validating dates, transitioning
the source row to `transferred`, recording its audit event, and
creating the new `active` row (with its own `student_enrollment.created`
audit event) — runs inside ONE `DB::transaction()`. If creating the new
Enrollment fails for any reason (duplicate roll number in the target
Section, an unexpected active-enrollment conflict, an FK/integrity
failure), the WHOLE transaction rolls back: the source Enrollment is
left exactly as it was before the attempt (still `active`, its
original `ends_on`), no new row is created, and — because
`SchoolAuditEvent` shares the same database connection/transaction as
every other write in this service — the `student_enrollment.transferred`
audit event for the source row's (attempted) transition rolls back
with it too, so no false lifecycle audit record ever survives a failed
transfer. Proven directly in
`StudentEnrollmentLifecycleTest::a_transfer_rolls_back_entirely_when_the_target_roll_number_conflicts`
(and the cross-year/cross-School/cross-grade rejection tests
alongside it), which also proves the connection remains fully usable
immediately afterward — the same `TenantContext::withSchool()`
`DB::transaction()`-wrapping pattern Phase 1B.2 established for
`enroll()` closes the Phase 1B.1 P3 footgun for this write path too,
without any change to `TenantContext` itself.

Inside the transaction, the source row is transitioned OUT of `active`
status BEFORE the new row is inserted — so by the time the new `active`
row's INSERT runs, the Phase 1B.1 partial unique index
(`student_enrollments_one_active_per_student_year`) sees zero
conflicting rows for that Student/AcademicYear pair, and the invariant
("at most one active Enrollment per Student per AcademicYear") is
never violated even momentarily from an external observer's
perspective, since the whole sequence is one transaction.

### Audit events (Phase 1B.3 additions)

```text
student_enrollment.completed
student_enrollment.withdrawn
student_enrollment.cancelled
student_enrollment.transferred
```

Metadata carries only ids/statuses/dates (e.g. `studentId`,
`academicYearId`, `sectionId`, `endsOn`; `transferred` additionally
carries `fromSectionId`/`toSectionId`) — never a Student's name, date
of birth, or Guardian PII, and roll number remains excluded, matching
Phase 1B.2's precedent. A successful transfer also naturally emits the
existing `student_enrollment.created` event for the new row through
the same shared creation path `enroll()` uses — never double-recorded,
since there is exactly one `create()` call per Enrollment row
regardless of which method reached it.

## Authorization (Phase 1B.4)

Two capabilities, following this codebase's established `.view`/
`.manage` convention exactly (never a micro-capability per action):

```text
enrollments.view     View Student Enrollment placement and history
enrollments.manage   Manage Student Enrollment (create, complete, withdraw, cancel, transfer)
```

They are **independent** — `CapabilityResolver` has no
capability-inheritance mechanism (`docs/security/AUTHORIZATION.md`), so
`enrollments.manage` does NOT implicitly grant `enrollments.view` and
vice versa; a role needing both must be granted both explicitly.
Proven in `EnrollmentCapabilityTest::enrollments_view_does_not_imply_enrollments_manage`
and its `_manage_does_not_imply_..._view` counterpart.

**Default grants**: both existing School roles (`school_admin`,
`principal` — the only two School-scoped roles in this codebase's
catalog) receive both capabilities, matching the exact rationale
already used for `students.*`/`guardians.*`: Enrollment/academic
placement is the same kind of day-to-day operational concern for a
Principal as Student/Guardian identity already is. No new role
(`teacher`/`registrar`/`admissions_officer`/...) was invented to make
this look more complete — role design for a delegated,
narrower-than-Principal Enrollment administrator belongs to its own
future roadmap item, not this checkpoint.

**Tenant isolation**: a capability grant is always School-membership-
scoped — `enrollments.view`/`.manage` in School A never implies
anything in School B, and a bare authenticated `User` with no
membership in a School has neither capability there, exactly matching
the existing `CapabilityResolver`/`students.*` precedent. Proven in
`EnrollmentCapabilityTest`'s isolation tests.

**Application services remain authorization-neutral.** Neither
`StudentEnrollmentService` nor `StudentEnrollmentReadService` calls
`Gate::authorize`/`CapabilityResolver`/checks a role name — this is a
deliberate, load-bearing architecture decision (matching
`StudentService`/`AcademicYearService`/every other Application service
in this codebase), not an oversight to fix later. The authorization
boundary is always the future controller:

```text
Read (list/detail/current/history) controller boundary: requires enrollments.view
Mutation (create/complete/withdraw/cancel/transfer) controller boundary: requires enrollments.manage
StudentEnrollmentService: tenant-safe (cross-School/cross-year/cross-grade
  checks + composite FKs + RLS), but authorization-neutral
StudentEnrollmentReadService: tenant-safe (SchoolScope/RLS), but
  authorization-neutral
```

No HTTP controller exists yet (Phase 1B.5), so nothing calls
`Gate::authorize('capability', ['enrollments.view'/'enrollments.manage', $school])`
in production code today — these tests exercise that same Gate/
CapabilityResolver boundary directly, exactly as the future controller
will, mirroring `StudentGuardianCapabilityTest`'s identical approach
from Phase 1A.4.

## Read architecture (Phase 1B.4)

`App\Domain\Students\Application\StudentEnrollmentReadService` is the
canonical read layer — centralizes "what does the current/historical
placement for this Student look like" so a future API/UI layer never
independently rebuilds this query logic. Deliberately internal
application architecture, not yet an API contract: no DTOs, no API
Resources — every method returns a plain Eloquent model, `Collection`,
or `LengthAwarePaginator`, matching `StudentController::index()`/
`show()`'s established shape for Student's own read layer.

**Tenant-safe, not authorization-neutral by omission but by design**:
every method relies entirely on the ambient `SchoolScope`/RLS
protection already active on `StudentEnrollment` (and `Student`, for
the directory's search filters) — it never calls
`TenantContext::withSchool()` itself, matching `StudentController`'s
own read methods (`Student::query()->paginate(...)`, no explicit
context wrapping) rather than the write-service pattern. A caller of
this class is always an already-tenant-resolved request/job; no
`withoutGlobalScopes()`, no `pgsql_admin` connection, no `SET
row_security = off` anywhere in it (verified by direct grep, not
merely asserted).

### Current Enrollment semantics

`currentFor(Student $student, ?AcademicYear $academicYear = null)` —
**never** "the latest row by `starts_on`/`created_at`", and **never** a
historical/terminal row silently substituted for "current". Returns
the `active` Enrollment for the Student within one specific
AcademicYear, or `null` if none exists — it does not guess.

If `$academicYear` is omitted, the School's currently `active`
AcademicYear is resolved via the existing
`CurrentAcademicYearResolver::tryResolve()` (Phase 0D's one
authoritative "find the active year" helper, reused rather than
re-implemented); if the School has no active AcademicYear, `currentFor()`
returns `null` rather than silently falling back to some other year or
guessing at history. Proven in
`StudentEnrollmentReadServiceTest::current_for_returns_null_when_the_school_has_no_active_academic_year_and_none_is_supplied`
and `..._never_substitutes_a_historical_row_when_no_active_enrollment_exists_for_the_year`.

An explicit `$academicYear` argument is always supported (not just the
"no argument = active year" path) — a future UI must let staff inspect
2025-26/2026-27/2027-28 without pretending only the global active year
exists; read logic is never permanently baked around
`AcademicYear.status = active`.

### Historical reads

`historyFor(Student $student)` returns every permitted Enrollment
record for the Student — every status (`active`/`completed`/
`withdrawn`/`transferred`/`cancelled`), never implicitly filtered to
"current". Ordering: `starts_on` ascending, then `created_at` as a
deterministic tiebreaker. `starts_on` alone is sufficient for correct
AcademicYear-chronological order without joining `academic_years` at
all — a later AcademicYear's Enrollments always start later than an
earlier one's, by construction (Enrollment can only be created for
Sections belonging to a real, dated AcademicYear). Relationships
eager-loaded: `academicYear`, `campus`, `gradeLevel`, `section` (not
`student` — the caller already holds it).

### Enrollment detail

`detail(string $enrollmentId)` eager-loads `student`, `academicYear`,
`campus`, `gradeLevel`, `section` — every relationship needed to
explain one Enrollment's historical placement. Deliberately does
**not** eager-load Guardian data (`student.guardians`/
`student.guardianRelationships`) — Enrollment reads stay academically
focused; a future Student-detail UI combining Enrollment with Guardian
information does so by composing two separate reads, not by this
service reaching into Guardian's domain.

### Administrative listing

`directory(array $filters = [], int $perPage = 25)` returns a
`LengthAwarePaginator`. Supported filters — every one an already-
established domain concept, nothing speculative:

```text
academic_year_id, campus_id, grade_level_id, section_id,
status, student_number, student_name, roll_number
```

Filters combine with implicit AND. `student_number`/`student_name`
use `whereHas('student', ...)` — the `student` relation on
`StudentEnrollment` is itself `SchoolScope`/RLS-protected exactly like
`StudentEnrollment` is, so a filter value naming a real row in a
*different* School (a Section id, a Campus id, ...) simply matches
**zero rows** — it can never widen the query or reveal that the
foreign row exists, proven directly in
`StudentEnrollmentReadServiceTest::school_a_read_methods_never_return_school_bs_enrollment`.
No filter accepts or applies a `school_id` value at all — tenant
identity comes entirely from whatever `TenantContext` the caller
already established, never from filter input (the same rule 19
principle applied to the read layer).

**Pagination**: `paginate()`, the same mechanism `StudentController::index()`
already uses — never an unbounded `StudentEnrollment::all()`/`->get()`
administrative listing. Default page size 25, caller-adjustable.
(`historyFor()`'s plain `->get()` is intentionally NOT paginated — it
is scoped to one Student's own history, which is bounded by
construction to at most a few dozen rows over an entire School
career, categorically different from an unbounded cross-Student
directory query.)

**Eager loading / no N+1**: `student` (a **deliberately partial**
column selection — `id, school_id, student_number, first_name,
middle_name, last_name, status`, explicitly excluding
`date_of_birth`), `academicYear`, `campus`, `gradeLevel`, `section`.
Never `guardians`/`contacts`/`subjects`/attendance/fees. Listing N
Enrollments issues a small, constant number of queries (one count +
one page + five eager-load queries) regardless of N — proven directly
in `StudentEnrollmentReadServiceTest::directory_does_not_issue_one_query_per_row`
(10 rows, asserted well under 15 total queries).

### Privacy / data minimization

`date_of_birth` is never pulled into the Enrollment directory by
default — the same privacy boundary Phase 1A's own Student list
(`StudentController::presentSummary()`) already established, extended
here at the query layer itself (a partial-column eager load) rather
than left to a not-yet-built presentation layer. Proven directly in
`StudentEnrollmentReadServiceTest::directory_rows_never_expose_the_students_date_of_birth`
(asserts the key is genuinely absent from the loaded model's
attributes, not merely unused). No Guardian contact information is
ever loaded by any read-service method — structurally guaranteed by
never eager-loading `student.guardians`/`student.guardianRelationships`
anywhere in this class.

### Read-layer immutability

No read method mutates state — no "fix stale placement while reading,"
no automatic status completion, no automatic rollover, no lazy
roll-number correction. Reads are reads.

## Cross-cutting TenantContext flakiness — resolved (Phase 1B.4A)

Verifying Phase 1B.4 surfaced a genuine, intermittent, pre-existing
`TenantContext` cleanup defect (an aborted PostgreSQL transaction
causing a secondary `RESET app.current_school_id` failure to mask the
real original exception) that caused occasional full-suite ordering
flakiness — never reproduced within Phase 1B's own tests specifically,
and not a defect in this module's write paths. It was root-caused and
fixed as a dedicated platform-level checkpoint, Phase 1B.4A — see
`docs/architecture/TENANCY.md` ("TenantContext cleanup and
aborted-transaction safety") for the full mechanism and fix, and the
Phase 1B.4A checkpoint report for the verification evidence (3
consecutive clean full-suite runs, a random-order run, and a 20/20
targeted stress loop, all green).

## Administrative HTTP boundary (Phase 1B.5)

`App\Domain\Students\Http\Controllers\StudentEnrollmentController`
exposes the Enrollment domain above through the repository's existing
authenticated administrative `/api/v1` surface — the same Sanctum +
`school-membership` + capability architecture Phase 1A.5's
`StudentController`/`StudentGuardianRelationshipController` already
established. Deliberately thin: every mutation delegates to
`StudentEnrollmentService`; every non-trivial read delegates to
`StudentEnrollmentReadService`. No Enrollment rule is duplicated in the
controller.

### Authentication and School membership

Every route requires `auth:sanctum` and the `school-membership`
middleware (`App\Http\Middleware\Api\EnsureSchoolMembershipContext`) —
an authenticated central User with no active membership in the routed
School gets `404`, identical to a School that doesn't exist (never
`403` — matches every other `/api/v1/schools/{school}/...` route).

### Capabilities

`enrollments.view` gates every read action; `enrollments.manage` gates
every mutation. They are independent (Phase 1B.4) — `manage` never
implies `view` and vice versa, proven by
`StudentEnrollmentApiTest::enrollments_manage_only_is_still_denied_read_access()`/
`::enrollments_view_only_is_denied_create()`. Mutation routes carry the
`capability:` route middleware AND the controller's own
`AuthorizesCapability::authorizeCapability()` call (defense in depth,
matching every other mutation controller in this codebase); read
actions authorize inline only, matching `AcademicYearController`/
`StudentController`'s own read methods.

### Route inventory

Nested under `/students/{student}` for per-Student reads and creation
(an Enrollment's history/current placement/creation naturally belong
to one Student); flat under `/enrollments` for the administrative
directory/detail/lifecycle actions — the same "nested for index/store,
flat for singular actions" split `StudentGuardianRelationshipController`/
`RoomController`/`AcademicTermController` already established.

| Method | URI | Capability | Purpose |
| --- | --- | --- | --- |
| GET | `/schools/{school}/enrollments` | `enrollments.view` | Directory (`StudentEnrollmentReadService::directory()`) |
| GET | `/schools/{school}/enrollments/{enrollment}` | `enrollments.view` | Detail (`StudentEnrollmentReadService::detail()`) |
| GET | `/schools/{school}/students/{student}/enrollments` | `enrollments.view` | Full history (`StudentEnrollmentReadService::historyFor()`) |
| GET | `/schools/{school}/students/{student}/enrollments/current` | `enrollments.view` | Current placement (`StudentEnrollmentReadService::currentFor()`) |
| POST | `/schools/{school}/students/{student}/enrollments` | `enrollments.manage` | Create (`StudentEnrollmentService::enroll()`) |
| POST | `/schools/{school}/enrollments/{enrollment}/complete` | `enrollments.manage` | `StudentEnrollmentService::complete()` |
| POST | `/schools/{school}/enrollments/{enrollment}/withdraw` | `enrollments.manage` | `StudentEnrollmentService::withdraw()` |
| POST | `/schools/{school}/enrollments/{enrollment}/cancel` | `enrollments.manage` | `StudentEnrollmentService::cancel()` |
| POST | `/schools/{school}/enrollments/{enrollment}/transfer` | `enrollments.manage` | `StudentEnrollmentService::transferPlacement()` |

All five mutation routes carry `throttle:school-api-mutations` and the
`idempotent` middleware (an `Idempotency-Key` header is required) —
every one is a consequential, plausibly-retried state change.

### Tenant-safe id resolution

Path-identified resources (`{student}`, `{enrollment}`) resolve via a
plain `Student::query()->findOrFail($id)` /
`StudentEnrollment::query()->findOrFail($id)` (or the read service's
equivalent) — SchoolScope/RLS already make a foreign-School id `404`,
byte-identical to a random UUID, matching `StudentController`'s own
`show()`/`update()`. Body-supplied cross-references (`section_id` on
create, `target_section_id` on transfer) resolve via
`Rule::exists('sections', 'id')->where('school_id', $school->id)`
followed by `Section::query()->findOrFail(...)` — the exact
`StudentGuardianRelationshipController::store()` pattern for a
caller-supplied sibling-entity id in the request body: a missing id
and a foreign-School id both fail validation with the identical
generic "the selected section id is invalid" message, never
distinguishing the two. Directory/current filter ids
(`academic_year_id`, `campus_id`, `grade_level_id`, `section_id`) are
validated as `uuid` only and passed straight into the already
tenant-safe read-service query — a foreign-School filter value simply
matches zero rows (`directory()`) or `404`s exactly like a random UUID
(`current`'s explicit `academic_year_id`), never revealing whether the
id exists in another School.

### Create input contract

Only `section_id`, `roll_number`, `starts_on` are accepted — the route
`{student}` is authoritative, and Section is the sole authoritative
placement input. `academic_year_id`/`campus_id`/`grade_level_id`/
`school_id`/`student_id` in the request body are silently absent from
`$request->validate()`'s result (Laravel's validator only returns
listed keys) and can never reach `StudentEnrollmentService::enroll()`
— proven by
`StudentEnrollmentApiTest::malicious_redundant_placement_fields_never_override_the_derived_placement()`.
`roll_number` is preserved as a string end-to-end (`"007"` stays
`"007"`, never cast to `7`). The transfer endpoint applies the
identical rule for `target_academic_year_id`/`target_campus_id`/
`target_grade_level_id` against `target_section_id`.

### Response shape and privacy

Directory rows and detail responses share one presenter: `id`, a
minimal `student` summary (`id`, `studentNumber`, `firstName`,
`middleName`, `lastName` — never `dateOfBirth`), `academicYear`/
`campus`/`gradeLevel`/`section` references (`id`/`name`/`code`),
`rollNumber`, `status`, `startsOn`, `endsOn`; detail additionally
includes `createdAt`/`updatedAt`. No Guardian data, no encrypted
contact values, no lookup hashes, no `school_id`, no raw audit events
are ever serialized — proven directly against the JSON response body
in `StudentEnrollmentApiTest::directory_rows_never_expose_sensitive_fields()`,
not just by source inspection. Reads never audit (only the existing
create/lifecycle service events fire).

`current` returns HTTP `200` with `"data": null` when the Student has
no active Enrollment in the resolved/given Academic Year — this is a
valid state, not a not-found error; a `404` is reserved for a
route-identified resource that genuinely doesn't resolve (a bad
`{student}`/`{enrollment}` id, or an explicit but foreign/nonexistent
`academic_year_id` filter on `current`, which fails closed exactly
like a random UUID rather than silently falling back to the School's
active year).

### Error mapping

Every existing Phase 1B domain exception already carries its own
`getStatusCode()`/`errorCode()` (`StudentException`'s shape) and is
rendered automatically by `bootstrap/app.php`'s generic exception
handler — no new mapping, no per-controller `catch`, and no new
exception classes were introduced. All are `422` today (this
checkpoint does not change any exception's existing HTTP status):

| Exception | Code | Trigger |
| --- | --- | --- |
| `CrossSchoolEnrollmentException` | `CROSS_SCHOOL_ENROLLMENT` | Defense-in-depth only — HTTP callers can't reach this since Student/Section are both already tenant-resolved |
| `ActiveEnrollmentConflictException` | `ACTIVE_ENROLLMENT_CONFLICT` | A second `active` Enrollment for the same Student+Year |
| `DuplicateEnrollmentRollNumberException` | `DUPLICATE_ENROLLMENT_ROLL_NUMBER` | Roll number already used in that Section+Year |
| `InvalidEnrollmentRollNumberException` | `INVALID_ENROLLMENT_ROLL_NUMBER` | Blank roll number surviving Laravel's own `required` trim-check (e.g. programmatic callers) |
| `InvalidEnrollmentTransitionException` | `INVALID_ENROLLMENT_TRANSITION` | e.g. `completed → withdraw` |
| `InvalidEnrollmentDateRangeException` | `INVALID_ENROLLMENT_DATE_RANGE` | An end/effective date before the Enrollment's `starts_on` |
| `CrossAcademicYearTransferException` | `CROSS_ACADEMIC_YEAR_TRANSFER` | Transfer target Section in a different Academic Year |
| `IntraYearGradeChangeException` | `INTRA_YEAR_GRADE_CHANGE` | Transfer target Section in a different GradeLevel |

No `SQLSTATE`, constraint/index name, or stack trace ever reaches a
response body — proven directly in
`StudentEnrollmentApiTest` for every conflict case above.

### Transfer atomicity through HTTP

`StudentEnrollmentApiTest::transfer_failure_is_fully_atomic_through_http()`
proves the controller does not break `transferPlacement()`'s
transaction semantics end-to-end: when the target Section already has
the requested roll number, the response is a clean `422`, the source
Enrollment is left exactly `active` with its original (`null`)
`ends_on`, no replacement Enrollment row exists, and no
`student_enrollment.transferred` audit event was recorded for the
source Student.

### Deferred by this checkpoint

- Promotion/rollover, bulk operations, roll-number auto-generation —
  no API surface added for any of them (unchanged from Phase 1B.1-1B.4).
- Admissions, Attendance, Exams, Fees — untouched.
- Vue UI — Phase 1B.6.

## Administrative UI (Phase 1B.6)

`App\Http\Controllers\App\StudentEnrollmentController` is a
session-authenticated Inertia controller (`/app/...`, NOT `/api/v1`)
using the exact same architecture Phase 1A.6's `StudentController`/
`GuardianController` already established: `TenantContext::requireSchool()`
for the active School, `AuthorizesCapability::authorizeCapability()`
for every action, and every mutation delegated to
`StudentEnrollmentService`/every non-trivial read to
`StudentEnrollmentReadService` -- the SAME two services Phase 1B.5's
JSON API controller uses. It never calls the `/api/v1` HTTP surface
internally, and duplicates no Enrollment business rule.

### Page/route inventory

| Method | URI | Inertia component | Purpose |
| --- | --- | --- | --- |
| GET | `/app/enrollments` | `App/Enrollments/Index` | Directory |
| GET | `/app/students/{student}/enrollments/create` | `App/Enrollments/Create` | Enroll form |
| POST | `/app/students/{student}/enrollments` | — (redirect) | Create |
| POST | `/app/enrollments/{enrollment}/complete` | — (redirect) | Complete |
| POST | `/app/enrollments/{enrollment}/withdraw` | — (redirect) | Withdraw |
| POST | `/app/enrollments/{enrollment}/cancel` | — (redirect) | Cancel |
| GET | `/app/enrollments/{enrollment}/transfer` | `App/Enrollments/Transfer` | Transfer form |
| POST | `/app/enrollments/{enrollment}/transfer` | — (redirect) | Transfer |

`App/Students/Show` (Phase 1A.6) gained an "Academic placement"
section (current placement card + full history + inline
Complete/Withdraw/Cancel forms + a Transfer link) -- the existing
Student identity/Guardian sections are otherwise untouched.
`App/Dashboard`'s nav gained an "Enrollments" link
(`nav.canViewEnrollments`).

### Capability-aware rendering, never the only protection

Every route re-checks `enrollments.view`/`enrollments.manage` via
`authorizeCapability()` regardless of what any prop implies (root
CLAUDE.md rule 6: hiding a button is UX only). `StudentController::show()`
includes `currentEnrollment`/`enrollmentHistory`/`canManageEnrollments`
in its Inertia props ONLY when the actor holds `enrollments.view` --
`canViewEnrollments` is always present (`true`/`false`), but the three
other keys are entirely ABSENT (not just falsy) when `false`, so a
user with `students.view` but not `enrollments.view` gets a normal
Student page with zero Enrollment data in the response body, proven
directly against Inertia props in
`StudentEnrollmentUiTest::students_view_without_enrollments_view_sees_the_student_but_no_enrollment_data()`.
`enrollments.manage` never implies `enrollments.view` here either
(Phase 1B.4's independent-capability design, unchanged).

### Create / Transfer input contract

Identical to the JSON API (Phase 1B.5, section "Create input
contract"/"Transfer input contract") -- only `section_id`/
`roll_number`/`starts_on` (create) and `target_section_id`/
`roll_number`/`effective_date` (transfer) are accepted;
`school_id`/`academic_year_id`/`campus_id`/`grade_level_id`/
`target_academic_year_id`/`target_campus_id`/`target_grade_level_id`
in the request body are silently absent from `$request->validate()`'s
result and can never reach the service. `roll_number` inputs use
`type="text"` in Vue, never `type="number"` -- `"007"` round-trips
exactly. Section options are labeled
`"{GradeLevel} · Section {name} · {Campus} · {AcademicYear}"` so
identical Section names across years/campuses/grades are never
ambiguous (`sectionOptions()`, restricted to non-closed Academic
Years/active Sections for create/transfer pickers -- a UI convenience
only, never enforced server-side beyond what the domain already
enforces).

### Domain exception -> Inertia validation error mapping

Every domain exception a user can plausibly trigger here is caught and
re-thrown as Laravel's own `ValidationException` (the
`GuardianController::storeContact()` pattern) so Inertia's
`form.errors` renders it inline, reusing the exception's own
already-safe message verbatim:

| Exception | Field |
| --- | --- |
| `ActiveEnrollmentConflictException`, `CrossSchoolEnrollmentException` (create) | `section_id` |
| `DuplicateEnrollmentRollNumberException`, `InvalidEnrollmentRollNumberException` (create) | `roll_number` |
| `InvalidEnrollmentDateRangeException` (create) | `starts_on` |
| `InvalidEnrollmentTransitionException`, `InvalidEnrollmentDateRangeException` (lifecycle) | `ends_on` |
| `CrossAcademicYearTransferException`, `IntraYearGradeChangeException`, `CrossSchoolEnrollmentException` (transfer) | `target_section_id` |
| `DuplicateEnrollmentRollNumberException`, `InvalidEnrollmentRollNumberException` (transfer) | `roll_number` |
| `InvalidEnrollmentTransitionException`, `InvalidEnrollmentDateRangeException` (transfer) | `effective_date` |

No exception class name, SQLSTATE, or constraint name ever reaches a
session-flashed error string.

### Lifecycle UI

Complete/Withdraw/Cancel are three explicit, separately-labeled inline
forms on the Student page (each collecting exactly one `ends_on`
field) -- there is no generic status `<select>` and no single
"transition" endpoint; each button posts to its own named backend
action, matching CLAUDE.md's "no generic status endpoint" principle
applied identically to this UI. Transfer is a dedicated page (mirrors
`AddGuardian.vue`'s "pick another entity" pattern) with a
`window.confirm()` step before submitting (matching this codebase's
existing `unlink()` confirmation pattern in `Students/Show.vue`) that
explicitly explains the current placement will close and a new one
will open. A failed transfer (e.g. duplicate target roll number) never
optimistically marks the source as transferred -- Inertia's error
redirect re-renders the SAME transfer page with fresh server props, so
the client never diverges from server truth; proved end-to-end in
`StudentEnrollmentUiTest::transfer_failure_leaves_the_source_untouched_and_shows_a_clean_error()`.

### Privacy, accessibility, responsiveness

Every Enrollment Inertia prop (directory rows, Student-page current/
history) excludes `dateOfBirth`/Guardian PII/`school_id`/encrypted
values/lookup hashes -- proven directly against response props, not
just by source inspection. `StatusBadge.vue` was extended from a fixed
active/inactive binary to a keyed style map covering all 5 Enrollment
statuses (`active`/`completed`/`withdrawn`/`transferred`/`cancelled`)
-- existing Student/Guardian active/inactive rendering is
byte-identical to before; status is still never conveyed by color
alone (text label + decorative dot). Every form input has a real
`<label for>`, `aria-invalid`, and an `aria-describedby`-linked error
paragraph, matching `StudentIdentityFields.vue`'s established pattern.
The directory uses a `<table>` with `scope="col"` headers on
desktop/tablet and a card list on mobile, mirroring
`Students/Index.vue` exactly.

## Academic-Year Rollover & Promotion — Architecture Decision (Phase 1B.7)

**Phase 1B.7 was a decision record, not an implementation** — it
changed no production code. Every rule below is either **DECIDED**
(binding on the implementation checkpoints), **DEFERRED** (explicitly
out of scope, to be decided later), or an **OPEN DECISION** (a genuine
product question with no safe default). Phase 1B.7A (below, "Schema &
Domain Foundation") is the first checkpoint that actually implements
part of this decision record — read "Schema & Domain Foundation (Phase
1B.7A)" after this section for exactly which pieces are real today.

### Terminology (DECIDED)

- **Promotion** — one Student's academic placement moves from a source
  AcademicYear's Enrollment to a target AcademicYear's Enrollment,
  normally representing an advance to the next GradeLevel.
- **Rollover** — the controlled, reviewed, bulk *operation* that
  prepares and applies promotion (and repeat/retention/exclusion)
  outcomes for a population of Students between one source and one
  target AcademicYear. A rollover produces many individual promotions
  (or repeats, or explicit exclusions) as its result.
- **Repeat / Retention** — a promotion-plan *outcome*, not a new
  Enrollment status: the target Enrollment's `grade_level_id` equals
  the source Enrollment's `grade_level_id` (the Student stays at the
  same GradeLevel), possibly in a different Section/Campus.
- **Transfer** (Phase 1B.3, unchanged) — a same-AcademicYear placement
  move (`StudentEnrollmentService::transferPlacement()`). Rollover
  NEVER reuses `transferPlacement()` — a cross-year move is always a
  new Enrollment row created by `enroll()`, exactly like
  `CrossAcademicYearTransferException`'s own docblock already states
  ("a different AcademicYear is promotion/rollover, not a transfer").

### Current academic model (as verified in this codebase today)

**AcademicYear** (`App\Domain\AcademicStructure\Infrastructure\AcademicYear`,
`App\Domain\AcademicStructure\Application\AcademicYearService`):
- Status is `draft → active → closed` (the model's own docblock also
  lists `archived`, but no service method transitions any year to
  it today — this is a pre-existing minor doc/code inconsistency,
  not something this checkpoint touches or resolves).
- Exactly one `active` AcademicYear per School, DB-enforced by a
  partial unique index (`academic_years_one_active_per_school`) —
  `AcademicYearService::activate()` closes whatever was previously
  active in the same transaction.
- **A year can be created and exist in `draft` status, with no
  activation, indefinitely, and multiple `draft` years can coexist**
  (the partial unique index only constrains `status = 'active'`) —
  confirmed by reading `AcademicYearService::create()`/`activate()`
  directly. A School can therefore create/configure next year's
  AcademicYear (and its Sections) while this year is still active.
  This is exactly the precondition Model B below depends on.
- **AcademicYears cannot overlap within a School** —
  `AcademicYearService::assertNoOverlap()` rejects any new year whose
  `[starts_on, ends_on]` interval overlaps an existing one, checked at
  creation time. This answers CLAUDE.md/brief section 20 directly:
  non-overlapping is guaranteed, but **non-overlapping does not mean
  contiguous** — a School's calendar can still have a gap between
  `source.ends_on` and `target.starts_on` (e.g. a summer break), so
  `target.starts_on := source.ends_on + 1 day` must NEVER be assumed.

**GradeLevel** (`App\Domain\AcademicStructure\Infrastructure\GradeLevel`):
- School-wide reference data, NOT AcademicYear-scoped (the same
  GradeLevel row is reused across every year).
- Has an explicit `sequence` integer ("never inferred from name/code"
  per its own docblock) — but `sequence` is a same-year *display/sort*
  ordering convention only. **No code anywhere in this repository
  today treats `sequence + 1` (or any other GradeLevel relationship)
  as "the next GradeLevel for promotion purposes."** There is no
  `next_grade_level_id` column or equivalent.
- No cross-year lineage concept exists for GradeLevel at all (it does
  not need one — it is not year-scoped).

**Section** (`App\Domain\AcademicStructure\Infrastructure\Section`):
- Belongs to exactly one AcademicYear, one Campus, one GradeLevel
  (composite-FK protected, Phase 0D). "Grade 5 A" in 2026-27 and
  "Grade 5 A" in 2027-28 are permanently distinct rows.
- Uniqueness is `(school_id, academic_year_id, campus_id,
  grade_level_id, code)` — confirmed via the constraint name
  `sections_school_id_academic_year_id_campus_id_grade_level_id_co`
  encountered directly during Phase 1B.5/1B.6 test-writing.
- `capacity` is nullable and **advisory only — "no admission-blocking
  logic reads it yet"** per the model's own docblock. **NOT
  AVAILABLE — FUTURE CAPACITY FEATURE.** Rollover dry-run in the first
  implementation MUST NOT invent capacity enforcement.
- **No cross-year lineage field exists on Section at all** — nothing
  links "2026-27 Grade 5 A" to "2027-28 Grade 6 A" or "2027-28 Grade 5
  A (repeat)". Confirmed by reading the model, its migration, and
  every Academic Structure service; a repository-wide search for
  `promote`/`promotion`/`rollover`/`section mapping`/`next grade`/
  `graduate(d)` found zero existing implementation — only forward
  references in comments deferring exactly this work (e.g.
  `StudentEnrollmentService`'s docblock, `CrossAcademicYearTransferException`,
  `IntraYearGradeChangeException`).

**Student** (`App\Domain\Students\Infrastructure\Student`): `status` is
`active`/`inactive` only — no `graduated`/`alumni`/`promoted` value
exists, and `InvalidStudentStatusException`'s message explicitly lists
only those two as supported.

**Domain ownership** (`docs/architecture/DOMAIN-MAP.md`): rollover/
promotion is squarely inside the existing **Students/SIS** module row
("Student master record, enrollment status, **academic history**"),
depending only on Academic Structure/Schools/Campuses — all
dependencies it already has. **No new top-level domain/module is
warranted;** every future class lives under `App\Domain\Students\...`
alongside `StudentEnrollmentService`/`StudentEnrollmentReadService`.

**ADR precedent**: every existing ADR (`docs/architecture/adr/0001`-`0028`)
is a cross-cutting *platform* decision (tenancy, storage, webhooks, AI,
secrets...); no ADR documents a single module's business rules —
those live in the module's own doc (e.g. this file's existing
"Lifecycle transitions", "Same-School integrity" sections). Rollover
is a Students/SIS business-domain decision, so it belongs here, not in
a new ADR — consistent with existing repository precedent.

### Recommended architecture: Option B — persistent plan + items (DECIDED)

Three options were compared:

| | **A. Stateless bulk service** | **B. Persistent plan + items** | **C. Ad hoc per-request script** |
| --- | --- | --- | --- |
| Data integrity | OK for small schools, fragile at scale | Strong — every item's state is durable | Weak |
| Auditability | Only via generic Enrollment events | Rich — plan/item provenance, reasons, timestamps | Poor |
| Operator review | Must recompute preview every time | Dry-run persists for review/edit before execution | None |
| Idempotency | Must be reconstructed per request | Item → `target_enrollment_id` is a natural idempotency key | None |
| Resumability | None — a crash mid-batch loses progress | Each item's own row survives a crash | None |
| Concurrency safety | Hard to detect staleness between preview and execute | Snapshot fields + revalidation at execution, same pattern as `webhook_deliveries` | Hard |
| Complexity (first release) | Lower | Higher, but bounded | Lowest, unacceptable for production risk |
| Future queue compatibility | Awkward retrofit | Natural — items are already the queue payload's unit of work | None |

**Decision: Option B.** A School-wide, potentially 4-figure-Student
bulk operation with real financial/academic consequences (wrong Grade,
lost placement, duplicate Enrollment) needs the same "durable unit of
work + explicit review + safe retry" shape this codebase already
uses for exactly this class of problem: `webhook_deliveries` +
`webhook_delivery_attempts` (CLAUDE.md sections 38-49) — one row per
logical item, an atomic processing-lease claim (conditional `UPDATE`,
never check-then-act), append-only history, and a fixed failure
classification. Option A's per-request recomputation cannot survive a
crash or support a "resolve blockers, come back tomorrow, execute"
workflow a real school year-end needs. Option C (no persistence) was
never seriously viable for a mutation this consequential and is
rejected outright.

**Persistent plan: YES.** Conceptual entities (names illustrative, not
approved column lists — the actual migration is 1B.7A's job):

- **`EnrollmentRolloverPlan`** — one row per rollover operation: School,
  source AcademicYear, target AcademicYear, status (see lifecycle
  below), actor/timestamps, and a configuration snapshot (default
  GradeLevel mapping, default roll-number strategy).
- **`EnrollmentRolloverItem`** — one row per (plan, Student): the
  resolved source Enrollment, the proposed target Section/roll number/
  decision (promote/repeat/exclude/manual), the last dry-run's result
  code + reason, and — once executed — the resulting
  `target_enrollment_id`. This is the idempotency anchor (section
  "Idempotency" below).

Both are School-owned, RLS-protected (`App\Support\Tenancy\BelongsToSchool`
+ `TenantRls`, exactly like every other tenant-owned table — CLAUDE.md
sections 17-18), with composite FKs from `EnrollmentRolloverItem` to
`(id, school_id)` on Student/AcademicYear/Section, the same pattern
`membership_role_assignments`/`student_enrollments` already established
(CLAUDE.md section 70). No caller-supplied `school_id` anywhere in this
design — same as every other module in this codebase.

### Source Enrollment selection (DECIDED)

For one Student and the plan's source AcademicYear, the authoritative
source row is resolved as:

```
rows := StudentEnrollment WHERE student_id = ? AND academic_year_id = source_year
          AND status IN ('active', 'completed')
```

- **Exactly one row** → that is the authoritative source (subject to
  further eligibility checks below).
- **Zero rows** → NOT eligible for this plan (either the Student was
  never enrolled in the source year, or every row for that year is
  `withdrawn`/`cancelled`/`transferred` — a `transferred` row is
  explicitly EXCLUDED from selection here: it is a superseded
  historical artifact, never the authoritative row, per Phase 1B.3's
  own transfer semantics. The row that *replaced* it, if any, is what
  this query already finds instead).
- **More than one row** → this is an **anomalous history** (Phase
  1B.1's DB constraints only guarantee at most one `active` row per
  Student/year — nothing prevents two independently-created
  `completed` rows for the same Student/year in a pathological case).
  The dry-run MUST flag this `MANUAL_REVIEW`, never silently pick
  "the latest" — this directly addresses brief section 37's callout.

**Eligibility matrix** (source status → rollover-eligible):

| Source status | Eligible? | Reasoning |
| --- | --- | --- |
| `active` | **Yes** | The normal "prepare next year while this year is still running" case (Model B). |
| `completed` | **Yes** | The normal "complete this year's placement first, then roll over" case — `complete()`'s own docblock ("the academic year's teaching period genuinely ended") makes this an equally valid, arguably more natural precondition. |
| `withdrawn` | **No** (default) | The Student left before the year ended; automatically re-enrolling them next year would silently override that decision (brief section 36). A future manual re-enrollment is a distinct, explicit workflow, not a rollover default. |
| `cancelled` | **No** | Never an operational placement; nothing to roll forward. |
| `transferred` | **No, as a source row** | Superseded by definition — see selection rule above; it is never itself picked, and the plan does not need a separate rule to exclude it beyond the `status IN ('active','completed')` filter. |

### Source completion semantics (DECIDED)

**Rollover creates the target Enrollment; it never mutates the source
Enrollment's lifecycle status.** Concretely: `EnrollmentPromotionService`
(name illustrative) calls `StudentEnrollmentService::enroll()` for the
target row and nothing else — it never calls `complete()`/`withdraw()`/
`cancel()` on the source as part of promotion.

Why, evaluated against the three options the brief poses:

- **(A) Complete source immediately at rollover time** — rejected.
  Schools legitimately prepare next year's classes while the current
  year is still in session (this is exactly why per-year `active`
  uniqueness was designed the way it was, and why `currentFor()` is
  AcademicYear-aware rather than "latest active row globally").
  Force-completing the source the moment a target row is prepared
  would mark a still-ongoing placement "completed" while the Student
  is still attending — actively wrong for any future module (Attendance,
  Exams) that reads "is this Enrollment operationally current" for
  today's date.
- **(B) Source remains active/whatever it already is; completion is
  independent** — **adopted.** This is trivially safe because the
  domain already supports source(`active`, 2026-27) and
  target(`active`, 2027-28) coexisting (per-year uniqueness, not
  global). It requires zero new orchestration and zero new invariant.
- **(C) Completion handled by a future AcademicYear-closure feature**
  — compatible with (B), not a competing option: whenever that future
  feature exists, it will call the SAME `StudentEnrollmentService::complete()`
  every other caller uses; rollover does not need to know about it or
  wait for it.

**Precondition this implies:** the target AcademicYear and its target
Sections must already exist (created through the ordinary, already-
accepted Academic Structure setup flow) before a rollover plan can
reference them. Rollover creates Enrollments, never AcademicYears/
GradeLevels/Sections.

### Grade progression (DECIDED: explicit, per-plan mapping — never inferred)

**No automatic "next Grade."** `sequence` is not treated as a
promotion contract (see "Current academic model" above) — using it
would be exactly the "infer promotion from Grade names/codes" anti-
pattern the brief explicitly forbids, just one layer more sophisticated
(numbers instead of strings). A future implementation MAY offer
`sequence + 1`'s GradeLevel as a **pre-filled UI suggestion** for a
human to confirm or change (a pure UX convenience, decided at 1B.7F,
not now) — but the plan's own explicit GradeLevel mapping is always
what is stored and executed, never a live re-derivation.

The plan stores an explicit **source GradeLevel → target GradeLevel**
mapping (a small per-plan table/config, keyed by source GradeLevel),
with two supported outcomes per source GradeLevel:

- **Promote** — target GradeLevel differs from source (the normal
  case).
- **Repeat/retain** — target GradeLevel equals source GradeLevel
  (explicitly configured, never a fallback/default when a mapping is
  simply missing — a missing mapping is a blocking validation error,
  never silently treated as "repeat").

**Terminal grade**: a source GradeLevel with no configured target
mapping at all produces `TERMINAL_GRADE` for every Student in it — no
target Enrollment is created, and this is not an error; it is an
expected outcome for a School's highest Grade. This checkpoint does
**not** invent a Student "graduated"/"alumni" status (Student.status
stays `active`/`inactive` — see "No new Enrollment status"/"No Student
status abuse" below); what happens to a Student's identity after
their terminal Grade (alumni tracking, deactivation, etc.) is
explicitly deferred to a later, separate checkpoint.

### Section mapping (DECIDED: explicit, per-plan, Section-authoritative)

**No automatic mapping by Section name.** Confirmed no cross-year
Section lineage exists in the schema (see above) — Sections are
reorganized freely year to year in real schools, so "Section A → Section
A" by name would be actively unsafe.

The plan stores an explicit **source Section → target Section**
mapping as its default per (source GradeLevel, target GradeLevel)
pair, and the target Section remains the single authoritative
placement input exactly as it already is for `enroll()`/
`transferPlacement()` (CLAUDE.md's Section-authoritative-placement
principle, unchanged) — the target's AcademicYear/Campus/GradeLevel
are always read FROM the target Section, never independently stored/
trusted on the plan item. Cross-campus promotion is supported for
free by this same rule (the target Section simply may belong to a
different Campus — no separate "Campus mapping" concept needed,
mirroring how Transfer already allows a Campus change via Section
alone).

**Per-Student override** (brief section 13/35): the plan-level
default mapping is only a default. Each `EnrollmentRolloverItem` can
independently override:
- decision (`promote` / `repeat` / `exclude` / `manual_review`),
- target Section,
- target roll number.

An override never rewrites the mapping in a way the domain doesn't
already support — it just picks a different (already valid) target
Section for that one Student. Any override triggers revalidation of
that item (never a stale cached "READY" after an edit).

**Explicitly NOT overridable** once a plan exists: Student identity,
the resolved source Enrollment, the source AcademicYear, and the
target AcademicYear (changing the target year is a different plan,
not an edit to this one).

### Roll Number strategy (DECIDED: explicit or preserve-current; no auto-generation)

Evaluated against the brief's four conceptual strategies, the
narrowest safe first release is **A + B combined, never C or D**:

- **Preserve source Roll Number** is the default suggestion per item
  (pre-filled from the source Enrollment), because it is the
  least-surprising behavior and requires no school-specific policy
  knowledge.
- **Explicit target Roll Number** is always accepted as an override
  (required whenever the preserved value would collide, or whenever
  the operator simply wants a different one).
- **No silent auto-generation** (alphabetical, sequence-based, or
  Student-Number-as-Roll-Number) in the first release — Roll Number
  policy varies too much across Indian schools (admission order,
  custom historical numbering, house-based schemes...) to hardcode
  safely, and CLAUDE.md's roll-number rules (22, 55) already establish
  that Roll Number is always caller-owned text, never system-derived.
  A future **configurable generation policy** (brief strategy C/D)
  is explicitly deferred, not rejected — it needs its own product
  decision about which policy(ies) to support.

**Conflict detection (dry-run, mandatory):** for every target Section,
the set of proposed `(target_section_id, roll_number)` pairs across
ALL items in the plan must be checked for (a) duplicates among
themselves, and (b) collision with any `StudentEnrollment` row already
persisted for that Section/year/roll-number combination — the exact
`student_enrollments_school_id_academic_year_id_section_id_roll_`
constraint's application-layer mirror, checked before execution, not
discovered as a raw constraint violation.

### Dry-run (DECIDED: mandatory, read-only, persists only plan/item metadata)

Dry-run computes and stores, per item, a result code and reason — it
**never** creates/updates/deletes a `StudentEnrollment`, touches
`Student`, or touches `AcademicYear`. The only writes a dry-run makes
are to the plan/item rows themselves (their own validation-result
columns) — a failed or repeated dry-run has zero Enrollment-lifecycle
side effects, satisfying brief section 44 exactly.

Result-code taxonomy (illustrative; exact enum values are 1B.7B's job,
not fixed here) grouped by category:

| Category | Example codes | Meaning |
| --- | --- | --- |
| Ready | `READY` | No blockers; safe to execute as configured. |
| Blocking | `INELIGIBLE_STATUS`, `MISSING_SOURCE`, `MULTIPLE_SOURCE_CANDIDATES`, `MISSING_GRADE_MAPPING`, `TERMINAL_GRADE`, `MISSING_TARGET_SECTION`, `CROSS_SCHOOL_REFERENCE`, `ROLL_NUMBER_CONFLICT`, `INVALID_DATE` | Execution must refuse this item until resolved. |
| Review (non-blocking but not auto-ready) | `ALREADY_ENROLLED_MATCHES`, `MANUAL_REVIEW` | `ALREADY_ENROLLED_MATCHES` can execute as a no-op (idempotent); `MANUAL_REVIEW` requires an explicit human decision (e.g. anomalous source history) before it can become `READY`. |
| Conflict | `ALREADY_ENROLLED_DIFFERS` | An existing target Enrollment does not match the plan's proposal — never auto-resolved, never overwritten. |
| Excluded | `EXCLUDED` | Operator explicitly excluded this Student from this plan (e.g. already handled another way). |

Plan-level summary counts (`total`/`ready`/`blocked`/`review`/`excluded`)
are derived from item result codes, never separately maintained state
that could drift from the items themselves.

### Staleness detection (DECIDED)

A dry-run result is a snapshot, not a guarantee — it can go stale
between review and execution (brief sections 26, 53, 54). Detection
strategy: **each item stores a snapshot of the source Enrollment's
identity + status + `updated_at`** (and the target Section's identity
+ status) at dry-run time. Execution re-fetches the current source
Enrollment and target Section fresh, inside the same transaction as
the write (`lockForUpdate()`, exactly like `StudentEnrollmentService`'s
own lifecycle methods already do), and compares against the snapshot:

- **Source transferred/withdrawn/cancelled since dry-run** → item
  fails closed as stale, re-flagged, never silently promoted from the
  now-superseded snapshot.
- **Target Section became inactive** → item fails closed.
- **A target Enrollment now exists that didn't at dry-run time**
  (concurrent manual enrollment, brief section 53) → re-run the
  "already enrolled" check fresh (see below) rather than trusting the
  stale "no target exists" snapshot.
- **Roll Number now collides** (another item's execution, or a manual
  create, landed first) → `StudentEnrollmentService::enroll()`'s own
  `DuplicateEnrollmentRollNumberException` translation is the final
  backstop even if the pre-execution revalidation somehow missed it.

**Dry-run passing yesterday is never sufficient on its own** — the
plan lifecycle (below) requires re-validation after any edit, and
execution always performs its own fresh preflight regardless of when
the plan was last validated.

### Plan lifecycle (DECIDED, minimal)

```
draft → validated → executing → completed
                                → completed_with_errors
        (any edit)      ↘
             └── back to draft (re-validation required)
draft/validated → cancelled
```

- **`draft`** — being configured (mappings, per-item overrides). No
  dry-run result is trusted yet.
- **`validated`** — the most recent dry-run's results are current for
  the plan's present configuration. Any edit to the plan or any item
  immediately invalidates this (reverts to conceptually `draft` for
  that item, or the whole plan — 1B.7A's exact granularity decision,
  not fixed here) — this directly satisfies brief section 25's
  "editing mappings after validation should invalidate the previous
  dry-run."
- **`executing`** — execution has started; the plan's configuration
  becomes immutable (brief section 50) — corrections happen through
  ordinary Enrollment workflows or a new plan, never by rewriting an
  executing/executed plan.
- **`completed`** / **`completed_with_errors`** — terminal; retained
  as an operational/audit record (brief section 49 — no retention
  duration invented, no hard-delete).
- **`cancelled`** — an operator abandoned the plan before executing;
  retained, not deleted.

No unnecessary intermediate states (e.g. no separate "reviewed"
state distinct from "validated" — reviewing IS validating in this
design).

### Atomicity, idempotency, resumability (DECIDED)

- **Batch transaction: NO.** A whole-School single transaction across
  potentially thousands of Students is rejected outright (brief
  section 28) — lock duration, rollback cost, and contention make it
  unsuitable, and it provides no partial-progress story at all.
- **Per-item transaction: YES.** Each `EnrollmentRolloverItem`'s
  execution is exactly one `DB::transaction()` that (a) re-validates
  the snapshot, (b) calls `StudentEnrollmentService::enroll()` for the
  target row, and (c) records the resulting `target_enrollment_id` and
  a terminal item status, all inside that same transaction — mirroring
  `StudentEnrollmentService::transferPlacement()`'s own "everything or
  nothing for this one unit of work" shape. This directly answers
  brief section 29: it is structurally impossible for this design to
  produce "target created without recording it" or "source touched
  without a target" (the source is never touched at all — see above).
- **Chunking**: execution processes items in bounded chunks (exact
  size is an implementation parameter, not an architecture question),
  the same shape `ProcessOutboxEventJob`/webhook redispatch already
  use for "don't hold one giant unit of work."
- **Failure behavior**: one item's failure marks that item
  `failed`/`review` and the batch continues to the next item — a
  single Student's roll-number collision must never abort promotion
  for the other 400 Students in the same plan. This is bounded,
  visible in the plan's summary counts, and never silently swallowed.
- **Idempotency**: `EnrollmentRolloverItem.target_enrollment_id` IS the
  idempotency anchor — before executing an item, check whether it
  already has one:
  - **has one already** → skip (already executed; this is what makes
    a retried/duplicate execution request safe — brief section 52).
  - **none yet, but a matching target Enrollment now exists anyway**
    (the "already enrolled" reconciliation from "Dry-run" above,
    including the natural case where `StudentEnrollmentService::enroll()`
    itself throws `ActiveEnrollmentConflictException` because a prior
    partial run already created it) → record that Enrollment's id as
    this item's `target_enrollment_id` and mark it succeeded, rather
    than treating the domain's own natural uniqueness guarantee as a
    hard failure. This turns `ActiveEnrollmentConflictException` from
    "unexpected error" into "expected idempotent outcome," exactly
    the way `WebhookSubscriptionController::destroy()`'s "deleting an
    already-deleted relationship 404s harmlessly" turns a natural
    domain guarantee into safe retry behavior.
  - **none yet, and no matching target exists** → execute normally.
- **Plan-level double-execute guard**: an `executing`/`completed`
  plan cannot be re-submitted for execution (the plan `status` column
  itself is that guard, checked with the same "conditional UPDATE,
  never check-then-act" discipline `AcademicYearService::activate()`
  already established) — PostgreSQL uniqueness remains the final
  backstop underneath this, never the whole contract on its own
  (brief section 52).
- **Resumability**: because each item durably records its own
  terminal state (`succeeded`/`failed`/`review`) and its
  `target_enrollment_id`, a crashed/restarted execution run simply
  re-scans "items in this plan not yet `succeeded`" — no in-memory
  batch state is ever the only record of what happened, directly
  satisfying brief section 51.

### Concurrency (DECIDED)

| Race | Handling |
| --- | --- |
| Source Enrollment locking | `lockForUpdate()` on the source row during per-item execution (same primitive `StudentEnrollmentService`'s own lifecycle methods already use) |
| Target Enrollment race (two executions of the same item) | The idempotency anchor above + `ActiveEnrollmentConflictException` reconciliation |
| Roll Number race | PostgreSQL's `student_enrollments_school_id_academic_year_id_section_id_roll_` unique constraint is the final backstop; `DuplicateEnrollmentRollNumberException` translation already exists and needs no new code |
| AcademicYear state race (e.g. someone closes/reactivates the target year mid-execution) | Execution re-checks the target AcademicYear's current status as part of its fresh preflight (not just the dry-run snapshot) — no lock is taken on `AcademicYear` itself; that would risk contending with unrelated, already-existing AcademicYear operations, so revalidate-and-fail-closed is preferred over locking a resource this module does not own the lifecycle of |

**Existing platform risk, not caused by this checkpoint**: Phase 1B.4A
documented an intermittent `AcademicYearActivationConcurrencyTest`
process-scheduling flakiness (a pre-existing P2). This architecture
gate does not touch `AcademicYearActivationService`/its test, and
nothing in this design requires activating/closing an AcademicYear as
part of rollover execution (see "Academic Year activation" below) — so
this risk is unchanged, not newly introduced, and remains tracked
under its original P2, not re-classified here.

### Academic Year activation (DECIDED: not coupled)

Rollover execution never activates or closes an AcademicYear itself.
The intended real-world sequence is: prepare the rollover plan for the
still-`draft` (or already-`active`) target year → validate → execute
(creating target Enrollments) → activate the target year later,
whenever the School is ready, through the existing, independent
`AcademicYearService::activate()` — completely decoupled in time from
when target Enrollments were prepared. This is safe precisely because
`enroll()` never required the target AcademicYear to be `active` in
the first place (nothing in `StudentEnrollmentService::enroll()`
checks `AcademicYear.status`).

### Authorization (RECOMMENDATION ONLY — not seeded in this checkpoint)

`enrollments.manage` is necessary but arguably not sufficient on its
own: a single Enrollment edit and a 2,000-Student bulk rollover are
very different blast radii, and CLAUDE.md's own capability philosophy
(role names are never authorization; capabilities are; least privilege
throughout) supports distinguishing them. **Recommendation:** a
dedicated `enrollments.rollover.manage` capability (naming mirrors
`integrations.webhooks.manage`'s own narrower-than-parent-domain
precedent), required in ADDITION to `enrollments.manage` for every
rollover-plan/execution action, granted to `school_admin`/`principal`
by default in the same seeder as every other Phase 1B capability. This
is a recommendation for 1B.7E to implement, if accepted — **no
capability row, migration, or seeder change was made in this
checkpoint.**

### Audit (DECIDED)

Reuses `AuditRecorder::school()` exactly as every other module does —
no new audit mechanism. Plan-level events (`enrollment_rollover_plan.created`,
`.validated`, `.execution_started`, `.completed`, `.cancelled` —
illustrative names) carry the plan id and summary counts. Item-level
events reuse the ALREADY-EXISTING `student_enrollment.created` event
`StudentEnrollmentService::enroll()` fires today — rollover does not
need a parallel "promotion succeeded" audit event for the Enrollment
mutation itself, only for the promotion-specific *decision* (which
plan/item produced it), avoiding duplicate audit noise (brief section
41). A failed/skipped item is audited at the plan-item level (id +
result code), never with Student PII in metadata — IDs and result
codes only, matching every other audit call in this codebase.

### Multi-tenancy (DECIDED)

No new pattern — `BelongsToSchool` + `TenantRls` on both new tables
(CLAUDE.md sections 17-18), composite FKs from `EnrollmentRolloverItem`
to `(id, school_id)` on every referenced Student/AcademicYear/Section
(CLAUDE.md section 70's established pattern), and `school_id` never
accepted from request input anywhere in the future controller (CLAUDE.md
section 19). A plan's source/target AcademicYear, every item's Student,
source Enrollment, and target Section must all resolve to the SAME
School — enforced the same way Phase 1B.2's `CrossSchoolEnrollmentException`
already enforces it for a single Enrollment, applied per item.

### Execution model: synchronous vs. queued (RECOMMENDATION ONLY)

Given School OS already has queue infrastructure (Redis, existing job
patterns), and this design's items are already the natural unit of
queued work: **recommend a size threshold below which execution can
run synchronously in the request** (a small School, tens of Students)
**and above which it must be queued** (hundreds to thousands of
Students) — the exact threshold is an implementation-time tuning
decision, not an architecture question, and nothing above requires
choosing it now: a per-item-transaction, resumable-by-durable-state
design moves to a queue with zero change to the domain semantics
(a queued worker just calls the same "execute one item" primitive a
synchronous loop would). **No job class is created in this checkpoint.**

### Future UI flow (DESCRIPTION ONLY — no Vue changed)

```
Academic Years
  → Rollover / Promotion
    → Select source Year, select/create target Year
    → Configure GradeLevel mapping (promote/repeat) + default Section mapping
    → Review per-Student exceptions/overrides
    → Dry run
    → Resolve blockers (missing mappings, roll-number conflicts, manual-review items)
    → Review summary (counts: ready/blocked/review/excluded)
    → Explicitly execute (high-risk confirmation, shows affected count)
    → Results (succeeded/failed/review, retryable)
```

Individual (single-Student) promotion should reuse the SAME
`EnrollmentPromotionService`/per-item primitive a bulk plan uses
internally — e.g. a one-item plan, or a direct call to the same
underlying method — never a second, incompatible "promote one
Student" implementation. CSV import of target Section/roll number/
decision is future work that would populate plan items through the
same item model, not a parallel input path. None of this is built now.

### No new Enrollment status / no Student status abuse (DECIDED)

Promotion-plan outcomes (`promote`/`repeat`/`exclude`/`manual_review`)
live ONLY on `EnrollmentRolloverItem.decision` — `StudentEnrollment.status`
gains no new value (`promoted`/`pending_promotion` etc. are explicitly
rejected) and `Student.status` is never used to represent rollover
progress. Every Enrollment produced by rollover is an ordinary `active`
row created by the ordinary `enroll()` path — indistinguishable, from
the Enrollment table's own perspective, from one created by a single
manual enrollment through the existing UI.

### Options rejected

| Option | Why rejected |
| --- | --- |
| Stateless bulk service (Option A) | No durable review/edit step, no crash resumability, no natural idempotency anchor — unacceptable for a whole-School mutation. |
| Ad hoc per-request script (Option C) | No persistence, no auditability, no safe retry — not production-grade for this risk class. |
| Infer target GradeLevel from `sequence + 1` | `sequence` was never established as a promotion contract; silent inference from any numeric/name proximity is exactly the anti-pattern the brief forbids. |
| Infer target Section from matching name | No cross-year Section lineage exists in the schema; schools reorganize Sections yearly. |
| Auto-generate Roll Numbers (alphabetical/sequential) | Roll Number policy is School-specific and out of this module's authority to assume; CLAUDE.md already establishes Roll Number as always caller-owned text. |
| Complete source Enrollment automatically at rollover time | Would falsely mark a still-ongoing placement "completed" when rollover is prepared ahead of year-end (the common real-world case this design is built to support). |
| Couple execution to AcademicYear activation | Would force activation timing decisions onto the rollover feature that belong entirely to the independent, already-accepted `AcademicYearService`. |
| One whole-School transaction for execution | Lock duration/rollback cost/contention unacceptable at real School scale; no partial-progress story. |

### Open decisions (genuine product questions, not implementation gaps)

1. **Exact `enrollments.rollover.manage` capability name and default
   role grants** — recommended above, but naming/grant-set is a
   product call for whoever owns the capability catalog, not something
   this gate can finalize alone.
2. **Whether/when a future "AcademicYear closure" feature should
   *offer* to bulk-complete that year's remaining `active` Enrollments**
   — explicitly out of scope for rollover itself (see "Source
   completion semantics"), but a real open question for a later
   checkpoint that this gate deliberately does not answer.
3. **Whether GradeLevel should eventually gain an explicit
   `next_grade_level_id`/similar canonical relationship** (which would
   let a future UI suggest mappings with more confidence than
   `sequence + 1`) — a schema question for Academic Structure, not
   Students/SIS, and not required for a safe first rollover
   implementation (explicit per-plan mapping works without it).
4. **Roll-Number auto-generation policy** (which policy, or policies,
   a future configurable generator should support) — deferred, not
   designed, because no default is safe without a specific product
   decision about which Indian-school numbering convention(s) to
   support first.
5. **The exact size threshold for synchronous vs. queued execution** —
   an implementation/ops tuning parameter, left to 1B.7C/1B.7D, not a
   product decision.

Everything else in this document is a technical architecture decision
this gate is making now, not an open product question — per the
brief's own instruction not to leave technical choices open merely
because implementation hasn't started.

### Recommended implementation checkpoint sequence

1. **1B.7A — Rollover Plan Schema & Domain Foundation — IMPLEMENTED**,
   see "Schema & Domain Foundation (Phase 1B.7A)" below.
2. **1B.7B — Dry-Run / Eligibility / Conflict Engine — IMPLEMENTED**,
   see "Dry-Run, Eligibility & Conflict Engine (Phase 1B.7B)" below.
3. **1B.7C — Per-Student Promotion Execution**: `EnrollmentPromotionService`
   orchestrating one item's execution via `StudentEnrollmentService::enroll()`,
   the idempotency/staleness revalidation from this document, single-item
   transaction boundary — no batch orchestration yet (callable for one
   item at a time, proven correct and safe first).
4. **1B.7D — Bulk/Resumable Execution & Audit**: chunked execution
   over a whole plan, plan-level double-execute guard, resumability
   after a simulated crash, full audit event set, and (only once this
   is proven correct synchronously) the queued-execution path.
5. **1B.7E — Administrative HTTP/API**: `/api/v1` surface for plan
   CRUD/dry-run/execute, mirroring Phase 1B.5's conventions exactly,
   including the `enrollments.rollover.manage` capability decision
   above if accepted.
6. **1B.7F — Administrative UI**: the Vue/Inertia flow described above,
   mirroring Phase 1B.6's conventions.

Each checkpoint should independently regression-test against the
current full-platform baseline before proceeding, exactly like every
prior Phase 1B checkpoint.

## Schema & Domain Foundation (Phase 1B.7A)

Implements ONLY the durable representation the architecture decision
above calls for. No dry-run/eligibility engine, no execution engine, no
Roll Number generation, no HTTP/API, no UI, no queue job — those remain
exactly as deferred above. Nothing in this checkpoint creates a
`StudentEnrollment` row, completes/withdraws/cancels one, or touches
`AcademicYear`/`Student` status.

### Tables

Three new tables, all `BelongsToSchool` + `TenantRls`-protected (RLS
enabled and FORCED, proven under the unprivileged `school_os_app`
runtime role in `EnrollmentRolloverIntegrityTest`), following every
composite-FK-to-`(id, school_id)` convention already established in
this codebase:

- **`enrollment_rollover_plans`** — the plan header. `source_academic_year_id`/
  `target_academic_year_id` (composite-FK'd to `academic_years`, must
  differ — a real PostgreSQL CHECK constraint,
  `enrollment_rollover_plans_source_target_differ_check`), `status`
  (`draft`/`validated`/`executing`/`completed`/`completed_with_errors`/
  `cancelled` — a plain string, no DB-level enumeration, matching every
  other lifecycle column in this codebase), `configuration_version`/
  `validated_configuration_version` (the explicit staleness mechanism —
  execution may only proceed when they are equal), `created_by_user_id`,
  and the usual lifecycle timestamps. At most one OPEN plan
  (`draft`/`validated`/`executing`) may exist per (School, source year,
  target year) — `enrollment_rollover_plans_one_open_per_year_pair`, a
  PostgreSQL partial unique index (the `academic_years_one_active_per_school`
  pattern); terminal plans are exempt, so a cancelled plan's year pair
  can be legitimately re-attempted.
- **`enrollment_rollover_mappings`** — a plan's explicit source-Grade-
  or source-Section-keyed default. `source_section_id` NULL means a
  Grade-level default (`enrollment_rollover_mappings_one_grade_default`,
  a partial unique index, one per plan+Grade); set means a Section-
  specific override (`enrollment_rollover_mappings_one_section_override`,
  one per plan+Section). Repeat/retention has no dedicated column — it
  is simply `target_grade_level_id = source_grade_level_id`; terminal
  Grade is the absence of any mapping row for that source Grade, never
  a row with a null target.
- **`enrollment_rollover_items`** — the durable per-Student unit.
  Columns are grouped into three families populated by three different
  future checkpoints: CONFIGURATION (`mapping_id`, `decision`,
  `target_section_id`, `roll_number_strategy`, `target_roll_number` —
  1B.7B), VALIDATION/STALENESS (`validation_result`, `validation_reason`,
  and four `*_snapshot` columns — 1B.7B), EXECUTION (`execution_status`,
  `target_enrollment_id`, `executed_at` — 1B.7C, left fully NULL rather
  than defaulted so a freshly configured item never looks like a queued
  execution attempt). `source_enrollment_id` anchors the item to the
  exact, already-resolved authoritative source `StudentEnrollment` row
  (never re-derived loosely from `student_id` at execution time). At
  most one item per (plan, Student) and per (plan, source Enrollment) —
  both plain unique constraints.

### The structural Student/source-Enrollment guarantee

`enrollment_rollover_items.source_enrollment_id` carries **two**
independent composite foreign keys against `student_enrollments`: one
paired with `school_id` (the standard same-School guarantee) and one
paired with `student_id`, made possible by a small, purely additive
supporting index added to the already-accepted `student_enrollments`
table in this same checkpoint —
`unique(['id', 'student_id'])` (migration
`2026_08_24_090000_add_student_composite_unique_to_student_enrollments_table`).
`id` alone was already globally unique, so this index changes no
existing behavior for any Phase 1B.1-1B.6 code; its only purpose is
making it structurally IMPOSSIBLE to construct an item whose
`source_enrollment_id` belongs to a different Student than its own
`student_id` — no trigger, and no reliance on a future service "getting
it right" the way `student_enrollments` itself must rely on
`StudentEnrollmentService` alone for its own internal `section_id`/
`academic_year_id` consistency. Proven directly in both
`EnrollmentRolloverSchemaTest` (Eloquent) and
`EnrollmentRolloverIntegrityTest` (raw SQL via `pgsql_admin`).

### What is deliberately NOT database-structural

Documented explicitly rather than silently assumed: `sections` exposes
no `unique(['id', 'grade_level_id'])`/`unique(['id', 'academic_year_id'])`
today, and this checkpoint does **not** alter Academic Structure to add
one (unlike the narrow, explicitly-scoped `student_enrollments`
addition above). Three invariants therefore remain
APPLICATION-validated, deferred to Phase 1B.7B's dry-run/eligibility
engine, never enforced by this schema:

1. A mapping's `source_section_id`'s own `grade_level_id` equals its
   `source_grade_level_id`.
2. A mapping's `target_section_id`'s own `grade_level_id` equals its
   `target_grade_level_id`.
3. Any Section referenced by a mapping or item belongs to the plan's
   declared source/target AcademicYear.

### `EnrollmentRolloverPlanService`

The sole sanctioned write path in this checkpoint — `createDraft()`
only. Validates same-School (source/target year must belong to the
plan's School) and source-year-≠-target-year BEFORE writing
(`CrossSchoolRolloverPlanException`/`InvalidRolloverPlanYearsException`,
mirroring `StudentEnrollmentService::enroll()`'s pre-write same-School
check exactly), writes inside one transaction, translates the open-plan
partial-unique violation to `OpenRolloverPlanConflictException`, and
audits `enrollment_rollover_plan.created` via the existing
`AuditRecorder`. Mapping and item creation had **no service yet** as
of this checkpoint — mapping configuration and item population/
validation land in Phase 1B.7B (below), which needed real mapping/
eligibility validation logic to do either safely.

## Dry-Run, Eligibility & Conflict Engine (Phase 1B.7B)

Implements the persistent VALIDATION engine on top of Phase 1B.7A's
schema -- answers "if this exact configuration were executed now, what
would happen for every Student?" by writing ONLY rollover planning
state. **No migration was needed** -- every column Phase 1B.7B
populates (`EnrollmentRolloverItem.validation_result`/
`validation_reason`/the four `*_snapshot` columns,
`EnrollmentRolloverPlan.status`/`validated_configuration_version`/
`validated_at`) already existed, exactly as 1B.7A's own docblocks
anticipated. Nothing in this checkpoint creates, completes, withdraws,
cancels, or transfers a `StudentEnrollment`, and nothing mutates
`Student`/`AcademicYear`/`Section`/`Campus`/`GradeLevel` -- proven
directly by a before/after row-fingerprint test
(`EnrollmentRolloverDryRunServiceTest::dry_run_changes_zero_rows_in_every_academic_table()`).

### `RollNumberNormalizer` (small refactor)

`StudentEnrollmentService`'s private `normalizeRollNumber()` (trim,
reject blank) was extracted verbatim into
`App\Domain\Students\Application\RollNumberNormalizer::normalize()` --
a pure, side-effect-free string rule with no DB access and no
authorization -- so the dry-run engine can evaluate whether a
PROPOSED Roll Number would be valid without duplicating subtly
different logic. `StudentEnrollmentService::enroll()`/
`transferPlacement()` now call the shared normalizer instead of their
own private method; behavior is unchanged (proven by the full,
unmodified Phase 1B.1-1B.6 regression suite still passing).

### `EnrollmentRolloverPlanService` additions

Two new methods, both incrementing `configuration_version` in the SAME
transaction as their write (the accepted staleness mechanism) and both
gated by a shared `assertConfigurable()` (plan must be `draft` or
`validated`, else `RolloverPlanNoLongerConfigurableException` --
identical allowed-status set for configuration AND dry-run):

- **`upsertMapping(plan, sourceGradeLevel, ?sourceSection, targetGradeLevel, ?targetSection)`**
  -- creates or updates either granularity of mapping in one call
  (`?sourceSection === null` -> Grade-level default; given -> Section-
  specific override), same-School checked before any write.
- **`setItemDecision(plan, item, decision, ?targetSectionOverride, ?rollNumberStrategy, ?targetRollNumber)`**
  -- sets one Item's operator-controlled configuration. Rejects an
  unrecognized `decision` (`InvalidRolloverItemDecisionException`) or
  `roll_number_strategy` (`InvalidRollNumberStrategyException`) before
  writing anything.

Neither method is reachable from an HTTP endpoint yet (no controller
exists) -- tests call them directly, exactly like `createDraft()`.

### `EnrollmentRolloverDryRunService::run()`

The one public entry point. Population and validation happen in a
single pass:

1. **Population** -- finds every Student with an eligible
   (`active`/`completed`) source-year Enrollment who has no Item yet in
   this plan, and creates one (`decision` left at its `undecided`
   default). This is bookkeeping, not an operator decision -- it never
   touches `configuration_version`. An ambiguous Student (>1 eligible
   candidate) still gets exactly one Item, anchored to the lowest-id
   candidate purely as a technical placeholder to satisfy the schema's
   NOT NULL `source_enrollment_id` -- the anchor carries no decision
   weight because the ambiguity itself immediately classifies the Item
   `review`/`multiple_source_candidates`, which can never become
   `ready`. A Student whose ONLY source-year rows are
   `withdrawn`/`cancelled` is never populated into the plan at all --
   not even as a "blocked" Item (this checkpoint's brief, sections 16/77).
2. **Validation** -- re-evaluates EVERY Item (new or pre-existing)
   against the plan's CURRENT configuration and the database's CURRENT
   state, then persists a result + reason + staleness snapshots for
   every Item and updates the Plan's own validation state -- but ONLY
   if `configuration_version` is still exactly what was captured at the
   start of the run (`StaleRolloverConfigurationException` otherwise,
   and the ENTIRE persistence transaction rolls back -- nothing from
   that attempt is recorded, not even a partial Item update).

Batches every query (candidate discovery, fresh source lookup, target
Section lookup, already-enrolled lookup, persisted-conflict lookup) --
no per-Item query, regardless of plan size.

### Deterministic evaluation priority (per Item)

1. **Source ambiguity** -- >1 eligible (`active`/`completed`) source-year
   row for this Student right now (re-derived fresh on every run, not
   cached from population time) -> `review`/`multiple_source_candidates`.
2. **Source eligibility** -- the anchored `source_enrollment_id`'s
   CURRENT status is not `active`/`completed` (e.g. transferred/
   withdrawn/cancelled since population, or since a prior dry-run) ->
   `blocked`/`source_status_ineligible`.
3. **Mapping resolution** -- Section-specific mapping (keyed by the
   source Enrollment's own `section_id`) takes precedence over a
   Grade-level default (keyed by `grade_level_id`); never both, never
   chosen by creation time -- the two partial unique indexes from
   1B.7A make each level structurally unambiguous already.
4. **Decision**:
   - `exclude` -> `excluded` (non-blocking), reason `terminal_grade` if
     no mapping exists at all for this source Grade, else no reason.
   - `undecided`/`manual_review` -> `review`, reason `terminal_grade`
     if no mapping exists, else `undecided`. Dry-run never invents a
     decision.
   - `promote` -- requires a mapping whose target Grade differs from
     the source Grade (else `blocked`/`target_grade_mismatch`, never
     silently reinterpreted as `repeat`).
   - `repeat` -- requires a mapping whose target Grade EQUALS the
     source Grade (else `blocked`/`target_grade_mismatch`, never
     silently reinterpreted as `promote`).
   - no mapping at all for `promote`/`repeat` -> `blocked`/`missing_mapping`.
5. **Target Section resolution** -- the Item's own `target_section_id`
   override takes precedence over the mapping's default; neither ->
   `blocked`/`missing_target_section`. Whatever resolves is then
   independently verified (never trusted from the mapping/override
   alone) to belong to the plan's `target_academic_year_id` (else
   `blocked`/`target_wrong_academic_year`) and to the mapping's
   `target_grade_level_id` (else `blocked`/`target_grade_mismatch`) --
   Section remains the sole placement authority, exactly like `enroll()`/
   `transferPlacement()` already require.
6. **Roll Number resolution** -- `preserve_source` normalizes the
   source Enrollment's own `roll_number`; `explicit` normalizes
   `target_roll_number`; anything else (including a null strategy)
   resolves to nothing. A normalization failure (blank after trim) ->
   `blocked`/`invalid_roll_number`. Uses `RollNumberNormalizer` --
   the identical rule `StudentEnrollmentService::enroll()` enforces,
   never a subtly different copy. No automatic generation exists or
   is reachable.
7. **Already-enrolled check** (batched across all remaining Items) --
   any existing target-year Enrollment for this Student, regardless of
   status:
   - an `active` one matching the proposal exactly (Section + Roll
     Number) -> `already_enrolled`/`already_enrolled_match`
     (non-blocking NO-OP). `target_enrollment_id` is deliberately left
     NULL here -- see `EnrollmentRolloverDryRunService`'s own docblock
     for why conflating "resolved" with "executed" would corrupt
     `EnrollmentRolloverItem::hasExecuted()`'s meaning for Phase
     1B.7C; recording the provenance link is execution's job, not
     dry-run's.
   - an `active` one that differs -> `blocked`/`already_enrolled_conflict`.
   - only non-`active` (terminal) target-year history exists -> `review`/
     `already_enrolled_conflict` (anomalous enough to need a human
     look, not necessarily wrong).
8. **In-plan duplicate detection** (set-based, in-memory, over every
   Item still in play) -- grouped by (target Section, normalized Roll
   Number); every member of a colliding group is
   `blocked`/`roll_number_conflict_in_plan`, never "first Student
   wins," never order-dependent.
9. **Persisted conflict with another Student** (one batched query
   across every remaining target Section) -- an existing Enrollment at
   the exact (Section, Roll Number) belonging to someone else ->
   `blocked`/`roll_number_conflict_existing`. Whatever survives every
   prior step is `ready`.

### Plan readiness

A Plan becomes `validated` (with `validated_configuration_version` set
to the version just evaluated, and `validated_at` stamped) only when
**zero** Items are `review` or `blocked` -- `ready`/`excluded`/
`already_enrolled` never block validation. Otherwise the Plan's status
is left untouched (typically `draft`) and `validated_configuration_version`
is NOT advanced, so a stale plan can never be mistaken for a validated
one. Every Item's result is still persisted either way, so an operator
can see exactly what needs fixing.

### Result taxonomy

| `validation_result` | Blocks Plan validation? | Meaning |
| --- | --- | --- |
| `ready` | No | Safe to execute as configured (Phase 1B.7C). |
| `excluded` | No | Operator decision -- never executed, never a problem. |
| `already_enrolled` | No | Idempotent no-op -- a matching Enrollment already exists. |
| `review` | Yes | Needs a human DECISION (ambiguous source, undecided, anomalous already-enrolled history) -- not necessarily a configuration mistake. |
| `blocked` | Yes | A structural/configuration problem an operator must fix (mapping, target placement, Roll Number, or a hard conflict). |

Reason codes (`validation_reason`, a plain string, no DB enumeration):
`multiple_source_candidates`, `source_status_ineligible`,
`missing_mapping`, `missing_target_section`,
`target_wrong_academic_year`, `target_grade_mismatch`,
`invalid_roll_number`, `roll_number_conflict_in_plan`,
`roll_number_conflict_existing`, `already_enrolled_match`,
`already_enrolled_conflict`, `undecided`, `terminal_grade`. There is no
`stale_configuration` reason code stored on an Item -- staleness at the
PLAN level is a thrown `StaleRolloverConfigurationException` that
aborts the whole persistence transaction, not an Item-level result.

### Staleness / revalidation (proven, not just designed)

Re-running dry-run after the underlying state changes correctly
detects it every time, without any special-casing: a source transfer
after a first successful validation flips that Item to
`blocked`/`source_status_ineligible` on the next run; a manually
created target Enrollment that exactly matches the proposal resolves
to `already_enrolled` (no duplicate, no error); a Roll Number occupied
by another Student afterward flips the Plan out of `validated`; editing
a mapping through `upsertMapping()` after validation increments
`configuration_version`, which `isValidatedForCurrentConfiguration()`
immediately reports as stale. Running dry-run twice with nothing
changed is fully idempotent -- identical summary, no duplicate Items,
`configuration_version` untouched (validating is never a configuration
edit).

### Audit

`enrollment_rollover_plan.validated` (Plan reaches `validated`) or
`enrollment_rollover_plan.validation_completed` (blockers remain) --
one event per dry-run run, carrying only the plan id, evaluated
`configurationVersion`, and summary counts, never Student PII, Roll
Numbers, or names. `enrollment_rollover_plan.configuration_changed` is
emitted by `upsertMapping()`/`setItemDecision()`. No per-Item audit
event exists (deliberately -- brief section 59) and no NEW audit event
was needed for the Enrollment domain itself, since dry-run creates no
Enrollment.

## Per-Student Promotion Execution (Phase 1B.7C)

Implements the ONE-item execution primitive the accepted architecture
("Atomicity, idempotency, resumability" above) already anticipated —
`App\Domain\Students\Application\EnrollmentRolloverItemExecutionService::execute(EnrollmentRolloverItem $item, ?User $actor = null)`
materializes exactly the target-year `StudentEnrollment` ONE
already-validated Item describes. No whole-Plan loop, no queue, no
HTTP/API, no UI — see "Deferred" below.

### Schema

No migration. Every column this checkpoint needed
(`execution_status`, `target_enrollment_id`, `executed_at`) already
existed on `enrollment_rollover_items` from Phase 1B.7A, left `NULL`
specifically so this checkpoint could populate them.
`execution_status` gained one value beyond its original 1B.7A docblock
vocabulary (`pending|succeeded|failed|skipped`): `reconciled`, for the
already-enrolled/idempotent-match outcome the accepted architecture's
"already enrolled" reconciliation explicitly calls for — the column
has no database CHECK constraint (matching `decision`/
`validation_result`'s own precedent), so this is a vocabulary
extension, not a schema change.

### Trusted creation primitive

`StudentEnrollmentService::enroll()` — the SAME public method ordinary
Enrollment creation uses, called nested inside this service's own
`DB::transaction()`. This is safe, not merely convenient: Laravel
promotes a nested `DB::transaction()` to a real PostgreSQL SAVEPOINT,
so `enroll()`'s own `UniqueConstraintViolationException` handling only
ever rolls back to that SAVEPOINT — never the whole connection —
before translating and rethrowing `ActiveEnrollmentConflictException`/
`DuplicateEnrollmentRollNumberException`. This checkpoint's own
insert-race reconciliation test
(`a_roll_number_claimed_by_another_student_after_validation_is_detected_and_recoverable`)
proves the connection remains fully usable immediately afterward — no
SQLSTATE 25P02, no need for a second, separately-opened transaction to
recover. `TenantContext::withSchool()` nests the same way (always
restores whatever was active before its own callback ran), and since
`enroll()` is always called here for the SAME School this service
already established, the nested re-set is a same-value no-op.

### Execution preconditions (checked in this order, one deterministic lock order: Plan -> Item -> source Enrollment)

1. **Idempotent replay first** (`Item.target_enrollment_id !== null`)
   — re-verifies the referenced target Enrollment still coheres with
   the Item's Student/target Academic Year and returns it unchanged; a
   retried/duplicate `execute()` call is always safe regardless of
   whether the Plan is STILL validated (a later edit to OTHER items
   must never retroactively fail a replay of THIS item's own
   already-complete work). A genuine mismatch here — the referenced
   Enrollment no longer coheres — raises a hard `RuntimeException`
   (structurally near-impossible given `target_enrollment_id`'s
   `restrictOnDelete()` FK from 1B.7A; defense in depth only).
2. **Plan currently execution-ready**: `status === 'validated'` AND
   `validated_configuration_version === configuration_version`
   (`RolloverPlanNotExecutionReadyException` otherwise) — `status`
   alone is not sufficient, since `upsertMapping()`/`setItemDecision()`
   bump `configuration_version` without touching `status`.
3. **Item classification**: `excluded` → marked `skipped`, zero
   academic writes. `ready`/`already_enrolled` → proceeds.
   Anything else (`review`/`blocked`/`undecided`) →
   `RolloverItemNotExecutableException` — defense in depth; a
   validated Plan already structurally guarantees no such Item exists.
4. **Source Enrollment snapshot revalidation**: the fresh, locked
   source row's `status`/`updated_at` must exactly match the Item's
   1B.7B snapshot columns, or execution stops as
   `source_changed_since_validation` — the identical staleness
   detection the "Staleness detection" section above already
   documented, now proven under a real sanctioned
   `transferPlacement()` call in this checkpoint's own test.
5. **Target Section resolution + revalidation**: the target Section is
   re-derived (Item-level override if set, else the Plan's Section-
   specific or Grade-level default mapping — no persisted
   "resolved target Section" column exists, so this is a bounded
   re-derivation, not a second independent planning pass; see the
   service's own docblock on `resolveTargetSectionId()` for the exact
   two-guard proof of why it is safe), then its fresh `status`/
   `updated_at` must match the Item's snapshot, or execution stops as
   `target_section_changed_since_validation`.
6. **Roll Number re-derivation**: `RollNumberNormalizer::resolveForStrategy()`
   — the SAME shared rule 1B.7B's dry-run engine uses (extracted in
   this checkpoint specifically so both services apply one rule, never
   two subtly different ones) — applied to the (snapshot-proven-
   unchanged) source Roll Number, never auto-generated.
7. **Existing target-year Enrollment pre-check**: an active existing
   row that exactly matches (Section + Roll Number) reconciles
   idempotently (`execution_status = 'reconciled'`, zero new
   Enrollments); one that does not match invalidates the Plan
   (`existing_target_enrollment_conflict_at_execution`) rather than
   overwriting/transferring it; an `already_enrolled`-classified Item
   finding no active match anymore invalidates as
   `already_enrolled_match_no_longer_valid`.

### External-state drift always demotes Plan readiness, never just the one Item

Every drift/conflict path (source changed, target Section changed,
existing-target mismatch, Roll Number race) calls one shared
`invalidateForDrift()`: the Item is marked `execution_status = 'failed'`,
`validation_result = 'blocked'` with a specific reason code: the Plan's
`status` reverts to `draft` and `validated_configuration_version` is
cleared — `configuration_version` itself is deliberately left
untouched (this is not a configuration edit, so it must not look like
one, matching the "Plan lifecycle" section's "reverts to conceptually
draft" language above). `validated_at` is deliberately preserved as
the historical record of the last real review.

### Insert-race and Roll Number race reconciliation

Both are backstopped by the exact database constraints the
"Concurrency" section above already named
(`student_enrollments_one_active_per_student_year`,
`student_enrollments_school_id_academic_year_id_section_id_roll_`) —
caught via `enroll()`'s own already-translated
`ActiveEnrollmentConflictException`/`DuplicateEnrollmentRollNumberException`,
then reconciled (if the race winner exactly matches the proposal) or
invalidated (otherwise) on the SAME still-healthy transaction, per the
SAVEPOINT reasoning above. Proven with a REAL two-OS-process
concurrency test
(`EnrollmentRolloverItemExecutionConcurrencyTest`, mirroring
`AcademicYearActivationConcurrencyTest`'s established pattern) — two
genuinely separate PHP processes executing the identical Item leave
exactly one active target Enrollment.

### `setItemDecision()` guard

Refuses to reconfigure an Item once `target_enrollment_id` is set
(`RolloverItemAlreadyExecutedException`) — the primary, fail-fast
defense against silently orphaning `target_enrollment_id`'s provenance
guarantee; the idempotent-replay integrity check above is the second,
structural line of defense for any path that bypasses this guard.

### Audit

`enrollment_rollover.item_succeeded` (creation),
`enrollment_rollover.item_reconciled` (idempotent match),
`enrollment_rollover.item_skipped` (excluded), and
`enrollment_rollover.item_execution_failed` +
`enrollment_rollover_plan.execution_invalidated` (drift) — every event
carries only plan/item/enrollment ids, the configuration version, and
a reason code, never Student PII, Roll Numbers, or names. Successful
creation's `student_enrollment.created` audit (from `enroll()` itself)
commits inside the SAME transaction as `item_succeeded` — proven by a
dedicated audit-count test that a rejected/rolled-back attempt
produces neither.

## Bulk & Resumable Rollover Execution (Phase 1B.7D)

Implements the PLAN-LEVEL orchestrator
(`App\Domain\Students\Application\EnrollmentRolloverExecutionService`)
that claims a validated Plan, processes its Items in deterministic
bounded batches, and finalizes it once every Item reaches a
terminal-accepted outcome — it never duplicates one-Item promotion
logic: every academic mutation still goes through
`EnrollmentRolloverItemExecutionService::execute()`, exactly once per
Item.

### 1B.7C integration: `validated` OR `executing`

`EnrollmentRolloverItemExecutionService::execute()`'s Plan-readiness
gate now accepts `status IN ('validated', 'executing')` (previously
`validated` only) — `executing` is what `start()` transitions a Plan
to for the duration of a bulk run, and every Item it processes must
still pass through the identical gate.
`validated_configuration_version === configuration_version` remains
required either way; `status` alone was never sufficient (see
`EnrollmentRolloverPlan::isValidatedForCurrentConfiguration()` —
`upsertMapping()`/`setItemDecision()` bump `configuration_version`
without touching `status`). Per-Student academic semantics inside
`execute()` are otherwise completely unchanged.

### Public contract: `start()` / `resume()`

- **`start(plan, actor, batchSize, afterEachItem)`** — claims a
  `validated` Plan (short, row-locked transaction: verify status +
  configuration version, conditional `UPDATE ... WHERE status =
  'validated'`, set `execution_started_at` only if it was still null,
  audit `enrollment_rollover.execution_started`), then processes it.
  Refuses `RolloverPlanNotExecutionReadyException` for a Plan that
  isn't currently validated, `RolloverPlanAlreadyExecutingException`
  for one that already is — a duplicate/concurrent `start()` call
  never becomes a second active processor for the same Plan.
- **`resume(plan, actor, batchSize, afterEachItem)`** — continues an
  ALREADY-`executing` Plan; no claim/transition step. Refuses
  `RolloverPlanNotResumableException` for any other status, including
  an already-`completed`/`completed_with_errors` one — calling
  `resume()` again after completion is a clean no-op rejection, never
  a silent re-run.
- `$afterEachItem` (both methods) is a test-only seam — invoked with
  the just-processed Item's id after every Item; returning `true`
  stops processing immediately, deterministically simulating a
  crashed/interrupted process without any timing/sleep-based test
  (mirrors `EnrollmentRolloverDryRunService::run()`'s `$beforePersist`
  precedent).

### Bounded batch processing, no offset pagination

Item selection is `WHERE plan_id = ? AND (execution_status IS NULL OR
execution_status NOT IN ('succeeded','reconciled','skipped'))
ORDER BY id LIMIT $batchSize` (default 100) — offset-free by
construction: the WHERE clause itself naturally shrinks as Items are
processed, so repeating the identical bounded query with no offset
always returns the next genuinely-still-pending batch; a processed
Item can never cause another to be skipped. (A plain `NOT IN` alone is
NOT sufficient here — SQL's three-valued logic excludes `NULL` rows
from a bare `NOT IN (...)`, which would silently skip every
never-yet-attempted Item; the `execution_status IS NULL OR ...` form
is required.)

### Stop-immediately on invalidation

The Plan's status/configuration-version is re-verified BEFORE every
batch AND after every single Item (not merely before the next batch)
— the moment `EnrollmentRolloverItemExecutionService::invalidateForDrift()`
demotes the Plan (source changed, target Section changed, a
conflicting target Enrollment, a Roll Number race), the orchestrator
stops before any later Item is ever attempted. It never re-runs
dry-run itself and never overwrites an invalidated Plan's `draft`
status with `completed`/`completed_with_errors`.

### Finalization

Only when the Plan is still genuinely `executing` (re-checked under a
fresh row lock) AND zero Items remain outside the terminal-accepted
set (`succeeded`/`reconciled`/`skipped`) — never trusts the caller's
in-memory belief that processing finished. `completed_with_errors` is
kept as a structurally-supported branch (any `failed` Item at
finalization time) but is, by the current architecture's own
invariant, effectively unreached: every path that marks an Item
`failed` also demotes the Plan out of `executing` in the same write,
which the orchestrator's own stop-immediately check catches before
`finalize()` is ever reached again.

### Revalidation after partial execution

Dry-run's persistence step never touches `execution_status`/
`target_enrollment_id`/`executed_at` (unchanged from 1B.7C) — an
already-succeeded Item surviving a partial/interrupted run is
naturally reclassified `already_enrolled` on the NEXT dry-run (its own
target Enrollment now exists), while its execution provenance is
completely untouched. A later `start()`/`resume()` never re-selects a
`succeeded`/`reconciled`/`skipped` Item for processing at all — it
simply survives, permanently, never re-touched, never reclassified to
`reconciled`. `EnrollmentRolloverPlanService::setItemDecision()`'s
existing 1B.7C guard (`RolloverItemAlreadyExecutedException`) remains
the narrower defense for the case where the Plan has reverted to
`draft`/`validated` but a specific Item already executed;
`assertConfigurable()` alone already blocks ANY Item reconfiguration
while the Plan is `executing`.

### Concurrency

Plan claim: `lockForUpdate()` on the Plan row, proven by a REAL
two-OS-process test
(`EnrollmentRolloverExecutionConcurrencyTest`, mirroring
`AcademicYearActivationConcurrencyTest`/
`EnrollmentRolloverItemExecutionConcurrencyTest`'s established
pattern) — exactly one of two concurrent `start()` calls claims the
Plan. Concurrent `resume()`: deliberately NOT given a distributed
lease/worker-leasing subsystem — per-Item `lockForUpdate()` (1B.7C)
plus target-Enrollment uniqueness remain the final, sufficient
backstop even if two resume processors inspect overlapping batches; a
single logical orchestrator is the recommended operational model.

### Queue compatibility (still deferred)

No `ShouldQueue` job/scheduler/command exists yet — `start()`/
`resume()` are designed as though the PHP process could stop after any
committed Item (each Item is its own committed transaction), so a
future queue worker can call either method exactly as a synchronous
caller does today, with zero change to academic semantics.

## Rollover Authorization & Administrative HTTP/API (Phase 1B.7E)

Exposes the already-accepted rollover domain (Phase 1B.7A-1B.7D)
through the existing authenticated `/api/v1/schools/{school}/...`
administrative surface. Finalizes the rollover-specific authorization
model. Does NOT create the Vue/Inertia UI (Phase 1B.7F) or a queue
(deferred, still no bound checkpoint number).

### Capability family

`enrollments.rollovers.view` / `enrollments.rollovers.manage` --
three-segment naming mirroring `integrations.webhooks.*`'s existing
sub-resource-of-a-domain precedent (rollover is a sub-resource of
Enrollment the same way webhooks are a sub-resource of integrations)
rather than a squashed `enrollment_rollovers.*` (no other capability
key in the catalog uses an underscore).

**Dual authorization is required for every rollover action** -- the
dedicated capability is required IN ADDITION TO, never instead of, the
base Enrollment one:

| Action | Requires |
| --- | --- |
| List/detail/items | `enrollments.view` AND `enrollments.rollovers.view` |
| Create/configure/validate/start/resume | `enrollments.manage` AND `enrollments.rollovers.manage` |

Neither pair implies the other -- `CapabilityResolver` has no
inheritance mechanism (same rule every other capability pair in this
catalog already follows). Read actions authorize both inline
(`AuthorizesCapability`, matching `StudentEnrollmentController`'s
index/show pattern); every mutation route additionally carries BOTH
`capability:` route middleware entries.

### Default role grants

- **`school_admin`**: `enrollments.rollovers.view` AND
  `enrollments.rollovers.manage`.
- **`principal`**: `enrollments.rollovers.view` ONLY -- a deliberate
  break from the "Principal gets full parity with School Admin"
  pattern every other `enrollments.*`/`students.*`/`guardians.*` pair
  on this role follows. A Principal may legitimately inspect/review
  academic rollover planning, but bulk EXECUTION can mutate hundreds/
  thousands of next-year Enrollments in one action -- a materially
  higher blast radius than any single `enrollments.manage` operation
  -- so it stays School-Admin-only by default (least privilege),
  matching this role's own existing view-only treatment of School
  profile/Campus administration.
- No new role was created (`registrar`/`academic_admin`/etc.) -- the
  capability architecture already permits future custom role
  assignments without inventing role semantics now.

### Route inventory (10 routes)

```
GET    /schools/{school}/enrollment-rollovers
POST   /schools/{school}/enrollment-rollovers
GET    /schools/{school}/enrollment-rollovers/{rollover}
GET    /schools/{school}/enrollment-rollovers/{rollover}/items
PATCH  /schools/{school}/enrollment-rollovers/{rollover}/items/{item}
POST   /schools/{school}/enrollment-rollovers/{rollover}/mappings
PATCH  /schools/{school}/enrollment-rollovers/{rollover}/mappings/{mapping}
POST   /schools/{school}/enrollment-rollovers/{rollover}/validate
POST   /schools/{school}/enrollment-rollovers/{rollover}/start
POST   /schools/{school}/enrollment-rollovers/{rollover}/resume
```

No generic `PATCH /enrollment-rollovers/{rollover}` exists -- Plan
lifecycle is controlled by the three explicit actions
(`validate`/`start`/`resume`) only; `status`/`configuration_version`/
execution timestamps are never caller-controlled. No mapping DELETE
route exists -- `EnrollmentRolloverPlanService` has no sanctioned
removal method today, and this checkpoint does not invent one. No
Plan cancellation route exists for the identical reason.

Mappings and Items are NESTED under the Plan
(`/enrollment-rollovers/{rollover}/mappings[/{mapping}]`, `/items[/{item}]`)
specifically so nested ownership can be verified against the ROUTE's
Plan, not merely the current School --
`EnrollmentRolloverMapping::query()->where('plan_id', $plan->id)->findOrFail($mapping)`
makes a same-School-but-different-Plan id 404 exactly like a
foreign-School or random uuid.

### Controllers stay thin

`EnrollmentRolloverController` (Plan directory/create/detail +
`validate`/`start`/`resume`), `EnrollmentRolloverMappingController`
(create/update), `EnrollmentRolloverItemController` (directory +
update) -- every read delegates to the new
`EnrollmentRolloverReadService` (mirrors
`StudentEnrollmentReadService`'s exact shape: plain Eloquent models/
paginators, authorization-neutral, relies entirely on ambient
SchoolScope/RLS), every mutation delegates to the already-accepted
`EnrollmentRolloverPlanService`/`EnrollmentRolloverDryRunService`/
`EnrollmentRolloverExecutionService`. No dry-run logic, mapping
precedence, Roll Number validation, promotion rule, execution
ordering, resumability, or staleness handling is duplicated in HTTP
code.

`EnrollmentRolloverMappingController::update()` reuses
`upsertMapping()` exactly as-is (no new PlanService method was
needed) -- it reads the EXISTING mapping's own source Grade/Section
from the resolved, route-verified model (never from the request body)
and only accepts a new target Grade/Section, so a caller can never
re-key a PATCH onto a different mapping's identity.

### Protected/caller-authority fields

Never accepted from any rollover request body: `school_id`, `status`,
`configuration_version`, `validated_configuration_version`,
`created_by_user_id`, execution timestamps, `student_id`,
`source_enrollment_id`, `validation_result`, `validation_reason`,
`execution_status`, `target_enrollment_id`, snapshot columns. Every
controller uses an explicit `$request->validate()` allow-list --
anything else in the body is silently dropped before it ever reaches
a service, proven directly in
`EnrollmentRolloverApiTest::item_configuration_update_preserves_roll_number_text_and_ignores_protected_fields`.

### Synchronous execution operational limitation

No queue exists yet. `start()`/`resume()` reuse
`EnrollmentRolloverExecutionService`'s existing `$afterEachItem`
extension point (Phase 1B.7D's own deterministic-interruption test
seam) for a second, legitimate production purpose: bounding ONE
synchronous HTTP request to `EnrollmentRolloverController::MAX_ITEMS_PER_REQUEST`
(100) Items, server-owned and never caller-controlled. A Plan with
more pending Items than that simply returns with the Plan still
`executing`; the client issues additional `resume()` calls to
continue. This is a real, documented limitation (not silently
papered over), and it required zero changes to the 1B.7D orchestrator
itself -- it is reused, not redesigned.

### Response privacy

`RolloverItem`'s `student` field is the same minimized summary
(id/studentNumber/first-middle-last name) `StudentEnrollmentController`
already established -- never `date_of_birth`, Guardian data, contact
values, lookup hashes, or encrypted fields. Snapshot/internal
integrity columns are never serialized raw; only `validationResult`/
`validationReason` (the human-meaningful state/reason) are exposed.

### Deferred within this checkpoint

No queue job/scheduler/command, no rollover Vue/Inertia UI (Phase
1B.7F), no automatic source Enrollment completion, no automatic Roll
Number generation, no AcademicYear activation/closure -- all
unchanged from the domain layer's own existing invariants.

## Rollover Administrative UI (Phase 1B.7F)

Builds the human review/execution workflow on top of the already-
accepted rollover services -- session-authenticated Inertia/Vue pages
under `/app/enrollment-rollovers`, mirroring Phase 1B.6's exact
convention (NOT the Bearer-token JSON API; the web layer calls the
SAME `EnrollmentRolloverPlanService`/`EnrollmentRolloverDryRunService`/
`EnrollmentRolloverExecutionService`/`EnrollmentRolloverReadService`
directly, server-side -- Vue never calls `/api/v1` over HTTP).

### Human workflow

1. `/app/enrollment-rollovers` -- Plan directory.
2. `/app/enrollment-rollovers/create` -- pick source + target Academic
   Year (only fields collected; School/actor/status/version are
   server-owned).
3. `/app/enrollment-rollovers/{id}` -- the single Plan workspace:
   Grade/Section mapping configuration, paginated Student Items with
   inline decision/target/Roll-Number editing, Run Validation, and the
   Start/Resume execution panel. One focused workspace, not a wizard
   (no existing wizard convention in this repository).

### Item population

Items are never manually added ("Add Student to rollover" does not
exist) -- they appear ONLY as a byproduct of Run Validation
(`EnrollmentRolloverDryRunService::run()`'s own population step,
unchanged from Phase 1B.7B). The UI reflects this truth directly: the
Items panel shows "Run Validation to populate them" when a Plan has
none yet.

### Authorization

Every page/action requires the finalized dual-capability pairs (Phase
1B.7E) -- `enrollments.view` AND `enrollments.rollovers.view` for
reads, `enrollments.manage` AND `enrollments.rollovers.manage` for
every mutation -- enforced INSIDE each controller
(`AuthorizesCapability` trait), matching every other `App/` controller
in this codebase (route middleware is the API's pattern, not the
web layer's). `canManage` is computed server-side and passed as a
prop so Vue can hide mutation controls for UX, but every mutation
route independently re-checks both capabilities regardless -- a
Principal (`enrollments.rollovers.view` only) attempting a direct POST
to any mutation route is rejected exactly as if no button existed
(proven directly, not just by hiding a button).

### Mapping / Item configuration

`MappingsPanel.vue`/`ItemsPanel.vue` (Show.vue's two sub-components)
submit directly to `EnrollmentRolloverMappingController`/
`EnrollmentRolloverItemController` -- both new, both mirroring the
Phase 1B.7E JSON API's identical controllers exactly (same protected-
field allow-list, same nested Plan-ownership resolution, same
`upsertMapping()`/`setItemDecision()` delegation, no Mapping Delete
route since no sanctioned removal method exists). Roll Number inputs
are always `type="text"`, never coerced with `Number()`/`parseInt` --
"007" round-trips exactly.

### Dry-run / validation review

`plan.validationSummary` (Ready/Excluded/Already-enrolled/Review/
Blocked/Unvalidated counts) is a NEW small read-only aggregate query
(`EnrollmentRolloverReadService::validationSummary()`, added this
checkpoint) -- distinct from the already-existing `executionSummary()`
(a different dimension: what execution DID vs what validation
PROPOSED). Needed because the "high-risk execution review" panel must
show these counts even on a fresh page load, not only immediately
after a `validate()` call's own transient response. Machine reason
codes are translated to staff-facing text (`rolloverReasons.ts`)
without changing their meaning; the original code remains available
in the same prop.

### Execution review, Start, Resume

Start is enabled only when `status === 'validated'` AND
`isValidatedForCurrentConfiguration` -- both server-computed flags,
never inferred client-side from counts. `window.confirm()` (matching
Phase 1B.6's `Transfer.vue` precedent) explains: target Enrollments
will be created, source Enrollments will NOT change, and the request
may leave the Plan `executing` if more Students remain. Resume uses
its own confirmation and its own button, shown only when
`status === 'executing'` -- a duplicate `Start` submission never
silently becomes a Resume (the `RolloverPlanAlreadyExecutingException`
409 conflict surfaces as a plain inline error).

### Synchronous execution, honestly represented

No queue exists. `start()`/`resume()` are capped to
`App\Support\Rollover\BoundsRolloverExecutionRequest::MAX_ITEMS_PER_REQUEST`
(100) Items per request -- a small shared trait extracted this
checkpoint so the JSON API controller (Phase 1B.7E) and this web
controller enforce the IDENTICAL cap, never two independently-tuned
numbers. The UI never fabricates a progress bar, polling status check,
or "processing in the background" message -- once the synchronous
request returns, the page shows exactly the persisted Plan/Item state
(`succeeded`/`reconciled`/`pending`/`failed` counts recomputed from the
database), and if Items remain pending, the UI shows Resume; nothing
calls Resume automatically.

### Partial invalidation recovery

If a per-Item execution discovers external drift mid-run, the Plan
reverts to `draft` (Phase 1B.7C/1B.7D's existing invalidation
behavior, unchanged) -- the Show page surfaces this as a prominent
"Revalidation required" banner (never as `completed`/
`completed_with_errors`), explicitly stating that already-created
target Enrollments remain valid and were not undone. Re-running
validation classifies the already-created targets as
`already_enrolled` (existing Phase 1B.7D behavior); a subsequent Start
never duplicates them.

### Completed Plan history

A `completed`/`completed_with_errors`/`cancelled` Plan renders fully
read-only -- no mapping/item edit controls, no Run Validation, no
Start/Resume -- but remains permanently viewable (no hard delete, no
"edit completed Plan").

### Privacy

`ItemsPanel.vue`'s Student summary is the SAME minimized shape
(id/studentNumber/first-middle-last name) as the JSON API and Phase
1B.6's own Enrollment UI -- never `date_of_birth`, Guardian data,
contact values, or encrypted/hash fields.

### Accessibility / responsive

Every form control has an associated `<label>`; invalid fields carry
`aria-invalid`/`aria-describedby` (matching Phase 1B.6's established
pattern); tables use semantic `<table>`/`<thead>`/`<th scope="col">`;
status is always conveyed by text plus `StatusBadge` (extended this
checkpoint with the rollover Plan-status/validation-result/execution-
status vocabularies -- one superset component, matching how Phase
1B.6 itself extended it, never a redesign); the Item table becomes a
stacked card list below the `md` breakpoint, matching every other
paginated table in this codebase.

### Deferred within this checkpoint

No queue job/scheduler, no automatic source Enrollment completion, no
automatic Roll Number generation, no AcademicYear activation/closure --
unchanged from the domain layer's own existing invariants.

## As-of-date SubjectOffering eligibility (RES.1, ADR 0068 §5, §18)

`SubjectOfferingEligibilityReadService` answers, for a School, Student,
SubjectOffering and date, whether the Student was eligible to be assessed
in that Offering on that date, and on which placement:
- **required Offering:** a placement covering the date in the Offering's
  AcademicYear, Campus and GradeLevel, whatever its Section (returned as
  evidence);
- **elective Offering:** additionally a `student_subject_enrollments` row
  for the Offering covering the date, with its placement anchor;
- **temporal, not status-based,** exactly as `membersAsOf()` (cancelled and
  other terminal intervals count for their dates); a later transfer,
  rollover or withdrawal never changes an earlier date's answer;
- **fails closed** with a closed reason (another School, no placement, no
  elective row, a legacy unanchored row, ambiguous or inconsistent history);
- a plain read and a lock-capable variant (`FOR SHARE`, inside the caller's
  transaction).

Internal only (no route or capability) and no consumer yet: StudentMark
(ADR 0068 RES.2) will be the first, after legal item RES-L0.

## Deferred (not yet implemented)

- **Rollover queue-backed execution** — the architecture was decided
  in Phase 1B.7, the durable plan/mapping/item schema landed in Phase
  1B.7A, the dry-run/eligibility/conflict engine landed in Phase
  1B.7B, the per-Student promotion EXECUTION primitive landed in Phase
  1B.7C, PLAN-LEVEL bulk/resumable execution landed in Phase 1B.7D,
  authorization + the administrative HTTP/API landed in Phase 1B.7E,
  and the human administrative UI landed in Phase 1B.7F (see
  "Rollover Administrative UI (Phase 1B.7F)" above) — the full
  persistent rollover workflow (create, configure, validate, start,
  resume, inspect, recover from partial invalidation) is now reachable
  end-to-end through both the API and the web UI. Still deferred: any
  queue job/scheduler/background worker -- execution remains bounded
  synchronous HTTP requests (100 Items per request, explicit Resume
  for the rest), an intentional, honestly-represented operational
  model rather than a silent limitation. A future queue-backed
  execution enhancement remains a legitimate later operational
  improvement, not a functional gap in Phase 1B's own acceptance
  criteria.
- **Transfer certificate / TC document generation, inter-school
  electronic transfer network** — out of scope for the transfer/
  withdrawal *status* concept this schema already supports.
- **Admissions (enquiry/lead/application/interview/offer workflow)** —
  explicitly a separate, later Admissions checkpoint. Phase 1B provides
  the landing point (`accepted applicant → Student identity →
  StudentEnrollment`), not the applicant pipeline itself.
- **Attendance, Exams, Fees, Transport, Health, Communications, AI/
  automation, government identifiers, parent/student portals** — none
  touched by this foundation; `student_enrollments` exposes
  `unique(['id', 'school_id'])` specifically so those future modules
  can reference it via the same composite-FK pattern without a later
  migration.

## Retention (E21.2D, 2026-10-01)

E21-D7 (`docs/security/E21-RETENTION-DETERMINATION.md`, project-adopted,
pending ratification) runs every period from the Student's **final exit**.
`App\Domain\Students\Application\Retention\StudentRetentionEligibility`
is the single rule:
- the Student is `inactive`;
- no non-cancelled placement is `active` or open, and no Subject
  Enrollment is `active`;
- the latest non-cancelled placement ended `completed` or `withdrawn`.

The exit date is that `ends_on`. Every other state is unresolved and kept.
- Re-enrollment, always a new row, restarts the clock from the next
  departure.
- Placements, cancelled ones included, and Subject Enrollments are the core
  academic record. They are kept 25 years after final exit, then purged
  with the Student by `platform:student-retention-prune`.
- Rollover items are operational and go after 7 years. Plans and their
  grade/subject mappings are School configuration and stay.
- No request path deletes any of these. The purge never cascades another
  module's rows: any referencing row keeps the Student.
- **E21.3B (2026-10-02):** the same final exit also governs returned
  Library loans and ended Transport/Hostel assignments (7 years; an open
  one keeps the Student), and the core evidence that goes with the record
  (processing authorizations, converted admission applications, the Student
  subject's consent events and domain preferences). A converted
  application's `converted_student_enrollment_id` therefore no longer keeps
  the placement once the Student is eligible; one converted into ANOTHER
  Student still does. Every composition is
  `App\Support\Retention\StudentRetention`.
