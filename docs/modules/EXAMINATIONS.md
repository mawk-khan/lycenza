# Examinations (Phase 0H.4)

## 1. Module scope and the first checkpoint

`docs/architecture/DOMAIN-MAP.md` defines **Examinations** as *exam
scheduling, grading, report cards*, and `docs/modules/ACADEMICS.md` §2
records the full boundary: *exam scheduling, **grading**, marks, grade
scales, result publication, **report cards**, transcripts*.

That umbrella is broad, so its first implementation checkpoint —
**Phase 0H.4A: Examination Foundation** — implements the narrowest
coherent fact underneath it.

| Layer | Name |
|---|---|
| Roadmap umbrella | **Examinations** (Phase 0H.4) |
| First checkpoint | **Phase 0H.4A — Examination Foundation** (implemented) |
| Second checkpoint | **Phase 0H.4B — ExaminationPaper / Scheduling** (implemented) |
| Third checkpoint | **Phase 0H.4C — GradeScale / GradeBand mapping** (implemented; published to main at `70e4a43`) |
| Domain directory | `app/Domain/Examinations` |
| Models / tables | `Examination` / `examinations`; `ExaminationPaper` / `examination_papers`; `GradeScale` / `grade_scales`; `GradeBand` / `grade_bands` |
| Capability families | **`examinations.definitions.*`**; **`examinations.papers.*`**; **`examinations.grade_scales.*`** |

**The capability root is `examinations.*`, deliberately depth-2.** A
flat `examinations.view`/`.manage` would eventually grant clerical marks
entry and principal-level result publication with the same key.
Depth-2 leaves clean room for `examinations.papers.*`,
`examinations.grade_scales.*`, `examinations.marks.*` and
`examinations.results.*`, matching the established
`timetable.periods.*`/`timetable.schedule.*` and
`canteen.directory.*`/`canteen.orders.*` module.area shape. It is **not**
`academics.*` (owned by Academic Structure) and never Academic
Structure's own `academics.years.*`, even though the parent AcademicYear
belongs to that module — the Canteen capability-boundary lesson.

## 2. Boundaries

| Concept | Owner |
|---|---|
| Curriculum delivery, lesson planning, syllabus tracking | **Academics** |
| Exam scheduling, **grading**, marks, grade scales, result publication, **report cards**, transcripts | **Examinations** |
| Learning content, **assignments**, **submissions**, coursework files | **LMS** (Phase 0I) |

`Examinations` depends on `Academics`; Academics depends on neither and
must never reference either. **An LMS assignment or a
`CurriculumDelivery` coverage record must never implicitly become a
grade** — a grade may only ever originate from an explicit Examinations
mark.

## 3. What an Examination is

> **One named assessment WINDOW that a School holds within one
> AcademicYear (e.g. "Mid-Term Examination 2026-27", 10–20 September),
> owning only its own identity and the date range it spans.**

**One row means:** this School has scheduled this named examination
window inside this AcademicYear, over these dates.

**It owns:** its code, its name, the AcademicYear it belongs to, its
start and end dates, and whether it is active.

**It does not own:** any Subject, SubjectOffering, Section, paper,
per-paper sitting date/time or max marks; any Student, enrollment,
teacher or invigilator; any mark, grade, result, publication state,
report card or transcript.

### A WINDOW, not a PAPER

This is the single most important thing to preserve about the entity.
One row is the examination period a School announces; it is **not** one
Subject's sitting. *"Mid-Term runs 10–20 September"* is a complete,
durable, useful statement a School publishes **before** any datesheet
exists — indeed schools announce the window first and the datesheet
later.

The per-Subject entity is a future **`ExaminationPaper`** (Phase 0H.4B,
behind its own architecture gate), which is what will carry
`SubjectOffering`, a per-paper date/time and max marks. The pair
`Examination` (container) + `ExaminationPaper` (member) is unambiguous
because the member noun carries "Paper" explicitly — the same
container/member shape as `AcademicYear`/`AcademicTerm` and
`SubjectOffering`/`SyllabusUnit`.

### Why `Examination`, not `ExaminationCycle`/`ExaminationWindow`

`docs/modules/ACADEMIC-STRUCTURE.md`'s naming discipline prefers the
real domain noun and avoids invented substitutes (`Session` avoided for
AcademicYear, `Course` avoided for Subject, `Class` banned outright).
Schools say *"the Mid-Term Examination"*, never *"the Mid-Term
Examination Cycle"*. With `ExaminationPaper` as the member noun there is
no ambiguity to resolve, and `ExaminationCycle` + `ExaminationPaper`
would be clumsier without being clearer.

