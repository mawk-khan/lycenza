# Academics (Phase 0H.3)

## 1. Module scope and the Academics → Syllabus → `syllabus.*` mapping

`docs/architecture/DOMAIN-MAP.md` defines **Academics** as *curriculum
delivery, lesson planning, syllabus tracking*.

That umbrella is broad, so its first implementation checkpoint —
**Phase 0H.3A: Syllabus Foundation** — implements the narrowest
coherent fact underneath it, and the implementation artefacts are named
after that fact rather than after the umbrella:

| Layer | Name |
|---|---|
| Roadmap umbrella | **Academics** (Phase 0H.3) |
| First checkpoint | **Phase 0H.3A — Syllabus Foundation** |
| Domain directory | `app/Domain/Syllabus` |
| Model / table | `SyllabusUnit` / `syllabus_units` |
| Capability family | **`syllabus.*`** |
| Second checkpoint | **Phase 0H.3B — Curriculum Delivery** |
| Domain directory | `app/Domain/CurriculumDelivery` |
| Model / table | `CurriculumDelivery` / `curriculum_deliveries` |
| Capability family | **`curriculum.delivery.*`** |

Both checkpoints follow the same naming principle: the implementation
artefacts are named after the **fact**, not the umbrella. Curriculum
Delivery's capability root is `curriculum.delivery.*` — a **sibling** of
`syllabus.*`, not an extension of it, because the catalogue and its
delivery are independently grantable concerns (a future teacher role
must be able to record delivery *without* the right to rewrite the
syllabus). It is deliberately depth-2 rather than a bare `curriculum.*`,
which would imply rights over a `Curriculum` entity that Academic
Structure explicitly defers, and it is still not `academics.*` for the
reason recorded above.

**The capability root is `syllabus.*`, deliberately NOT `academics.*`.**
The `academics.*` root is already fully owned by **Academic
Structure** — `academics.structure.*`, `academics.years.*`,
`academics.subjects.*` — and a second, unrelated family under the same
root would leave an administrator granting rights unable to tell which
domain a capability governs. Academic Structure's published
capabilities are **not** renamed. This divergence is a deliberate
architectural decision recorded here so it can never later be mistaken
for an accidental inconsistency.

## 2. Boundaries

| Concept | Owner |
|---|---|
| Curriculum delivery, lesson planning, syllabus tracking | **Academics** |
| Exam scheduling, **grading**, marks, grade scales, result publication, **report cards**, transcripts | **Examinations** |
| Learning content, **assignments**, **submissions**, coursework files | **LMS** (Phase 0I) |

`Examinations` depends on `Academics`; Academics depends on neither and
must never reference either.

## 3. What a SyllabusUnit is

> **One ordered unit of instructional content that a `SubjectOffering`
> is expected to cover.**

It is a catalogue of **expected** content. It records nothing about
what was actually taught (a future Curriculum Delivery checkpoint),
nothing about an individual lesson (future Lesson Planning), and
nothing about any Student.

## 4. Parent: SubjectOffering, and only SubjectOffering

Every `SyllabusUnit` belongs to exactly one `SubjectOffering`, pinned
tenant-safely:

```
(subject_offering_id, school_id) → subject_offerings(id, school_id)  ON DELETE RESTRICT
```

`SubjectOffering` already pins AcademicYear × Campus × GradeLevel ×
Subject, so **none of those is denormalized** onto `syllabus_units`.
The composite-context pattern used by `timetable_entries` and
`attendance_records` exists to pin **two** parents to the *same*
context; this table has one parent, so copying context would add drift
surface for no invariant. `unique(id, school_id)` is retained so a
future Curriculum Delivery row can reference a unit tenant-pinned.

