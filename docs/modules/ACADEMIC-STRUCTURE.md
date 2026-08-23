# School OS — Academic Structure Module

Phase 0D. The first genuine business/domain module in this codebase —
`app/Domain/AcademicStructure/{Application,Infrastructure,Http}`
follows the layout `apps/platform/app/Domain/README.md` documented
since Phase 0A for exactly this moment. This document is the
operational reference for the entities, invariants, and deliberate
scope boundaries this module establishes; future modules (SIS, Fees,
Attendance, Timetable, Examinations) reference this structure without
needing to redesign it.

## Canonical terminology (locked)

| Term | Meaning | Avoid |
|---|---|---|
| `GradeLevel` | Educational progression step (Nursery, LKG, UKG, Grade 1 … Grade 12) | `Class`, `Standard`, `Batch` |
| `Section` | Organizational subdivision of a GradeLevel within one AcademicYear/Campus (e.g. "Grade 5 A") | — |
| `Subject` | An academic subject (Mathematics, Physics, …) | `Course` |
| `AcademicYear` | A School's own academic-year definition (e.g. "2026-27") | `Session` |
| `AcademicTerm` | A subdivision of an AcademicYear (Term 1, Semester 1, …) | — |
| `AcademicDepartment` | Academic grouping of Subjects | `Department` (reserved for a future HR organizational concept) |
| `EducationBoard` | Platform-level reference catalog (CBSE, CISCE, IB, Cambridge, …) | — |
| `SubjectOffering` | The (AcademicYear, Campus, GradeLevel, Subject) offering-assignment row | — |

`Class` is deliberately never used as a PHP model/table name — it is a
language keyword and semantically ambiguous with "the Class the
student attends" in everyday School usage. `GradeLevel` is used
everywhere a "grade" is meant.

## Entity model

```
School (Phase 0B, extended)
  └─ EducationBoard (platform catalog, optional default via schools.education_board_id)
  └─ Campus (Phase 0B, extended)
       └─ Room
  └─ AcademicYear
       └─ AcademicTerm
       └─ Section (AcademicYear + Campus + GradeLevel)
       └─ SubjectOffering (AcademicYear + Campus + GradeLevel + Subject)
  └─ GradeLevel (School-wide)
  └─ AcademicDepartment (School-wide)
       └─ Subject (School-wide, optional AcademicDepartment)
```

## Board / Curriculum decision

**Implemented**: `EducationBoard` is a **platform reference catalog**
(`education_boards` table — no `school_id`, no RLS; the same
architectural shape as `capabilities`/`roles`), seeded with a
deliberately minimal, factual list (CBSE, CISCE, IB, Cambridge, Other/
Custom — see `Database\Seeders\EducationBoardSeeder`). A School picks
**one default Board** via `schools.education_board_id` (nullable).

**Deliberately deferred**: a separate `Curriculum` entity distinct from
`EducationBoard`. Section 12 of this checkpoint's brief explicitly
permitted deferring this if `EducationBoard` + the Grade/Subject
structure is sufficient for V1 — it is: nothing in this checkpoint
needs to distinguish "the Board" from "the School's specific curriculum
version of that Board." If a real requirement emerges (e.g. two
Schools on CBSE running genuinely different subject sets that need
independent tracking beyond what `SubjectOffering` already provides),
introduce `Curriculum` as a School-owned entity referencing
`EducationBoard` at that time — the current schema does not need to
change to accommodate it.

