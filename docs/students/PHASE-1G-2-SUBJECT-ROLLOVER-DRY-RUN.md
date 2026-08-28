# Phase 1G.2 — Subject Rollover Dry-Run Integration

> Extends `App\Domain\Students\Application\EnrollmentRolloverDryRunService::run()`
> with the elective-subject evaluation stage accepted by
> `docs/students/PHASE-1G-0-SUBJECT-ENROLLMENT-ROLLOVER-ARCHITECTURE.md`
> §8, composed on top of the `enrollment_rollover_subject_mappings`
> schema/mutation surface from
> `docs/students/PHASE-1G-1-SUBJECT-ROLLOVER-MAPPING-FOUNDATION.md`.
> **Zero academic writes**, unchanged from Phase 1B.7B's own invariant —
> this checkpoint only ever reads `StudentSubjectEnrollment`/
> `SubjectOffering`/`EnrollmentRolloverSubjectMapping` and writes
> `EnrollmentRolloverItem.validation_result`/`.validation_reason` and
> `EnrollmentRolloverPlan`'s own validation columns, exactly like every
> other Phase 1B.7B evaluation pass. No execution
> (`EnrollmentRolloverItemExecutionService`/`EnrollmentRolloverExecutionService`),
> `StudentSubjectEnrollmentService`, API/route, Vue/Inertia, OpenAPI, or
> capability change is made here — those remain Phase 1G.3/1G.4.

## 1. Where this composes

`EnrollmentRolloverDryRunService::run()`'s existing five-pass placement
pipeline (structural → target-Section resolution → already-enrolled →
in-plan conflicts → persisted conflicts) is unchanged. A SIXTH pass,
`evaluateSubjectParticipation()`, runs immediately after it, and only
ever considers an Item the placement pipeline already resolved to
`ready` (a fresh promotion about to happen) or `already_enrolled` (its
target placement already exists) — every other Item
(`excluded`/`review`/`blocked` from placement alone) is left completely
untouched, which is what makes "existing Phase 1B placement failure
precedence first" (accepted checkpoint brief §30) fall out for free
rather than needing special-case ordering logic: a subject conflict can
only ever WIN the Item's single `validation_reason` slot when placement
itself had nothing to say.

## 2. Candidate source-elective selection

For each Item under subject evaluation, eligible source participations
are:

- **Anchored rows** — `StudentSubjectEnrollment` where
  `student_enrollment_id = item.source_enrollment_id AND status = 'active'`
  (Phase 1F.2's own anchor column; this is a strict identity match, never
  a `(student_id, academic_year_id)` re-derivation).
- **Legacy `student_enrollment_id = NULL` rows** — resolved via the
  **corrected** strict rule below (superseding the count-only rule
  originally proposed in the 1G.0 architecture doc §4 — see §3).

Both sets exclude a row whose `subject_offering_id` resolves to an
`is_required = true` Offering — defensive only (no sanctioned service
path can create such a row; `StudentSubjectEnrollmentService::enroll()`/
`transfer()` both reject a required target outright), covering only
theoretically-possible malformed/pre-1C.1 data. Such a row is silently
excluded, never flagged as a conflict, and never requires a subject
mapping.

## 3. Legacy null-anchor resolution (corrected rule, now implemented)

The rule actually implemented here is the **corrected** three-condition
rule first recorded (but not implemented) in Phase 1G.1's documentation
§12 — **not** the simpler count-only rule Phase 1G.0's architecture doc
§4 originally proposed. A legacy row is attributable to
`item.source_enrollment_id` if and only if ALL three hold:

1. **Exactly one** `student_enrollments` row (of ANY status) exists for
   `(student_id, plan.source_academic_year_id)` — a bare `COUNT(*)`
   proof. Two or more rows (regardless of whether the extra ones are
   `active`/`completed` or `withdrawn`/`transferred`/`cancelled`) makes
   the attribution ambiguous outright — no attempt is made to
   disambiguate via compatibility even if only one of them turns out
   compatible; this is deliberately conservative (accepted checkpoint
   brief §9).
2. That sole candidate is **academically compatible** with the source
   `SubjectOffering` under the exact Phase 1C.1 rule
   (`StudentSubjectEnrollmentService::resolveCompatibleEnrollment()`):
   same `academic_year_id`/`campus_id`/`grade_level_id`. `Section` is
   deliberately never compared.
