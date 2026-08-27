# Phase 1F.0 — Elective Group / Mutual-Exclusivity Architecture

> **"Phase 1F" is an informal, Students/SIS-module-local checkpoint
> label only** (like 1A-1E before it) — it is **not** a formal
> `docs/roadmap/MASTER-ROADMAP.md` phase, and this checkpoint does not
> modify that document. This is architecture/discovery output for the
> Phase 1C-deferred requirement "elective-group mutual exclusivity",
> produced after Phase 1E closed architecturally with no implementation
> (`docs/students/PHASE-1E-0-STUDENT-LIFECYCLE-ARCHITECTURE.md`). It
> contains **no schema, PHP service, route, capability, or Vue
> changes** — documentation only.

## 0. Scope and method

Every claim below is sourced from the actual code/docs at this
checkpoint's base commit — no SIS-vocabulary import from outside this
repository (CLAUDE.md rule 2). Phase 1C's accepted semantics are
treated as fixed unless evidence forces a change.

## 0A. Phase 1F.0A hardening pass — what changed and why

Phase 1F.0 (commit `494cb83`) was accepted in principle but left three
details insufficiently exact, and one speculative field unjustified.
This pass (commit-to-follow) corrects all four, sourced from re-reading
the actual `student_subject_enrollments` migration/service code, not
from the prior report's wording:

1. **Exclusivity key corrected.** `student_subject_enrollments` has
   **no `student_enrollment_id` column** (verified from the actual
   migration, §3). The originally-implied `(student_id,
   academic_year_id, elective_group_id)` key is **provably unsafe**: a
   Student can be withdrawn from a `StudentEnrollment` and later
   re-enrolled in the **same AcademicYear** (a brand-new
   `StudentEnrollment` row — nothing in `StudentEnrollmentService::enroll()`
   prevents this, and `withdraw()` never touches
   `StudentSubjectEnrollment`), leaving a stale `active`
   `StudentSubjectEnrollment` row from the *withdrawn* placement that
   would falsely collide with a legitimate new elective choice under
   the *new* placement. **Fix: add a new `student_enrollment_id` column**
   to `student_subject_enrollments`, and key the new index on it
   instead of `(student_id, academic_year_id)` (§4, §10).
2. **Snapshot-to-offering DB equality proof added.** §11A defines an
   exact composite FK proving `StudentSubjectEnrollment.elective_group_id`
   equals the referenced `SubjectOffering`'s `elective_group_id` at
   INSERT time, for every grouped (non-null) row — not merely
   "validated by service."
3. **Configuration-vs-enrollment race resolved.** §16A defines the
   exact serialization rule (lock the target `SubjectOffering` row in
   every enroll/transfer/configuration-mutation transaction) and proves
   no inconsistent state is reachable.
4. **`ElectiveGroup.status` removed.** No repository evidence supports
   an independent group lifecycle (§8, revised) — the restrict-on-delete
   FKs already protect historical references without it.

Every other Phase 1F.0 decision (ownership, terminology, roster/
Communications/rollover boundaries, authorization, no-schema-yet scope)
is **unchanged and reaffirmed** below.

## 1. The exact deferral this checkpoint resolves

`docs/students/PHASE-1C-STUDENT-SUBJECT-ENROLLMENT-FOUNDATION.md`
§27 ("Deferred work"):