**No `Curriculum` entity.** Academic Structure's deliberate deferral
stands: its reopening criteria (*"two Schools on CBSE running genuinely
different subject sets that need independent tracking beyond what
`SubjectOffering` already provides"*) remain unmet, and a
per-Offering syllabus is strictly finer-grained than a School-wide
curriculum.

**No `academic_term_id`.** `AcademicTerm` has no status column, no
lifecycle and no consumers; a syllabus is year-scoped through its
Offering. A nullable term reference is a purely additive future path.

**No `section_id`.** The syllabus is Offering-wide — every Section
taking an Offering is expected to cover the same content. Per-Section
variation is a *delivery* concern.

**Required and elective Offerings are both supported.** The syllabus
belongs to the Offering itself, so Timetable v1's required-only
scheduling restriction (which exists because scheduling presumes a
Section-wide cohort) deliberately does not apply to a catalogue.

**No Student roster and no teacher ownership.** There is no
`student_id`, no `student_enrollment_id`, no
`student_subject_enrollment_id`, no `teacher_id` and no `user_id`
anywhere in this module.

## 5. Schema

| Field | Type | Null | Notes |
|---|---|---|---|
| `id` | uuid | NOT NULL | PK, UUIDv7 (ADR 0019) |
| `school_id` | uuid | NOT NULL | Tenant root; FK → `schools(id)` CASCADE |
| `subject_offering_id` | uuid | NOT NULL | Composite FK, RESTRICT |
| `code` | varchar(64) | NOT NULL | `NormalizesCode`; CI-unique within the Offering |
| `title` | varchar(255) | NOT NULL | Unit name |
| `sequence` | integer (unsigned) | NOT NULL | Teaching order; **not unique** |
| `status` | varchar | NOT NULL | `active` \| `inactive`, DB CHECK, default `active` |
| `created_at` / `updated_at` | timestamp | NOT NULL | Standard |

**Deliberately absent:** `description`, free-text notes, learning
outcomes/objectives, `parent_id`, `academic_term_id`, `section_id`,
`teacher_id`, any Student field, dates, score/marks/weight/grading
fields, files and attachments.

`sequence` is non-unique on purpose: two units may legitimately share a
position mid-reorder, and a unique sequence would turn every reorder
into a multi-statement dance through temporary values. Ties break
deterministically on `upper(code)`.

## 6. Code normalization and uniqueness

`App\Support\NormalizesCode` uppercases/trims on assignment;
`App\Support\NormalizesCodeInput` normalizes inbound input **before**
validation so an ordinary duplicate returns a clean `422` rather than a
raw constraint violation.

The authoritative invariant is the database's own expression index:

```sql
CREATE UNIQUE INDEX syllabus_units_offering_code_ci_unique
ON syllabus_units (school_id, subject_offering_id, upper(code));
```

Model-layer normalization alone is not relied upon — a raw insert
bypassing the mutator would not collide with a plain-string index.

**The index is unconditional**, deliberately not scoped
`WHERE status = 'active'`. An inactive unit continues to reserve its
code, which is exactly what makes reinstatement conflict-free — and is
why this entity needs no activate/deactivate command (§7).

`U1` and `u1` cannot coexist under one Offering; the same normalized
code may exist under a different Offering.

## 7. Lifecycle — and why there is no DELETE and no activate/deactivate

Create · view · update · retire/reinstate via `status` **in the
ordinary PATCH**.

This follows Academic Structure's reference entities (`Section`,
`SubjectOffering`, `Room`, `Subject`, `GradeLevel`,
`AcademicDepartment`), none of which has a lifecycle route. The
entities that *do* carry `activate`/`deactivate` commands in this
codebase — `AcademicYear`, `TimetablePeriod`, `TimetableEntry` — each
re-validate a real invariant on activation (overlap, conflict,
one-active-per-School). A `SyllabusUnit` can conflict with nothing on
reinstatement, because its unique code index is unconditional.

**No delete route** (CLAUDE.md rule 73): a retired unit is kept, since
a future Curriculum Delivery or Examinations row may reference it.

## 8. Classification: Confidential

`docs/security/DATA-CLASSIFICATION.md` defines **Confidential** as
*business-sensitive data whose disclosure would harm the school or the
product, but which is **not personal data***, requiring authentication
plus a specific capability and exclusion from routine debug logging.

A `SyllabusUnit` contains **no personal data at all** — no Student,
Guardian, Employee or teacher identity. This is precisely what
separates it from Timetable, which the same document classifies
*Sensitive* only because a `TimetableEntry` identifies a specific
Employee. The direct precedent is the Canteen catalogue row.

**Forward note:** attaching a named teacher in a later checkpoint (a
delivery record or lesson plan) would elevate that entity to
**Sensitive** by the Timetable row's exact reasoning.

## 9. Authorization

