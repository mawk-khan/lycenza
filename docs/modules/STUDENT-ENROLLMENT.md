# Student Enrollment (Phase 1B.1 / 1B.2 / 1B.3 / 1B.4 / 1B.4A / 1B.5)

Status: **schema + sanctioned write path + lifecycle transitions +
authorization + read foundation + administrative HTTP/API.** Phase
1B.1 shipped the schema/model/RLS/composite-FK foundation; Phase 1B.2
added `StudentEnrollmentService::enroll()`, the only sanctioned way to
create a StudentEnrollment; Phase 1B.3 added the four terminal
lifecycle transitions (`complete()`/`withdraw()`/`cancel()`) and the
atomic same-Academic-Year placement transfer (`transferPlacement()`);
Phase 1B.4 added the `enrollments.view`/`enrollments.manage`
capabilities and `StudentEnrollmentReadService`, the canonical read
layer; Phase 1B.4A hardened cross-cutting TenantContext cleanup; Phase
1B.5 exposes all of the above through the repository's existing
authenticated School administrative `/api/v1` surface (see
"Administrative HTTP boundary (Phase 1B.5)" below) — it does not
redesign the Enrollment domain. Still no Vue UI, and no
promotion/academic-year rollover yet — see "Deferred" below.

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

## Deferred (not yet implemented)

- **Administrative Vue UI** — the API above is fully authorized and
  tested; no frontend consumes it yet. Phase 1B.6.
- **Promotion / bulk academic-year rollover** — conceptually "complete
  old Enrollment, create next-year Enrollment," but a bulk
  `Promote Grade 5A → Grade 6A` engine is a later checkpoint (Phase
  1B.7) once the Enrollment model itself is stable.
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
