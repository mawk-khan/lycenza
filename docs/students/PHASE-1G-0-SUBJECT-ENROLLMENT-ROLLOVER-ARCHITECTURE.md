# Phase 1G.0 — Subject Enrollment Rollover Architecture

> "Phase 1G" is an informal, Students/SIS-module-local checkpoint label
> only (like 1A-1F before it) — it is **not** a formal
> `docs/roadmap/MASTER-ROADMAP.md` phase, and this checkpoint does not
> modify that document. This is an ARCHITECTURE-ONLY gate — no PHP,
> migration, test, route, Vue, OpenAPI, or capability change is made
> here. It closes the "Year-rollover integration" deferral recorded in
> `docs/students/PHASE-1C-STUDENT-SUBJECT-ENROLLMENT-FOUNDATION.md`
> §22/§27 and composes with the fully-implemented Phase 1B.7 Enrollment
> rollover machinery and the fully-implemented Phase 1F elective
> mutual-exclusivity machinery — it does not design a second rollover
> engine and does not redesign either.

## 1. What this checkpoint answers

`EnrollmentRolloverPlan`/`Mapping`/`Item` (Phase 1B.7A-1B.7F) already
roll forward a Student's **placement** (GradeLevel/Section/Roll
Number) across AcademicYears, end to end, through both the API and the
UI. It does not touch `StudentSubjectEnrollment` at all today. This
checkpoint designs the smallest coherent extension that also rolls
forward a Student's **explicit elective participation**, without
duplicating any existing machinery and without weakening any Phase
1F.1/1F.2/1F.3 invariant.

## 2. What actually rolls over (DECIDED)