3. That sole candidate's `id` **equals** `item.source_enrollment_id`
   exactly.

Any failure of any condition (ambiguous count, incompatible sole
candidate, or a compatible-but-wrong sole candidate) resolves to the
SAME stable code, `legacy_source_anchor_ambiguous` — no name/date/order
inference, ever. This mirrors `multiple_source_candidates`'s own
`review` classification for the analogous placement-side ambiguity: the
Item is marked `review`, never silently dropped, never silently
attributed.

**Why the 1G.0 count-only rule was superseded**: the original §4 rule
attributed a legacy row whenever exactly one `StudentEnrollment` existed
for the student/source year, with no compatibility or identity check
against the Item's own anchor. That leaves open two failure modes this
checkpoint's test suite explicitly proves are real:
`legacy_null_anchor_row_incompatible_with_the_sole_candidate_is_ambiguous`
(the sole candidate belongs to a different Campus than the source
Offering) and
`legacy_null_anchor_row_whose_only_candidate_is_not_the_items_source_enrollment_is_ambiguous`
(a data-integrity edge case where the Item's own `source_enrollment_id`
does not match the year's sole candidate at all — structurally
possible because `enrollment_rollover_items`' composite FK on
`source_enrollment_id` only requires same-School/same-Student, never
same-year). Both are now correctly rejected rather than silently
misattributed.

## 4. Three-state mapping lookup (unchanged from 1G.1, now consumed)

For each eligible source elective's `SubjectOffering`, exactly one
`EnrollmentRolloverSubjectMapping` row for `(plan_id,
source_subject_offering_id)` is looked up (batched, keyed by source
Offering id — never a per-Item query):

| State | Dry-run outcome |
|---|---|
| No row | **Blocking** — `missing_subject_mapping`. The operator must configure an explicit mapping (carry-forward OR omit) — absence is never treated as intentional. |
| Row, `target_subject_offering_id = NULL` | **Non-blocking** — the elective is validly, explicitly dropped. No target write will ever be attempted for it. |
| Row, target set | Full target validation (§5) is required before the mapping counts as satisfied. |

## 5. Target Offering validation

For a non-null mapping, the target `SubjectOffering` is validated
against CURRENT database state (never trusting that a once-valid
mapping stays valid — mirrors the placement side's own target-Section
re-validation):

- **Required** — `is_required = false`, else `elective_target_required`.
  Enforced by the 1G.1 mutation service at configuration time too, but
  re-checked here in case the flag changed since (theoretically possible
  only via direct database manipulation, not a sanctioned service path).
