# ADR 0033 — ExaminationPaper / Scheduling

**Status:** Accepted (Phase 0H.4B)

Supersedes ADR 0032 §"Provisional future sequence" row "0H.4B —
ExaminationPaper / scheduling", which is now RATIFIED / implemented
rather than PROVISIONAL. ADR 0032 itself is not rewritten.

## Context

ADR 0032 deliberately deferred ExaminationPaper as "a separate, later
checkpoint" (§3), reserving `examinations.papers.*` in the capability
catalog (§9) and `unique(id, school_id)` on `examinations` (§4) for it.
`docs/modules/EXAMINATIONS.md` §18 (as of 0H.4A) sketched the intended
shape: *"one Subject's paper within an Examination — SubjectOffering, a
per-paper date/time and max marks, with composite context FKs pinning
the Examination and the SubjectOffering to one AcademicYear."*

This ADR is that checkpoint. It implements exactly the fact ADR 0032
anticipated, no more.

## Decision

### 1. The fact: one SubjectOffering assessed within one Examination

> **ExaminationPaper — one SubjectOffering assessed within one
> Examination, with its scheduled sitting (date and time range) and
> maximum obtainable marks.**

An Examination *child* and an *Offering-wide* scheduling fact — not a
physical uploaded question-paper file, not Section-specific, not a
Student attempt, not a mark/result, not an LMS assignment.

### 2. Cardinality: exactly one Paper per Examination × SubjectOffering

`examination_papers_examination_offering_unique`: `UNIQUE (school_id,
examination_id, subject_offering_id)`, unconditional (never scoped
`WHERE status = 'active'`), so an inactive Paper continues to reserve
the pair and ordinary reactivation is conflict-free — the identical
reasoning `examinations_year_code_ci_unique` already established. No
Paper 1/Paper 2, no theory/practical component, no sequence; a future
architecture/migration would be required for multi-component papers.

### 3. Cross-parent integrity requires an additive Examination key

An ExaminationPaper has TWO tenant-pinned parents that must agree on
AcademicYear: Examination and SubjectOffering. `subject_offerings`
already carries the 5-column `subject_offerings_context_unique` (added
in Phase 0H for `timetable_entries`); `examinations` had only the
2-column `examinations_id_school_id_unique`. A dedicated additive
migration adds `examinations_context_unique`: `UNIQUE (id, school_id,
academic_year_id)` — touching no other Examination constraint, index or
RLS policy.

Both of ExaminationPaper's composite FKs then reference the SAME stored
`academic_year_id` column:

```
(examination_id, school_id, academic_year_id)
  → examinations(id, school_id, academic_year_id)                RESTRICT
(subject_offering_id, school_id, academic_year_id, campus_id, grade_level_id)
  → subject_offerings(id, school_id, academic_year_id, campus_id, grade_level_id)  RESTRICT
```

This is what makes `Examination.academic_year_id ==
SubjectOffering.academic_year_id` a database-structural guarantee — not
merely an application check — even through a raw SQL insert bypassing
`ExaminationPaperService` entirely: no single stored `academic_year_id`
value can satisfy both FKs when the two parents genuinely disagree,
proven directly in `Tests\Feature\Postgres\ExaminationPapersRlsIsolationTest`.

`academic_year_id`, `campus_id` and `grade_level_id` are therefore
INTERNAL INTEGRITY PINS: derived server-side from the resolved
Examination/SubjectOffering, never accepted from a client, and never
exposed in the public API representation (mirroring how `school_id`
itself is already never exposed).

### 4. Required and elective SubjectOfferings, honoured as promised

ADR 0032 §"Provisional future sequence" bound this checkpoint: *"a
binding constraint on the paper and marks checkpoints: Examinations must
support both required and elective SubjectOfferings."* `is_required` is
never inspected by `ExaminationPaperService` for eligibility — both
kinds create identically. `SubjectOfferingRosterReadService` is still
not called; Student roster resolution remains deferred to a future marks
checkpoint, exactly as ADR 0032 reserved it.

