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
checkpoint's base commit (`5f72f84`, itself `origin/main` `ed87ca8` +
the Phase 1E.0 doc) — no SIS-vocabulary import from outside this
repository (CLAUDE.md rule 2). Phase 1C's accepted semantics (brief
section 6) are treated as fixed unless evidence forces a change; none
does.

## 1. The exact deferral this checkpoint resolves

`docs/students/PHASE-1C-STUDENT-SUBJECT-ENROLLMENT-FOUNDATION.md`
§27 ("Deferred work"):

> Elective-group mutual exclusivity (e.g. "French OR Spanish, never
> both") — no such grouping concept exists in `Subject`/`SubjectOffering`
> yet; `student_subject_enrollments_one_active_per_offering` only
> prevents a duplicate active row in the SAME offering, not membership
> across two related offerings. A future checkpoint introducing an
> elective-group concept on `Subject`/`SubjectOffering` would need to
> add its own exclusivity enforcement.

The migration that creates `student_subject_enrollments`
(`2026_08_24_100000_create_student_subject_enrollments_table.php`)
repeats this verbatim in its own docblock. This is the single, precise,
repository-sourced statement of the gap — everything below designs the
"future checkpoint" it names.

## 2. Evidence: current `SubjectOffering`

`apps/platform/database/migrations/2026_08_23_091000_create_subject_offerings_table.php`
+ `app/Domain/AcademicStructure/Infrastructure/SubjectOffering.php`:

- Fields: `id`, `school_id`, `academic_year_id`, `campus_id`,
  `grade_level_id`, `subject_id`, `is_required` (bool, default `true`),
  `sequence`, `weekly_periods_target`, `status` (`active`/`inactive`,
  app-enforced, no DB CHECK), timestamps.
- **`unique(school_id, academic_year_id, campus_id, grade_level_id,
  subject_id)`** — a Subject has **at most one Offering** per
  (Year, Campus, Grade). This is important: within one academic
  context, "Subject" and "Offering" are 1:1, so an elective-group
  concept keyed on either is equivalent within that context (§18 of
  the brief, resolved). Multiple Offerings of the same Subject can
  only exist across *different* Years/Campuses/Grades — different
  elective contexts entirely, never candidates for the same group.
- **No existing grouping concept**: no `group`, `category`, `choice`,
  or curriculum-bucket column anywhere on this table. `is_required` is
  the only per-Student-relevant flag, confirmed unchanged since Phase
  0D/1C.
- `Subject.subject_type` (`core`/`elective`/`co_scholastic`/`language`/
  `other`, on `subjects`, School-wide, not context-scoped) is a coarse
  taxonomy tag, **not** an exclusivity mechanism — reusing it would
  incorrectly bundle e.g. *every* language Subject a School ever offers
  into one giant mutually-exclusive set, with no AcademicYear/Campus/
  GradeLevel scoping at all. Ruled out (brief §17's Option C, effectively).

## 3. Evidence: current `StudentSubjectEnrollment`

`apps/platform/database/migrations/2026_08_24_100000_create_student_subject_enrollments_table.php`
+ `app/Domain/Students/Infrastructure/StudentSubjectEnrollment.php` +
`app/Domain/Students/Application/StudentSubjectEnrollmentService.php`:

- Fields: `id`, `school_id`, `student_id`, `subject_offering_id`,
  `academic_year_id` (denormalized off `subject_offering_id` — see
  §4), `status` (`active`/`withdrawn`/`cancelled`/`transferred`, app-
  enforced), `starts_on`, `ends_on`, timestamps.
- **Current uniqueness**: `student_subject_enrollments_one_active_per_offering`,
  a **partial unique index** — `(student_id, subject_offering_id) WHERE
  status = 'active'`. This is the codebase's own established pattern
  for every "at most one active X" invariant in this domain
  (`student_enrollments_one_active_per_student_year`,
  `academic_years_one_active_per_school`) — never a lock-only strategy.
- **Transactions/locking, verified by reading the service directly**:
  - `enroll()`: **locks nothing**. It relies entirely on the partial
    unique index + `UniqueConstraintViolationException` translation
    (`ActiveSubjectEnrollmentConflictException`) for its only race-
    safety guarantee (the same-offering duplicate). No `StudentEnrollment`
    lock, no `StudentSubjectEnrollment` lock — there is nothing to lock
    yet for a first-time enroll.
  - `transfer()`: locks the **source `StudentSubjectEnrollment` row**
    (`lockForUpdate()`), not the parent `StudentEnrollment`. Inside one
    transaction: reload+lock source, verify still `active`, mark it
    `transferred` (`ends_on` = effective date − 1 day), then insert the
    target row via the same `createRow()` `enroll()` uses. Both the
    UPDATE and the INSERT are unconditionally covered by the same
    partial unique index.
  - `withdraw()`/`cancel()`: lock the target row only, conditional
    `WHERE status = 'active'` UPDATE, affected-row-count check — same
    "reload, lock, conditionally transition, detect a lost race"
    pattern as `StudentEnrollmentService`.
- **Confirmed sole writer**: `StudentSubjectEnrollmentService` is the
  only writer to `student_subject_enrollments` (grepped the full
  `app/Domain` tree — the only other reference is
  `Communications\Application\Audience\SubjectOfferingAudienceResolver`,
  which is read-only, resolving a roster via
  `SubjectOfferingRosterReadService`, never writing).

**This directly answers the brief's §57 challenge** ("does the current
service transaction actually permit [locking `StudentEnrollment`]?"):
**no** — `enroll()` locks nothing today, and `transfer()` locks the
wrong row (the child, not the parent). Adopting a locking-only strategy
would require *adding* new lock acquisition to `enroll()` that does not
exist today, with no database backstop if a future write path (a bulk
import job, say) ever bypasses that lock discipline. See §11 for why
this checkpoint does not choose that path.

## 4. The exact business rule (DECIDED)

> A `StudentEnrollment` may have **at most one current (`active`)
> `StudentSubjectEnrollment`** whose target `SubjectOffering` belongs to
> the same `ElectiveGroup`.

Scope key:
- **Per `StudentEnrollment`, not per durable `Student`.** Exclusivity
  is evaluated against the Student's one current active
  `StudentEnrollment` (exactly like `assertCompatible()` already scopes
  every other elective check today) — never across the Student's whole
  multi-year history. A Year-A "French" choice must never block a
  Year-B "Spanish" choice; different `StudentEnrollment` rows, and
  (§14) different `ElectiveGroup` rows, since groups are themselves
  AcademicYear-scoped.
- **AcademicYear**: implicit — an `ElectiveGroup` belongs to exactly
  one `AcademicYear` (§14), so cross-year collision is structurally
  impossible without extra scoping logic (§49, resolved by construction).
- **Campus/GradeLevel**: implicit — same reasoning; a group's members
  all share one Campus and one GradeLevel (§14, §37 — DB-enforced, not
  just conventional).
- **Group**: explicit — a new `ElectiveGroup` aggregate (§6-§8). A
  Student may hold **one active selection per independent group**
  simultaneously — a Language group and an Arts group are unrelated;
  choosing French does not block choosing Music (brief §11's "one
  elective total" misreading explicitly rejected — no repository
  evidence supports it, and real schools plainly need independent
  groups).

## 5. Duplicate-same-offering vs. group-conflict — kept distinct (DECIDED)

Two separate invariants, never conflated:
1. **Existing**: a `StudentEnrollment` cannot have two active rows in
   the identical `SubjectOffering` — unchanged,
   `student_subject_enrollments_one_active_per_offering`.
2. **New**: a `StudentEnrollment` cannot have two active rows whose
   offerings belong to the same `ElectiveGroup` — a new, additional
   partial unique index (§9), never a rewrite of invariant 1.

## 6. Group ownership (DECIDED)

**AcademicStructure owns `ElectiveGroup`.** Reasoning, evaluated against
the brief's three candidates:

- **(A) AcademicStructure** — chosen. `ElectiveGroup` defines *which
  offerings the curriculum treats as mutually exclusive choices* — a
  fact about the curriculum's structure, identical in kind to
  `SubjectOffering` itself (also AcademicStructure-owned) declaring
  "this Subject is offered to this Grade this Year." `docs/architecture/DOMAIN-MAP.md`
  confirms AcademicStructure "has almost no dependencies, deliberately"
  and is "reference data most Layer 2-3 modules depend on" — adding
  `ElectiveGroup` there is a natural, same-shape extension, not new
  coupling.
- **(B) Students owns the selection rule** — **adopted, but only for
  *enforcement*, not for the group concept itself.** `StudentSubjectEnrollmentService`
  (Students-owned) is where the actual conflict check/exception lives,
  exactly like it already owns `assertCompatible()` even though the
  compatibility facts (AcademicYear/Campus/GradeLevel) come from
  AcademicStructure entities. Students should not own the curriculum
  structure merely because it enforces a Student-facing constraint
  (brief §13's own caution) — it already doesn't own `SubjectOffering`,
  `Subject`, or `is_required` either, and reads all three today.
- **(C) SubjectOffering owns a lightweight group identifier alone (no
  aggregate)** — rejected as the *sole* mechanism, but its column
  (`elective_group_id`) is exactly what §8 uses; the missing piece is
  the group *entity itself* (name, code, and — critically — the shared
  academic-context columns §14/§37 need for DB-enforced integrity),
  which a bare FK-with-no-target can't provide.

This matches `StudentSubjectEnrollment` §7's own precedent almost
exactly ("why this model is Academic-owned, not Communications-owned")
— the same "define vs. enforce" split, one layer up.

## 7. Terminology (DECIDED)

**`ElectiveGroup`.** Reasons:
- Already the exact phrase this repository uses for this exact concept
  — `PHASE-1C-STUDENT-SUBJECT-ENROLLMENT-FOUNDATION.md` §27 says
  "elective-group concept" and "elective-group mutual exclusivity"
  twice. Reusing established repo vocabulary beats inventing new terms.
- `SubjectGroup` rejected — ambiguous with a possible future
  departmental/curriculum-category concept (`Subject.subject_type`
  already exists as a *different*, non-exclusivity classification;
  `SubjectGroup` reads as a synonym for that, inviting confusion).
- `ElectiveChoiceGroup` rejected — no more precise than `ElectiveGroup`,
  just longer; no existing naming precedent in this codebase favors the
  extra word (compare `AcademicDepartment`, `GradeLevel`, `SubjectOffering`
  — concise two/three-word names throughout).

## 8. Group scope and persistence (DECIDED)

**New table: `elective_groups`** (AcademicStructure-owned, same shape
as every other reference entity in that domain — `GradeLevel`,
`AcademicDepartment`, `Subject`):

```
id                UUID (UUIDv7, ADR 0019)
school_id         UUID  -> schools, cascade
academic_year_id  UUID  -> academic_years, restrict
campus_id         UUID  -> campuses, restrict
grade_level_id    UUID  -> grade_levels, restrict
name              string
code              string  (NormalizesCode, unique within School+Year+Campus+Grade)
status            string, default 'active'  ('active'/'inactive', same
                  "deactivate, never delete" convention as every other
                  Academic Structure reference entity)
timestamps
```

**Membership: a single nullable FK on `SubjectOffering`,
`elective_group_id`** — not a pivot/junction table. Reasoning (brief
§17, Option B vs. Option A/D):

- An Offering participates in **at most one** exclusivity set — there
  is no evidence (and no plausible product need) for an Offering
  belonging to two independent `ElectiveGroup`s simultaneously (that
  would mean choosing it excludes members of *two* unrelated groups at
  once, which is not what "French OR Spanish" style exclusivity means).
  A many-to-many pivot would be speculative complexity for a
  relationship that is structurally 1:0..1 (CLAUDE.md rule 2).
- A nullable FK is the smallest correct model, mirrors how
  `academic_department_id` already works on `Subject` (nullable,
  optional grouping), and requires no new join table, index, or pivot
  service.

**Academic-context integrity — DB-enforced, not just conventional
(resolves brief §14/§37 explicitly):**

`elective_groups` gets a **widened composite unique key**,
`(id, school_id, academic_year_id, campus_id, grade_level_id)`, in
addition to the standard `(id, school_id)` every tenant-owned table
already carries. `subject_offerings.elective_group_id` then uses a
**composite foreign key against that widened key**:

```sql
ALTER TABLE subject_offerings
  ADD CONSTRAINT subject_offerings_elective_group_context_fk
  FOREIGN KEY (elective_group_id, school_id, academic_year_id, campus_id, grade_level_id)
  REFERENCES elective_groups (id, school_id, academic_year_id, campus_id, grade_level_id);
```

This makes it **structurally impossible** for a `SubjectOffering` to
reference an `ElectiveGroup` from a different Year/Campus/Grade —
PostgreSQL rejects the INSERT/UPDATE outright. No service-layer check
is the sole line of defense (unlike the general Academic Structure
cross-entity write pattern `docs/modules/STUDENT-ENROLLMENT.md` §"Deferred
lifecycle status decision" describes as *deliberately* unenforced
elsewhere — here it *can* be DB-enforced cheaply, using the exact same
composite-FK-against-parent's-own-context mechanism CLAUDE.md rule 70
already mandates for Section/SubjectOffering/etc., so it is).

**Fields deliberately NOT added**: `description`, `min_choices`,
`max_choices`, `credits`, `priority`, `ranking`, `capacity` — none has
repository evidence; this checkpoint is scoped to "choose at most one"
only (brief §15/§16).

## 9. Required/elective rule (DECIDED)

- A **required** (`is_required = true`) `SubjectOffering` may **never**
  have `elective_group_id` set. Enforced two ways:
  - **Database CHECK** (same-row, so PostgreSQL can enforce it
    directly — no cross-table limitation here):
    `CHECK (elective_group_id IS NULL OR is_required = false)` on
    `subject_offerings`.
  - Service-layer validation in the future group-configuration service
    (defense in depth, matching every other validated-then-DB-enforced
    invariant in this codebase).
- **Ungrouped electives remain allowed** — `elective_group_id` is
  nullable; an elective Offering with no group is simply never subject
  to the new exclusivity check (its `StudentSubjectEnrollment.elective_group_id`
  snapshot is `NULL`, excluded by the partial index's `WHERE` clause,
  §10). Not every elective must belong to a group (brief §12,
  confirmed — no evidence requires universal grouping).

## 10. Persistence & race-safety: chosen strategy (DECIDED)

**`StudentSubjectEnrollment` gains a snapshotted `elective_group_id`
column**, derived server-side from the target Offering at write time
(identical pattern to how `academic_year_id` is already denormalized
on this exact table — the migration's own docblock explains why:
*"a partial unique index cannot reference a joined table's column"* —
this checkpoint needs the identical property one column further):

```
elective_group_id  UUID NULL  -> elective_groups (id, school_id), restrict
```

**New partial unique index**:

```sql
CREATE UNIQUE INDEX student_subject_enrollments_one_active_per_elective_group
  ON student_subject_enrollments (student_id, elective_group_id)
  WHERE status = 'active' AND elective_group_id IS NOT NULL
```

**Database unique constraint: YES. Transactional lock: NO new lock
required.** This is the core finding of this checkpoint, reached by
directly evaluating the brief's suggested "lock `StudentEnrollment`"
candidate (§57) against the actual current code (§3) and rejecting it:

- The locking-only strategy requires *adding* new lock acquisition
  code to `enroll()` (which locks nothing today) and *changing* what
  `transfer()` locks (currently the child row, not the parent) —
  real, non-trivial service changes with **zero database backstop**:
  any future write path that doesn't participate in the lock
  (a bulk-import job, an admin console fix-up script, a bug) can still
  violate the invariant.
- The DB-constraint strategy needs **no new lock acquisition at all** —
  `enroll()`'s existing shape (assert compatibility → `DB::transaction`
  → `UniqueConstraintViolationException` → translate) already handles
  it, because it is the *exact same mechanism* `enroll()` already uses
  today for the same-offering case. Two concurrent `enroll()` calls
  targeting different Offerings in the same group simply both attempt
  the INSERT; PostgreSQL's own index guarantees exactly one commits.
  This is also the pattern this codebase uses for *every* comparable
  "at most one active X" invariant (`student_enrollments_one_active_per_student_year`,
  `academic_years_one_active_per_school`) — never locking-only.

**Concurrent same-group enroll**: both transactions attempt
`INSERT ... elective_group_id = X`; PostgreSQL serializes on the index,
exactly one INSERT commits, the loser's `UniqueConstraintViolationException`
is translated to `ElectiveGroupConflictException` (§ below). **Outcome:
exactly one succeeds** (brief §64's mandatory test target).

**Concurrent different-group enroll**: two different `elective_group_id`
values → two independent index entries → **both succeed** (brief §64's
second mandatory test target).

**Why race-safe, stated plainly**: PostgreSQL unique indexes are
enforced atomically at commit (via `ON CONFLICT`-free plain INSERT,
the violation surfaces as a constraint error) regardless of statement
ordering or isolation level — this is the same guarantee
`academic_years_one_active_per_school` already relies on, proven in
production-shape code by `AcademicYearActivationConcurrencyTest`. No
`SERIALIZABLE` isolation, no advisory lock, and no new row-lock
discipline is needed.

## 11. Join-limitation problem, addressed explicitly (per brief §21)

PostgreSQL cannot enforce "one active `StudentSubjectEnrollment` per
group" with a plain unique index when group membership lives only on a
*joined* `SubjectOffering` row — a partial unique index's `WHERE`/key
columns must come from the indexed table itself. This is exactly why
`elective_group_id` is **snapshotted onto `StudentSubjectEnrollment`
itself** (§10) rather than left to live only on `SubjectOffering`
(brief §22, chosen) — the index then has everything it needs on one
row, with no join required at enforcement time. The service still
*derives* the value from the live Offering server-side at write time
(never accepted as independent caller input, matching every other
derived field on this table) — the duplication is deliberate and
principled, not an oversight, following this table's own existing
`academic_year_id` precedent to the letter.

## 12. Persistence options — comparison table (per brief §56)

| | **1. Nullable group FK on Offering + `StudentEnrollment` locking** | **2. Group FK snapshot on `StudentSubjectEnrollment` + DB partial unique (CHOSEN)** | **3. Separate current-selection table** | **4. Reuse `subject_type` / no new aggregate** |
|---|---|---|---|---|
| Domain clarity | OK | Strong — matches `academic_year_id`'s own precedent exactly | OK, but duplicates lifecycle `StudentSubjectEnrollment` already owns | Poor — `subject_type` isn't context-scoped, wrong granularity (§2) |
| Historical correctness | Weak — no snapshot, group meaning can drift under the covers | Strong — snapshot is immutable once written | Strong, but two lifecycle sources of truth to keep consistent | N/A |
| DB enforceability | None (lock-only) | Full (partial unique index) | Full, but needs its own new index + service | None |
| Race safety | Depends entirely on every caller obeying lock discipline (§3: not even true today) | DB-guaranteed regardless of caller discipline | DB-guaranteed, but adds a second write path to keep atomic with the first | Unsafe / not applicable |
| Migration complexity | Low (one nullable FK) | Low (two nullable FK columns + one index + one CHECK) | Higher (new table, new service, new tests, coordinate two tables) | None, but doesn't solve the problem |
| Transfer complexity | Must remember to lock parent on every write path, forever | None — `transfer()`'s existing `createRow()` call is already covered | New: two aggregates must transition together | N/A |
| Future rollover compatibility | Same as chosen (year-scoped either way) | Same as chosen | Extra aggregate to also roll over | N/A |
| Query cost | Low | Low (one extra indexed column) | Low, but an extra table to query for "current selection" | N/A |
| Duplication | None | One denormalized column, same class as `academic_year_id` already accepted | Full lifecycle duplication vs. `StudentSubjectEnrollment` | N/A |

**Chosen: Option 2.** It is the smallest correct model that is also
the one already-established idiom in this exact table for this exact
class of problem (CLAUDE.md rule 2's "no speculative infrastructure"
cuts *against* Options 1 and 3 here, not for them — Option 1 needs new,
undemonstrated lock discipline; Option 3 duplicates an aggregate that
already exists).

## 13. Historical correctness (DECIDED)

**Can offering group membership change after participation: only
before any participation exists.** Policy: **`SubjectOffering.elective_group_id`
becomes immutable once any `StudentSubjectEnrollment` row (active *or*
historical) references that Offering.** The future group-configuration
service enforces this with an `exists()` check inside a transaction
(locking the `SubjectOffering` row) before permitting a group
reassignment — matching the brief's own suggested policy (§35/§54)
and avoiding the entire class of "reference data changed after use"
retroactive-conflict problems other reference entities in this
codebase already sidestep the same way (`GradeLevel`/`Section`/etc.
never mutate in a way that changes historical meaning; see
`docs/modules/ACADEMIC-STRUCTURE.md` "Reference-data lifecycle").

**Historical `StudentSubjectEnrollment` meaning is preserved by**: the
`elective_group_id` snapshot itself (§10/§11) — even in the
(now-prevented) hypothetical of a later reassignment, an already-written
historical row's snapshot never changes, so its recorded conflict
context stays permanently intelligible, identical in spirit to how
`academic_year_id`'s snapshot already protects this table's history.

## 14. Enrollment lifecycle interaction (DECIDED)

Which `StudentSubjectEnrollment` states count toward exclusivity —
determined directly from the partial index's own `WHERE status =
'active'` clause, no separate rule needed:

| State | Counts toward group occupancy? |
|---|---|
| `active` | **Yes** |
| `withdrawn` | **No — slot released.** No history deleted; the row remains, just no longer indexed as occupying the group. |
| `cancelled` | **No — slot released.** Same mechanism. |
| `transferred` | **No — slot released**, and specifically by the *same transaction* that creates the replacement row (§15). |

## 15. Transfer semantics (DECIDED)

- **Same-group transfer** (French → Spanish, both Group X): **valid,
  atomic replacement, never a conflict.** `transfer()` already marks
  the source `transferred` (releasing the slot) *before* inserting the
  target row, inside one transaction (§3) — by the time the INSERT's
  constraint is checked, the source's row is no longer `active`, so
  there is no self-conflict. No code change to this ordering is needed;
  only `createRow()` gains the `elective_group_id` derivation.
- **Different-group transfer** (target belongs to Group Y): the same
  insert-time unique-index check that guards `enroll()` guards this
  too — if the Student already holds an active Group-Y selection
  elsewhere, the transaction fails with `ElectiveGroupConflictException`
  and rolls back entirely (the source row's `transferred` mark is
  undone too — correct, since the whole switch didn't happen). The old
  Group-X slot is released exactly as in the same-group case.
- **Grouped → ungrouped**: target `elective_group_id` is `NULL`; the
  partial index's `WHERE elective_group_id IS NOT NULL` clause excludes
  it — no conflict possible, source's group slot still released as
  normal.
- **Ungrouped → grouped**: target conflict checked normally via the
  index, same as any other grouped insert.
- **Ungrouped → ungrouped**: current behavior, completely unchanged.

## 16. Offering deactivation — unchanged (DECIDED)

Phase 1C.1A's rule (`assertOfferingIsActive()`, gates only *new*
participation, never blocks withdraw/cancel/transfer *out* of an
inactive Offering) is untouched. Group state never substitutes for or
alters Offering-active semantics — the two are orthogonal checks, both
evaluated independently in `enroll()`/`transfer()`.

## 17. Group deletion / lifecycle (DECIDED)

- **No delete endpoint for `ElectiveGroup`** — `status` (`active`/
  `inactive`) only, matching every other Academic Structure reference
  entity (CLAUDE.md rule 73). `SubjectOffering.elective_group_id`'s
  composite FK is `restrictOnDelete` regardless (defensive-only, since
  no delete path will ever exist).
- **No cascade to `SubjectOffering` or `StudentSubjectEnrollment`** on
  group deactivation — deactivating a group only prevents *new*
  Offerings from being assigned to it (service-layer check); existing
  membership and all history are untouched, exactly like deactivating
  any other reference entity never retroactively alters children.

## 18. Configuration safety with existing data (DECIDED)

If staff attempt to place two Offerings into the same group while a
Student already holds independent active participation in each: this
is now impossible *before* the fact (§13's immutability-after-participation
rule prevents assigning a group to an Offering that already has
participation — but doesn't by itself prevent creating a *new* group
containing two Offerings *neither* of which has participation yet, then
… no further issue arises, since exclusivity is only checked at
`StudentSubjectEnrollment` write time, and by construction a Student
cannot already hold two active rows across those Offerings once they
share a group, because the same partial unique index would already
have rejected the second one). Concretely: **configuration-time
validation is unnecessary beyond §13's immutability rule** — the two
invariants (immutable-after-use, and the partial unique index) compose
to make an invalid state unreachable, without needing a separate
"reject configuration until conflicts are resolved" workflow (brief
§53's first option, shown here to be unnecessary given §13's stronger
guarantee, so the simpler policy wins per CLAUDE.md rule 2).

## 19. Roster read model — unchanged (DECIDED)

`SubjectOfferingRosterReadService` requires **no change**. Its elective
roster query (`student_subject_enrollments WHERE status = 'active' AND
subject_offering_id = ?`, re-validated against the Student's current
`StudentEnrollment`) is unaffected by *which offerings a Student is
disallowed from combining* — exclusivity is a write-time constraint on
what rows can exist, not a read-time filter on rows that do exist. If
the write-time constraint holds, every roster query already returns
correct data with zero group-awareness. No group-aware roster filtering
is added as a workaround for invalid data, because invalid data cannot
occur (§10-§18).

## 20. Communications boundary — unchanged (DECIDED)

Phase 5C/5B's `SubjectOfferingAudienceResolver` consumes
`SubjectOfferingRosterReadService` unchanged (§19) — **no direct
Communications impact.** Mutual exclusivity affects which
`StudentSubjectEnrollment` rows may be *created*, never how
Communications resolves an already-valid Offering's roster. This
boundary is identical in shape to Phase 1E.0's own "Student lifecycle
changes must never leak into Communications roster logic" conclusion.

## 21. Rollover boundary (DECIDED — deferred, mapping requirement noted)

**Not included in Phase 1F.0.** `elective_groups` is AcademicYear-scoped
exactly like `subject_offerings`/`student_subject_enrollments` already
are, so a future Subject-level rollover checkpoint (mirroring Phase
1B.7A's `EnrollmentRolloverPlan` machinery, itself already deferred for
`StudentSubjectEnrollment` per `PHASE-1C...md` §22) can extend without
a redesign — **but doing so is out of scope here.** Forward note for
that future checkpoint: it will need an explicit **source
`ElectiveGroup`/Offering → target-year `ElectiveGroup`/Offering**
mapping, the same "no automatic inference by name" principle
`docs/modules/STUDENT-ENROLLMENT.md`'s Grade/Section rollover mapping
already established — never inferred from matching `code`/`name`
strings across years.

## 22. UI/API deferred decision (DECIDED)

Phase 1C shipped `StudentSubjectEnrollmentController` (JSON API,
explicit named actions: `store`/`withdraw`/`cancel`/`transfer`, no
generic status PATCH) with **no Vue page** — the established precedent
this codebase already follows (API ahead of UI, Phase 0D's own
documented pattern). Phase 1F.0's core invariant is **complete with
backend configuration + service enforcement alone** — absence of a
group-configuration UI does not weaken the domain invariant, since the
database constraint holds regardless of how a group gets configured
(even directly via `tinker`/a seeder during initial rollout). UI/API
for group configuration itself (AcademicStructure side) and any future
group-aware selection UI (Students side) remain explicitly deferred to
a later slice (§26).

## 23. Authorization (DECIDED)

**Reuse `academics.subjects.view`/`academics.subjects.manage`** for all
`ElectiveGroup` configuration actions — verified still the exact
capability pair `SubjectOfferingController`/`StudentSubjectEnrollmentController`
use on current `main` (§3). No new capability
(`academics.elective-groups.manage`) — no privilege-boundary evidence
distinguishes "who may configure elective groups" from "who may
configure subject offerings"; they are the same administrative concern
at the same blast radius, exactly like Phase 1C reused this same pair
rather than inventing a Student-subject-specific one.

## 24. Audit (DECIDED, prospective)

Existing `student_subject_enrollment.created`/`.transferred` events
gain one new, PII-minimal metadata field when present:
`electiveGroupId`. New prospective events for the future configuration
service: `elective_group.created`, `subject_offering.elective_group_assigned`
(metadata: `subjectOfferingId`, `electiveGroupId` — old/new pair on
reassignment, which per §13 can only ever be null→value, never
value→different-value). No Student name, DOB, or Guardian data in any
of the above, matching every existing audit event in this domain.

## 25. Failure outcome (DECIDED)

New domain exception: **`ElectiveGroupConflictException`** — named
consistently with `ActiveSubjectEnrollmentConflictException`/
`IncompatibleSubjectOfferingException` already in
`app/Domain/Students/Application/Exceptions`. Carries the conflicting
`elective_group_id` and the Student's existing `StudentSubjectEnrollment`
id (safe structural context) — never Student name/DOB. HTTP status-code
mapping is a future controller/exception-handler concern, not decided
here (matches how `ActiveSubjectEnrollmentConflictException`'s own HTTP
mapping wasn't re-litigated in this doc either — it already exists in
the exception-handling infrastructure this new exception plugs into
the same way).

## 26. Proposed implementation slices

1. **Phase 1F.1 — Elective Group Domain & Schema Foundation.**
   `elective_groups` migration (AcademicStructure), `ElectiveGroup`
   model, `subject_offerings.elective_group_id` + CHECK constraint +
   composite context-FK migration, `student_subject_enrollments.elective_group_id`
   + new partial unique index migration, `ElectiveGroupConflictException`.
   No service/controller/route changes yet.
2. **Phase 1F.2 — Elective Group Configuration Service.**
   AcademicStructure-owned service: create group, assign/remove
   Offering membership (enforcing §9's required-offering rule, §13's
   immutability-after-participation rule). No API/UI.
3. **Phase 1F.3 — StudentSubjectEnrollment Mutual-Exclusivity
   Enforcement.** `StudentSubjectEnrollmentService::createRow()` derives
   and snapshots `elective_group_id`; `translateUniqueViolation()` maps
   the new index to `ElectiveGroupConflictException`. Mandatory tests
   (§27) including the two required-PostgreSQL concurrency tests.
4. *(Deferred, not committed to a number yet)* — Administrative API/UI
   for group configuration (AcademicStructure) and any future
   group-aware Student selection UI (Students) — separate later slices,
   not required for the domain invariant to be complete (§22).

## 27. Mandatory future test plan (acceptance criteria for 1F.3)

- **Same-group concurrent enroll**: two real, separate processes/
  transactions attempt two different Offerings in the same group for
  the same `StudentEnrollment` — expected: exactly one succeeds
  (mirrors `AcademicYearActivationConcurrencyTest`'s two-process
  pattern, not a sequential simulation).
- **Different-group concurrent enroll**: same shape, two independent
  groups — expected: both succeed.
- **Same-group transfer**: atomic replacement succeeds, no false
  conflict.
- **Conflicting target group on transfer**: blocked, source row's
  `transferred` mark rolled back too (whole transaction fails).
- **Configuration conflict**: attempting to reassign an Offering's
  `elective_group_id` after any `StudentSubjectEnrollment` participation
  exists is rejected (§13).
- **Cross-School**: `elective_groups`/`subject_offerings.elective_group_id`/
  `student_subject_enrollments.elective_group_id` all reject a
  cross-School reference at INSERT time (composite FKs), proven under
  `pgsql_admin` bypassing RLS's own WITH CHECK, mirroring
  `StudentSubjectEnrollmentIntegrityTest`'s existing pattern.
- **RLS**: `elective_groups` uses `TenantRls::enable()` (enabled AND
  forced), no-context fails closed, School A/B isolation — the same
  8-test shape `StudentSubjectEnrollmentIntegrityTest` already
  establishes, applied to the new table.

## 28. Database/tenancy checklist (for 1F.1)

New tenant table (`elective_groups`): **YES.** UUIDv7: yes
(`GeneratesUuidV7`). RLS: `TenantRls::enable('elective_groups')`.
FORCE: yes (part of `TenantRls::enable()`). Same-School FKs: yes,
including the widened composite key described in §8 that also
DB-enforces academic-context consistency.

## 29. External module impact (unchanged from Phase 1C's own matrix,
re-verified)

| Module | Impact |
|---|---|
| SubjectOfferingRosterReadService | NO IMPACT (§19) |
| Communications | NO IMPACT (§20) |
| Rollover | DEFERRED (§21) |
| Admissions | NO IMPACT — never touches Subject-level placement |
| Guardians / Documents | NO IMPACT — unrelated aggregates |
| Attendance / Timetable / Exams / Fees | DEFERRED — unimplemented modules, out of scope |

## 30. Findings

- **P0**: none.
- **P1**: none.
- **P2**: none.
- **P3**: none currently open — every question the brief posed resolved
  to a DECIDED answer with direct repository evidence; no ARCHITECTURE
  DECISION REQUIRED escalation needed.
- **P4**: the deferred rollover mapping requirement (§21) and deferred
  UI/API surfaces (§22/§26 item 4) — tracked, not blocking.

Known test-infrastructure backlog (Flutter SDK verification, etc.) is
carried separately per `apps/mobile/README.md`, unrelated to this
checkpoint.
