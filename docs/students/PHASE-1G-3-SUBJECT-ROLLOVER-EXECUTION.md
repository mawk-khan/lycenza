# Phase 1G.3 — Subject Rollover Item Execution & Concurrency

> Extends `App\Domain\Students\Application\EnrollmentRolloverItemExecutionService::execute()`
> to actually create explicit elective `StudentSubjectEnrollment` rows,
> per `docs/students/PHASE-1G-0-SUBJECT-ENROLLMENT-ROLLOVER-ARCHITECTURE.md`
> §7, on top of the mapping schema
> (`docs/students/PHASE-1G-1-SUBJECT-ROLLOVER-MAPPING-FOUNDATION.md`) and
> dry-run validation
> (`docs/students/PHASE-1G-2-SUBJECT-ROLLOVER-DRY-RUN.md`). No
> API/route/Vue/OpenAPI/capability change is made here — those remain
> Phase 1G.4.

## 1. Atomic composition

`execute()`'s existing single `DB::transaction()` (Phase 1B.7C) is
extended, not duplicated. Immediately after the target `StudentEnrollment`
is established — by any of the three existing paths (a pre-existing
exact match, a race-reconciled match, or a freshly-created row) — the
SAME transaction calls a new private method,
`applySubjectElectives(EnrollmentRolloverPlan $plan, EnrollmentRolloverItem $item, StudentEnrollment $targetEnrollment, ?User $actor)`,
before the Item's own success/`reconciled` bookkeeping is written. No
second transaction exists anywhere in this checkpoint.

## 2. Shared candidate/resolution logic (no duplicated rules)

`App\Domain\Students\Application\SubjectRolloverResolution::resolve()`
is a new, pure, read-only, static method — issues no queries, performs
no writes — extracted from `EnrollmentRolloverDryRunService`'s own
per-Item elective-evaluation body. It is now the SINGLE implementation
of:

- anchored active-elective candidate selection;
- the strict legacy `student_enrollment_id IS NULL` anchor rule
  (PHASE-1G-1 doc §12/PHASE-1G-2 doc §3);
- three-state mapping resolution (absent/omit/mapped);
- target Offering validation (required/active/context);
- plan-internal duplicate-mapping and same-group collision detection;
- reconciliation against an already-active exact/target-group
  participation.

Both `EnrollmentRolloverDryRunService::evaluateSubjectParticipation()`
(read-only, batched across every Item under evaluation) and
`EnrollmentRolloverItemExecutionService::applySubjectElectives()`
(single-Item, write path) call this SAME method with their own
already-loaded reference data. The dry-run refactor is a **behavior-
preserving extraction only** — proven by the complete, unmodified
Phase 1G.2 dry-run test suite (60 tests / 150 assertions) passing
byte-for-byte identically after the refactor.