**Deliberately deferred**: per-Campus Board override
(`docs/modules/ACADEMIC-STRUCTURE.md`'s own hypothetical
`CampusAcademicBoard`). A School operating multiple Boards across
Campuses is not a demonstrated requirement yet; the single
`schools.education_board_id` column is the simplest correct model for
now, and the future extension path (a nullable `campuses.education_board_id`
override, falling back to the School's default) requires no migration
of existing data if added later — it is purely additive.

**Not implemented, and explicitly out of scope**: any integration with
a real Board's systems, and any hard-coded assumption about which
Boards exist beyond the seeded catalog — `code` is an open string
specifically so a School's own "Other/Custom" or a future State Board
entry never requires an application code change.

## Academic Year lifecycle

States: `draft → active → closed`. (`archived` is reserved in the
column's comment but has no code path producing it yet — no reopening/
archival workflow exists in this checkpoint, matching section 18's
explicit scope limit.)

- **Creation** (`AcademicYearService::create()`) rejects a date range
  overlapping any existing Academic Year for the same School (section
  15's chosen default: overlap prohibited). This is an
  **application-layer** check, not a PostgreSQL exclusion/range
  constraint — the added complexity of a `daterange` generated column
  + `EXCLUDE USING gist` was judged not worth it for reference data
  with a low write rate. `starts_on < ends_on` IS a database `CHECK`
  constraint (defense in depth beyond Laravel's `after:starts_on`
  validation rule).
- **Activation** (`AcademicYearService::activate()`) is the one
  consequential lifecycle transition with a real concurrency
  guarantee: PostgreSQL's own **partial unique index**
  (`academic_years_one_active_per_school`, `WHERE status = 'active'`)
  is the actual "only one active year per School" guarantee — not an
  application-level check-then-update. Activating a new year
  automatically closes whatever year was previously active for that
  School, in the SAME transaction. A genuine race between two
  concurrent activation attempts for the SAME School (proven live,
  real separate OS processes, in
  `Tests\Feature\AcademicStructure\AcademicYearActivationConcurrencyTest`)
  results in exactly one winner; the loser receives
  `ACADEMIC_YEAR_ACTIVATION_CONFLICT` (409), translated from
  PostgreSQL's own `UniqueConstraintViolationException`, never a raw
  SQL error and never a silently-accepted second active row.
- **Closing** (`AcademicYearService::close()`) is a separate, explicit
  action — never an automatic side effect of anything except
  activating a *different* year. Reopening a closed year is **not
  implemented** (section 18: "once future student/finance/exam data
  exists, reopening may be sensitive" — this checkpoint establishes the
  state machine's foundation, not the full policy around reversing it).

## Academic Term

Belongs to exactly one AcademicYear. `AcademicTermService::create()`
enforces, at the application layer: the Term's dates must fall fully
within the parent Year's date range
(`ACADEMIC_TERM_OUT_OF_RANGE`), and Terms within the same Year must not
overlap (`ACADEMIC_TERM_OVERLAP`, section 21's chosen default). The
composite foreign key `(academic_year_id, school_id) → academic_years(id, school_id)`
makes a cross-School Year reference a database-level impossibility,
independent of the application checks.

## Grade Level / Section — historical safety

`GradeLevel` is **School-wide** reference data (section 62's chosen
default) — not Campus- or AcademicYear-scoped. Its `sequence` column
is the explicit pedagogical ordering (Nursery, LKG, UKG, Grade 1, …);
progression is never inferred from `name`/`code` string parsing.

`Section`, by contrast, belongs to **exactly one AcademicYear**
(section 28's mandatory historical-safety rule): "Grade 5 A" in
2026-27 and "Grade 5 A" in 2027-28 are two distinct database rows,
never the same row mutated or reused across years. This is what makes
future student-enrollment history possible without a redesign — an
enrollment record referencing a specific Section id will always point
at the exact historical (year, grade, campus) combination it actually
occurred in, permanently. `capacity` is advisory-only; no
admission-blocking logic reads it in this checkpoint (section 27).

## Subject / Subject Offering

`Subject` is **School-wide** reference data, optionally linked to one
`AcademicDepartment` (also School-wide). `SubjectOffering` is the
academic-offering layer — it is what actually says "GradeLevel X, in
Campus Y, for AcademicYear Z, offers Subject W" — so a Subject is never
assumed to apply to every Grade automatically (section 37).
`SubjectOffering` is **AcademicYear-specific by construction** (section
38): "2026-27 Grade 8 → French" and "2027-28 Grade 8 → Spanish" are
distinct rows; changing next year's offering never rewrites this year's
history.

**Deliberately NOT attached to Section** (section 40): a Subject is
offered to a GradeLevel within a Campus/AcademicYear and is inherited
by that Grade's Sections. A future section-specific elective model
(e.g. "only Section B offers French, not Section A") can extend this
schema later — `subject_offerings` would gain an optional
`section_id` column — without requiring a redesign of the existing
rows, which remain valid as "offered to the whole Grade."

## Multi-campus rules (the chosen default)

| Entity | Scope |
|---|---|
| AcademicYear | School-wide (shared across all Campuses) |
| AcademicTerm | Follows its AcademicYear (School-wide) |
| GradeLevel | School-wide |
| AcademicDepartment | School-wide |
| Subject | School-wide |
| Campus | The tenant's own sub-tenant dimension (ADR 0004) |
| Room | Campus-scoped |
| Section | AcademicYear + Campus + GradeLevel scoped |
| SubjectOffering | AcademicYear + Campus + GradeLevel scoped |

This is a deliberate default (section 62), not an accident: a
multi-campus School's calendar and curriculum taxonomy are shared,
while its physical spaces and actual class groupings are necessarily
per-Campus. Every reference to Campus, AcademicYear, or GradeLevel from
a School-scoped-but-not-School-wide entity (Room → Campus; Section →
AcademicYear/Campus/GradeLevel; SubjectOffering → AcademicYear/Campus/
GradeLevel/Subject) is protected by a **composite foreign key** against
`(id, school_id)` on the parent table — the same pattern
`school_memberships`/`membership_role_assignments` established in
Phase 0B, and `webhook_subscriptions`/`webhook_endpoints` in Phase
0C.3. A School A row referencing a School B parent is a foreign-key
constraint violation, not merely an application bug — proven directly
in `Tests\Feature\Postgres\AcademicStructureCrossRelationTest`.

## Reference-data lifecycle: deactivate, never delete

No DELETE endpoint exists for any Academic Structure entity in this
checkpoint (section 58). `GradeLevel`, `AcademicDepartment`, `Subject`,
`Room`, `Section`, `SubjectOffering`, and `Campus` all use a
`status` column (`active`/`inactive`) instead — list endpoints default
to `active` only, with an explicit `include_inactive` query parameter
to see historical/deactivated rows. This is deliberate: future SIS/
Attendance/Exams/Fees modules will hold real foreign-key references to
these rows, and a hard DELETE would either cascade-destroy that history
or require restrictive foreign keys that make ordinary reference-data
housekeeping error-prone. `AcademicYear` uses its own richer lifecycle
(`draft/active/closed`) for the same underlying reason.

## Case-insensitive code uniqueness

Every entity with a `code` column normalizes it to uppercase on write
(`App\Support\NormalizesCode`, a model-level mutator) and controllers
normalize inbound `code` values the same way before running a
`Rule::unique()` validation check (`App\Support\NormalizesCodeInput`)
— so `"math"`/`"MATH"`/`"Math"` collide with a clean `422`, not a raw
database constraint-violation exception. This project deliberately
does not add the PostgreSQL `citext` extension for this (section 65) —
a shared mutator trait was judged sufficient given the low cardinality
and write rate of this reference data.

## What Phase 0D deliberately does NOT implement

Per the hard stop-gates: no Student/Guardian/Enrollment records, no
Admissions, no Fees, no Attendance, no Examinations/gradebook, no
Timetable/period scheduling, no teacher assignment, no HR. Every place
a future module will need to reference a Student or Teacher (e.g. a
future `student_sections` enrollment table, a future teacher-to-Section
assignment) is left as an explicit gap for that future module to fill
— nothing here fakes or stubs that relationship.
