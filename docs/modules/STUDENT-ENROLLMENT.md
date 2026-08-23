# Student Enrollment (Phase 1B.1)

Status: **foundation only** — schema, model, and factory/fixtures.
No controllers, no API, no UI, no Application service, no
authorization, and no domain events yet. Those land in later Phase 1B
checkpoints (see "Deferred" below).

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
trigger). It is guaranteed by construction: the future
`App\Domain\Students\Application\StudentEnrollmentService` (Phase 1B.4)
will be the only sanctioned write path and will always derive
`academic_year_id`/`campus_id`/`grade_level_id` **from** the
caller-chosen `section_id` server-side — the same trust model already
used everywhere else in this codebase (a client never supplies
`school_id`; here, a client never independently supplies the three
denormalized ancestor ids either — only `section_id`). Phase 1B.1's
test fixture (`CreatesTenancyFixtures::createStudentEnrollment()`)
already enforces this by construction, so no test can accidentally
construct an inconsistent state that real code could not produce.

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

## Deferred (not yet implemented)

- **Application service** (`StudentEnrollmentService`) — validated
  create/update/status-transition write path, audit recording,
  exception translation. Phase 1B.4.
- **Authorization** (`enrollments.view`/`enrollments.manage` or
  equivalent capability family) — no controller exists yet to gate.
  Phase 1B.4/1B.5.
- **Administrative HTTP/API and Vue UI** — Phase 1B.5/1B.6.
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