## 4. Parents: School and AcademicYear, and only those

```
(academic_year_id, school_id) → academic_years(id, school_id)  ON DELETE RESTRICT
```

**No `campus_id`, no `grade_level_id`.** A multi-campus School normally
holds one Mid-Term across campuses and across grades. Per-campus or
per-grade variation is a **paper** concern — a paper pins a
`SubjectOffering`, which already pins AcademicYear × Campus × GradeLevel
× Subject. Adding either here would force a separate Examination row per
campus even when nothing differs.

With a single non-tenant parent there is no cross-parent pinning to do,
so CLAUDE.md rule 70's composite-context pattern does not arise — the
identical reasoning `syllabus_units` records. `unique(id, school_id)` is
retained so a future `ExaminationPaper` row can reference this table
tenant-pinned.

**No `academic_term_id`.** *"Midterm Examination"* is a **name**, not a
term reference; naming an examination after a term creates no data
dependency. A School that does not model AcademicTerms must still be
able to schedule examinations, and no invariant here needs one: date
validity is checked against the AcademicYear, which is the AcademicTerm's
own parent anyway. `AcademicTerm` additionally still has no lifecycle
status and no consuming domain. A nullable
`(academic_term_id, school_id)` reference is a purely additive future
path once a real invariant appears (term-wise report cards; *"term closed
⇒ marks frozen"* — the latter also requiring the lifecycle status
AcademicTerm lacks).

**No Student, enrollment, teacher or invigilator field.** There is no
`student_id`, `student_enrollment_id`, `student_subject_enrollment_id`,
`teacher_id`, `employee_id`, `user_id` or `invigilator_id` anywhere in
this module.

## 5. Schema

| Field | Type | Null | Notes |
|---|---|---|---|
| `id` | uuid | NOT NULL | PK, UUIDv7 (ADR 0019) |
| `school_id` | uuid | NOT NULL | Tenant root; FK → `schools(id)` CASCADE |
| `academic_year_id` | uuid | NOT NULL | Composite FK, RESTRICT; fixed at creation |
| `code` | varchar(64) | NOT NULL | `NormalizesCode`; CI-unique within the AcademicYear |
| `name` | varchar(255) | NOT NULL | Human label; **not** unique |
| `starts_on` | date | NOT NULL | School calendar date the window opens |
| `ends_on` | date | NOT NULL | School calendar date it closes; `>= starts_on` |
| `status` | varchar | NOT NULL | `active` \| `inactive`, DB CHECK, default `active` |
| `created_at` / `updated_at` | timestamp | NOT NULL | Standard |

**Deliberately absent:** `academic_term_id`, `campus_id`,
`grade_level_id`, `subject_id`, `subject_offering_id`, `section_id`,
`syllabus_unit_id`, `curriculum_delivery_id`, `timetable_entry_id`, any
attendance reference, any Student/enrollment/teacher/invigilator
identity, `sequence`, `description`/`instructions`/any free text, and
every paper / per-paper time / `max_marks` / score / grade / grade-scale
/ result / publication / report-card / transcript / attachment field.

`description` is excluded on the same grounds `syllabus_units` excluded
it: an unbounded prose column is an audit-leakage and classification
hazard — it could carry Student-specific commentary.

## 6. Code normalization and uniqueness

`App\Support\NormalizesCode` uppercases/trims on assignment;
`App\Support\NormalizesCodeInput` normalizes inbound input **before**
validation so an ordinary duplicate returns a clean `422` rather than a
raw constraint violation (CLAUDE.md rule 74).

The authoritative invariant is the database's own expression index:

```sql
CREATE UNIQUE INDEX examinations_year_code_ci_unique
ON examinations (school_id, academic_year_id, upper(code));
```

Model-layer normalization alone is not relied upon — a raw insert
bypassing the mutator would not collide with a plain-string index.

**The index is unconditional**, deliberately not scoped
`WHERE status = 'active'`. An inactive Examination continues to reserve
its code, which is exactly what makes reinstatement conflict-free — and
is why this entity needs no activate/deactivate command (§7).

`MID1` and `mid1` cannot coexist in one AcademicYear; the same
normalized code **legitimately recurs in a different year** ("MID1"
every year is a distinct row).

## 7. Lifecycle — and why there is no DELETE and no activate/deactivate

Create · view · update · retire/reinstate via `status` **in the ordinary
PATCH**.

