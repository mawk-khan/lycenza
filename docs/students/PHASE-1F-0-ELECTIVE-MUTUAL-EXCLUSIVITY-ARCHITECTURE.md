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

Phase 1F.0 (`494cb83`) was accepted in principle but left three details
insufficiently exact, and one speculative field unjustified. This pass
corrected: (1) the exclusivity key needs a new `student_enrollment_id`
snapshot, not `(student_id, academic_year_id)` — proven unsafe because
a withdrawn `StudentEnrollment`'s `StudentSubjectEnrollment` rows are
never touched by `StudentEnrollmentService::withdraw()`, so a same-year
re-enrollment could produce a false conflict against a stale `active`
row; (2) a composite FK was proposed to prove a grouped snapshot
matches its `SubjectOffering` (superseded by §11A below); (3) every
enroll/transfer/configuration mutation must lock the target
`SubjectOffering` row first (§16A); (4) `ElectiveGroup.status` removed
— no repository evidence supports independent group lifecycle.

## 0B. Phase 1F.0B hardening pass — what changed and why

Phase 1F.0A's own composite-FK snapshot-integrity mechanism (§11A) was
**empirically proven insufficient** using an isolated PostgreSQL 16
experiment (temporary, isolated container; no repository schema
touched) reproducing the exact proposed constraint:

```sql
FOREIGN KEY (subject_offering_id, school_id, elective_group_id)
REFERENCES subject_offerings (id, school_id, elective_group_id)
```

Under PostgreSQL's default `MATCH SIMPLE`, a child row with
`elective_group_id = NULL` **bypasses the FK check entirely**,
regardless of the referenced Offering's actual group. Proven directly:
inserting a `student_subject_enrollments` row with `elective_group_id
= NULL` against an Offering whose real group was `X` **succeeded**.
Worse, proven as a real invariant bypass: two rows for the **same**
`student_enrollment_id`, targeting two **different** Offerings that
both actually belong to Group `X`, both snapshotted `NULL` — **both
inserts succeeded**, because the partial unique index (keyed on
non-null `elective_group_id`) never saw either row. **This is a real,
working exploit of the `9612ca2` design**, not a theoretical concern.

This pass replaces the composite-FK mechanism with a **PostgreSQL
trigger** (§11A, revised) — directly matching an existing repository
precedent (`assert_membership_role_assignment_scope()` in
`2026_08_22_091000_create_membership_role_assignments_table.php`,
CLAUDE.md rule 25) — and adds the **placement-anchor FK** for
`student_enrollment_id` (§11B, new), plus a corrected implementation
slice order (§26).

## 1. The exact deferral this checkpoint resolves

`docs/students/PHASE-1C-STUDENT-SUBJECT-ENROLLMENT-FOUNDATION.md`
§27 ("Deferred work"):

