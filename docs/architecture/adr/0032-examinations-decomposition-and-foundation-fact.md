# ADR 0032 — Examinations decomposition and the Examination Foundation fact

**Status:** Accepted (Phase 0H.4A)

## Context

`docs/architecture/DOMAIN-MAP.md` has always declared an **Examinations**
module — *"exam scheduling, grading, report cards"*, depending on
Academic Structure, Students/SIS and Academics — and
`docs/modules/ACADEMICS.md` §2 records the full boundary: *exam
scheduling, grading, marks, grade scales, result publication, report
cards, transcripts*.

Until Phase 0H.4A the module had **no implementation and no
decomposition of any kind**. A repository-wide sweep before this
checkpoint found zero occurrences of `Examination`, `ExaminationCycle`,
`ExaminationWindow`, `Exam Period`, `ExaminationPaper`, `GradeScale`,
`ReportCard`, `Transcript` or `marks` as code, migration, route,
capability, OpenAPI operation, module document or ADR. The only prior
mentions were the boundary declarations above, plus forward-looking
prose in `ACADEMICS.md` §19 (*"Examinations and LMS may later reference
`syllabus_unit_id` or `curriculum_delivery_id`"*) and storage examples in
ADR 0011/0012.

So the module's outer boundary was settled, but the order in which to
build it — and what its first durable fact should be — was not. This
ADR records that decision, following the precedent of ADR 0028
(`phase-8a-hr-sequencing-and-domain-foundation`).

## Decision

### 1. The first fact is a WINDOW, not a paper

> **Examination — one named assessment window that a School holds within
> one AcademicYear, owning only its own identity and the date range it
> spans.**

One row means: this School has scheduled this named examination window
inside this AcademicYear, over these dates. It owns its code, name,
AcademicYear, start and end dates, and whether it is active. It owns no
Subject, SubjectOffering, Section, paper, per-paper sitting date/time or
max marks; no Student, enrollment, teacher or invigilator; and no mark,
grade, result, publication state, report card or transcript.

An Examination row without any paper is a **coherent, durable, useful
fact**: schools announce the examination window first and the datesheet
later, and *"Mid-Term runs 10–20 September"* answers a real question on
its own.

### 2. Named `Examination`, not `ExaminationCycle`/`ExaminationWindow`

`docs/modules/ACADEMIC-STRUCTURE.md`'s naming discipline prefers the real
domain noun and avoids invented substitutes — `Session` is avoided for
AcademicYear, `Course` for Subject, and `Class` is banned outright.
Schools say *"the Mid-Term Examination"*, never *"the Mid-Term
Examination Cycle"*.

The container/member pair `Examination` + a future `ExaminationPaper` is
unambiguous, because the member noun carries "Paper" explicitly — the
same shape as `AcademicYear`/`AcademicTerm` and
`SubjectOffering`/`SyllabusUnit`. `ExaminationCycle` + `ExaminationPaper`
would be clumsier without being clearer.

### 3. ExaminationPaper is a separate, later checkpoint

Including papers in the first checkpoint would immediately pull in the
`SubjectOffering` composite-context FKs, the required-vs-elective
question, per-paper date/time scheduling and `max_marks` — roughly
quadrupling the checkpoint and blurring the data-classification boundary
(see §6). Since the window is independently coherent, papers stay out.

### 4. Parents are School and AcademicYear only

`(academic_year_id, school_id) → academic_years(id, school_id)`
RESTRICT. **No Campus and no GradeLevel**: a multi-campus School normally
holds one Mid-Term across campuses and grades, and per-campus or
per-grade variation is a *paper* concern — a paper pins a
`SubjectOffering`, which already pins AcademicYear × Campus × GradeLevel
× Subject. Adding either here would force a separate Examination row per
campus even when nothing differs.

With a single non-tenant parent there is no cross-parent pinning to do,
so CLAUDE.md rule 70's composite-context pattern does not arise.
`unique(id, school_id)` is retained so a future `ExaminationPaper` can
reference the table tenant-pinned.

### 5. AcademicTerm is deferred, again — with a concrete precondition

*"Midterm Examination"* is a **name**, not a term reference; naming an
examination after a term creates no data dependency. A School that does
not model AcademicTerms must still be able to schedule examinations, and
no invariant needs one: date validity is checked against the
AcademicYear, which is the AcademicTerm's own parent anyway.

Direct verification of `AcademicTerm` on the reviewed baseline confirms
it has a table, model, service with in-year-range and non-overlap
validation, audit, a domain event, four API operations and OpenAPI
coverage — but **no lifecycle status and no consuming domain**. The
sentence in `ACADEMICS.md` §4 asserting exactly that was re-verified and
is accurate; no correction was warranted.

A nullable `(academic_term_id, school_id)` reference is a purely
additive future path once a real invariant appears (term-wise report
cards; *"term closed ⇒ marks frozen"*). The second of those **also
requires AcademicTerm to gain the lifecycle status it currently lacks** —
that, not a vague notion of immaturity, is the concrete precondition.

### 6. Confidential now; the Sensitive boundary is a checkpoint boundary

An `Examination` contains no personal data at all, so it is
**Confidential**, matching the `SyllabusUnit` and `CurriculumDelivery`
precedents.

The transition into **Sensitive** happens exactly when Student marks are
introduced. That is deliberately used as a checkpoint boundary: every
checkpoint up to and including grade scales can ship as non-personal
administrative catalogue work, and the checkpoint that first records a
mark must undergo a dedicated privacy/security architecture audit —
including the children's-data **[LEGAL REVIEW REQUIRED]** gate in
`docs/security/DATA-CLASSIFICATION.md` — before implementation.

### 7. Date semantics deliberately invert two Curriculum Delivery rules

- **Future dates are permitted and expected.** A delivery records what
  has happened; an examination is scheduled ahead. `academic_years` and
  `academic_terms` both routinely carry wholly future ranges and neither
  service applies a not-future rule.
- **Overlapping windows are permitted.** No business invariant forbids
  running "Grade 10 Board Prep" and "Grade 6 Unit Test" in the same
  weeks. `academic_terms` forbids overlap only because terms *partition*
  a year by definition; examinations partition nothing.

Consequently this module introduces **no `TenantLock`, no advisory lock,
no `lockForUpdate()`, no exclusion constraint and no concurrency test** —
there is no multi-row invariant at all. The only race is a duplicate
code, settled by one PostgreSQL unique index.

The AcademicYear is **not** required to be active: planning next year's
examinations inside a draft year is legitimate work, so Curriculum
Delivery's active-year-on-create rule does not apply.

### 8. `active | inactive`, no delete, no state machine

Every School-owned reference entity in this codebase (`Section`,
`SubjectOffering`, `Room`, `Subject`, `GradeLevel`,
`AcademicDepartment`, `SyllabusUnit`) carries exactly `active|inactive`
with no delete route, and `Examination` will soon acquire RESTRICT
children. `status` is therefore the counterpart of the no-delete
decision: it makes "cancelled" and "created in error" expressible
without a delete route and without a breaking change when 0H.4B lands.

A `draft`/`active`/`closed` state machine is deliberately **not** built:
every concern it would serve belongs to a checkpoint that does not
exist, and none of its transitions would re-validate any invariant.

### 9. Capability root is `examinations.definitions.*`

Depth-2, not flat. A flat `examinations.manage` would eventually grant
clerical marks entry and principal-level result publication with the
same key. Depth-2 leaves clean room for `examinations.papers.*`,
`examinations.grade_scales.*`, `examinations.marks.*` and
`examinations.results.*`, matching the established
`timetable.periods.*`/`timetable.schedule.*` shape. Not `academics.*`
(owned by Academic Structure) and never Academic Structure's own
`academics.years.*`, even though the parent AcademicYear belongs to it.

## Provisional future sequence

Only 0H.4A is architecture-closed. The rest is recorded so the intended
shape is visible, **not** as settled design:

| Checkpoint | Status |
|---|---|
| **0H.4A — Examination Foundation** | **RATIFIED / implemented** |
| 0H.4B — ExaminationPaper / scheduling | PROVISIONAL — own gate required |
| GradeScale | PROVISIONAL — independent of the Examination chain; may ship in parallel; hard constraint is only that it precede result calculation |
| Marks | PROVISIONAL and **GATED** — crosses into Sensitive personal data (§6) |
| Result calculation / publication | PROVISIONAL — calculation and publication may warrant separation |
| Report cards / transcripts | PROVISIONAL — likely a Documents integration |

A binding constraint on the paper and marks checkpoints: **Examinations
must support both required and elective SubjectOfferings.** Curriculum
Delivery's required-only restriction must not be inherited — that
restriction exists because its aggregate is `Section × SyllabusUnit` and
an elective has no Section-wide cohort, whereas an examination mark is
per-**Student**, which is exactly what `StudentSubjectEnrollment`
records. `App\Domain\Students\Application\SubjectOfferingRosterReadService`
is the authoritative seam for either Offering type.

## Consequences

- Examinations is started but explicitly not complete; Phase 0H remains
  incomplete, and Lesson Planning remains independently blocked by the
  absent ownership-based authorization model.
- The first checkpoint is small and non-personal, and needs none of the
  missing platform machinery (no teacher role, no ownership model).
- A future `ExaminationPaper` can reference `examinations(id, school_id)`
  tenant-pinned without a schema change to this table.
- The Confidential → Sensitive crossing is a named, deliberate gate
  rather than something a later checkpoint discovers.

## Alternatives considered

- **Start with GradeScale.** Rejected: it is a pure mapping function
  with no consumer until result calculation, so its boundary semantics
  (inclusive/exclusive edges, gap/overlap prohibition, School- vs
  GradeLevel-specific scales, rounding) would have to be designed with
  nothing to validate them against.
- **Start with Examination + Paper together.** Rejected: §3.
- **Start with marks.** Rejected: no examination to attach them to, and
  it would cross into Sensitive personal data immediately.
- **Make AcademicTerm a required parent.** Rejected: §5.
- **Do Lesson Planning first.** Rejected: it is hard-blocked by the
  missing ownership model, which is a cross-platform Phase 0B concern
  and not on any Examinations path.