This follows Academic Structure's and Academics' reference entities
(`Section`, `SubjectOffering`, `Room`, `Subject`, `GradeLevel`,
`AcademicDepartment`, `SyllabusUnit`), none of which has a lifecycle
route. The entities that *do* carry `activate`/`deactivate` commands in
this codebase — `AcademicYear`, `TimetablePeriod`, `TimetableEntry` —
each re-validate a real invariant on activation. An `Examination` can
conflict with nothing on reinstatement, because its unique code index is
unconditional.

**Deliberately NOT a `draft`/`active`/`closed` state machine.** Every
concern such a machine would serve — papers frozen after closure, marks
frozen, results published, archived — belongs to a checkpoint that does
not exist yet, and none of its transitions would re-validate any
invariant. Adding it now would be a speculative state machine
(CLAUDE.md rule 2).

**No delete route** (rule 73): a cancelled or mistaken Examination is
marked `inactive` and kept, since a future `ExaminationPaper` or mark
will hold a RESTRICT reference to it. `status = inactive` is what makes
the no-delete lifecycle stable — it will not need a breaking change when
0H.4B lands.

## 8. Date semantics — two deliberate departures from Curriculum Delivery

Dates are **School calendar dates** (`date` columns), matching
`academic_years`, `academic_terms` and `curriculum_deliveries`.

### Future dates are PERMITTED AND EXPECTED

This is the opposite of `CurriculumDelivery`'s rule, and it is not an
oversight. A delivery records what **has** happened; an examination is
**scheduled ahead**. `academic_years` and `academic_terms` both
routinely carry wholly future ranges at creation and neither service
applies a not-future rule. Forbidding future dates would make this
entity useless.

### Overlapping windows are PERMITTED

Also deliberate. No business invariant forbids a School running
"Grade 10 Board Prep" and "Grade 6 Unit Test" in overlapping weeks.
`academic_terms` forbids overlap only because terms **partition** a year
by definition; examinations partition nothing.

**This is precisely why this module has no `TenantLock`, no advisory
lock, no `lockForUpdate()`, no exclusion constraint and no concurrency
test** — there is no multi-row invariant at all. An architecture guard
test fails if any locking is ever introduced.

### The remaining date rules

- both dates must fall inside the AcademicYear's **inclusive**
  `[starts_on, ends_on]` — mirroring `AcademicTermService::assertWithinYear()`;
- `ends_on >= starts_on`, enforced in the service **and** authoritatively
  by `examinations_date_order_check`;
- **the AcademicYear is NOT required to be active.** Defining a future
  examination inside a `draft` year is legitimate planning work.
  Curriculum Delivery's active-year-on-create rule deliberately does not
  apply, because that rule exists for records of what has already
  happened.

Corrections re-run every applicable invariant: an existing row is not a
back door around them.

## 9. Classification: Confidential

`docs/security/DATA-CLASSIFICATION.md` defines **Confidential** as
*business-sensitive data whose disclosure would harm the school or the
product, but which is **not personal data***, requiring authentication
plus a specific capability and exclusion from routine debug logging.

An `Examination` contains **no personal data at all** — no Student,
Guardian, Employee, teacher or invigilator identity. It states only that
a School scheduled a named window within a year. The direct precedents
are the `SyllabusUnit` and `CurriculumDelivery` rows.

**Forward note — the re-tier triggers.** Each of these would elevate the
affected entity to **Sensitive**, requiring the classification change in
the same branch:

- attaching an **invigilator or teacher** identity, by the Timetable
  row's exact reasoning (Sensitive only because a `TimetableEntry`
  identifies a specific Employee);
- **Student marks**, by the `AttendanceRecord` row's reasoning;
- **individual results**, report cards or transcripts.

*Superseded for marks (RES.0B, 2026-10-06, ADR 0068 R2):* Student marks
and their corrections are **Highly Sensitive** children's data, not merely
Sensitive (`DATA-CLASSIFICATION.md`, "Student marks and mark corrections").
The note above stays as history.

## 10. Structural integrity / RLS

UUIDv7 · `BelongsToSchool` · `TenantRls::enable('examinations')` (ENABLE
**and** FORCE) · tenant policy on `school_id` · `unique(id, school_id)` ·
composite `(academic_year_id, school_id)` FK RESTRICT ·
`CHECK (status IN ('active','inactive'))` ·
`CHECK (ends_on >= starts_on)` · `examinations_year_code_ci_unique`.

Exactly two foreign keys — the tenant root and the AcademicYear.