> Elective-group mutual exclusivity (e.g. "French OR Spanish, never
> both") — no such grouping concept exists in `Subject`/`SubjectOffering`
> yet; `student_subject_enrollments_one_active_per_offering` only
> prevents a duplicate active row in the SAME offering, not membership
> across two related offerings.

## 2. Evidence: current `SubjectOffering`

Fields: `id`, `school_id`, `academic_year_id`, `campus_id`,
`grade_level_id`, `subject_id`, `is_required` (bool, default `true`),
`sequence`, `weekly_periods_target`, `status`, timestamps.
`unique(school_id, academic_year_id, campus_id, grade_level_id,
subject_id)` — Subject:Offering is 1:1 within one academic context. No
existing grouping concept. `Subject.subject_type` ruled out (wrong
granularity, not context-scoped).

## 3. Evidence: current `StudentSubjectEnrollment`

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

`student_enrollment_id`: **NO** (does not exist). `student_id`: **YES**.
`academic_year_id`: **YES**. No FK to `student_enrollments`.

**Locking**: `enroll()` locks nothing; `transfer()` locks only the
source `StudentSubjectEnrollment` row. **Confirmed sole writer**:
`StudentSubjectEnrollmentService`. **Confirmed**:
`StudentEnrollmentService::withdraw()`/`complete()`/`cancel()` never
reference `StudentSubjectEnrollment` — grepped directly, zero hits.
`StudentEnrollmentService::enroll()` has no check preventing a second,
later `StudentEnrollment` for the same `(student_id, academic_year_id)`
once the first is no longer active — this is what makes the §0A
false-conflict scenario real.

## 4. The exact business rule and exclusivity key (DECIDED)

> A `StudentEnrollment` may have **at most one current (`active`)
> `StudentSubjectEnrollment`** whose target `SubjectOffering` belongs to
> the same `ElectiveGroup`.

**New column**: `student_enrollment_id` (nullable UUID, composite FK to
`student_enrollments`, §11B), populated by
`StudentSubjectEnrollmentService` from the exact `StudentEnrollment`
row `assertCompatible()` already resolves.

**Exact partial unique index**:

```sql
CREATE UNIQUE INDEX student_subject_enrollments_one_active_per_elective_group
  ON student_subject_enrollments (student_enrollment_id, elective_group_id)
  WHERE status = 'active' AND elective_group_id IS NOT NULL
```

Cross-year safe: **YES** (each `StudentEnrollment` and each
`ElectiveGroup` belong to exactly one `AcademicYear`). Cross-campus/
grade safe: **YES**, transitively via `elective_group_id`'s own
DB-enforced context (§8).

## 5. Duplicate-same-offering vs. group-conflict (unchanged, DECIDED)

1. **Existing, retained exactly as-is**:
   `student_subject_enrollments_one_active_per_offering` — `(student_id,
   subject_offering_id) WHERE status = 'active'`.
2. **New**: `student_subject_enrollments_one_active_per_elective_group`
   (§4).

### 5A. Observation: the same-offering index shares the theoretical staleness shape

Pre-existing Phase 1C behavior, unrelated to and not introduced by
Phase 1F, out of scope to redesign here (the same-offering index is
keyed on `student_id`, not `student_enrollment_id`). Tracked for
awareness only.

## 6. Group ownership (unchanged, DECIDED)

**AcademicStructure owns `ElectiveGroup`**; **Students/SIS enforces**
the selection constraint.

## 7. Terminology (unchanged, DECIDED)

**`ElectiveGroup`**.

## 8. Group scope and persistence (DECIDED)

**New table: `elective_groups`**:

```
id                UUID (UUIDv7, ADR 0019)
school_id         UUID  -> schools, cascade
academic_year_id  UUID  -> academic_years, restrict
campus_id         UUID  -> campuses, restrict
grade_level_id    UUID  -> grade_levels, restrict
name              string
code              string  (App\Support\NormalizesCode, CLAUDE.md rule 74)
timestamps
```

**No `status` column** — no repository evidence of independent group
lifecycle; delete safety comes entirely from restrict-on-delete FKs
(§17).

`code` uniqueness: `(school_id, academic_year_id, campus_id,
grade_level_id, code)`. `name` not unique (display label; `code` is
canonical identity, CLAUDE.md rule 74).

**Membership**: a single nullable FK on `SubjectOffering`,
`elective_group_id` — not a pivot table (1:0..1 relationship).

**Academic-context integrity — DB-enforced**:

```sql
ALTER TABLE elective_groups
  ADD CONSTRAINT elective_groups_context_unique
  UNIQUE (id, school_id, academic_year_id, campus_id, grade_level_id);

ALTER TABLE subject_offerings
  ADD CONSTRAINT subject_offerings_elective_group_context_fk
  FOREIGN KEY (elective_group_id, school_id, academic_year_id, campus_id, grade_level_id)
  REFERENCES elective_groups (id, school_id, academic_year_id, campus_id, grade_level_id);
```

**Fields deliberately NOT added**: `description`, `min_choices`,
`max_choices`, `credits`, `priority`, `ranking`, `capacity`, `status`.

## 9. Required/elective rule (unchanged, DECIDED)

`CHECK (elective_group_id IS NULL OR is_required = false)` on
`subject_offerings`, plus service-layer validation.
`StudentSubjectEnrollmentService` already structurally rejects required
offerings via `RequiredSubjectOfferingEnrollmentException`, unmodified.
Ungrouped electives remain allowed.

## 10. Persistence: two snapshot columns, one new index, one new CHECK (DECIDED, corrected)

`StudentSubjectEnrollment` gains **two** new columns:

```
student_enrollment_id  UUID NULL  -> student_enrollments (composite, §11B), restrict
elective_group_id      UUID NULL  -> elective_groups (id, school_id), restrict
```

Both nullable at the column level (legacy-row compatibility, §18A/§18B)
but always populated by `StudentSubjectEnrollmentService` for every row
it creates going forward.

**New same-row CHECK** (cheap, DB-enforceable, no cross-table
limitation — directly resolves the brief's §16):

```sql
ALTER TABLE student_subject_enrollments
  ADD CONSTRAINT student_subject_enrollments_grouped_requires_anchor_check
  CHECK (elective_group_id IS NULL OR student_enrollment_id IS NOT NULL)
```

This does **not** by itself solve the snapshot-equality problem (§11A
does) — it only guarantees a grouped participation row can never exist
without also recording *which* placement it belongs to, closing off an
"grouped but anchor-less" orphan state as a defensive backstop.

**Database unique constraint: YES** (§4). **Transactional lock: NO new
lock on `StudentEnrollment`/`StudentSubjectEnrollment`** — the
cross-offering exclusivity race remains fully index-protected. A **new
lock on the target `SubjectOffering`** *is* required for a different
reason (§16A).

## 11. Join-limitation problem (unchanged reasoning, DECIDED)

Snapshotting `elective_group_id` and `student_enrollment_id` onto
`StudentSubjectEnrollment` itself is what lets a plain partial unique
index work with no join at enforcement time.

## 11A. Snapshot integrity: PostgreSQL trigger (REVISED — supersedes 1F.0A's composite-FK approach)

### The empirical proof (§0B)

Reproduced in an isolated, throwaway PostgreSQL 16 container (matching
the project's production version; no repository schema touched):

```sql
-- minimal reproduction of subject_offerings / student_subject_enrollments
CREATE TABLE subject_offerings (id UUID PRIMARY KEY, school_id UUID NOT NULL,
  elective_group_id UUID NULL, UNIQUE (id, school_id, elective_group_id));
CREATE TABLE student_subject_enrollments (id UUID PRIMARY KEY, school_id UUID NOT NULL,
  subject_offering_id UUID NOT NULL, elective_group_id UUID NULL, status TEXT DEFAULT 'active',
  FOREIGN KEY (subject_offering_id, school_id, elective_group_id)
    REFERENCES subject_offerings (id, school_id, elective_group_id));

INSERT INTO subject_offerings VALUES ('offering-A', 'school-A', 'group-X');

-- Attempt: offering IS grouped (X), but child snapshot is NULL
INSERT INTO student_subject_enrollments (id, school_id, subject_offering_id, elective_group_id)
  VALUES ('row-1', 'school-A', 'offering-A', NULL);
-- RESULT: INSERT 0 1 -- ACCEPTED. The FK check is skipped under MATCH SIMPLE
-- whenever any referencing column is NULL, regardless of the referenced row's
-- actual (non-null) elective_group_id.
```

Extending this with a second Offering B also in Group X, the actual
partial unique index (`(student_enrollment_id, elective_group_id) WHERE
status='active' AND elective_group_id IS NOT NULL`), and two `NULL`-
snapshotted rows for the same `student_enrollment_id` targeting
Offerings A and B: **both rows were accepted.** The Student now holds
two simultaneous active memberships in Group X, completely undetected
by any database mechanism in the `9612ca2` design.

**Classification: DB invariant complete under `9612ca2`: NO.**
"Caller cannot supply `elective_group_id`" is real application safety,
but does **not** make the invariant database-enforced — a future bug,
a raw `DB::table()` call, a seeder, or a data-migration script bypassing
`StudentSubjectEnrollmentService::createRow()` could reproduce this
exact bypass with no database rejection.

### Repository precedent search

- **Triggers**: **present** —
  `2026_08_22_091000_create_membership_role_assignments_table.php` /
  `2026_08_22_090800_create_platform_role_assignments_table.php` both
  use `CREATE OR REPLACE FUNCTION ... RETURNS trigger` +
  `CREATE TRIGGER ... BEFORE INSERT OR UPDATE ... FOR EACH ROW`, with
  `SELECT ... INTO` a variable, `IS DISTINCT FROM` for NULL-safe
  comparison, and `RAISE EXCEPTION` to reject — enforcing that
  `membership_role_assignments.role_id` references a role whose
  `scope = 'school'` (a cross-table invariant ordinary FKs cannot
  express, per that migration's own docblock: "the trigger below is a
  database-level guarantee").
- **Generated columns** (`GENERATED ALWAYS`): **absent**.
- **`MATCH FULL`/`NULLS NOT DISTINCT`**: **absent**.
- **A directly relevant negative precedent**: `student_enrollments`'
  own migration docblock states, for its *own* internal `section_id`-
  vs-`academic_year_id`/`campus_id`/`grade_level_id` consistency:
  "Consistency... is NOT a database constraint (PostgreSQL cannot
  cheaply express 'these columns must equal another row's columns'
  without a trigger) — it is guaranteed by construction because
  [`StudentEnrollmentService`] is the only sanctioned write path." This
  is the repository's own precedent for **accepting service-only
  enforcement** in an analogous situation — but critically, that case
  has a property mine does not: **all four columns there are written by
  the *same* service, from the *same* source object, in the *same*
  call, and the row is never mutated afterward to reference a different
  Section.** My case has **two different services** (the future
  `ElectiveGroupConfigurationService` writing `subject_offerings.elective_group_id`,
  and `StudentSubjectEnrollmentService` writing the snapshot) touching
  two different tables independently — structurally the *same* shape as
  `membership_role_assignments`' `roles`/`membership_role_assignments`
  split, which the repository *did* choose to enforce with a trigger.

### Four strategies, evaluated exactly as required

| | **A. Nullable snapshot + service-only** | **B. Nullable snapshot + trigger (CHOSEN)** | **C. Non-null selection key** | **D. Separate invariant relation** |
|---|---|---|---|---|
| Domain clarity | OK | Strong — snapshot's meaning is DB-guaranteed | Confusing — a single column means two different things (group id or offering id) depending on a hidden discriminator | OK, but a second aggregate |
| DB enforcement | **None** (empirically proven bypassable) | **Full** — every INSERT/UPDATE checked | Full, but only if the discriminator itself is also proven correct | Full |
| NULL behavior | Silently wrong | Correctly handled via `IS DISTINCT FROM` | Eliminates NULL by construction, at the cost of needing an explicit `kind` discriminator to avoid UUID-namespace collision between an `elective_group_id` value and a `subject_offering_id` value (both are UUIDs from different tables — `COALESCE` alone is genuinely ambiguous, exactly the brief's own warning) | N/A (own table, own PK) |
| Legacy migration | Trivial (nullable, no backfill) | Trivial (nullable, no backfill; trigger only applies to future writes since it fires on INSERT/UPDATE, never retroactively) | Requires backfilling a synthetic non-null key for every existing row — real complexity for zero benefit given electives are ungrouped today anyway | New table, no legacy rows to migrate but a new service to build |
| Historical integrity | Weak (unproven) | Strong (DB-proven at write time, immutable after — §13) | Strong | Strong, but duplicates lifecycle |
| Concurrency | Same-offering-pair race still needs §16A's lock regardless | Same — trigger doesn't replace §16A's lock, it complements it (below) | Same | Same, plus a second aggregate's own concurrency story |
| Transfer | No change needed | No change needed (trigger fires on the same `INSERT` `createRow()` already performs) | `transfer()` must recompute the discriminated key on both legs | New aggregate must transition alongside `StudentSubjectEnrollment` |
| Configuration mutation | N/A | Trigger only fires on `student_subject_enrollments` writes, not on `subject_offerings` reassignment — §13's immutability rule (enforced in the app layer, inside the §16A lock) is what prevents *that* side from drifting | Reassignment would need to rewrite the discriminated key on... nothing, since existing rows never get touched (fine) | Same immutability rule, on a third table |
| Complexity | Lowest, but incomplete | Low — one function, one trigger, mirrors an existing pattern exactly | Medium-high — new discriminator concept, new decoding logic everywhere the key is read | Highest |
| Repository precedent | `student_enrollments`' own docblock explains *why they didn't* — but that case's single-writer property doesn't hold here | **Direct, exact-shape precedent**: `assert_membership_role_assignment_scope()` | None | None |

**Chosen: B.** Per the brief's own bar (§8 of this gate): Strategy A is
only acceptable if this document explicitly claims "service-enforced,
not DB-enforced" and justifies why a DB-bypassable exclusivity
invariant is acceptable here — **this document does not make that
claim**, because a stronger, low-complexity, directly-precedented
option (B) exists and closes the empirically-proven gap completely.

### The exact trigger (conceptual definition, not implemented in this checkpoint)

```sql
CREATE OR REPLACE FUNCTION assert_student_subject_enrollment_elective_group_snapshot()
RETURNS trigger AS $$
DECLARE
    offering_group_id uuid;
BEGIN
    SELECT elective_group_id INTO offering_group_id
    FROM subject_offerings
    WHERE id = NEW.subject_offering_id AND school_id = NEW.school_id;

    IF NEW.elective_group_id IS DISTINCT FROM offering_group_id THEN
        RAISE EXCEPTION
            'student_subject_enrollments.elective_group_id (%) must match subject_offerings.elective_group_id (%) for subject_offering_id %',
            NEW.elective_group_id, offering_group_id, NEW.subject_offering_id;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_student_subject_enrollments_elective_group_snapshot
BEFORE INSERT OR UPDATE ON student_subject_enrollments
FOR EACH ROW EXECUTE FUNCTION assert_student_subject_enrollment_elective_group_snapshot();
```

`IS DISTINCT FROM` (NULL-safe) correctly **allows** grouped-correct
(`X` vs `X`) and ungrouped-correct (`NULL` vs `NULL`), and **rejects**
grouped→NULL, grouped→wrong-group, and ungrouped→any-group — exactly
the brief's §9 conceptual invariant, verified against every cell of
§F below.

**§7 of the original 1F.0A's composite-FK mechanism
(`student_subject_enrollments_offering_group_fk` +
`subject_offerings_id_school_group_unique`) is DROPPED, superseded by
this trigger**, which fully subsumes its coverage (grouped-mismatch)
and additionally closes the NULL-bypass gap it could not. Keeping both
would be redundant complexity for zero additional coverage (CLAUDE.md
rule 2) — the existing plain FK `subject_offering_id → subject_offerings(id,
school_id)` (Phase 1C, unmodified) already guarantees basic referential/
same-School validity independent of this trigger.

**Locking interaction, evaluated explicitly**: the trigger fires
`BEFORE INSERT OR UPDATE`, inside the *same transaction* as the
application's `INSERT`. Because §16A requires the application to
already hold `SELECT ... FOR UPDATE` on the target `SubjectOffering`
row *before* this `INSERT` runs, the trigger's own `SELECT` (no
explicit locking needed inside the trigger itself) reads that *same*,
*already-locked*, *already-current* row within the *same* transaction
— consistent by construction, no additional locking required inside
the trigger. **The trigger does not replace §16A's lock**: the lock
prevents *concurrent writers* from racing on the Offering's
configuration; the trigger guarantees that *whatever value the
application actually inserts* is scrupulously correct, defending
against an application bug (e.g. a stale in-memory model, a hand-
rolled script) that reads the right thing but writes the wrong thing.
Genuine defense in depth, not redundant with each other.

This trigger is **narrow by design** — it validates one relational
snapshot-equality fact only, never a lifecycle/business decision (no
status transitions, no exclusivity check — that remains the partial
unique index's job).

## 11B. Placement anchor integrity (NEW — resolves §12-15 of this gate's brief)

### Actual `student_enrollments` schema, re-verified from code

```
id, school_id, student_id, academic_year_id, campus_id, grade_level_id,
section_id, roll_number, status, starts_on, ends_on, timestamps
```

Existing unique keys: `unique(id, school_id)` and
`unique(school_id, academic_year_id, section_id, roll_number)`. **No
existing unique key on `(id, school_id, student_id, academic_year_id)`.**

### The counterexample, proven relevant

Without anchor integrity, nothing today would stop a row like:

```
student_subject_enrollments:
  student_id            = Student A
  academic_year_id      = Year 2026
  student_enrollment_id = <Student B's StudentEnrollment, same School>
  elective_group_id     = Group X
```

If such a row could exist, the partial unique index would evaluate
exclusivity against **Student B's placement**, not Student A's —
mutual exclusivity silently bypassed across unrelated Students sharing
a School.

### Exact anchor FK (proposed for Phase 1F.1's migration — not added now)

Requires widening `student_enrollments` with one **additional** unique
constraint (safe: a superset of the already-unique `(id, school_id)`,
automatically satisfied by any existing data, no backfill possible or
needed):

```sql
ALTER TABLE student_enrollments
  ADD CONSTRAINT student_enrollments_id_school_student_year_unique
  UNIQUE (id, school_id, student_id, academic_year_id);

ALTER TABLE student_subject_enrollments
  ADD CONSTRAINT student_subject_enrollments_placement_anchor_fk
  FOREIGN KEY (student_enrollment_id, school_id, student_id, academic_year_id)
  REFERENCES student_enrollments (id, school_id, student_id, academic_year_id);
```

**Wrong `StudentEnrollment` id rejected by DB: YES.** **Wrong Student:
YES** (same mechanism — the referenced tuple must match
`student_subject_enrollments.student_id`). **Wrong AcademicYear: YES**
(same mechanism). Unlike §11A's problem, this FK needs **no trigger**:
`student_enrollments` rows are historically immutable — once written,
their `student_id`/`academic_year_id` never change (the same
write-once property `student_enrollments`' own migration docblock
already relies on for its internal consistency, §11A) — so there is no
"live value could drift after the fact" risk here, only a static
referential fact an ordinary composite FK proves once and for all.

**NULL behavior**: for legacy rows where `student_enrollment_id IS
NULL`, this FK (like any multi-column FK under `MATCH SIMPLE`) is
skipped — correct and intended, since legacy rows have no known anchor
(§ below) and, per §18A, can never carry a non-null `elective_group_id`
either, so nothing meaningful is left unprotected.

### Boundary: what is DB-enforced vs. what remains service-enforced

- **Parent-identity integrity** (does `student_enrollment_id` really
  belong to this Student and this AcademicYear): **DB-enforced** — the
  new composite FK above.
- **Offering-placement compatibility** (does the chosen `SubjectOffering`'s
  Campus/GradeLevel match the Student's *current* placement):
  **existing, unmodified application-service check** (`assertCompatible()`),
  matching the exact "single sanctioned write path, guaranteed by
  construction" precedent `student_enrollments`' own migration already
  established for itself. This is safe to leave service-enforced
  because — unlike the `elective_group_id` snapshot problem — nothing
  ever mutates an existing `StudentEnrollment` row's `campus_id`/
  `grade_level_id` after creation (same write-once property), so there
  is no staleness class of risk to close with a trigger here. **No
  Campus/GradeLevel denormalization is added to `StudentSubjectEnrollment`**
  merely for this checkpoint — DB integrity does not require it.

## 12. Persistence options — original 1F.0 comparison (superseded by §11A's four-strategy table for the snapshot-integrity question specifically)

Option 2 (snapshot + DB partial unique) remains chosen for the
*exclusivity* invariant (§4/§10); §11A's Strategy B is the refinement
that makes the *snapshot-equality* sub-problem fully DB-enforced too.

## 13. Historical correctness and configuration immutability (unchanged conclusion, now DB-reinforced)

Group assignment is immutable once any participation exists (§16A
proves this race-safe). The snapshot's value is: (1) enabling the
partial unique index without a join, (2) direct historical query
context, (3) **now DB-proven correct at write time by the trigger**
(§11A) rather than merely "should be correct by convention."

## 14. Enrollment lifecycle interaction (unchanged, DECIDED)

`active` counts; `withdrawn`/`cancelled`/`transferred` release the
slot.

## 15. Transfer semantics (unchanged conclusions; ordering in §16A)

Same-group: atomic replacement. Different-group: guarded by the index.
Grouped→ungrouped/ungrouped→grouped/ungrouped→ungrouped: unchanged.

## 16. Offering deactivation (unchanged, DECIDED)

Untouched by this checkpoint.

## 16A. Configuration-vs-enrollment race (unchanged from 1F.0A, DECIDED)

Every transaction that enrolls into, transfers into, or
assigns/removes/changes the `ElectiveGroup` of a `SubjectOffering`
locks that Offering row (`SELECT ... FOR UPDATE`) first. Exactly two
coherent outcomes are possible (config-first: enrollment snapshots the
new value correctly; enroll-first: configuration is rejected by the
immutability check, evaluated inside the same lock) — a forbidden
mismatched state is unreachable. `StudentEnrollment` locking remains
**not added, not needed** — the partial unique index alone protects
the cross-offering, same-group race; the offering lock protects only a
single offering's own configuration consistency; these are two
different races, each fully covered by its own mechanism (§ division
of responsibility, unchanged from 1F.0A). **§11A's trigger adds a
third, independent layer**: even if a future write path somehow
bypassed correct locking discipline, the trigger still rejects any
row whose snapshot doesn't match the Offering's value *at the instant
of that specific INSERT* — it cannot fix a genuine ordering race by
itself (that remains §16A's job), but it eliminates the *silent data
corruption* failure mode entirely: a race that isn't properly
serialized now surfaces as a rejected transaction (safe failure), never
as an undetected inconsistent row.

Exact `enroll()`/`transfer()` orderings: unchanged from 1F.0A (lock
target Offering → validate → derive snapshot values from the locked
row → `createRow()`), now additionally validated *by the trigger* at
the final `INSERT`, not merely trusted.

## 17. Group deletion / lifecycle (unchanged, DECIDED)

No `status` column, no delete endpoint in this checkpoint's scope.
Restrict-on-delete FKs provide all necessary protection.

## 18. Configuration safety with existing data (unchanged, DECIDED)

Unchanged — immutability + partial unique index compose to make an
invalid state unreachable.

## 18A. Initial adoption policy (unchanged from 1F.0A, DECIDED)

Grouping available only for `SubjectOffering`s with zero participation
at configuration time. No backfill workflow. Existing offerings remain
ungrouped; existing participation remains valid; historical offerings
with participation cannot subsequently be grouped through normal Phase
1F configuration.

## 18B. Legacy `student_enrollment_id` (NEW — resolves §16-18 of this gate's brief)

**Existing rows backfilled: NO.** For pre-1F.1 `StudentSubjectEnrollment`
rows, `student_enrollment_id` remains **NULL**, matching
`elective_group_id`'s own established no-backfill policy (§18A).

**Why deterministic backfill is not possible**: attribution would
require determining *which specific* `StudentEnrollment` was current
for a Student at the moment an old `StudentSubjectEnrollment` row's
`starts_on` occurred. Since a Student can legitimately have multiple
sequential `StudentEnrollment` rows for the same `academic_year_id`
(§3's withdrawn-then-re-enrolled scenario), this attribution can be
genuinely ambiguous for historical data with no reliable disambiguator
— per this gate's own explicit instruction, **no heuristic
name/date-based inference is performed.** `student_enrollment_id`
simply remains NULL for every row that predates this migration.

**Consequence, confirmed harmless**: legacy rows also always have
`elective_group_id IS NULL` (§18A — no backfill there either), so they
never participate in the new exclusivity index (`WHERE elective_group_id
IS NOT NULL`) regardless of their `student_enrollment_id` value. The
new CHECK constraint (§10) is trivially satisfied by `NULL`/`NULL`.

**New-row policy (service-level, not a DB constraint)**: going forward,
`StudentSubjectEnrollmentService::createRow()` **always** populates
`student_enrollment_id` from the resolved `StudentEnrollment` —
including for **ungrouped** electives — because the value is already
in hand from `assertCompatible()`'s own query, at zero extra cost, and
improves future lifecycle correctness/queryability (this gate's own
rationale, accepted). **Global database `NOT NULL`: NO** — cannot be
added while legacy rows must remain NULL; the CHECK constraint (§10)
is the closest DB-level backstop available, applying only to grouped
rows.

## 19. Roster read model (unchanged, DECIDED)

No change.

## 20. Communications boundary (unchanged, DECIDED)

No direct impact.

## 21. Rollover boundary (unchanged, DECIDED — deferred)

Unchanged.

## 22. UI/API deferred decision (unchanged, DECIDED)

Unchanged.

## 23. Authorization (unchanged, DECIDED)

Reuse `academics.subjects.view`/`.manage`.

## 24. Audit (unchanged, DECIDED, prospective)

Unchanged.

## 25. Failure outcome (unchanged, DECIDED)

`ElectiveGroupConflictException` for the exclusivity conflict. The
trigger's `RAISE EXCEPTION` (§11A) surfaces as a raw PostgreSQL error
if ever reached directly — this is intentional (a trigger firing at
all means the application-layer service check that should have
prevented it already failed; this is a last-resort integrity backstop,
not a user-facing error path, exactly like `assert_membership_role_assignment_scope()`'s
own trigger is never expected to fire against well-behaved application
code).

## 26. Proposed implementation slices — reordered (revised, resolves §25 of this gate's brief)

**New order**: schema-and-enforcement-together, before staff-facing
configuration — closing the brief's own concern that the prior order
("configuration service ships before enrollment knows how to respect
it") could create an interval where configuring a group has no
enforced effect.

1. **Phase 1F.1 — Elective Group Domain & Schema Foundation.**
   `elective_groups` migration (no `status`); `ElectiveGroup` model;
   `subject_offerings.elective_group_id` + CHECK + context FK (§8);
   `student_enrollments`' new widened unique key (§11B);
   `student_subject_enrollments` gains `student_enrollment_id` +
   `elective_group_id` + the new partial unique index (§4) + the new
   anchor FK (§11B) + the new CHECK (§10) + the new trigger (§11A);
   `ElectiveGroupConflictException`. No service/controller/route
   changes.
2. **Phase 1F.2 — StudentSubjectEnrollment Mutual-Exclusivity
   Enforcement** *(renumbered ahead of configuration)*.
   `enroll()`/`transfer()` updated to the exact §16A ordering (lock
   target Offering, derive both snapshot columns from the locked row,
   `createRow()`); `translateUniqueViolation()` maps the new index to
   `ElectiveGroupConflictException`. Test fixtures set
   `elective_group_id` directly on factory-created `SubjectOffering`
   rows (no configuration service needed yet to exercise this slice).
   Mandatory tests (§27).
3. **Phase 1F.3 — Elective Group Configuration Service**
   *(renumbered after enforcement)*. AcademicStructure-owned service:
   create group, assign/remove Offering membership — locks the target
   `SubjectOffering` and checks participation existence inside that
   lock (§16A), enforcing §9's required-offering rule and §13's
   immutability rule. No API/UI.
4. *(Deferred, unnumbered)* — Administrative API/UI.

**Why this order is coherent**: `elective_group_id` starts `NULL` for
every Offering until explicitly set (even via `tinker`/a seeder during
initial rollout, §22) — so 1F.2's enforcement logic and tests need no
real configuration service to exist yet. Shipping enforcement before
configuration means there is never a window where a staff-configured
group silently has no enforced effect; shipping configuration last
means the moment staff can create a group, exclusivity is already live.

## 27. Mandatory future test plan (acceptance criteria, updated with direct-SQL requirements)

- **Direct-SQL NULL-bypass test (NEW, required)**: attempt, via raw SQL
  (not through the service), exactly the empirically-reproduced bypass
  row from §0B/§11A — a `student_subject_enrollments` INSERT with
  `elective_group_id = NULL` against an Offering whose real
  `elective_group_id` is non-null. **Expected: rejected** by the
  trigger (this is the corrected behavior; under the un-hardened
  `9612ca2` design this same test would have **succeeded**, which is
  exactly the defect this pass closes).
- **Direct-SQL wrong-group test (NEW, required)**: raw SQL INSERT with
  a `elective_group_id` that doesn't match the target Offering's actual
  group. **Expected: rejected** by the trigger.
- **Direct-SQL wrong-`StudentEnrollment` test (NEW, required)**: raw
  SQL INSERT using another Student's `StudentEnrollment` id (§11B's
  counterexample), same School otherwise valid. **Expected: rejected**
  by the new anchor FK. Also test a wrong-`AcademicYear` variant if
  independently constructible.
- **Same-group concurrent enroll**: exactly one succeeds.
- **Different-group concurrent enroll**: both succeed.
- **Configuration-vs-enroll race**: real two-process test, one of
  §16A's two coherent outcomes only, never a mismatched snapshot.
- **Group removal/change race**: same shape, applied to
  removing/changing an existing unused group assignment.
- **Same-group transfer**: atomic replacement, no false conflict.
- **Conflicting target group on transfer**: blocked, whole transaction
  rolled back.
- **Configuration conflict (sequential)**: reassignment after
  participation exists is rejected.
- **Cross-School**: all new FKs (including the trigger's implicit
  same-School join via `school_id`, and the new anchor FK) reject
  cross-School references, proven under `pgsql_admin` bypassing RLS's
  own WITH CHECK.
- **RLS**: `elective_groups` enabled AND forced, no-context fails
  closed, School A/B isolation.

**None of these are satisfied by testing only through the service** —
per this gate's explicit instruction, the three new direct-SQL tests
specifically bypass `StudentSubjectEnrollmentService` to prove the
database itself, not just well-behaved application code, rejects the
forbidden states.

## 28. Database/tenancy checklist (updated)

New tenant table (`elective_groups`): YES, no `status`. UUIDv7: yes.
RLS: enabled and forced. Same-School FKs: yes, including both widened
composite keys (§8's offering/group context FK, §11B's placement
anchor FK) and the new function+trigger pair (§11A) — the trigger's own
`SELECT` is implicitly School-scoped via `WHERE ... AND school_id =
NEW.school_id`, so it cannot be used to leak or validate against
another School's Offering.

## 29. External module impact (unchanged)

Unchanged from Phase 1F.0 §29.

## 30. Findings

- **P0**: none remaining. The NULL-bypass defect (a real, empirically-
  reproduced database-invariant hole) was found and closed **before**
  any schema was implemented — nothing shipped with it.
- **P1**: none.
- **P2**: none.
- **P3**: §5A's pre-existing same-offering-index observation — tracked,
  out of scope.
- **P4**: deferred rollover mapping (§21), deferred UI/API, the
  narrower initial-adoption limitation (§18A), and the fact that legacy
  `StudentSubjectEnrollment` rows will permanently carry `NULL`
  `student_enrollment_id` (§18B) — all explicitly accepted trade-offs,
  not defects.

Known test-infrastructure backlog (Flutter SDK verification, etc.) is
carried separately per `apps/mobile/README.md`, unrelated to this
checkpoint.