> Elective-group mutual exclusivity (e.g. "French OR Spanish, never
> both") — no such grouping concept exists in `Subject`/`SubjectOffering`
> yet; `student_subject_enrollments_one_active_per_offering` only
> prevents a duplicate active row in the SAME offering, not membership
> across two related offerings.

The migration that creates `student_subject_enrollments` repeats this
verbatim in its own docblock — the single, precise, repository-sourced
statement of the gap this checkpoint designs the "future checkpoint" for.

## 2. Evidence: current `SubjectOffering`

`apps/platform/database/migrations/2026_08_23_091000_create_subject_offerings_table.php`
+ `app/Domain/AcademicStructure/Infrastructure/SubjectOffering.php`:

- Fields: `id`, `school_id`, `academic_year_id`, `campus_id`,
  `grade_level_id`, `subject_id`, `is_required` (bool, default `true`),
  `sequence`, `weekly_periods_target`, `status` (`active`/`inactive`,
  app-enforced, no DB CHECK), timestamps.
- **`unique(school_id, academic_year_id, campus_id, grade_level_id,
  subject_id)`** — a Subject has **at most one Offering** per
  (Year, Campus, Grade). "Subject" and "Offering" are 1:1 within one
  academic context.
- **No existing grouping concept**: no `group`/`category`/`choice`
  column anywhere. `is_required` is the only per-Student-relevant flag.
- `Subject.subject_type` (`core`/`elective`/`co_scholastic`/`language`/
  `other`, School-wide, not context-scoped) is a coarse taxonomy tag,
  **not** an exclusivity mechanism — ruled out.

## 3. Evidence: current `StudentSubjectEnrollment` — re-verified from code, not prior report wording

`apps/platform/database/migrations/2026_08_24_100000_create_student_subject_enrollments_table.php`
(read directly for this hardening pass, exact `$table->` column list):

```php
$table->uuid('id')->primary();
$table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
$table->uuid('student_id');
$table->uuid('subject_offering_id');
$table->uuid('academic_year_id');
$table->string('status')->default('active'); // active|withdrawn|cancelled|transferred
$table->date('starts_on');
$table->date('ends_on')->nullable();
$table->timestamps();
```

Plus: `unique(id, school_id)`; FKs `student_id → students` (cascade),
`subject_offering_id → subject_offerings` (restrict),
`academic_year_id → academic_years` (restrict). **No FK to
`student_enrollments` anywhere in this migration.**

**Direct answers to the required schema questions:**
- `student_enrollment_id`: **NO** — does not exist on `student_subject_enrollments` today.
- `student_id`: **YES**.
- `academic_year_id`: **YES** (denormalized off `subject_offering_id`).

The prior Phase 1F.0 report's column listing was itself accurate; the
error was in what was *derived from* those columns (§4), not in
misreporting the schema.

**Locking, re-confirmed:**
- `enroll()`: locks nothing; relies solely on the partial unique index
  + exception translation.
- `transfer()`: locks the **source `StudentSubjectEnrollment` row**
  only, not `StudentEnrollment`, not the target `SubjectOffering`.
- **Confirmed sole writer**: `StudentSubjectEnrollmentService`
  (Communications' `SubjectOfferingAudienceResolver` is read-only).

**Confirmed sole writer for `StudentEnrollment` lifecycle**:
`StudentEnrollmentService::withdraw()`/`complete()`/`cancel()` —
grepped directly; **zero references to `StudentSubjectEnrollment`**
anywhere in that service. Withdrawing/completing/cancelling a
`StudentEnrollment` never touches any `StudentSubjectEnrollment` row.
`StudentEnrollmentService::enroll()` has no check preventing a second,
later `StudentEnrollment` for the same `(student_id, academic_year_id)`
once the first is no longer `active` — the partial unique index
`student_enrollments_one_active_per_student_year` only forbids two
*concurrently active* rows, not two *sequential* ones. **This is the
exact mechanism that makes §0A finding 1 real, not hypothetical.**

## 4. The exact business rule and exclusivity key (DECIDED, corrected)

> A `StudentEnrollment` may have **at most one current (`active`)
> `StudentSubjectEnrollment`** whose target `SubjectOffering` belongs to
> the same `ElectiveGroup`.

**Exact future schema addition**: `student_subject_enrollments` gains a
new column, **`student_enrollment_id`** (nullable UUID, composite FK to
`student_enrollments(id, school_id)`, restrict-on-delete), populated by
`StudentSubjectEnrollmentService` from the exact `StudentEnrollment` row
`assertCompatible()` already resolves and verifies at write time — no
new query, just capturing a value the service already has in hand.

**Exact future partial unique index** (no placeholders):

```sql
CREATE UNIQUE INDEX student_subject_enrollments_one_active_per_elective_group
  ON student_subject_enrollments (student_enrollment_id, elective_group_id)
  WHERE status = 'active' AND elective_group_id IS NOT NULL
```

**Why this is per-`StudentEnrollment` and safe, precisely:**
- `student_enrollment_id` identifies **one specific placement row**,
  not merely "this Student in this Year" — so the withdrawn-then-
  re-enrolled scenario in §3 produces **two different**
  `student_enrollment_id` values (the old, withdrawn placement's id,
  and the new placement's id), which are **two different index keys**,
  never colliding. The stale `active` row from the withdrawn placement
  no longer poses a false-conflict risk.
- **Cross-year safe: YES.** Each `StudentEnrollment` belongs to exactly
  one `AcademicYear` (its own `academic_year_id` column), and each
  `ElectiveGroup` likewise belongs to exactly one `AcademicYear` (§8) —
  a Year-A `student_enrollment_id` can never coincide with a Year-B
  `elective_group_id`'s legitimate participants, and even if it somehow
  could, the pair `(student_enrollment_id, elective_group_id)` is
  already unique per real-world placement, so no additional
  `academic_year_id` column is needed in the index.
- **Cross-campus/grade safe: YES**, transitively — every `ElectiveGroup`
  is DB-scoped to one Campus/GradeLevel (§8's widened composite FK), so
  `elective_group_id` alone already carries that context; a
  `StudentEnrollment` likewise has one Campus/GradeLevel at a time.
  No explicit `campus_id`/`grade_level_id` columns are needed in this
  index — the invariant is inherited through both foreign keys, not
  re-derived.

**Multiple independent groups remain fully supported** — unchanged from
Phase 1F.0: a Language group and an Arts group are unrelated;
choosing French does not block choosing Music.

## 5. Duplicate-same-offering vs. group-conflict — kept distinct (unchanged, DECIDED)

Two separate invariants, never conflated or replaced:
1. **Existing, retained exactly as-is**:
   `student_subject_enrollments_one_active_per_offering` —
   `(student_id, subject_offering_id) WHERE status = 'active'`. A
   Student cannot have two active rows in the identical `SubjectOffering`.
   Note this key uses `student_id`, not `student_enrollment_id` — it is
   an **existing, already-accepted Phase 1C invariant**, out of scope
   for this checkpoint to redesign (see §5A for an observation, not a
   correction).
2. **New**: `student_subject_enrollments_one_active_per_elective_group`
   (§4) — a Student cannot have two active rows whose offerings belong
   to the same `ElectiveGroup`.

### 5A. Observation (not a correction): the same-offering index shares the theoretical staleness shape

For completeness: the *existing* `student_subject_enrollments_one_active_per_offering`
index is keyed on `student_id` (not `student_enrollment_id`), so it
shares the same theoretical "stale active row from a withdrawn
`StudentEnrollment`" shape §3/§0A describes for the *new* index. This
is **pre-existing Phase 1C behavior, unrelated to and not introduced
by Phase 1F**, and out of scope to redesign here (the brief's own §29
explicitly directs "retain the existing independent invariant... do
not replace it"). Flagged for future awareness only — not a Phase
1F.0A finding requiring action.

## 6. Group ownership (unchanged, DECIDED)

**AcademicStructure owns `ElectiveGroup`**; **Students/SIS enforces**
the selection constraint. Unchanged from Phase 1F.0 — see that
reasoning (curriculum-structure fact vs. Student-facing enforcement,
matching `StudentSubjectEnrollment`'s own "Academic-owned facts,
Students-owned enforcement" precedent).

## 7. Terminology (unchanged, DECIDED)

**`ElectiveGroup`** — already the exact phrase this repository's own
Phase 1C doc uses for this exact concept.

## 8. Group scope and persistence — corrected (DECIDED)

**New table: `elective_groups`** (AcademicStructure-owned):

```
id                UUID (UUIDv7, ADR 0019)
school_id         UUID  -> schools, cascade
academic_year_id  UUID  -> academic_years, restrict
campus_id         UUID  -> campuses, restrict
grade_level_id    UUID  -> grade_levels, restrict
name              string
code              string  (App\Support\NormalizesCode, per CLAUDE.md rule 74)
timestamps
```

**`status` REMOVED from this checkpoint's proposal (correction).**
Re-searched the repository for any evidence of a need to
activate/deactivate an `ElectiveGroup` independently of its member
Offerings: **none found.** `SubjectOffering` already carries the
relevant lifecycle (`status`, gating *new* participation into any of
its members individually); `AcademicYear` already carries the
relevant year-level lifecycle. Adding `status` to `ElectiveGroup` would
have been pattern-matching against other reference entities "for
symmetry" alone — exactly what CLAUDE.md rule 2 and this gate's brief
both warn against. **Delete/deactivation policy instead relies entirely
on the restrict-on-delete FKs already in place** (§17, revised): once
any `SubjectOffering` or `StudentSubjectEnrollment` references a group,
PostgreSQL itself refuses a DELETE — no soft-delete/status flag is
needed to achieve "protect historical references." A genuinely unused
(zero-member, zero-history) group *may* get a real hard-delete endpoint
in a future checkpoint (1F.2's decision, not made here) precisely
because nothing references it and there is nothing to protect.

**`code` uniqueness scope**: `(school_id, academic_year_id, campus_id,
grade_level_id, code)` — a direct structural mirror of
`subject_offerings_unique_offering`'s own four-context-column +
one-distinguishing-field shape. **`name` uniqueness: NOT unique** —
`code` is the canonical normalized identity (CLAUDE.md rule 74); `name`
is a display label only, matching `Subject`'s own precedent (`code`
unique, `name` not).

**Both `name` and `code` retained**: justified directly by CLAUDE.md
rule 74's repository-wide convention ("every model with a `code` column
uses `App\Support\NormalizesCode`"), not invented for this checkpoint.

**Membership: a single nullable FK on `SubjectOffering`,
`elective_group_id`** — unchanged from Phase 1F.0 (1:0..1 relationship,
a pivot table would be speculative complexity per CLAUDE.md rule 2).

**Academic-context integrity — DB-enforced (unchanged from Phase 1F.0):**

```sql
ALTER TABLE elective_groups
  ADD CONSTRAINT elective_groups_context_unique
  UNIQUE (id, school_id, academic_year_id, campus_id, grade_level_id);

ALTER TABLE subject_offerings
  ADD CONSTRAINT subject_offerings_elective_group_context_fk
  FOREIGN KEY (elective_group_id, school_id, academic_year_id, campus_id, grade_level_id)
  REFERENCES elective_groups (id, school_id, academic_year_id, campus_id, grade_level_id);
```

This makes a `SubjectOffering` referencing an `ElectiveGroup` from a
different Year/Campus/Grade **structurally impossible** — PostgreSQL
rejects it at INSERT/UPDATE.

**Fields deliberately NOT added**: `description`, `min_choices`,
`max_choices`, `credits`, `priority`, `ranking`, `capacity`, and now
also `status` — none has repository evidence.

## 9. Required/elective rule (unchanged, DECIDED)

- A **required** Offering may never have `elective_group_id` set —
  **Database CHECK**: `CHECK (elective_group_id IS NULL OR is_required
  = false)` on `subject_offerings` (same-row, fully DB-enforceable, no
  cross-table limitation here), plus service-layer validation in the
  future configuration service (defense in depth).
- `StudentSubjectEnrollmentService` **already structurally rejects
  required offerings** today via `RequiredSubjectOfferingEnrollmentException`
  in both `enroll()` and `transfer()` (verified in the current code,
  unmodified by this checkpoint) — no explicit `StudentSubjectEnrollment`
  row is ever created for a required offering, before or after this
  checkpoint.
- **Ungrouped electives remain allowed** — `elective_group_id` nullable
  on both tables.

## 10. Persistence: two snapshot columns, one new index (DECIDED, corrected)

`StudentSubjectEnrollment` gains **two** new columns (not one):

```
student_enrollment_id  UUID NULL  -> student_enrollments (id, school_id), restrict   [NEW — §4]
elective_group_id      UUID NULL  -> elective_groups (id, school_id), restrict        [as Phase 1F.0]
```

Both are **nullable at the column level** (to avoid an impossible
backfill for pre-existing rows, §18A) but **always populated by
`StudentSubjectEnrollmentService`** for every row it creates going
forward — nullability here is a migration-compatibility concern, not a
semantic "optional" state for new writes.

**Database unique constraint: YES** (§4's exact index).
**Transactional lock: NO *new* lock on `StudentEnrollment` or
`StudentSubjectEnrollment`** — unchanged conclusion from Phase 1F.0,
now on firmer footing given the corrected key. A **new lock on the
target `SubjectOffering`** *is* required, but for a different reason
(§16A, the configuration race) — not for this same-group-across-
different-offerings race, which remains fully DB-index-protected with
zero new locking (§13/§14 of the original doc, reaffirmed).

**Concurrent same-group enroll** (two different Offerings, same group,
same `StudentEnrollment`): both attempt `INSERT ... student_enrollment_id
= E, elective_group_id = X`; PostgreSQL serializes on the index, exactly
one commits, the loser gets `ElectiveGroupConflictException`.

**Concurrent different-group enroll**: two different `elective_group_id`
values → two independent index entries → both succeed.

## 11. Join-limitation problem (unchanged reasoning, DECIDED)

Snapshotting `elective_group_id` (and now `student_enrollment_id`) onto
`StudentSubjectEnrollment` itself is what lets a plain partial unique
index work with no join at enforcement time — unchanged from Phase
1F.0's reasoning, identical in kind to this table's own existing
`academic_year_id` denormalization.

## 11A. Snapshot DB integrity: proving the group snapshot matches the Offering (NEW — resolves §6/§7/§8 of this gate's brief)

The duplicated state (`SubjectOffering.elective_group_id` vs.
`StudentSubjectEnrollment.elective_group_id`) needs the database itself
to prove equality for grouped rows, not merely "the service derived it
correctly." **Chosen mechanism: a composite foreign key**, evaluated
against the brief's four options:

- **(A) Composite FK tying the snapshot directly to `SubjectOffering` —
  CHOSEN.**
- (B) Trigger-based validation — rejected; not needed (see NULL
  analysis below — the one case a declarative FK can't close has zero
  business consequence, so a trigger buys nothing).
- (C) Service-only validation — rejected as the *sole* mechanism (no DB
  backstop against a future bypassing write path — same reasoning §10
  of the original doc already applied to the locking question).
- (D) No snapshot, locking-only — rejected; already the rejected
  Option 1 from §12's comparison table.

**Exact mechanism**: widen `subject_offerings`' existing tenant-unique
key with one more column, then reference it:

```sql
ALTER TABLE subject_offerings
  ADD CONSTRAINT subject_offerings_id_school_group_unique
  UNIQUE (id, school_id, elective_group_id);

ALTER TABLE student_subject_enrollments
  ADD CONSTRAINT student_subject_enrollments_offering_group_fk
  FOREIGN KEY (subject_offering_id, school_id, elective_group_id)
  REFERENCES subject_offerings (id, school_id, elective_group_id);
```

**PostgreSQL NULL behavior, evaluated carefully (per the brief's
explicit instruction):**
- **Grouped participation** (`student_subject_enrollments.elective_group_id
  IS NOT NULL`): all three FK columns are non-null, so PostgreSQL
  **fully enforces** the constraint — a matching `subject_offerings`
  row with that *exact* `(id, school_id, elective_group_id)` combination
  must exist. Since `subject_offerings.id` is already unique per
  School, this is a **direct database proof** that the snapshot equals
  the Offering's live `elective_group_id` at INSERT time. **Grouped
  mismatch is impossible.**
- **Ungrouped participation** (`elective_group_id IS NULL`): under
  PostgreSQL's default `MATCH SIMPLE` semantics, a multi-column FK with
  *any* NULL column is **not checked at all** — the constraint is
  trivially satisfied regardless of what `subject_offering_id` points
  to. **This is correct, not a gap to close**, because: (a) it is
  exactly what allows a legitimately ungrouped elective's
  `StudentSubjectEnrollment` row to exist without requiring the
  Offering to *also* independently prove `elective_group_id IS NULL`
  via the FK; and (b) even in the residual case the FK doesn't
  literally re-verify (an ungrouped snapshot alongside a
  since-changed Offering), §13/§16A's immutability-plus-offering-lock
  rule already makes it **unreachable**: `elective_group_id` is always
  derived server-side, never caller-supplied, and once *any*
  participation (grouped or ungrouped) exists for an Offering, its
  group assignment is frozen (§13) — so a null snapshot permanently
  implies the Offering was, and remains, ungrouped. **No trigger is
  needed**: the one case ordinary constraints can't fully close is also
  the one case with zero business consequence, because the partial
  unique index (§4) already ignores `NULL` group values entirely — there
  is no exclusivity invariant protecting ungrouped rows in the first
  place.

**Ungrouped NULL valid: YES. Service-only consistency: NO** — DB
equality proof exists for every row that actually participates in the
exclusivity invariant.

## 12. Persistence options — comparison table (unchanged conclusion, DECIDED)

Unchanged from Phase 1F.0 §12 — Option 2 (snapshot + DB partial unique)
remains chosen, now refined to snapshot **two** columns
(`student_enrollment_id`, `elective_group_id`) instead of one, for the
reasons in §4.

## 13. Historical correctness and configuration immutability (DECIDED, tightened)

**Can offering group membership change after participation: only
before any participation exists — unchanged decision, now made
airtight by §16A's locking rule** (Phase 1F.0's version described the
*policy*; this pass proves it's actually *enforceable* under
concurrency, not just checked-then-acted). Policy: `SubjectOffering.elective_group_id`
becomes immutable once any `StudentSubjectEnrollment` row (active or
historical, grouped or ungrouped) references that Offering.

**Historical `StudentSubjectEnrollment` meaning is preserved by** the
snapshot itself — but stated precisely this time: **not** because it
"protects against a later legitimate reassignment" (reassignment after
participation is *forbidden*, full stop, by §13's own rule — there is
no legitimate-reassignment scenario to protect against). The snapshot's
actual value is (1) enabling the partial unique index to exist at all
without a join (§11), (2) giving every historical row direct,
self-contained query context, and (3) the DB equality proof (§11A) for
grouped rows. Once written, a row's snapshot is permanently and
trivially consistent with the (now-frozen) Offering it references.

## 14. Enrollment lifecycle interaction (unchanged, DECIDED)

Unchanged from Phase 1F.0: `active` counts; `withdrawn`/`cancelled`/
`transferred` all release the group slot, purely as a consequence of
the index's `WHERE status = 'active'` clause.

## 15. Transfer semantics (unchanged conclusions; ordering restated in §16A)

Same-group transfer: valid atomic replacement. Different-group
transfer: guarded by the same index. Grouped→ungrouped: slot released,
no conflict possible. Ungrouped→grouped: normal conflict check.
Ungrouped→ungrouped: unchanged. See §16A for the corrected exact
transaction ordering (adds the target-Offering lock).

## 16. Offering deactivation (unchanged, DECIDED)

Phase 1C.1A's rule is untouched; group state never substitutes for
Offering-active semantics.

## 16A. Configuration-vs-enrollment race — resolved (NEW, resolves §10-§19 of this gate's brief)

**The exact race**, restated precisely:

- **T1** (configuration): reads Offering A, sees no participation,
  plans to set `elective_group_id = NULL → X`.
- **T2** (enrollment): enrolls a Student into Offering A, must snapshot
  its *current* `elective_group_id`.

Without synchronization, T2 could snapshot `NULL` while T1 concurrently
commits `X` (or vice versa in ordering), producing a participation row
whose snapshot no longer matches the Offering's live configuration —
and because §11A's composite FK only proves equality *at INSERT time*,
a stale snapshot from a genuinely-racy interleaving would still pass
the FK check (it matched what was true a moment earlier) while
silently violating the *intended* business invariant.

**Chosen serialization rule**: **every transaction that (a) enrolls
into a `SubjectOffering`, (b) transfers into a `SubjectOffering`, or
(c) assigns/removes/changes that `SubjectOffering`'s `ElectiveGroup`
must begin by locking the target `SubjectOffering` row (`SELECT ...
FOR UPDATE`)** before reading `is_required`/`status`/`elective_group_id`
and before any participation/group mutation.

**Why this is sufficient, proven by interleaving**: whichever
transaction (T1 or T2) acquires the lock first runs to completion
(commit or rollback) before the other can even read the row — Postgres
blocks the second `SELECT ... FOR UPDATE` until the first transaction
ends, and the second transaction's subsequent read (under READ
COMMITTED, this codebase's default) then sees the first's *committed*
result. Exactly two coherent outcomes are possible, never a third:

- **Outcome A** — configuration (T1) commits first: T2 then locks the
  now-updated Offering row, reads `elective_group_id = X`, correctly
  snapshots `X`.
- **Outcome B** — enrollment (T2) commits first: T1 then locks the
  Offering row and, per §13's immutability rule (now itself
  race-safe because it's evaluated **inside the same lock**), finds
  participation now exists and **rejects** the group assignment.

**Forbidden state — offering group = X with a participation snapshot
of NULL, or any mismatched snapshot — is unreachable**, because no
interleaving other than A or B above is possible once both parties
acquire the same lock as their first action.

### Division of responsibility, stated explicitly (resolves §12 of this gate's brief)

- **`SubjectOffering` row lock**: protects the *consistency of one
  offering's own mutable configuration snapshot* against a concurrent
  read/write of *that same offering* — i.e., prevents "read group as
  NULL, then it changes mid-transaction" races (§16A above). It
  **cannot** and does **not** protect Student-level exclusivity across
  *different* offerings, because it is scoped to a single row.
- **Partial unique index** (§4): protects Student-level exclusivity
  *across different offerings* that happen to share a group — the
  *only* mechanism that can, since it is the only constraint spanning
  two different `SubjectOffering` rows via their child rows' shared
  snapshotted group value.
- **`StudentEnrollment` locking**: **not added, and not needed** — per
  §12's explicit steer, this would be locking "out of habit." The two
  mechanisms above are individually sufficient for the two distinct
  races they each target; a third lock on `StudentEnrollment` would add
  complexity without closing any remaining gap.

### Same-group concurrent enroll, re-confirmed under the corrected model (§13 of this gate's brief)

T1 enrolls Offering A (Group X), T2 enrolls Offering B (Group X) — for
the **same** `StudentEnrollment`. T1 and T2 lock **different**
`SubjectOffering` rows (A and B respectively) — the offering locks do
**not** serialize T1 against T2 at all. **The partial unique index
remains the decisive backstop**: both transactions proceed to their
respective `INSERT`s; PostgreSQL's index guarantees **exactly one
commits**.

### Different-group concurrent enroll (§14 of this gate's brief)

T1: Offering A / Group X. T2: Offering B / Group Y. Different
`elective_group_id` values → two independent index entries → **both
commit**.

### Exact enroll() ordering (corrected — resolves §16 of this gate's brief)

1. Cross-school check (fail-fast, before the transaction, as today).
2. `DB::transaction`:
   a. **Lock/reload the target `SubjectOffering`** (`lockForUpdate()`)
      — **NEW**.
   b. Validate `status` is active, against the *locked* row.
   c. Validate `is_required = false`, against the *locked* row.
   d. `assertCompatible()` — unchanged, resolves and verifies the
      Student's current active `StudentEnrollment`.
   e. Read the locked Offering's authoritative `elective_group_id`.
   f. `createRow()`: derive `student_enrollment_id` from (d),
      `elective_group_id` from (e); `INSERT`. No caller-provided
      `elective_group_id` anywhere in this path (resolves §16's "no
      caller-provided elective_group_id" requirement); no stale
      Offering model instance is ever used for the snapshot — only the
      just-locked, just-reloaded row (resolves "no stale SubjectOffering
      model determines the snapshot").

### Exact transfer() ordering (corrected — resolves §17 of this gate's brief)

1. Cross-school check (fail-fast, as today).
2. `DB::transaction`:
   a. Lock/reload the **source** `StudentSubjectEnrollment` (unchanged
      from current code).
   b. Verify source still `active` (unchanged).
   c. **Lock/reload the target `SubjectOffering`** — **NEW** (current
      code validates the target against an *unlocked* model instance
      the caller passed in; this pass corrects that to a locked,
      freshly-read row).
   d. Validate target `status`/`is_required` against the locked row.
   e. `assertCompatible()` against the target — unchanged.
   f. Mark source `transferred` (existing conditional `UPDATE`,
      unchanged — this already happens *before* the target insert in
      the current code, confirmed correct and retained).
   g. Read the locked target's authoritative `elective_group_id`.
   h. `createRow()` for the target with derived `student_enrollment_id`
      + `elective_group_id` from (g).

**Confirmed**: the source leaves `active` (step f) strictly before the
target `INSERT` (step h) within the same transaction — this ordering
already exists in current code and requires no change; only the new
target-Offering lock (step c) is added. The **source** Offering does
**not** need locking — its own snapshot was fixed immutably at *its*
creation time (§13) and nothing in a transfer writes to the source
Offering's configuration.

## 17. Group deletion / lifecycle — corrected (DECIDED)

- **No `status` column** (§8, corrected) and **no delete endpoint** in
  this checkpoint's scope. Delete safety comes entirely from
  restrict-on-delete FKs: `subject_offerings.elective_group_id` and
  `student_subject_enrollments.elective_group_id` both `restrictOnDelete`
  — PostgreSQL refuses a `DELETE` on any `elective_groups` row still
  referenced by either. **No cascade, ever.**
- A future checkpoint (1F.2) *may* add a real hard-delete endpoint for
  a group with zero references (nothing to protect) — not decided here,
  and not blocked by the absence of a `status` column.

## 18. Configuration safety with existing data (unchanged conclusion, DECIDED)

Unchanged from Phase 1F.0: the immutability rule (§13, now proven
race-safe by §16A) plus the partial unique index compose to make an
invalid configuration state unreachable — no separate "reject
configuration until conflicts are resolved" workflow is needed.

## 18A. Initial adoption policy (NEW — resolves §31-§33 of this gate's brief)

**Explicit choice: grouping is available only for `SubjectOffering`s
with zero participation at configuration time — never a backfill
workflow for offerings that already have historical participation.**
Evaluated against the brief's two options:

- **(a) Future/new-offering-only, via the existing immutability rule
  alone — CHOSEN.**
- (b) A controlled backfill operation (lock all affected offerings,
  inspect all history, verify no conflicts, backfill snapshots) —
  explicitly **NOT** built. No repository or product evidence justifies
  this complexity (CLAUDE.md rule 2), and the immutability rule (§13)
  already defines a coherent, simpler v1 without it.

**Practical scope of the limitation**: this is narrower than it first
sounds. The normal product workflow already configures elective groups
*before* an `AcademicYear`'s Sections/Offerings receive any Student
placements — Schools set up next year's curriculum ahead of enrollment
(the same "prepare next year while this year is running" pattern
`docs/modules/STUDENT-ENROLLMENT.md`'s rollover design already
established as normal). A School adopting this feature for a
**not-yet-started or newly-configured** AcademicYear is entirely
unaffected — no participation can exist yet for any of that year's
Offerings. The limitation only bites a School attempting to retrofit
exclusivity onto an **already-running** year's electives that already
have enrolled Students — an explicitly deferred, narrower case, not
hidden by this decision.

**Existing data migration, confirmed**: no backfill of any kind for
either `SubjectOffering.elective_group_id` or
`StudentSubjectEnrollment.elective_group_id`/`student_enrollment_id` —
all default/existing rows remain `NULL`. Existing rows remain fully
valid (an ungrouped/pre-existing row is indistinguishable from a
deliberately-ungrouped new row). The new partial group index imposes
**zero constraint** on any pre-1F.1 data until a group is explicitly
configured going forward.

## 19. Roster read model (unchanged, DECIDED)

`SubjectOfferingRosterReadService` requires no change — unchanged
reasoning from Phase 1F.0.

## 20. Communications boundary (unchanged, DECIDED)

No direct Communications impact — unchanged reasoning.

## 21. Rollover boundary (unchanged, DECIDED — deferred)

Unchanged from Phase 1F.0; forward mapping-requirement note retained.

## 22. UI/API deferred decision (unchanged, DECIDED)

Unchanged from Phase 1F.0.

## 23. Authorization (unchanged, DECIDED)

Unchanged: reuse `academics.subjects.view`/`.manage`, no new capability.

## 24. Audit (unchanged, DECIDED, prospective)

Unchanged, with one addition: prospective `elective_group.created`/
`subject_offering.elective_group_assigned` events, per §13, can now be
described precisely as **always null→value, never value→different-value**
transitions, since §16A proves the immutability rule holds under
concurrency, not merely "by convention."

## 25. Failure outcome (unchanged, DECIDED)

`ElectiveGroupConflictException` — unchanged.

## 26. Proposed implementation slices (updated scope for 1F.1)

1. **Phase 1F.1 — Elective Group Domain & Schema Foundation.**
   `elective_groups` migration (no `status` column — corrected scope);
   `ElectiveGroup` model; `subject_offerings.elective_group_id` + CHECK
   constraint + composite context-FK + `(id, school_id, elective_group_id)`
   widened unique key (§11A); `student_subject_enrollments` gains
   **both** `student_enrollment_id` **and** `elective_group_id` (§4,
   §10) + the new partial unique index (§4) +
   `student_subject_enrollments_offering_group_fk` (§11A);
   `ElectiveGroupConflictException`. No service/controller/route
   changes yet.
2. **Phase 1F.2 — Elective Group Configuration Service.**
   AcademicStructure-owned service: create group, assign/remove
   Offering membership — **must lock the target `SubjectOffering`
   row and check participation existence inside that same lock**
   (§16A), enforcing §9's required-offering rule and §13's
   immutability rule race-safely. No API/UI.
3. **Phase 1F.3 — StudentSubjectEnrollment Mutual-Exclusivity
   Enforcement.** `enroll()`/`transfer()` updated to the exact ordering
   in §16A (add the target-Offering lock; derive and snapshot both new
   columns); `translateUniqueViolation()` maps the new index to
   `ElectiveGroupConflictException`. Mandatory tests (§27).
4. *(Deferred, unnumbered)* — Administrative API/UI.

## 27. Mandatory future test plan (acceptance criteria for 1F.3, updated)

- **Same-group concurrent enroll**: two real, separate processes/
  transactions, two different Offerings in the same group, same
  `StudentEnrollment` — expected: exactly one succeeds.
- **Different-group concurrent enroll**: both succeed.
- **Configuration-vs-enroll race (NEW)**: two real, separate
  processes/transactions — one assigning Offering A to Group X, one
  enrolling a Student into Offering A — expected: exactly one of the
  two coherent outcomes from §16A (group-assignment-wins-first with a
  correctly-snapshotted `X`, or enrollment-wins-first with the
  configuration rejected by immutability); a mismatched
  group-vs-snapshot state must never be produced. Must be a real
  PostgreSQL two-process test, not a sequential simulation.
- **Group removal/change race (NEW)**: same shape as above, applied to
  removing/changing an existing (still-unused) group assignment
  concurrently with a first enrollment attempt — same coherent-outcome
  requirement, snapshot equality and immutability preserved.
- **Same-group transfer**: atomic replacement succeeds, no false
  conflict.
- **Conflicting target group on transfer**: blocked, source row's
  `transferred` mark rolled back too.
- **Configuration conflict (sequential)**: reassigning a
  `elective_group_id` after any participation exists is rejected.
- **Cross-School**: `elective_groups`, `subject_offerings.elective_group_id`,
  `student_subject_enrollments.elective_group_id`/`student_enrollment_id`
  all reject cross-School references at INSERT time, proven under
  `pgsql_admin` bypassing RLS's own WITH CHECK.
- **RLS**: `elective_groups` — enabled AND forced, no-context fails
  closed, School A/B isolation.

## 28. Database/tenancy checklist (for 1F.1, updated)

New tenant table (`elective_groups`): YES, **no `status` column**.
UUIDv7: yes. RLS: `TenantRls::enable('elective_groups')`. FORCE: yes.
Same-School FKs: yes, including both widened composite keys (§8's
year/campus/grade context FK, and §11A's offering/group equality FK).

## 29. External module impact (unchanged)

Unchanged from Phase 1F.0 §29 — no changes to any external module's
impact classification from this hardening pass.

## 30. Findings

- **P0**: none — the withdrawn-then-re-enrolled false-conflict defect
  (§0A finding 1) is caught and corrected **before** any schema was
  implemented; nothing shipped with the flaw.
- **P1**: none.
- **P2**: none.
- **P3**: §5A's observation about the *pre-existing* same-offering
  index sharing the same theoretical shape — explicitly out of scope,
  tracked for awareness only.
- **P4**: deferred rollover mapping (§21), deferred UI/API (§22/§26
  item 4), and the narrower initial-adoption limitation (§18A) for
  Schools retrofitting onto an already-running year.

Known test-infrastructure backlog (Flutter SDK verification, etc.) is
carried separately per `apps/mobile/README.md`, unrelated to this
checkpoint.