Proven at the raw-SQL layer in
`Tests\Feature\Postgres\ExaminationsRlsIsolationTest`, including
fail-closed behaviour with no tenant context.

## 11. Application architecture

**Application service required** —
`App\Domain\Examinations\Application\ExaminationService`, the sole write
path.

CLAUDE.md rule 76 names the trigger literally: *"a dedicated Application
service is required once real invariants exist (**date-range**/overlap
validation, a multi-step state machine)."* An Examination's window must
lie inside its parent AcademicYear's own range, which requires a parent
lookup and therefore cannot be expressed as a database CHECK. The
nearest structural sibling settles it: `AcademicTermService` exists for
exactly this shape. `SyllabusUnit`'s thin controller is not the
precedent here — it had *no* date validation at all, which is precisely
the distinction rule 76 draws.

**Server-derived context.** Callers supply only `code`, `name`,
`starts_on`, `ends_on` and optionally `status`. `school_id` comes from
the tenant context and `academic_year_id` from the trusted nested
route's resolved parent; neither is ever accepted from request data
(rules 19/68), and `academic_year_id` is absent from `$fillable` so no
mass-assignment path can reach it. **An Examination's AcademicYear is
fixed at creation and can never be reassigned.**

**Duplicate-code translation is constraint-specific.** The service
catches `UniqueConstraintViolationException` and translates it **only**
when the message names `examinations_year_code_ci_unique`; any other
unique violation is rethrown as the unexpected failure it is.

Both controllers are thin and perform no model write; an architecture
guard test enforces it.

## 12. API — exactly four operations

| Method | Path (`/api/v1/schools/{school}`) | Capability |
|---|---|---|
| `GET` | `/academic-years/{academicYear}/examinations` | `examinations.definitions.view` |
| `POST` | `/academic-years/{academicYear}/examinations` | `examinations.definitions.manage` |
| `GET` | `/examinations/{examination}` | `examinations.definitions.view` |
| `PATCH` | `/examinations/{examination}` | `examinations.definitions.manage` |

Nesting follows `AcademicTermController`'s established "nested for
collection, flat for instance" convention. Index filters on optional
`status`; ordering is `starts_on`, then `upper(code)`. Both mutations
carry `throttle:school-api-mutations`.

Public representation is exactly `id`, `academicYearId`, `code`, `name`,
`startsOn`, `endsOn`, `status` — `schoolId` is the route's own context
and is not exposed.

No DELETE, no activate/deactivate, no paper, scheduling, marks,
grade-scale, result, report-card, transcript, search, bulk or reporting
endpoint.

**No `Idempotency-Key`**: duplicate creation is already prevented by
`examinations_year_code_ci_unique` and PATCH is naturally idempotent
(rule 29 evaluated per endpoint, never blanket-applied). No Examination
response is ever written to `api_idempotency_keys`, so rule 36's
stored-response review does not arise.

## 13. Administrative UI