`SubjectRolloverResolution::REASON_PRECEDENCE` (moved out of
`EnrollmentRolloverDryRunService`'s own private constant, now `public
const` on the shared class) remains the single deterministic
rank list both dry-run and execution consult when more than one
subject-level issue is found for the same Item.

## 3. Candidate parity with dry-run

Execution identifies eligible source electives IDENTICALLY to dry-run:
active rows anchored via `student_enrollment_id = item.source_enrollment_id`,
plus legacy `NULL`-anchor rows resolved via the same strict three-
condition rule (exactly one `student_enrollments` candidate for
`(student_id, plan.source_academic_year_id)`, academically compatible
with the source Offering, and that candidate's id equals
`item.source_enrollment_id` — otherwise `legacy_source_anchor_ambiguous`,
never a guess). Required-offering participations are silently excluded,
never flagged, exactly like dry-run.

## 4. Defensive re-validation (post-validation drift)

`applySubjectElectives()` calls `SubjectRolloverResolution::resolve()`
fresh, against CURRENT database state, before writing anything. If
`resolve()` returns any reason (missing mapping, target
inactive/required/context-mismatch, plan-internal duplicate/group
conflict, an existing different-offering group conflict, or legacy
ambiguity), the WHOLE Item is refused via
`RolloverSubjectMappingExecutionException($reason)` — using the SAME
reason string dry-run would have used, since it is the identical
static check. Reaching this path indicates configuration/data drift
since the last successful dry-run (a new active source elective
appearing after validation, or an AcademicStructure mutation to the
target Offering) — normally unreachable for a Plan that was properly
revalidated before execution, but never silently guessed at either
way.

## 5. Canonical creation — never a direct write

Every actual target participation write goes through
`StudentSubjectEnrollmentService::enroll($item->student, $targetOffering, $subjectStartsOn, $actor)`
— no `StudentSubjectEnrollment::create()` anywhere in the rollover
execution code. `enroll()` itself locks and reloads the target
Offering, derives the compatible target `StudentEnrollment` fresh
(matching the just-established target placement by construction, since
`student_enrollments_one_active_per_student_year` guarantees at most
one candidate), and snapshots `elective_group_id` from the just-locked
Offering row — exactly the same "never trust a stale caller-held
model" discipline the placement side already follows for target
Sections.

**Target date source (checkpoint brief §45, decided)**: every mapped
elective's `startsOn` is `$plan->targetAcademicYear->starts_on->toDateString()`
— the identical value the placement side already uses for the target
`StudentEnrollment` itself (`EnrollmentRolloverItemExecutionService::execute()`'s
existing `enroll()` call). This is the one existing "rollover target
date rule" in this codebase; no second date source, no `today()`, no
copy of the source elective's own `starts_on`.

## 6. Exact-target idempotency / reconciliation

Before attempting `enroll()` for a resolved mapped target, execution
checks whether the target `StudentEnrollment` already has an ACTIVE
participation for that EXACT target Offering
(`student_enrollment_id = target.id AND subject_offering_id = target Offering id AND status = 'active'`)
— if so, it is skipped entirely (idempotent no-op, matches dry-run's
`elective_already_enrolled_match`). This makes replaying an already-
succeeded Item, or an Item where some mapped electives were already
satisfied before this Plan's rollover ran, safe by construction — no
duplicate row, no mutation of the pre-existing row.

A genuine RACE (a concurrent, unrelated `enroll()` call satisfies the
exact same intent between this Item's own resolution read and its own
`enroll()` attempt) is handled the same way, one layer down: catching
`ActiveSubjectEnrollmentConflictException`, re-querying on the SAME
transaction (safe — `enroll()`'s own nested transaction/SAVEPOINT
already rolled back before the exception reached this code, mirroring
the placement side's identical reasoning), and reconciling if the
racer's committed row matches the exact same `(target enrollment,
target Offering)` pair. Only if it does NOT match does this become a
stable Item conflict (`elective_target_existing_conflict_at_execution`).

## 7. Same-group conflicts — never auto-resolved

A pre-existing OR concurrently-appearing DIFFERENT active Offering in
the mapped target's `elective_group_id` is NEVER auto-withdrawn or
replaced. Depending on exactly when the conflicting row becomes
visible relative to this Item's own resolution read, one of two
EQUALLY correct, stable outcomes results:

- If already visible at `resolve()`'s own pre-check: `elective_target_existing_conflict`.
- If it appears strictly between that pre-check and this Item's own
  `enroll()` attempt (a genuine live race, or an ElectiveGroup
  reassignment made mid-attempt): `ElectiveGroupConflictException` is
  caught and translated to `elective_target_group_conflict_at_execution`.

Both are proven by the mandatory real-concurrency test (§10 below);
neither ever overwrites the other side's committed row.

## 8. Whole-Item atomicity

If `applySubjectElectives()` (or anything inside `execute()`'s
transaction) throws, `execute()` NEVER catches it inside the
transaction closure and returns a partial "failed" result — that would
commit a partial write. Instead, `RolloverSubjectMappingExecutionException`
is allowed to propagate all the way out of `DB::transaction()`,
forcing Laravel/PostgreSQL to genuinely roll back EVERYTHING this
attempt wrote: any elective already inserted earlier in the SAME
attempt, and a freshly-created target `StudentEnrollment` if this
attempt created one. Only AFTER that rollback has actually happened
does `execute()`'s outer `catch` block persist the
`invalidateForDrift()` bookkeeping, in a BRAND NEW transaction — never
inside the one that just rolled back. A PRE-EXISTING target placement
is never touched by this rollback (nothing wrote to it in the first
place), matching checkpoint brief §27's requirement exactly.

Proven by
`the_second_of_two_mapped_electives_failing_rolls_back_the_first_and_the_fresh_target_enrollment`
— a deterministic, order-independent sequential simulation (no real OS
concurrency needed for THIS specific proof): whichever of two mapped
electives is inserted first is detected via a `StudentSubjectEnrollment::created`
listener, which retargets the OTHER (not-yet-attempted) target
Offering into an already-occupied `ElectiveGroup` — forcing the SECOND
elective's own `enroll()` call to hit the real database constraint,
regardless of iteration order.

## 9. Audit rollback

`StudentSubjectEnrollmentService::enroll()`'s own `student_subject_enrollment.created`
audit write happens inside its own nested transaction/SAVEPOINT, which
is itself nested inside `execute()`'s outer transaction — so when the
outer transaction rolls back (per §8), that audit write rolls back
with it. No committed "success" audit record survives a rolled-back
Item; proven by the same atomicity test asserting the first elective's
row count is exactly `0` after the failure.

## 10. Real concurrency (mandatory, three scenarios proven)

All three use real, separate OS processes (Symfony `Process`), never
mocked locks, extending the existing
`EnrollmentRolloverItemExecutionConcurrencyTest`/`ElectiveGroupConfigurationConcurrencyTest`
patterns — no second concurrency-test harness invented:

1. **Same Item, two processes, with an elective**
   (`EnrollmentRolloverItemExecutionConcurrencyTest`, extended): two
   real processes both call `execute()` for the SAME already-validated
   Item, which has one mapped elective. Final state: exactly one active
   target `StudentEnrollment`, exactly one active target elective row —
   no duplicates from either the placement or the subject layer.
2. **Exact-target race** (`EnrollmentRolloverSubjectExecutionConcurrencyTest`):
   rollover's own mapped intent races an unrelated, concurrent direct
   `StudentSubjectEnrollmentService::enroll()` call for the IDENTICAL
   target Offering. Repeated 6× to observe both orderings. Rollover
   NEVER fails this race — it always reconciles to a coherent
   `succeeded`/`reconciled` outcome, with exactly one active row
   surviving regardless of winner.
3. **Same-ElectiveGroup conflicting race**
   (`EnrollmentRolloverSubjectExecutionConcurrencyTest`): rollover
   intends Offering A / Group X; a concurrent writer enrolls a
   DIFFERENT Offering B in the SAME Group X. Repeated 6×. Exactly one
   Group X row survives every repetition, regardless of winner — the
   loser (whichever side it is) receives a stable, non-overwriting
   rejection (`ElectiveGroupConflictException` for a losing direct
   `enroll()` call; `elective_target_existing_conflict` or
   `elective_target_group_conflict_at_execution` — both correct,
   timing-dependent — for a losing rollover Item).

### Lock-order review (no new deadlock cycle)

Composing `applySubjectElectives()`'s `enroll()` call(s) inside
`execute()`'s existing transaction adds exactly one further nested
lock acquisition to the SAME chain PHASE-1G-0 §11 already reviewed:

```
Plan -> Item -> source Enrollment -> (existing target Enrollment, if found)
  -> [nested, per mapped elective, via enroll()] target SubjectOffering
```

No other pathway acquires a `SubjectOffering` lock BEFORE a rollover
Plan/Item/Enrollment lock (`ElectiveGroupService::assignOffering()`/
`removeOffering()` only ever lock a `SubjectOffering` alone). Two
concurrent rollover Items for two different Students acquire different
first-locks before ever contending on a shared `SubjectOffering` — no
cross-wait cycle. No deadlock was observed across any of the repeated
concurrency runs in this checkpoint's verification.

## 11. Replay / resume

A successfully-executed Item's idempotent-replay fast path
(`target_enrollment_id !== null` → `reconcileAlreadyExecuted()`) is
UNCHANGED and untouched by this checkpoint — by the time
`target_enrollment_id` is persisted, subject-elective application for
that attempt already succeeded fully (or the whole attempt rolled
back and `target_enrollment_id` was never set), so a replay is
guaranteed to have nothing further to do. `EnrollmentRolloverExecutionService`
(bulk start/resume) requires ZERO code changes — it already delegates
every Item exactly once to `EnrollmentRolloverItemExecutionService::execute()`,
so subject application is included automatically; no separate subject
cursor/progress state exists or is needed.

## 12. Zero required-subject participation

Required `SubjectOffering`s are never written by this checkpoint —
`SubjectRolloverResolution::resolve()` silently excludes a required-
offering participation from candidacy (defensive only; no sanctioned
service path can create one). Required rosters remain fully derived
via `SubjectOfferingRosterReadService`, unchanged.

## 13. Roster / Communications

No production change to `SubjectOfferingRosterReadService` or any
Communications code. Once a canonical target `StudentSubjectEnrollment`
row exists (created via the same `enroll()` every other caller uses),
roster reads and Communications' target-offering audience resolution
include it automatically through the SAME "re-derive fresh" mechanism
that already makes every other elective enrollment path work with zero
special-casing. Regression-proven, not newly coded.

## 14. Authorization / tenancy

No new capability. `EnrollmentRolloverItemExecutionService` remains
authorization-neutral, exactly like every other Application service in
this codebase — the HTTP boundary (unchanged, Phase 1G.4's job) is
where `enrollments.rollovers.manage` is actually checked. Every new
query derives School from the already-established `TenantContext`
established by `execute()`'s own `withSchool()` call — no
`withoutGlobalScope(s)`, no caller-supplied `school_id`, no
cross-School probing.

## 15. Deferred to Phase 1G.4

- New/extended API routes exposing rollover subject-mapping
  configuration and execution results.
- `SubjectMappingsPanel.vue` and reason-code translations in the
  existing rollover-plan UI workspace.
- OpenAPI schema updates.
- No new capability is anticipated even then — the existing
  `enrollments.rollovers.view`/`.manage` pair already covers this
  surface (PHASE-1G-0 doc §12).