`syllabus.view` / `syllabus.manage`, granted to `school_admin` and
`principal`. **Admin-only v1** — no teacher ownership, no teacher
capability, no Student or Guardian access, and no role-name checks
(rule 24). Enforced by `capability:` route middleware on the API and by
`AuthorizesCapability` in the Inertia controller.

Syllabus never borrows Academic Structure's `academics.subjects.*`,
even though the parent Offering belongs to that module (the Canteen
capability-boundary lesson).

## 10. Structural integrity / RLS

UUIDv7 · `BelongsToSchool` · `TenantRls::enable('syllabus_units')`
(ENABLE **and** FORCE) · tenant policy on `school_id` ·
`unique(id, school_id)` · composite `(subject_offering_id, school_id)`
FK RESTRICT · `CHECK (status IN ('active','inactive'))` ·
`syllabus_units_offering_code_ci_unique`.

Proven at the raw-SQL layer in
`Tests\Feature\Postgres\SyllabusUnitsRlsIsolationTest`, including
fail-closed behaviour with no tenant context.

## 11. Application architecture

**Thin controller, no Application service** (CLAUDE.md rule 76). The
entity has no date-range invariant, no overlap validation, no lock, no
multi-row mutation, no state machine and no cross-domain coordination,
so a service would be ceremony rather than architecture. If any of
those appear later, the decision is reopened before the design grows.

No `TenantLock`, no `lockForUpdate()`, no advisory locks, no bulk
reorder, no multi-row sequence normalization.

## 12. API — exactly four operations

| Method | Path (`/api/v1/schools/{school}`) | Capability |
|---|---|---|
| `GET` | `/subject-offerings/{subjectOffering}/syllabus-units` | `syllabus.view` |
| `POST` | `/subject-offerings/{subjectOffering}/syllabus-units` | `syllabus.manage` |
| `GET` | `/syllabus-units/{syllabusUnit}` | `syllabus.view` |
| `PATCH` | `/syllabus-units/{syllabusUnit}` | `syllabus.manage` |

No DELETE, no activate/deactivate, no reorder, no bulk update, no
helper/search endpoint, and no grading/delivery/lesson-planning
endpoint. Index ordering is `sequence`, then `upper(code)`.

**No `Idempotency-Key`**: these are small reference-catalogue mutations
whose duplicate semantic creation is already prevented by the database
unique index (rule 29 evaluated per endpoint, never blanket-applied).

## 13. Administrative UI

`/app/syllabus` — resolve an AcademicYear (defaulting to the School's
active year) → choose a SubjectOffering → view units in teaching order
→ create → edit `code`/`title`/`sequence`/`status`.

It reuses the AcademicYear/Offering filter shape
`App\Http\Controllers\App\SubjectOfferingController::index()` already
established; **no new Offering search or discovery API was built**.

No Student roster, no marks, no grading, no delivery tracking, no
lesson plans, no attachments, no teacher portal, no Student or Guardian
view.

## 14. Audit

`AuditRecorder`, bounded metadata:

- `syllabus.unit.created` — `unitId`, `subjectOfferingId`, `code`,
  `sequence`
- `syllabus.unit.updated` — `unitId`, `subjectOfferingId`,
  `changedFields` (names only), and before/after **values** for `code`,
  `sequence` and `status` only

**`title` values never appear in audit metadata** — a title change is
reported by field name only, so an audit row never becomes a second
copy of curriculum content. There are no activation/deactivation audit
actions because those endpoints do not exist.

## 15. Events, Communications, Documents

**Zero domain events.** No `SyllabusUnitCreated`, no
`CurriculumUpdated`, nothing — no consumer exists (rule 2). No
Communications integration, no Documents integration, no uploads, no
attachments, no notifications, no webhooks.

## 16. OpenAPI and shared types

All four operations are documented in
`packages/contracts/openapi/school-os-api.yaml` **in the same branch
that introduced them** — not a later correction pass.
`Tests\Feature\Syllabus\SyllabusOpenApiCoverageTest` proves coverage
bidirectionally with the operation count pinned at 4, using the
corrected Phase 0H.2 path-block terminator logic. `packages/shared-types`
is regenerated and committed, with a second generation producing zero
drift and a clean `tsc --noEmit`.

## 17. Not in this checkpoint