`/app/examinations` — resolve an AcademicYear (defaulting to the
School's active year) → list that year's examination windows in
chronological order → create → edit `code`/`name`/dates/`status`.

It reuses the AcademicYear filter shape
`App\Http\Controllers\App\SubjectOfferingController::index()` already
established; **no new lookup or discovery API was built**. Three web
routes: index, store, update.

No paper editor, no scheduling grid, no marks entry, no grade-scale
editor, no results view, no Student roster, no teacher UI, no report
cards, no attachments.

## 14. Audit

`AuditRecorder`, bounded metadata:

- `examinations.examination.created` — `examinationId`,
  `academicYearId`, `code`, `startsOn`, `endsOn`
- `examinations.examination.updated` — `examinationId`,
  `changedFields` (names only), and before/after **values** for `code`,
  `starts_on`, `ends_on` and `status` only

**`name` values never appear in audit metadata** — a name change is
reported by field name only, so an audit row never becomes a second copy
of School-authored content (the rule `docs/modules/ACADEMICS.md` §14
established for `title`). There are no lifecycle audit actions because
those endpoints do not exist.

## 15. Events, Communications, Documents

**Zero domain events.** No `ExaminationCreated`, no
`ExaminationUpdated`, nothing — no consumer exists (rule 2). Not
registered in `App\Support\Webhooks\WebhookEventRegistry`, so it is not
externally subscribable (rules 45/77). No Communications integration, no
Notifications, no exam-reminder behaviour, no Documents, no LMS, no
outbox write, no Student/SIS projection.

`AcademicTerm` emits `AcademicTermCreated`; that is explicitly **not** a
reason for `Examination` to emit one. Each domain must justify its own
consumer.

## 16. Required and elective SubjectOfferings — a future constraint

Phase 0H.4A contains **no SubjectOffering or Student relationship at
all**, so the question does not arise here. It is recorded now because
it binds the future paper and marks checkpoints:

> **Examinations must eventually support BOTH required and elective
> SubjectOfferings.** Curriculum Delivery's required-only restriction
> must **not** be inherited. That restriction exists because its
> aggregate is `Section × SyllabusUnit` and an elective has no
> Section-wide cohort; Examinations' aggregate is per-**Student**, and
> individual elective enrollment is exactly what
> `StudentSubjectEnrollment` records.

`App\Domain\Students\Application\SubjectOfferingRosterReadService` is
the authoritative seam for resolving the current Student roster of
either Offering type. **It is deliberately not consumed in 0H.4A** —
there is no Student or Offering fact to resolve.

## 17. Not in this checkpoint

GradeScale · StudentMark/marks entry · grading · result calculation ·
result publication or revocation · report cards · transcripts ·
promotion decisions · attendance at exams · assessment
components/weighting (Paper 1/Paper 2, theory/practical) · teacher or
invigilator identity · room/invigilator allocation · ownership-based
authorization · Student, StudentEnrollment or StudentSubjectEnrollment
logic · rosters · Section/Syllabus/CurriculumDelivery/AcademicTerm
references · Timetable dependency · Attendance dependency · LMS
assignment results · attachments/Documents · free text · domain events ·
webhooks · analytics/AI · schedule-conflict optimization · Lesson
Planning.

## 18. Phase 0H.4B — ExaminationPaper / Scheduling

> **One SubjectOffering assessed within one Examination, with its
> scheduled sitting (date and time range) and maximum obtainable
> marks.**

An Examination **child** and an **Offering-wide** scheduling fact — NOT
a physical uploaded question-paper file, NOT Section-specific, NOT a
Student attempt, NOT a mark/result, NOT an LMS assignment. The pair
`Examination` (container) + `ExaminationPaper` (member) resolves exactly
as §3 anticipated.

### 18.1 Cardinality and aggregate

Exactly **one** Paper per `(school_id, examination_id,
subject_offering_id)` — `examination_papers_examination_offering_unique`,
**unconditional** (never scoped `WHERE status = 'active'`), so an
inactive Paper continues to reserve the pair and ordinary reactivation
is conflict-free. No Paper 1/Paper 2, no theory/practical component, no
sequence — a future architecture/migration would be required for
multi-component papers.

### 18.2 Required and elective SubjectOfferings — §16's constraint honoured

Both are supported **identically**; `is_required` is inspected only for
UI presentation, never for eligibility. `App\Domain\Students\Application\SubjectOfferingRosterReadService`
is **still not called** — Student roster resolution remains deferred to
a future marks checkpoint, exactly as §16 reserved it.

### 18.3 No `section_id` — Offering-wide, not Section-specific

Future Student eligibility derives from the Offering roster seam, not
from this Paper carrying a Section.

### 18.4 The additive Examination context key

`examinations_context_unique`: `UNIQUE (id, school_id,
academic_year_id)`, added by a dedicated additive migration that runs
**before** `examination_papers`' own creation migration. Every
pre-existing Examination constraint, RLS policy and index is untouched —
proven by rerunning `ExaminationsRlsIsolationTest`/
`ExaminationArchitectureGuardTest`/`ExaminationApiTest` unchanged (with
two narrow, controller-class-based route-filter corrections documented
in ADR 0033 §"Deviations", made necessary only because the new
ExaminationPaper routes deliberately share the literal path segment
"examinations").

### 18.5 Cross-parent PostgreSQL integrity

Two composite FKs, **both referencing the same stored
`academic_year_id` column**:

```
(examination_id, school_id, academic_year_id)
  → examinations(id, school_id, academic_year_id)              RESTRICT
(subject_offering_id, school_id, academic_year_id, campus_id, grade_level_id)
  → subject_offerings(id, school_id, academic_year_id, campus_id, grade_level_id)  RESTRICT
```

This is what **structurally, not just by application validation**,
guarantees `Examination.academic_year_id == SubjectOffering.academic_year_id`
— even through a raw SQL insert bypassing
`ExaminationPaperService` entirely (CLAUDE.md rule 70). Proven in
`Tests\Feature\Postgres\ExaminationPapersRlsIsolationTest`, including
that no single forged `academic_year_id` can satisfy both FKs when the
parents genuinely disagree.

`academic_year_id`, `campus_id` and `grade_level_id` are **internal
integrity pins**: server-derived inside the service, never accepted from
a client, and never exposed in the public API representation.

### 18.6 Schema

| Field | Type | Null | Notes |
|---|---|---|---|
| `id` | uuid | NOT NULL | PK, UUIDv7 |
| `school_id` | uuid | NOT NULL | Tenant root; FK → `schools(id)` CASCADE |
| `examination_id` | uuid | NOT NULL | Composite FK, RESTRICT; immutable after create |
| `subject_offering_id` | uuid | NOT NULL | Composite FK, RESTRICT; immutable after create |
| `academic_year_id` | uuid | NOT NULL | Internal pin, never exposed |
| `campus_id` | uuid | NOT NULL | Internal pin, never exposed |
| `grade_level_id` | uuid | NOT NULL | Internal pin, never exposed |
| `scheduled_on` | date | NOT NULL | School calendar date; inside the Examination's inclusive window |
| `starts_at` | time | NOT NULL | School-local wall-clock time |
| `ends_at` | time | NOT NULL | School-local wall-clock time; `> starts_at`, same day only |
| `max_marks` | numeric(6,2) | NOT NULL | `> 0`, DB CHECK |
| `status` | varchar | NOT NULL | `active` \| `inactive`, DB CHECK, default `active` |
| `created_at` / `updated_at` | timestamp | NOT NULL | Standard |

`unique(id, school_id)` is retained for a future StudentMark tenant-pinned
reference, exactly as `examinations`/`subject_offerings` already do.

**Deliberately absent:** `section_id`, `student_id`,
`student_enrollment_id`, `student_subject_enrollment_id`, `teacher_id`,
`employee_id`, `invigilator_id`, `room_id`, `timetable_entry_id`,
`academic_term_id`, `syllabus_unit_id`, `curriculum_delivery_id`, a
duplicate Subject column, `code`/`title`/`component`/`paper_number`, any
physical document/file field, marks/scores, grade scale, pass marks,
result/publication data, and any free-text description/instructions.

### 18.7 Local time semantics

`scheduled_on`/`starts_at`/`ends_at` are School-local wall-clock values —
never converted to or stored as UTC, no timezone column, no dispatch
scheduler. Same-day sittings only: `examination_papers_time_order_check`
enforces `ends_at > starts_at`; overnight sittings are unsupported in v1.

### 18.8 Overlap is permitted, exactly like Examination itself

Overlaps across **different** SubjectOfferings are intentionally
allowed — no overlap query, no roster intersection, no Student-aware
conflict detection, no `TenantLock`, no advisory lock, no exclusion
constraint. The only duplicate prevention is §19.1's aggregate unique
constraint. Proven with a positive test, mirroring `examinations`' own
"future-dated and overlapping windows accepted" precedent.

### 18.9 Lifecycle, active-parent rules and the reactivation guard

No DELETE route, no activate/deactivate command — `status` moves through
the ordinary PATCH, exactly like `Examination` itself.

**Create** requires BOTH the Examination and the SubjectOffering to be
currently active — `ExaminationNotActiveException` /
`SubjectOfferingNotAvailableException` (422), applying identically to
required and elective Offerings.

**Ordinary corrections never re-check parent activity** — an existing
Paper remains correctable as history even after a parent is later
deactivated, and no cross-domain hook synchronizes Paper status when a
parent is deactivated.

**Reactivation is the one deliberate asymmetry.** When an update moves
`status` from `inactive` to `active`, BOTH parents must currently be
active, or the same two domain exceptions apply — this prevents
reintroducing a Paper into active use beneath a withdrawn parent while
still allowing pure historical correction. No lock or CAS is required;
this is an ordinary invariant evaluated only on that specific
transition.

`examination_id` and `subject_offering_id` are **immutable after
create** — absent from the PATCH-accepted field set entirely, so
"changing the wrong Offering" is represented by inactivating the
incorrect Paper and creating a new one, never by repointing an existing
row.

### 18.10 Classification: Confidential

No Student, Employee, teacher/invigilator or roster-snapshot identity —
the same reasoning as `Examination` itself. Re-tier triggers: a future
Student roster snapshot, `StudentMark`, or teacher/invigilator identity
would each elevate the affected entity to Sensitive.

### 18.11 Audit

`AuditRecorder`, bounded metadata:

- `examinations.paper.created` — `paperId`, `examinationId`,
  `subjectOfferingId`, `scheduledOn`, `startsAt`, `endsAt`, `maxMarks`,
  `status`
- `examinations.paper.updated` — `paperId`, `changedFields` (names
  only), before/after limited to `scheduled_on`, `starts_at`, `ends_at`,
  `max_marks`, `status`

No Subject/Offering label, no Student data, no teacher identity, no full
model dump. No separate activated/deactivated audit actions.

### 18.12 API — exactly four operations

| Method | Path (`/api/v1/schools/{school}`) | Capability |
|---|---|---|
| `GET` | `/examinations/{examination}/examination-papers` | `examinations.papers.view` |
| `POST` | `/examinations/{examination}/examination-papers` | `examinations.papers.manage` |
| `GET` | `/examination-papers/{examinationPaper}` | `examinations.papers.view` |
| `PATCH` | `/examination-papers/{examinationPaper}` | `examinations.papers.manage` |

Index orders by `scheduled_on`, then `starts_at`. Public representation
is exactly `id`, `examinationId`, `subjectOfferingId`, `scheduledOn`,
`startsAt`, `endsAt`, `maxMarks`, `status` — `schoolId` and every
integrity pin are never exposed. No `Idempotency-Key`: duplicate
creation is already prevented by the aggregate unique constraint, and
PATCH is naturally idempotent.

### 18.13 Administrative UI

`/app/examinations/{examination}/papers` — a drill-down from the
Examinations list (`resources/js/Pages/App/Examinations/Papers/Index.vue`).
Offering selector scoped to active Offerings within the same
AcademicYear as the Examination (convenience only — the service
independently enforces active-Offering on write). Inactive Papers remain
visible and editable in the management list; reactivation is denied with
a clear validation error when a parent is currently inactive. Three web
routes: index, store, update.

### 18.14 Events, Communications, Documents

**Zero domain events**, exactly like `Examination` — no consumer exists.
Not registered in `WebhookEventRegistry`. No Timetable, Attendance, LMS,
Lesson Planning, Communications, Notifications or Documents integration.

## 19. Phase 0H.4C — GradeScale / GradeBand mapping (implemented; published)

A named, School-owned mapping that converts a normalized percentage
(0.00–100.00) into a discrete grade outcome through its ordered
GradeBands — wholly independent of the Examination chain, exactly as
ADR 0032's provisional sequence anticipated. Full design and rationale: ADR 0035
(`docs/architecture/adr/0035-grade-scale-band-mapping.md`).

**GradeBand stores only a lower-bound threshold** (`min_percentage`,
`label`) — no upper bound, no sequence. A percentage maps to the band
with the greatest `min_percentage <= P`. Overlap-freedom is a plain
`UNIQUE (grade_scale_id, min_percentage)` constraint; coverage/gap-
freedom is a single check — a GradeScale may activate only if it has a
GradeBand at `min_percentage = 0.00`.

**Lifecycle**: `draft | active | inactive`, exactly three legal
transitions (`draft->active`, `active->inactive`, `inactive->active`);
every other transition, including every no-op, is illegal. GradeBands
are mutable only while the parent is `draft`; once a scale has ever
been `active`, its bands are frozen forever. `code` is immutable after
creation; `name` is mutable at any lifecycle stage.

**Concurrency**: every mutating `GradeScaleService` method reloads the
target GradeScale with a parent-row `lockForUpdate()` — an aggregate-
local lock, deliberately NOT a School-wide `TenantLock`. Proven with
two real, separate OS processes in `GradeScaleConcurrencyTest`.

**API/web surface**: exactly seven API operations
(list/create/read/update the scale; create/update/delete a band) and
six web routes. No GradeScale DELETE route; GradeBand removal is the
sole delete anywhere in the surface.

**Audit**: GradeBand `label` values and GradeScale `name` values are
never included by value in audit metadata — only field names
(`changedFields`) and bounded status values.

StudentMark and result calculation remain blocked: architecture/
backend implementation has legal approval with conditions
(`docs/security/STUDENTMARK-CHILDRENS-DATA-DETERMINATION.md`), but
implementation has not started, pending its remaining platform
prerequisites (Phase 0H.4D-P1 Staff MFA — published; Phase 0H.4D-P2
Student Processing Authorization Registry, ADR 0038 — implemented;
Phase 0H.4D-P3 elective historical eligibility — not yet started), and
production enablement/Student-facing/Guardian-facing/result
publication remain separately withheld pending their own review (§20
below). GradeScale itself carries no Student/Enrollment/marks data and
ships fully independently of that blocker, as anticipated.

## 20. Future

- **Marks** (PROVISIONAL, **GATED**): the first per-Student academic
  record. This crosses from Confidential into **Sensitive** personal
  data, so the checkpoint that introduces it must undergo a dedicated
  privacy/security architecture audit first — including the
  `[LEGAL REVIEW REQUIRED]` children's-data gate
  (`docs/security/DATA-CLASSIFICATION.md`). A mark must carry **no
  free-text remark column**, or it risks drifting toward the Health
  tier's own legal gate.
- **Result calculation / publication** and **report cards /
  transcripts** (PROVISIONAL): recording a fact, deriving a result,
  publishing it and generating a document are four different things and
  are unlikely to belong in one checkpoint.

**Owner scope decision (2026-09-29, ADR 0061).** Examinations is **not**
complete: its foundations (§1–§19) are delivered as built.
- **Deferred post-v1, not required for Phase Zero closure** (Phase 0H is
  CLOSED FOR PHASE ZERO):
  - Phase 0H.4D-P3 elective historical eligibility;
  - StudentMark / marks entry;
  - grading beyond GradeScale/GradeBand;
  - result calculation;
  - result publication or revocation;
  - report cards;
  - transcripts;
  - Student/Guardian-facing surfaces;
  - any dependent Documents/report generation.
- **StudentMark determination:** unchanged and not widened. It must be
  revalidated when StudentMark is reopened (ADR 0061 §2.4).
- **Nothing resumes automatically** (ADR 0061 §2.5).

**RES reopening (RES.0B, 2026-10-06, ADR 0068).** RES.0 audited this
module; ADR 0068 reopens **only** two things:
- **P3** — a Students-owned as-of-date SubjectOffering eligibility seam
  (RES.1). It replaces the current-roster read for any historical
  question; this module never reproduces placement or enrollment logic.
- **Internal StudentMark** — administrative marks entry per
  ExaminationPaper (RES.2), a per-paper `open` → `locked` state and
  append-only, maker/checker corrections after lock (RES.3). RES-L0 was
  determined CURRENT WITH CHANGES on 2026-10-07: RES.2 is authorised for
  development only, under ADR 0068 §19's conditions (history kept before
  lock too; a withdrawn basis withholds reads). Teacher entry is RES.4
  (RES-L2, E33, RES-L0 re-review).

