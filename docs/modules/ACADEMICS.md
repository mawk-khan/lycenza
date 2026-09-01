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

## 18. Future

- **Curriculum Delivery** (a later checkpoint): "a `SyllabusUnit` was
  delivered to a `Section` on a date" — the natural next fact, and what
  `SyllabusUnit` exists to make possible. It will introduce a
  per-cohort historical record with its own correction semantics, and
  attaching a teacher would reclassify it as Sensitive.
- **Lesson Planning**: deferred until a real requirement exists; it
  would need a date, a cohort, an author, and probably a `SyllabusUnit`
  reference — the author question requires designing the platform's
  first ownership-based authorization model.
- **Examinations** and **LMS** may later reference `syllabus_unit_id`.
  Nothing here prevents that; the no-delete lifecycle and RESTRICT FK
  guarantee a reference target survives. Academics itself must never
  reference them.