Grading · marks · scores · weighting · grade scales · exams ·
assignments · submissions · student coursework · StudentEnrollment or
StudentSubjectEnrollment logic · rosters · teacher ownership · lesson
plans · curriculum-delivery records · academic progress tracking ·
Attendance integration · Timetable dependency · files/uploads ·
Documents · Communications · domain events · webhooks · a `Curriculum`
entity · `academic_term_id` · `section_id` · dates/scheduling ·
`description`/free-text/learning outcomes · analytics/AI.

## 18. Curriculum Delivery (Phase 0H.3B)

### 18.1 What a CurriculumDelivery is

> **The record that one `Section` has covered one `SyllabusUnit`: when
> that Section began it, and when, if yet, it finished.**

**Actual** instructional coverage by a **cohort** — the counterpart to
`SyllabusUnit`'s catalogue of **expected** content. It records nothing
about an individual lesson (future Lesson Planning), nothing about who
taught it, and nothing about any Student.

### 18.2 Section-specific, and required-Offering-only

The syllabus catalogue is deliberately Offering-wide (§4): every Section
taking an Offering is expected to cover the same content. **Per-Section
variation is exactly this delivery concern** — two Sections of one
GradeLevel genuinely progress at different rates, and *"has 10-A covered
Unit 3?"* is the question this table exists to answer.