**Guardrails — ADR 0068 is not authority for any of these:** result
calculation, finalization, publication or revocation; GradeScale selection,
grade points, GPA, pass/fail; report cards; transcripts; Student- or
Guardian-facing marks or results; rank or merit order; promotion or
detention from marks; attendance-based or statutory examination
eligibility; board-specific grading; components, weighting, grace, bonus,
moderation or normalization; any outbox event, webhook, notification,
Document, Analytics or AI consumer of marks. Each needs its own legal
answer (RES-L4 – RES-L9) and contract. Marks never live on
`examination_papers`, `examinations` or `grade_scales`: the existing guard
tests keep those column sets closed.

## Retention (E21.3D, 2026-10-02)

Examinations and examination papers are tenant-lifetime School academic
configuration without personal data (E21.2G A2): no E21 mechanism expires
them (`AcademicRetentionArchitectureGuardTest`). Student marks and results
are not implemented.

## StudentMark (RES.2, 2026-10-07; ADR 0068 §20)

Internal, administrative marks entry per ExaminationPaper, **development only**
(production: RES-L1). Highly Sensitive.
- **Data:** `student_marks` (one per paper × Student; `present` with a value
  0..max, `absent`, `exempt`; the P3 placement, eligibility source, elective
  row and ADR 0038 authorization it was written under; a version) and
  `student_mark_revisions` (the value history of every write, written by the
  database; insert-only). No remark, grade, percentage, pass/fail, rank or
  publication field.
- **Surface:** `GET` / `PUT /app/examination-papers/{paper}/marks` (session
  JSON, `examinations.marks.view` / `.manage` + `mfa`); `school_admin` and
  `principal` only.
- **Rules:** P3 eligibility on the paper's date; a current processing basis
  for every write (and for showing a value); optimistic versions; atomic
  batches; a closed year or an inactive paper refuses; once marked, a paper's
  `max_marks` and `scheduled_on` are frozen.
- **Not built:** lock, corrections (RES.3), teacher entry (RES.4), results,
  report cards, transcripts, Student/Guardian access, exports, analytics.