### 5. No `section_id` — Offering-wide, not Section-specific

A Paper assesses a SubjectOffering, not a cohort. Future Student
eligibility for a Paper derives from the Offering roster seam (the
deferred `SubjectOfferingRosterReadService`), never from a Section
column on this table. Introducing `section_id` would also force a
separate Paper row per Section even when the same sitting applies to
all of them — the identical reasoning `syllabus_units` and
`curriculum_deliveries` already established for the Offering-wide vs.
Section-specific distinction (except CurriculumDelivery is Section
specific by design; ExaminationPaper is not, because a Paper is a single
scheduled sitting the whole Offering shares).

### 6. School-local wall-clock time, same-day sittings only

`scheduled_on` (DATE), `starts_at`/`ends_at` (TIME) — School-local, never
UTC, no timezone column, no dispatch scheduler (mirroring
`timetable_periods`' existing plain-time convention).
`examination_papers_time_order_check` enforces `ends_at > starts_at`;
overnight sittings are explicitly unsupported in v1, a scope decision
that keeps the invariant a same-row CHECK rather than a cross-midnight
date computation.

### 7. Overlap is permitted, exactly like Examination itself

Overlapping sittings across DIFFERENT SubjectOfferings are intentionally
allowed — a School may sit two different Subjects' papers at the same
hour on different tracks. No overlap query, no roster intersection, no
Student-aware conflict detection, no `TenantLock`, no advisory lock, no
exclusion constraint. The only duplicate prevention is §2's aggregate
unique constraint, which a single PostgreSQL unique index settles
without any concurrency machinery — the identical reasoning ADR 0032 §7
already established for `Examination` windows, extended one level down.

### 8. `active | inactive`, no delete, no state machine — with one guarded transition

Status vocabulary is closed to `active`/`inactive`, mirroring
`Examination` (ADR 0032 §8) and every other reference entity in this
codebase. No delete route, no activate/deactivate command; `status`
moves through the ordinary PATCH.

**The one deliberate asymmetry**: an ordinary correction (reschedule,
adjust max marks) never re-checks parent activity — an existing Paper
remains correctable as history even after its Examination or
SubjectOffering is later deactivated. But a REACTIVATION (`status`
moving `inactive` → `active`) requires BOTH parents to be currently
active, using the same `ExaminationNotActiveException`/
`SubjectOfferingNotAvailableException` domain errors `create()` uses.
This prevents reintroducing a Paper into active/current use beneath a
withdrawn parent, while still allowing pure historical correction. No
lock or compare-and-swap is required — this is an ordinary invariant
evaluated only on that one specific transition, not a general
concurrency concern.

`examination_id` and `subject_offering_id` are immutable after create —
absent from the update's accepted field set entirely (not merely
ignored). "Changing the wrong Offering" is represented by inactivating
the incorrect Paper and creating a new one under the correct Offering,
never by repointing an existing row — the same discipline
`Examination`'s fixed AcademicYear already established.

### 9. Confidential, same tier as Examination

No Student, Employee, teacher/invigilator or room identity — the
identical reasoning ADR 0032 §6 established for `Examination`. The same
re-tier triggers apply: attaching an invigilator/teacher identity, a
Student roster snapshot, or `StudentMark` would each elevate the
affected entity to Sensitive, requiring the re-tier in the same branch
plus the children's-data **[LEGAL REVIEW REQUIRED]** gate for the latter
two.

### 10. Capability root: `examinations.papers.*`, the reserved sibling

`examinations.papers.view`/`.manage`, exactly the depth-2 leaf ADR 0032
§9 and the capability seeder's own comment named as reserved. Deliberately
separate from `examinations.definitions.*` — proven by a dedicated test
that `examinations.definitions.view` alone grants no ExaminationPaper
access, matching Attendance/Timetable/Academics' established
"module-owns-its-own-capability" boundary discipline.

## Deviations required by this checkpoint (documented per CLAUDE.md rule 15)