- **Active** — `status = 'active'`, else `elective_target_inactive`.
  Deliberately NOT enforced at 1G.1 configuration time (a mapping may
  remain durable while an Offering's activity changes) — this is
  precisely why dry-run, not the mutation service, is where this is
  checked.
- **Context compatibility** — the target Offering's
  `academic_year_id`/`campus_id`/`grade_level_id` must equal the Item's
  RESOLVED target placement context (§6) — never the Plan's declared
  years alone. Any mismatch (including wrong Plan target year, which
  is a special case of context mismatch since the resolved context's
  `academic_year_id` is always the Plan's target year) is the single
  code `elective_target_context_mismatch`. `Section` is deliberately
  NEVER part of this comparison — proven directly by
  `subject_mapping_target_section_difference_alone_never_conflicts`.

Source Offering *status* is deliberately never checked — historical
source-year configuration may reference an Offering that has since
gone inactive; that is fine, since nothing is created FROM the source
Offering itself, only decided based on its mapping.

## 6. Resolving the target placement context

- **A `ready` Item** (fresh promotion, target `StudentEnrollment` does
  not exist yet): context is the Item's already-resolved target
  Section's `campus_id`/`grade_level_id`, plus the Plan's own
  `target_academic_year_id`.
- **An `already_enrolled` Item** (target `StudentEnrollment` already
  exists, exact Section+Roll Number match): context is that EXISTING
  row's own `academic_year_id`/`campus_id`/`grade_level_id` — never a
  hypothetically re-derived one, per accepted checkpoint brief §19.

## 7. Reconciliation against an existing target placement

Only applies to `already_enrolled` Items (a `ready` Item's target
`StudentEnrollment` does not exist yet, so no target subject
participation can possibly exist for it either — `enroll()` always
requires an existing compatible `StudentEnrollment` first).

- **Exact mapped Offering already active** → non-blocking,
  `elective_already_enrolled_match` — idempotent no-op, execution will
  skip it (Phase 1G.3).
- **A DIFFERENT Offering already active in the mapped target's SAME
  `elective_group_id`** → blocking, `elective_target_existing_conflict`
  — never auto-withdrawn/replaced; human resolution required.

## 8. Plan-internal (within-Item) collisions

Computed purely from the Item's OWN set of resolved mapped target
Offerings — independent of whatever is already enrolled:

- **Two different mapped target Offerings sharing the same non-null
  `elective_group_id`** → blocking, `elective_target_group_conflict`.
  Detected BEFORE execution would ever attempt it — never resolved by
  iteration order (grouped by `elective_group_id`, any group with more
  than one member fails the whole Item).
- **Two different eligible source electives mapping to the exact SAME
  target Offering** → blocking, `elective_target_duplicate_mapping`
  (see §9 — this reason was NOT in the 1G.0 architecture doc's original
  nine-reason table).
- Multiple mapped ungrouped targets (`elective_group_id = NULL`):
  always allowed. Multiple mapped targets in DIFFERENT groups: always
  allowed. Grouped↔ungrouped transitions across the rollover: always
  allowed. Source vs. target `elective_group_id` identity/code is NEVER
  compared — target-year grouping is the sole authority (1G.0 §6).

## 9. Reason-code set (final, as implemented)

The accepted architecture (1G.0 §8) documented nine reason codes. This
checkpoint implements all nine, plus ONE addition the architecture doc
did not foresee:

| Reason | Blocks? | Source |
|---|---|---|
| `missing_subject_mapping` | Yes (`blocked`) | 1G.0 §8 |
| `elective_target_inactive` | Yes (`blocked`) | 1G.0 §8 |
| `elective_target_required` | Yes (`blocked`) | 1G.0 §8 |
| `elective_target_context_mismatch` | Yes (`blocked`) | 1G.0 §8 |
| `elective_target_group_conflict` | Yes (`blocked`) | 1G.0 §8 |
| `elective_target_existing_conflict` | Yes (`blocked`) | 1G.0 §8 |
| `legacy_source_anchor_ambiguous` | Yes (`review`) | 1G.0 §8 |
| `elective_already_enrolled_match` | No | 1G.0 §8 |
| `elective_explicitly_omitted` | No | 1G.0 §8 |
| **`elective_target_duplicate_mapping`** | **Yes (`blocked`)** | **New — §22 of this checkpoint's brief** |

**Why the tenth code was added**: the 1G.0 architecture only anticipated
a collision between two DIFFERENT target Offerings sharing an elective
group (`elective_target_group_conflict`). It never considered two
DIFFERENT SOURCE electives explicitly mapped to the exact SAME target
Offering — a distinct, narrower scenario (identical target id, not
merely same group) that the accepted checkpoint brief for THIS phase
explicitly raised and asked to be resolved as a blocking configuration
conflict (strong preference stated in the brief: "the Plan's subject
mapping is many-source→one-target ambiguous and probably operator
error"). Implemented as its own code rather than folding it into
`elective_target_group_conflict` so an operator can immediately tell
"two Offerings collided via a shared group" from "two of my own source
mappings point at the same target by mistake."

## 10. Deterministic single-reason precedence

`EnrollmentRolloverItem.validation_reason` is a single nullable string
column (unchanged schema, no migration this checkpoint) — when an
Item's several elective participations surface more than one subject
issue simultaneously, exactly ONE wins the slot, via a FIXED rank list
(`EnrollmentRolloverDryRunService::SUBJECT_REASON_PRECEDENCE`), never
query/iteration order:

1. `elective_target_duplicate_mapping`
2. `elective_target_group_conflict`
3. `elective_target_existing_conflict`
4. `missing_subject_mapping`
5. `elective_target_required`
6. `elective_target_inactive`
7. `elective_target_context_mismatch`
8. `legacy_source_anchor_ambiguous`

`legacy_source_anchor_ambiguous` is deliberately LAST — it is the least
specific signal ("we could not prove which source Enrollment a legacy
row belongs to"), so a concrete, actionable blocking problem elsewhere
in the same Item's elective set always wins. This subject-level
precedence is only ever consulted for an Item that ALREADY survived
every placement-level check (§1) — placement failure precedence is
therefore automatic, not a second explicit precedence list.

## 11. Zero-write guarantee

Every new test in `EnrollmentRolloverDryRunServiceTest` that exercises
subject-mapping evaluation asserts the `student_subject_enrollments`
row count is unchanged from before the dry-run call (e.g.
`subject_mapping_basic_map_is_ready_with_zero_target_subject_writes`,
`subject_mapping_dry_run_is_deterministic_and_repeatable_when_unchanged`).
`evaluateSubjectParticipation()` and `buildSubjectCandidateContexts()`
issue only `SELECT` queries (batched — one query per distinct
lookup shape across ALL Items under evaluation, never per-Item) against
`StudentSubjectEnrollment`/`SubjectOffering`/`StudentEnrollment`/
`Section`/`EnrollmentRolloverSubjectMapping`. No `StudentEnrollment`,
`StudentSubjectEnrollment`, `SubjectOffering`, or `ElectiveGroup` row is
ever created, updated, or deleted by this checkpoint — the existing
`dry_run_changes_zero_rows_in_every_academic_table` test (unchanged)
continues to pass unmodified, and every new subject-specific test
reinforces the same invariant for the tables this checkpoint newly
reads.

## 12. Versioning (reused, unchanged mechanism)

Subject mappings already participate in the Plan's single
`configuration_version` counter (Phase 1G.1) — dry-run's existing
staleness re-check (`StaleRolloverConfigurationException` if
`configuration_version` changed between calculation and persistence)
requires no changes here. A REAL subject-mapping change (mapped →
different target, mapped → omit, omit → mapped, or removal) after a
successful dry-run immediately invalidates
`isValidatedForCurrentConfiguration()` exactly like editing a Grade/
Section mapping already does — proven by
`a_real_subject_mapping_change_after_validation_invalidates_it`. A true
no-op `upsertSubjectMapping()` call (identical target, including
omit → omit) — 1G.1's own idempotency guarantee — never bumps the
version and therefore never invalidates an existing validation, proven
by `a_true_no_op_subject_mapping_upsert_preserves_validation`. No
second, parallel version counter exists or is needed.

## 13. Query efficiency

Every batch in `evaluateSubjectParticipation()`/
`buildSubjectCandidateContexts()` is a single query over the FULL set of
Items under subject evaluation (anchored participations, legacy
participations, all-status source-year StudentEnrollment counts, the
Plan's subject mappings, referenced target Offerings, existing target
participations, target Sections) — never a per-Item query, matching
every existing pass in this service's own established pattern.

## 14. Tenancy

Every new query relies on the SAME ambient tenant scope
(`SchoolScope`/`TenantContext::withSchool()`) `run()` already
establishes for the whole dry-run — no `withoutGlobalScope(s)`, no
caller-supplied `school_id`, no cross-School probing. RLS remains the
second layer of defense, unchanged.

## 15. Deferred to later checkpoints

- **Phase 1G.3 — Item Execution & Concurrency**: extends
  `EnrollmentRolloverItemExecutionService::execute()` to actually call
  `StudentSubjectEnrollmentService::enroll()` per resolved mapping,
  inside the same transaction as placement. Not implemented here — this
  checkpoint makes zero production changes to any execution service.
- **Phase 1G.4 — Rollover API/UI Integration**: extends the existing
  Phase 1B.7E/1B.7F rollover API/UI surface with the new subject
  mapping panel and the reason codes in §9. Not implemented here.
- Richer structured per-conflict detail (e.g. "which specific mapping
  IDs collided") beyond the single `validation_reason` string is
  explicitly out of scope — `EnrollmentRolloverItem` has no schema for
  it, and this checkpoint adds no migration. A future UI checkpoint
  that needs this should either accept the single-reason summary or
  design a genuinely new persisted detail surface at that time, not
  retrofit one here.