**Required** `SubjectOffering`s are never copied as
`StudentSubjectEnrollment` rows — required-offering rosters are always
*derived* from the Student's current `StudentEnrollment`
(`SubjectOfferingRosterReadService`), never materialized. Once the
target `StudentEnrollment` exists (Phase 1B.7C's job, unchanged), the
target year's required rosters are automatically, immediately correct
with zero Phase 1G code — this checkpoint has nothing to do for
required subjects.

**Only explicit elective participation** (`StudentSubjectEnrollment`
rows for `is_required = false` offerings) is potential roll-forward
data. This directly mirrors how Phase 1B.7 never rolls forward
anything Grade/Section can already derive — the same "reference
existing structure, do not duplicate it" principle applied one layer
down.

## 3. Source participation eligibility (DECIDED)

**Candidate rule**: for a given `EnrollmentRolloverItem`, the eligible
source subject participations are

```sql
StudentSubjectEnrollment
  WHERE student_enrollment_id = item.source_enrollment_id
    AND status = 'active'
```

This keys off the Item's own **already-resolved, anchored**
`source_enrollment_id` — never a loose `student_id` + `academic_year_id`
re-derivation (checkpoint brief §11's explicit requirement, and exactly
why Phase 1F.2 introduced `student_enrollment_id` in the first place:
`(student_id, academic_year_id)` is not equivalent to one specific
`StudentEnrollment` when a Student was withdrawn and re-enrolled within
the same year).

`withdrawn`/`cancelled`/`transferred` source rows are excluded — none
of them represent an operationally current choice to carry forward,
mirroring the identical `active`/`completed` (Enrollment) vs.
`withdrawn`/`cancelled` exclusion rule Phase 1B.7B already established
for the placement side. (`StudentSubjectEnrollment` has no `completed`
status — `active` is the sole eligible status here.)

## 4. Legacy `student_enrollment_id = NULL` rows (DECIDED: Option B, exact proof criteria)

A legacy (pre-1F.2) `StudentSubjectEnrollment` row has
`student_enrollment_id = NULL`, so §3's anchor-keyed query **structurally
never matches it** — no special-case code is needed to exclude a legacy
row from automatic candidacy; it simply cannot satisfy `student_enrollment_id
= item.source_enrollment_id` when its own anchor is `NULL`.

That default exclusion would silently drop real historical data an
operator might reasonably expect rolled forward, so this checkpoint
adds one **narrow, structurally-provable** recovery, not a heuristic:

> A legacy row (`student_enrollment_id IS NULL`, `student_id` matching
> the Item's Student) is deterministically attributable to the Item's
> `source_enrollment_id` **if and only if exactly one
> `student_enrollments` row (of ANY status) exists for
> `(student_id, source_academic_year_id)` in total** — i.e. the Student
> was never withdrawn-and-re-enrolled within that specific source year,
> so there is no second candidate the legacy row could possibly belong
> to instead. This is a single `COUNT(*)` proof, not a guess.

If that count is exactly 1, the legacy row is treated as anchored to
`item.source_enrollment_id` for rollover purposes (its own row is never
mutated/backfilled — this is a rollover-time read-only inference, never
a retroactive `UPDATE` of the legacy row's `student_enrollment_id`,
which stays permanently `NULL` per Phase 1F.1's own accepted policy).
If the count is `> 1`, the attribution is genuinely ambiguous — the
Student had multiple `StudentEnrollment` rows for that year — and this
checkpoint's dry-run flags a `legacy_source_anchor_ambiguous` conflict
(`review`, never silently dropped, never silently guessed), matching
Phase 1B.7B's own `multiple_source_candidates` precedent exactly for
the analogous placement-side ambiguity.

## 5. Target mapping — data model (DECIDED: Option B, offering-level only)

Four options compared:

| | **A. Extend `EnrollmentRolloverMapping`** | **B. New child table, Plan-owned** | **C. Per-Item subject rows** | **D. No persistence** |
|---|---|---|---|---|
| Domain clarity | Poor — that table is keyed by source Grade/Section for PLACEMENT; a Student may have several electives per Grade, an incompatible granularity | Clean — one row per (plan, source offering) | Confusing — a mapping fact modeled as if it were per-Student data | N/A |
| Durable resume | OK | OK | OK, but duplicates the Item aggregate's own concerns | None |
| Dry-run/versioning | Would need new nullable columns on an unrelated-grain row | Participates in the SAME `configuration_version` mechanism trivially | Same, but entangled with Item lifecycle | None |
| RLS | OK | OK, same pattern as every other rollover table | OK | N/A |
| Target-context integrity | N/A | Composite FKs, mirrors existing precedent | Same, but no clear parent | N/A |
| Idempotency | N/A | Falls out of the offering table's own state (see §9) | Would need its own anchor column, duplicating §9 | None |
| UI/API configurability | Awkward (overloads an existing form) | Natural — its own small CRUD, nested under the Plan | Same as B but harder to present as "plan config" | None |
| Repository precedent | None | Mirrors `EnrollmentRolloverMapping`'s own shape closely | None | None |

**Chosen: B.** A new table, `enrollment_rollover_subject_mappings`,
owned by (and cascade-deleted with) the SAME `EnrollmentRolloverPlan` —
never a second, parallel top-level plan aggregate (checkpoint brief
§22's explicit constraint).

**Granularity: source `SubjectOffering` → target `SubjectOffering`
only** — no separate `ElectiveGroup` mapping concept is needed. This is
the direct, load-bearing consequence of how Phase 1F.2's canonical
`StudentSubjectEnrollmentService::enroll()` already works: it derives
`elective_group_id` **from whatever the target Offering's CURRENT
`elective_group_id` is** at the moment of the call — Phase 1G never
needs to read, store, or reason about `ElectiveGroup` identity at all;
it only ever needs to know which target `SubjectOffering` a source
`SubjectOffering` maps to, and the canonical service does everything
else (group snapshot derivation, the mutual-exclusivity partial unique
index, the locking protocol). This single design choice is what makes
§10 (grouped→ungrouped), §11 (ungrouped→grouped), and §9
(grouped→grouped with different group ids/codes across years) all work
with **zero special-case code** — every one of them is just "enroll
into whatever offering the mapping names," exactly the same call Phase
1F.2's own test matrix (`grouped_to_ungrouped_transfer_releases_the_source_group_slot`,
`ungrouped_to_grouped_transfer_snapshots_the_new_group`, ...) already
proves handles every combination correctly.

### Table shape (conceptual — not a migration)

```
enrollment_rollover_subject_mappings
  id                          UUID (UUIDv7)
  school_id                   UUID -> schools, cascade
  plan_id                     UUID, composite FK -> enrollment_rollover_plans(id, school_id), cascade
  source_subject_offering_id  UUID, composite FK -> subject_offerings(id, school_id), restrict
  target_subject_offering_id  UUID NULL, composite FK -> subject_offerings(id, school_id), restrict
  timestamps

  unique(id, school_id)
  unique(plan_id, source_subject_offering_id)   -- at most one mapping per source offering per plan
```

`plan_id`'s composite FK cascades exactly like `enrollment_rollover_mappings.plan_id`
already does — a mapping row has no meaning outside its plan.

**Explicit omit, not absence-as-intent (checkpoint brief §25)**: the
row's mere *existence* is the operator's explicit decision.

- **No row for a source offering** → unconfigured — blocking (§8).
- **Row exists, `target_subject_offering_id = NULL`** → the operator
  explicitly chose NOT to carry this elective forward — non-blocking,
  behaves like `excluded`.
- **Row exists, `target_subject_offering_id` set** → carry forward into
  that exact target offering.

This is the identical "presence vs. value" convention Phase 1B.7A
already established for Grade mapping (terminal Grade = no row;
repeat = `target_grade_level_id = source_grade_level_id`) — no new
boolean flag column, no new modeling idiom.

### Recommended (not implemented) schema strengthening

`enrollment_rollover_mappings`' own docblock already documents that it
cannot structurally enforce "this Section belongs to the plan's
declared AcademicYear" because `sections` has no `unique(['id',
'academic_year_id'])` today — that invariant is application-validated
by the dry-run engine instead. The identical gap exists for
`subject_offerings`. Mirroring the EXACT precedent Phase 1F.1 itself
already set (adding a narrow, trivially-satisfied
`student_enrollments_id_school_student_year_unique` purely to enable a
stronger composite FK), a future 1G.1 migration **should** add:

```sql
ALTER TABLE subject_offerings
  ADD CONSTRAINT subject_offerings_id_school_academic_year_unique
  UNIQUE (id, school_id, academic_year_id);
```

so `enrollment_rollover_subject_mappings.source_subject_offering_id`/
`target_subject_offering_id` can each carry an ADDITIONAL composite FK
also pinning `academic_year_id` against the plan's own declared
source/target year — making "wrong academic year" a database-structural
impossibility rather than merely application-validated. This is a
recommendation for 1G.1 to implement, exactly like the `enrollments.rollover.manage`
capability was a recommendation Phase 1B.7 made for 1B.7E to accept —
**no migration is added by this checkpoint.** Campus/GradeLevel
mismatches need no equivalent FK: they are already caught by the
canonical `StudentSubjectEnrollmentService::enroll()`'s own
`IncompatibleSubjectOfferingException` at execution time (and
independently pre-checked by dry-run, §8), exactly like Phase 1B.7B
leaves Section-level Campus/Grade consistency to application validation
rather than a database constraint.

## 6. ElectiveGroup semantics under rollover (DECIDED)

- **Target group authority**: always the target year's own
  AcademicStructure — a target offering's current `elective_group_id`
  is authoritative, never required to match any source-year group's id
  or code (Groups are year/context-specific, architecture doc §8).
- **Same-group collision (checkpoint brief §15)**: if a Student's
  distinct source electives map to two target offerings that currently
  share the same `elective_group_id`, dry-run must flag **both** as a
  conflict — never silently pick a winner by mapping-row order. This is
  a per-Student, per-Item check: group the Item's resolved target
  offerings by their current `elective_group_id`, and any group with
  more than one member is `blocked`/`elective_target_group_conflict`
  for every member. At execution time, the REAL, final backstop is
  still the Phase 1F.1 partial unique index
  (`student_subject_enrollments_one_active_per_elective_group`) via
  Phase 1F.2's own `ElectiveGroupConflictException` translation — dry-run
  detection is an early warning, never the sole enforcement (mirrors
  Phase 1B.7B's own Roll-Number in-plan duplicate detection existing
  alongside the database's own final constraint).
- **Grouped→ungrouped / ungrouped→grouped**: both explicitly allowed
  through the mapping (§5) with zero special-case code — the target
  offering's own current state is simply whatever it is.

## 7. Item ownership and atomicity (DECIDED: composed inside the existing Item, one transaction)

**No new per-subject durable row.** A source participation's
roll-forward OUTCOME is never separately persisted (no
`EnrollmentRolloverSubjectItem` table) — the target
`StudentSubjectEnrollment` row created via the canonical service is
itself the durable record of what happened, exactly like the target
`StudentEnrollment` row already is for placement. This keeps Phase 1G
composing *inside* the existing `EnrollmentRolloverItem` unit of work
rather than inventing a second item-shaped aggregate underneath it —
the smallest structure that satisfies checkpoint brief §22/§23's own
"the target StudentEnrollment must exist before target subject
participation can be created... strongly suggests composition
inside/below an existing rollover item" framing.

**Execution options compared:**

| | **A. Inside the existing Item transaction** | **B. Separate step after Item success** | **C. Separate subject Items** |
|---|---|---|---|
| Atomicity | Whole-or-nothing per Student (checkpoint brief §30's own stated preference) | A crash between steps leaves placement committed, electives unattempted — a genuinely inconsistent state | Same risk, plus a second aggregate to keep consistent |
| Resumability | Free — re-running `execute()` on an item is already idempotent (§9) | Needs its own separate resumability story | Needs its own separate resumability story |
| Complexity | Lowest — reuses `execute()`'s existing transaction | New orchestration step, new failure mode | New table, new idempotency anchor, new concurrency story |

**Chosen: A.** `EnrollmentRolloverItemExecutionService::execute()`'s
existing `DB::transaction()` is extended: immediately after the target
`StudentEnrollment` is created/reconciled (unchanged 1B.7C logic), the
SAME transaction resolves this Item's eligible source subject
participations (§3/§4), resolves each one's mapping (§5), and calls
`StudentSubjectEnrollmentService::enroll()` for each resulting target
offering — all still inside the one `DB::transaction()` `execute()`
already opens. Nothing here bypasses `StudentSubjectEnrollmentService`
(checkpoint brief §29): no direct `StudentSubjectEnrollment::create()`
from rollover code anywhere.

**Failure behavior (checkpoint brief §31/§32) — resolved by ordinary
transaction semantics, no special-case code needed:**

- If the target `StudentEnrollment` is **freshly created by this same
  transaction** (a genuine new promotion) and a later elective creation
  fails, rolling back the transaction undoes the fresh `StudentEnrollment`
  too — correct, since nothing else has observed it yet.
- If the target `StudentEnrollment` **already existed** before this
  execution attempt (the idempotent-replay/reconcile path, §9), the
  placement step never mutates that row — it only reads/confirms it.
  A later elective failure's rollback therefore has nothing to undo for
  placement; the pre-existing row is untouched, automatically, because
  the transaction never wrote to it. This is exactly the reasoning
  checkpoint brief §32 itself invites ("rollback automatically handles
  this if all work uses one transaction and no pre-existing row was
  mutated incorrectly") — no distinct code path for "created vs.
  pre-existing" is required.

An elective-mapping problem is therefore always part of the SAME
Item's overall blocking surface (§8) — an Item can never reach
`ready` while any of its explicit elective mappings has an unresolved
blocking issue, because dry-run already proves execution would
otherwise create the placement, hit the elective failure, and roll the
whole thing back — a wasted, confusing attempt dry-run exists
specifically to prevent. The operator's escape hatch for a Student
whose elective would otherwise block their entire promotion is the
SAME explicit-omit mechanism §5 already provides (map the source
offering to `NULL` = "don't carry this one forward").

## 8. Dry-run integration (DECIDED)

Extends `EnrollmentRolloverDryRunService::run()`'s existing single pass
(unchanged for placement) with an additional evaluation stage per Item,
reusing the SAME `validation_result`/`validation_reason` columns
already on `EnrollmentRolloverItem` — no new columns, no parallel
result surface. **Zero academic writes**, unchanged (this stage only
ever reads `StudentSubjectEnrollment`/`SubjectOffering`/`ElectiveGroup`
and this checkpoint's own new mapping table).

New reason codes (additive to the existing taxonomy, same "plain
string, no DB enumeration" convention):

| Reason | Blocks? | Meaning |
|---|---|---|
| `missing_subject_mapping` | Yes | Source elective offering has no mapping row at all. |
| `elective_target_inactive` | Yes | Mapped target offering is not active. |
| `elective_target_required` | Yes | Mapped target offering is `is_required = true` (cannot be an explicit elective). |
| `elective_target_context_mismatch` | Yes | Mapped target offering does not belong to the Item's resolved target AcademicYear/Campus/GradeLevel. |
| `elective_target_group_conflict` | Yes | Two of this Item's mapped target offerings currently share the same `elective_group_id` (§6). |
| `elective_target_existing_conflict` | Yes | Target StudentEnrollment already has a DIFFERENT active offering in that same target ElectiveGroup (checkpoint brief §27 — human resolution required, never auto-replaced). |
| `legacy_source_anchor_ambiguous` | Yes (`review`) | §4's legacy NULL-anchor row has >1 candidate StudentEnrollment for the source year. |
| `elective_already_enrolled_match` | No | Target already has the exact mapped offering active — idempotent, non-blocking (§9). |
| `elective_explicitly_omitted` | No | §5's explicit-omit mapping (`target_subject_offering_id = NULL`). |

An Item's overall `validation_result` is `blocked`/`review` if EITHER
its existing placement evaluation OR any of its elective evaluations
above is blocking — one unified surface, matching §7's atomicity
reasoning. Plan readiness (`validated`) is unchanged: zero `blocked`/
`review` Items across the WHOLE evaluation, placement and electives
together.

## 9. Idempotency and "already applied" (DECIDED)

No new idempotency anchor column is needed. "Already applied" for one
mapped target offering is recognized the same way Phase 1F.2's own
service already makes it safe to recognize: an `active`
`StudentSubjectEnrollment` row for `(target_enrollment_id,
target_subject_offering_id)` already exists → treat as reconciled,
call `enroll()` again is unnecessary (skip it, this Item's elective
work for that mapping is done); a `ActiveSubjectEnrollmentConflictException`/
`ElectiveGroupConflictException` surfacing from an actual `enroll()`
attempt (a genuine race, §11) is caught and reconciled if it matches
the proposal, or turned into a stable Item conflict otherwise — mirroring
1B.7C's own `ActiveEnrollmentConflictException` reconciliation pattern
exactly, applied one layer down. A target Enrollment with a DIFFERENT
active offering already occupying that same ElectiveGroup is never
auto-withdrawn/replaced — checkpoint brief §27's explicit rule; it
surfaces as `elective_target_existing_conflict` for human resolution.

## 10. Source history (DECIDED, unchanged from Phase 1B.7/1F.2)

Rollover never calls `withdraw()`/`cancel()`/`transfer()` on the
source `StudentSubjectEnrollment`, and never calls `complete()`/
`withdraw()`/`cancel()` on the source `StudentEnrollment` — identical
to Phase 1B.7's own "source completion semantics" decision, applied
uniformly to both placement and subject participation. Source-year
history is permanently unchanged by rollover, for both tables.

## 11. Concurrency (DECIDED — composes entirely from existing guarantees, no new primitive)

No new lock, no new table-level concurrency mechanism. Three required
future test scenarios, each already covered by an EXISTING mechanism:

| Scenario | Existing mechanism that already makes it safe |
|---|---|
| Same rollover Item executed concurrently (placement AND electives together) | The SAME per-Item transaction/idempotent-replay discipline Phase 1B.7C already proved with a real two-process test (`EnrollmentRolloverItemExecutionConcurrencyTest`) — electives are now simply part of that same unit of work. |
| Target elective already inserted concurrently by an unrelated path | `StudentSubjectEnrollmentService::enroll()`'s own `ActiveSubjectEnrollmentConflictException` translation + this checkpoint's §9 reconciliation. |
| Target same-group conflicting elective concurrently inserted | Phase 1F.1's partial unique index (`student_subject_enrollments_one_active_per_elective_group`) + Phase 1F.2's `ElectiveGroupConflictException` translation — the SAME mechanism `ElectiveGroupConfigurationConcurrencyTest` already proved for the configuration-vs-enrollment race, now exercised via the rollover call path instead of a direct `enroll()` call. |

A future implementation's concurrency tests should extend
`EnrollmentRolloverItemExecutionConcurrencyTest`'s exact pattern (real
Symfony Process, two genuinely separate OS processes) to additionally
assert on the resulting `StudentSubjectEnrollment` state, never invent
a second concurrency-test harness.

### Lock-order review (no new deadlock cycle)

Composing an `enroll()` call inside `execute()`'s existing transaction
adds exactly one new lock acquisition to that transaction's existing
chain:

```
Plan (claim, 1B.7D) -> Item -> source StudentEnrollment (1B.7C, unchanged)
  -> [nested, via enroll()] target SubjectOffering (Phase 1F.2's lockOffering())
```

No other pathway in this codebase ever acquires a `SubjectOffering`
lock BEFORE a rollover Plan/Item/StudentEnrollment lock — `ElectiveGroupService::assignOffering()`/
`removeOffering()` (Phase 1F.3) only ever lock a `SubjectOffering`
alone, with no Plan/Item/StudentEnrollment involvement at all, so there
is no reverse-order pairing between rollover execution and elective
configuration. Two concurrent rollover Items for two different
Students acquire different first-locks (their own distinct source
`StudentEnrollment` rows) before ever contending on a shared
`SubjectOffering` — ordinary contention (one waits), never a cross-wait
cycle. **No deadlock cycle is introduced.**

## 12. Authorization (DECIDED: no new capability)

Reuses the existing `enrollments.rollovers.view`/`enrollments.rollovers.manage`
pair unchanged (confirmed seeded: `school_admin` gets both, `principal`
gets view-only) — subject mapping configuration is part of the SAME
rollover Plan aggregate, so it is authorized by the SAME dual-capability
rule (`enrollments.view`+`enrollments.rollovers.view` for reads,
`enrollments.manage`+`enrollments.rollovers.manage` for every mutation)
Phase 1B.7E already established for every other Plan sub-resource
(mappings, items). `academics.subjects.*` is never checked by rollover
code — `StudentSubjectEnrollmentService` remains authorization-neutral,
exactly like `StudentEnrollmentService` already is for the placement
side; rollover itself is the authorized actor, matching Phase 1B.7's
own "rollover operator is authorized by rollover capability" default.
No new capability, no seeder change, in this checkpoint.

## 13. API / UI (DECIDED: extend the existing rollover surface, no parallel API)

The existing Phase 1B.7E/1B.7F rollover API and UI are already
complete end-to-end (create → configure → validate → start/resume →
inspect). Checkpoint brief §47's own bar — a rollover administrator
must be able to actually configure and run this through the existing
interface, not merely have a backend that theoretically supports it —
means Phase 1G's scope is backend + a narrow EXTENSION of the existing
API/UI, never a parallel `/subject-rollovers` surface:

- **API**: new nested routes under the SAME Plan resource, e.g.
  `POST/PATCH /schools/{school}/enrollment-rollovers/{rollover}/subject-mappings[/{mapping}]`,
  mirroring `EnrollmentRolloverMappingController`'s exact shape (same
  nested-Plan-ownership resolution, same protected-field allow-list).
  Whether this is a new sibling controller or an extension of the
  existing mapping controller is an implementation-time call, not an
  architecture question.
- **UI**: a new `SubjectMappingsPanel.vue` added to the SAME
  `/app/enrollment-rollovers/{id}` Plan workspace page, alongside the
  existing `MappingsPanel.vue`/`ItemsPanel.vue`, plus surfacing the new
  §8 reason codes in the existing `rolloverReasons.ts` translation map
  and the existing validation-summary counts.

This is explicitly **not** Phase 1H (`SubjectOffering`/elective-
enrollment administrative UI — the day-to-day staff workflow for
viewing/managing a roster and an individual Student's elective
participation, unrelated to rollover). Phase 1G's UI surface is scoped
entirely to rollover-plan subject-mapping configuration; it does not
build a general elective-management screen.

## 14. Roster / Communications (DECIDED: no change)

`SubjectOfferingRosterReadService` needs no rollover-aware code —
once a canonical target `StudentSubjectEnrollment` row exists (created
via the same `enroll()` every other caller uses), roster reads
naturally include it through the SAME "re-derive compatibility fresh
against the Student's current `StudentEnrollment`" mechanism that
already makes Phase 1F.2's rows work with zero special-casing. No
Communications change — the target year's SubjectOffering audience
becomes correct automatically through the roster boundary, unchanged.
Regression only, no new code.

## 15. Audit (DECIDED: reuse, plus one rollover-specific summary event)

`student_subject_enrollment.created` (already fired by `enroll()`
itself, unchanged) is sufficient for the Enrollment-domain fact.
Additionally, mirroring `enrollment_rollover.item_succeeded`/
`.item_reconciled`/`.item_execution_failed`'s existing naming and
"IDs + reason codes only, never Student PII" shape exactly, this
checkpoint's implementation should add:
`enrollment_rollover.item_subject_participation_succeeded`,
`.item_subject_participation_reconciled`,
`.item_subject_participation_conflict` — one event per resolved
elective outcome, carrying only plan/item/target-offering/target-
enrollment ids, never a Student's name or Roll Number. No duplicate
"promotion succeeded" event is invented.

## 16. Versioning and mutability (DECIDED: reuse the existing mechanism unchanged)

A new `EnrollmentRolloverPlanService::upsertSubjectMapping()` method
increments the SAME `configuration_version` column in the SAME
transaction as its write, gated by the SAME shared `assertConfigurable()`
guard (`draft`/`validated` only) `upsertMapping()`/`setItemDecision()`
already use — no new staleness mechanism, no new lifecycle state.
Editing a subject mapping after validation immediately invalidates the
Plan's `validated` status exactly like editing a Grade/Section mapping
already does today. `executing`/terminal Plans remain fully immutable,
unchanged.

## 17. Implementation slices (DECIDED)

1. **1G.1 — Subject Rollover Mapping Schema & Domain Foundation.**
   `enrollment_rollover_subject_mappings` table/model/factory,
   `EnrollmentRolloverPlanService::upsertSubjectMapping()`, new
   exceptions (duplicate mapping, context mismatch — mirroring existing
   naming conventions), the recommended `subject_offerings` composite
   unique (§5). No dry-run/execution integration yet.
2. **1G.2 — Dry-Run Integration.** Extends
   `EnrollmentRolloverDryRunService::run()` with the §8 evaluation
   stage and its new reason codes. Still zero academic writes.
3. **1G.3 — Item Execution & Concurrency.** Extends
   `EnrollmentRolloverItemExecutionService::execute()` per §7, through
   `StudentSubjectEnrollmentService` only. Real multi-process
   concurrency tests per §11.
4. **1G.4 — Rollover API/UI Integration.** Extends the existing Phase
   1B.7E/1B.7F surface per §13. No parallel API/UI.

Each checkpoint independently regression-tests against the current
full-platform baseline before proceeding, matching every prior Phase
1B/1F checkpoint's own discipline.

## 18. Phase 1H boundary (preserved, not addressed here)

**Phase 1H — SubjectOffering / Elective Enrollment Administrative UI**
remains the second outstanding Phase 1 completion item, entirely
separate from Phase 1G: the day-to-day staff workflow for viewing a
SubjectOffering's roster and managing an individual Student's current
elective participation (enroll/withdraw/cancel/transfer through a UI,
today only reachable via the Phase 1C.1 JSON API). Phase 1G's own UI
work (§13) is limited to rollover-plan subject-mapping configuration
and never substitutes for Phase 1H's general elective-management
screen. Not implemented, not designed further, in this checkpoint.