**v1 accepts REQUIRED SubjectOfferings only**
(`RequiredSubjectOfferingOnlyException`, its own class — never an import
of Timetable's near-identical exception, which rule 4 forbids). An
elective has **no Section-wide cohort at all**: a Student opts into one
individually through `student_subject_enrollments`, which carries no
`section_id`. This is the identical restriction and rationale
`TimetableScheduleService` already applies to scheduling.

This deliberately makes **delivery narrower than the catalogue**, which
supports both required and elective Offerings. That asymmetry is a
reviewed decision, not an oversight: elective coverage needs a cohort
concept that does not exist in this platform yet. Reopening criterion —
a real requirement to track coverage for an elective cohort.

A nullable `section_id` meaning "Offering-wide delivery" was considered
and **rejected**: a NULL in a composite foreign key disables the entire
check under PostgreSQL's MATCH SIMPLE, the exact bypass
`attendance_sessions` documents and Phase 1F.1 needed a trigger to close.

### 18.3 The sparse "not started" representation

Only **two states are ever stored**: `in_progress` and `completed`.
**`not_started` is never a stored value** — the *absence* of a row is
what "not started" means, and pre-seeding one row per Section ×
SyllabusUnit would write a fact nobody asserted.

Every consumer that needs "all units with their state" must therefore
**start from `syllabus_units` and LEFT JOIN the deliveries in**, never
count rows here.
`App\Http\Controllers\App\CurriculumDelivery\CurriculumDeliveryController::unitRows()`
is the canonical demonstration; `curriculum_deliveries_status_check`
and `CurriculumDeliveryStatus`'s OpenAPI enum both exclude `not_started`
structurally.

### 18.4 The additive Syllabus integrity prerequisite

Phase 0H.3B adds one **additive, non-destructive** key to Phase 0H.3A's
published table:

```sql
UNIQUE (id, school_id, subject_offering_id)   -- syllabus_units_offering_context_unique
```

It exists solely so composite FK (3) below is declarable, exactly the
`add_context_unique_to_sections_table` /
`add_context_unique_to_subject_offerings_table` precedent. It is not
convenience denormalization, transforms no data, and can never fail on
existing rows (`id` is already unique alone).
`syllabus_units_offering_code_ci_unique`, the status CHECK, the
SubjectOffering FK and RLS are all untouched — asserted by
`Tests\Feature\Postgres\CurriculumDeliveriesRlsIsolationTest::the_syllabus_units_offering_context_key_exists`
alongside the Phase 0H.3A suite's own unchanged shape test.

**Rollback ordering matters**: `create_curriculum_deliveries_table` is
dated *after* the key migration, so `migrate:rollback` drops the table
(and its dependent FK) **before** the key is dropped. Reversing that
order would be rejected by PostgreSQL.

### 18.5 Cross-parent integrity — the central invariant

Tenant isolation alone is **not** sufficient consistency here: without
more, a same-School Section teaching Mathematics could record delivery
against a Science `SyllabusUnit`. Three composite foreign keys close
that, all `ON DELETE RESTRICT`:

```
1.  (section_id, school_id, academic_year_id, campus_id, grade_level_id)
      -> sections(id, school_id, academic_year_id, campus_id, grade_level_id)
2.  (subject_offering_id, school_id, academic_year_id, campus_id, grade_level_id)
      -> subject_offerings(id, school_id, academic_year_id, campus_id, grade_level_id)
3.  (syllabus_unit_id, school_id, subject_offering_id)
      -> syllabus_units(id, school_id, subject_offering_id)
```

(1) and (2) pin the Section and the Offering to the **same**
AcademicYear × Campus × GradeLevel — the shape `timetable_entries` and
`attendance_sessions` already use (rule 70). (3) pins the SyllabusUnit
to **that exact Offering**. Composed, the Unit's Offering is
context-identical to the Section's context, **even through a raw SQL
INSERT that bypasses Eloquent entirely**. Proven in
`CurriculumDeliveriesRlsIsolationTest`, whose central case builds a
Science unit in the same School, year, campus and grade — so tenant
isolation and both context FKs are fully satisfied and only FK (3) can
reject it.

`academic_year_id`/`campus_id`/`grade_level_id`/`subject_offering_id`
are therefore **integrity pins, not convenience denormalization** — every
one is a composite-FK component, none is client input, and none is
exposed in the API projection. Every FK component is NOT NULL, for the
MATCH SIMPLE reason above.

### 18.6 Schema

| Field | Type | Null | Notes |
|---|---|---|---|
| `id` | uuid | NOT NULL | PK, UUIDv7 (ADR 0019) |
| `school_id` | uuid | NOT NULL | Tenant root; FK → `schools(id)` CASCADE |
| `section_id` | uuid | NOT NULL | The cohort; composite FK (1), RESTRICT |
| `syllabus_unit_id` | uuid | NOT NULL | The unit covered; composite FK (3), RESTRICT |
| `subject_offering_id` | uuid | NOT NULL | Integrity pin; composite FK (2), RESTRICT |
| `academic_year_id` | uuid | NOT NULL | Integrity pin |
| `campus_id` | uuid | NOT NULL | Integrity pin |
| `grade_level_id` | uuid | NOT NULL | Integrity pin |
| `started_on` | date | NOT NULL | School-**local** calendar date |
| `completed_on` | date | NULL | NULL if and only if `in_progress` |
| `status` | varchar | NOT NULL | `in_progress` \| `completed`, DB CHECK, default `in_progress` |
| `created_at` / `updated_at` | timestamp | NOT NULL | Standard |

Constraints: `unique(id, school_id)` ·
`curriculum_deliveries_section_unit_unique (school_id, section_id, syllabus_unit_id)` ·
`curriculum_deliveries_status_check` ·
`curriculum_deliveries_completion_check` — the biconditional
`(status = 'completed') = (completed_on IS NOT NULL)`, which is what
makes `status` safe to keep alongside the date ·
`curriculum_deliveries_date_order_check`. RLS **ENABLED and FORCED**,
tenant policy on `school_id`, `BelongsToSchool`, UUIDv7.

**Deliberately absent:** `teacher_id`, `employee_id`, `user_id`,
`student_id`, any enrollment id, `timetable_entry_id`, any Attendance
reference, `academic_term_id`, `sequence`, and every free-text /
Lesson-Planning / LMS / Examinations field — notes, description,
objectives, resources, homework, lesson counts, periods used, hours
taught, attachments, Documents, assignments, marks, grades,
assessments. `Tests\Feature\CurriculumDelivery\CurriculumDeliveryArchitectureGuardTest`
fails if any of them is ever added.

### 18.7 Lifecycle, dates and concurrency

Start · list · show · correct dates · transition. **No delete, no
archive, no activate/deactivate, no bulk, no reorder** — these rows are
historical instructional activity (rule 73).

Legal transitions are exactly `in_progress → completed` and
`completed → in_progress`, from a closed map: both values belonging to
the vocabulary is *not* sufficient, so the machine can never grow an
unreviewed edge. Reopening always clears `completed_on`.

**Dates are School-LOCAL calendar dates**, resolved through
`App\Support\Tenancy\SchoolTimezone` — the user is entering a school
calendar date, and for a School in Asia/Kolkata the UTC date is a
different calendar day for several hours daily. (Attendance's existing
platform-UTC comparison is deliberately untouched by this checkpoint;
aligning it is separate platform maintenance.) Every date must be
non-future and inside the AcademicYear's inclusive
`[starts_on, ends_on]`; `completed_on >= started_on`.

**Historical-correction discipline:** starting a NEW delivery requires
an **active** AcademicYear; correcting or transitioning an EXISTING one
deliberately does not — a closed year must never make a genuine clerical
correction impossible. This is Attendance §13/§14's exact rule. A
correction **re-runs every applicable date invariant**: an existing row
is not a back door around them.

**Concurrency.** `transition()` runs inside `DB::transaction()` with
`lockForUpdate()`, and compares the *locked* status against
`expected_status` — a stale actor is refused with `409`
(`DeliveryStatusChangedException`) rather than silently overwriting a
colleague. A no-op is rejected `422`. Proven with two genuinely separate
OS processes in
`Tests\Feature\CurriculumDelivery\CurriculumDeliveryTransitionConcurrencyTest`.

**No `TenantLock`, no advisory lock, no School-wide lock** — there is no
multi-row invariant. Duplicate creation is settled by
`curriculum_deliveries_section_unit_unique` alone, never by an
application check-then-insert (rule 30's principle); the service
translates **only that specific named constraint** into
`DuplicateDeliveryException` and rethrows any other unique violation as
the unexpected failure it is.

### 18.8 Application architecture

**Application service required** —
`App\Domain\CurriculumDelivery\Application\CurriculumDeliveryService`,
the only sanctioned write path. Unlike SyllabusUnit (§11), all four of
rule 76's triggers are present: multi-parent consistency, date
validation, a state machine, and a transaction + row lock. Both
controllers are thin and perform no write of their own; the API surface
renders domain exceptions through `bootstrap/app.php`'s envelope with
their real status codes, while the Inertia surface converts them to
ordinary form errors (Attendance's established convention).

**Server-derived context:** callers supply only `section_id`,
`syllabus_unit_id` and `started_on`. All four integrity pins are derived
from the resolved parents and written with `forceFill()`; they are
absent from `$fillable`, so no mass-assignment path can reach them.

### 18.9 Classification: Confidential

A `CurriculumDelivery` contains **no personal data**: no Student,
Guardian, Employee or teacher identity, and no `student_id`/
`*_enrollment_id`/`teacher_id`/`user_id` column exists. A Section is an
organisational cohort, not an individual.

**Excluding teacher identity is what deliberately keeps this record
non-personal.** Recording who taught a unit would elevate the entity to
**Sensitive** by the Timetable row's exact reasoning (§8), and needs the
platform's first ownership-based authorization model — which does not
exist: there is no teacher role, no teacher self-service, and
`employees.user_id` is nullable.

### 18.10 Authorization

`curriculum.delivery.view` / `curriculum.delivery.manage`, granted to
`school_admin` and `principal`. **Admin-only v1** — no teacher
capability, no teacher ownership, no Student or Guardian access, no
role-name checks (rule 24). `capability:` route middleware on the API;
`AuthorizesCapability` in the Inertia controller. Curriculum Delivery
never borrows `syllabus.*` or `academics.subjects.*`, even though both
parents belong to those modules.

### 18.11 API — exactly five operations

| Method | Path (`/api/v1/schools/{school}`) | Capability |
|---|---|---|
| `GET` | `/subject-offerings/{subjectOffering}/curriculum-deliveries` | `curriculum.delivery.view` |
| `POST` | `/subject-offerings/{subjectOffering}/curriculum-deliveries` | `curriculum.delivery.manage` |
| `GET` | `/curriculum-deliveries/{curriculumDelivery}` | `curriculum.delivery.view` |
| `PATCH` | `/curriculum-deliveries/{curriculumDelivery}` | `curriculum.delivery.manage` |
| `POST` | `/curriculum-deliveries/{curriculumDelivery}/transition` | `curriculum.delivery.manage` |

Index filters: optional `section_id`, optional `status`; ordering is the
**catalogue's** own `syllabus_units.sequence` then `upper(code)`. PATCH
**never accepts `status`**. No DELETE, no helper/search, no
bulk/reorder, no reporting endpoint, no teacher or Student route.

**No `Idempotency-Key`** (rule 29, evaluated per endpoint): duplicate
creation is already prevented by the unique index, a duplicate
transition fails closed on the compare-and-swap, and PATCH is naturally
idempotent — so no CurriculumDelivery response is ever written to
`api_idempotency_keys` and rule 36's stored-response review does not
arise.

### 18.12 Administrative UI

`/app/syllabus-delivery` — AcademicYear (defaulting to the active year)
→ **required** SubjectOffering → a Section sharing that Offering's exact
AcademicYear/Campus/GradeLevel → the Offering's active SyllabusUnits in
teaching order, each showing its delivery state, with **"Not started"**
rendered from the absence of a row. Actions: Start · Mark completed ·
Reopen · Correct dates, each sending `expected_status` from the row as
loaded. Four web routes; writes gated by `canManage` in the UI and
independently authorized server-side.

It reuses the AcademicYear/Offering filter shape
`SubjectOfferingController::index()` established; **no new Offering,
Section or Unit discovery API was built.** No Student roster, no
attendance entry, no timetable editing, no marks, no lesson planner, no
files/resources, no teacher portal.

### 18.13 Audit

`AuditRecorder`, bounded metadata:

- `curriculum.delivery.created` — `deliveryId`, `sectionId`,
  `subjectOfferingId`, `syllabusUnitId`, `startedOn`
- `curriculum.delivery.transitioned` — `deliveryId`, `previousStatus`,
  `newStatus`, `completedOn`
- `curriculum.delivery.updated` — `deliveryId`, `changedFields` (names
  only), and before/after **values** for the two date fields

The SyllabusUnit's `title` is **never** copied into audit metadata (only
`syllabusUnitId`), the same rule §14 applies to Syllabus. No free-text
field exists on this entity at all, and no person is ever named.

### 18.14 Events, Communications, Documents

**Zero domain events.** No consumer exists (rule 2). Not registered in
`WebhookEventRegistry`, so it is not externally subscribable (rules
45/77). No Communications, Notifications, Documents or LMS integration;
no outbox write; no reporting projection.

### 18.15 Not in this checkpoint

Lesson plans and every lesson-level field · teacher identity and
ownership-based authorization · Timetable dependency · Attendance
dependency · Student/roster data of any kind · `academic_term_id` ·
elective-Offering delivery · grading, marks, scores, weighting, grade
scales, exams · assignments, submissions, coursework, files, uploads,
Documents · domain events, webhooks · a `Curriculum` entity · bulk or
reorder operations · analytics/AI.

**Later addition (Phase 0L.2-1, 2026-09-23):** Curriculum Delivery now
exposes one read-only AGGREGATE contract,
`App\Domain\CurriculumDelivery\Application\CurriculumCoverageReadService`,
for Analytics (ADR 0040 §3): syllabus-unit counts per required Offering
and Section (completed / in progress / not started), using this page's
exact projection rules. It returns no dates, rows or person data and
adds no write path, route or event to this module. See
`docs/modules/ANALYTICS.md` §13.

## 19. Future

- **Lesson Planning**: still deferred until a real requirement exists;
  it would need a date, a cohort, an author, and probably a
  `SyllabusUnit` or `CurriculumDelivery` reference — the author question
  requires designing the platform's first ownership-based authorization
  model, which still does not exist. Phase 0H.3B deliberately stores
  nothing at lesson granularity, so Lesson Planning remains fully
  necessary rather than redundant.
- **Teacher identity on a delivery** is a purely additive future path: a
  nullable `teacher_id` with `(teacher_id, school_id) → employees(id, school_id)`
  RESTRICT (the `attendance_sessions_teacher_fk` shape), plus a
  mandatory re-tier of this module to **Sensitive** in the same branch.
- **Elective delivery** would need a Section-independent cohort concept
  that does not exist yet (§18.2).
- **Examinations** and **LMS** may later reference `syllabus_unit_id` or
  `curriculum_delivery_id`. Nothing here prevents that; the no-delete
  lifecycle and RESTRICT FKs guarantee a reference target survives.
  Academics itself must never reference them.