Two pre-existing Phase 0H.4A tests used loose scanning that this
checkpoint's spec-mandated shapes collide with, and both required a
narrow, non-weakening correction:

1. **`ExaminationArchitectureGuardTest::the_registered_route_surface_is_exactly_the_sanctioned_one`**
   and **`ExaminationApiTest::there_is_no_delete_route_and_exactly_four_operations`**
   filtered routes by a `str_contains($uri, 'examinations')` substring.
   `ExaminationPaper`'s own routes deliberately share that literal path
   segment (`/examinations/{examination}/examination-papers`,
   `/app/examinations/{examination}/papers`) per this ADR's own §1-2 —
   there is no alternative path shape that avoids it without violating
   the "nested under the owning Examination" convention
   `ExaminationController` itself established. Both tests were updated
   to filter by controller class instead (the same technique
   `ExaminationOpenApiCoverageTest::liveOperations()` already used) —
   the assertion is unchanged: Examination's own route surface is still
   pinned to exactly the same four API + three web routes.

2. **`ExaminationArchitectureGuardTest::only_the_application_service_writes_the_model`**
   and **`the_module_references_no_forbidden_domain`** scanned the ENTIRE
   `app/Domain/Examinations` directory. `ExaminationPaperService.php`
   now legitimately lives there (per this ADR's §3, the class is named
   `App\Domain\Examinations\Application\ExaminationPaperService`) and
   legitimately writes `ExaminationPaper` and references
   `SubjectOffering`. Both tests were narrowed: the write-path exclusion
   list now names both sole-writer services, and the `SubjectOffering`
   class-name ban is scoped to exclude ExaminationPaper's own files
   only — `Examination.php`/`ExaminationService.php`/
   `ExaminationController.php` themselves remain just as strictly
   forbidden from ever referencing `SubjectOffering` as they were before
   this checkpoint. A dedicated `ExaminationPaperArchitectureGuardTest`
   independently enforces ExaminationPaper's own boundaries (including
   the SAME forbidden-domain list minus `SubjectOffering`, which is its
   own explicitly sanctioned dependency).

Neither change weakens any 0H.4A invariant; both are pure precision
corrections a loosely-scoped test could not have anticipated before a
sibling entity existed in the same module directory.

## Consequences

- ExaminationPaper is implemented; Examinations now has two ratified
  checkpoints. GradeScale, marks, result calculation/publication, report
  cards and transcripts remain unimplemented (ADR 0032's remaining
  provisional sequence, unchanged).
- A future `StudentMark` can reference `examination_papers(id,
  school_id)` tenant-pinned without a schema change to this table.
- The required/elective constraint ADR 0032 bound is proven honoured,
  removing that open question from the next (marks) checkpoint.
- Two pre-existing 0H.4A tests were corrected in this same branch (see
  "Deviations" above) rather than left to fail; both stay exactly as
  strict for Examination as before.

## Alternatives considered

- **Give ExaminationPaper a `section_id`.** Rejected: §5 — a Paper is
  Offering-wide, and eligibility is a roster-seam concern, not a stored
  column here.
- **Support multiple components (Paper 1/Paper 2, theory/practical) in
  v1.** Rejected: not required by the current checkpoint scope, and the
  unconditional `(school_id, examination_id, subject_offering_id)`
  unique constraint is simpler and sufficient until a real multi-
  component requirement emerges — at which point it needs its own
  architecture/migration, not a silent extension.
- **Allow overnight sittings.** Rejected: no current requirement, and
  same-day-only keeps the time-order invariant a same-row CHECK.
- **Reject reactivation under an inactive parent instead of guarding
  only the transition.** Considered "always require both parents active
  on any write," but rejected: it would make ordinary historical
  correction (rescheduling a typo, adjusting max marks) impossible the
  moment a School retires an old Examination, which is a real and
  common operational need.
- **Fold ExaminationPaper's capability into `examinations.definitions.*`.**
  Rejected: identical reasoning to ADR 0032 §9 — a flat family would
  eventually let one grant imply rights over a materially different
  concern (defining windows vs. scheduling papers).
