# Phase 1G.1 — Subject Rollover Mapping Schema & Domain Foundation

> Implements ONLY the schema/domain foundation accepted by
> `docs/students/PHASE-1G-0-SUBJECT-ENROLLMENT-ROLLOVER-ARCHITECTURE.md`
> section 17's slice 1: the `enrollment_rollover_subject_mappings`
> table, its model/factory/relationships, and a narrow mutation surface
> on the existing `EnrollmentRolloverPlanService`. No dry-run
> (`EnrollmentRolloverDryRunService`), execution
> (`EnrollmentRolloverItemExecutionService`/`EnrollmentRolloverExecutionService`),
> `StudentSubjectEnrollmentService`, API/route, Vue/Inertia, OpenAPI, or
> capability change is made here — those are Phase 1G.2/1G.3/1G.4.

## 1. Table

```
enrollment_rollover_subject_mappings
  id                          UUID (UUIDv7)
  school_id                   UUID -> schools, cascade
  plan_id                     UUID, composite FK -> enrollment_rollover_plans(id, school_id), cascade
  source_subject_offering_id  UUID, composite FK -> subject_offerings(id, school_id), restrict
  target_subject_offering_id  UUID NULL, composite FK -> subject_offerings(id, school_id), restrict
  created_at / updated_at

  unique(id, school_id)
  unique(plan_id, source_subject_offering_id)
```

One row per (plan, source `SubjectOffering`) — never a second, parallel
top-level rollover aggregate. Owned by, and cascade-deleted with, the
same `EnrollmentRolloverPlan` that `enrollment_rollover_mappings`
(Grade/Section placement) already belongs to, following the identical
ownership precedent.

## 2. Three-state mapping semantics

The row's mere *existence*, and whether its target is null, are the
entire state model — no boolean flag, no separate "configured" column:

| State | Representation |
|---|---|
| **Unconfigured** | No row for that `(plan, source Offering)` pair at all. |
| **Explicit omit** | Row exists, `target_subject_offering_id = NULL`. The operator explicitly decided NOT to carry this elective forward. |
| **Mapped** | Row exists, `target_subject_offering_id` set. Carry forward into that exact target `SubjectOffering`. |

This mirrors `enrollment_rollover_mappings`' own "absence = terminal
Grade" convention one layer down. Absence and explicit omit are never
conflated — `EnrollmentRolloverSubjectMapping::isExplicitOmit()` is
`true` only for an existing row with a null target.

## 3. FKs and tenancy

- `id` — UUIDv7 (`App\Support\Identifiers\GeneratesUuidV7`, ADR 0019).
- `school_id` — `App\Support\Tenancy\BelongsToSchool` (Layer 1 scope +
  auto-fill from `TenantContext` on create); no other sanctioned way to
  make this model tenant-scoped (rule 17).
- RLS: `App\Support\Tenancy\TenantRls::enable()` — `ENABLE` + `FORCE
  ROW LEVEL SECURITY`, proven in
  `Tests\Feature\Postgres\EnrollmentRolloverIntegrityTest`. No-context
  reads return zero rows (fail closed); School A cannot read, write, or
  create-as School B.
- **Plan FK**: composite `(plan_id, school_id)` →
  `enrollment_rollover_plans(id, school_id)`, `cascadeOnDelete()` — a
  mapping row has no meaning outside its plan, identical rationale to
  `enrollment_rollover_mappings.plan_id`.
- **Source/target Offering FK**: composite `(source_subject_offering_id,
  school_id)` / `(target_subject_offering_id, school_id)` →
  `subject_offerings(id, school_id)`, `restrictOnDelete()` on both.
  `subject_offerings` already had the required `unique(['id',
  'school_id'])` from its original migration — **no new composite
  unique was added to `subject_offerings`** for this checkpoint (see
  §5 below for why the architecture doc's "recommended, not
  implemented" year-pinning composite unique was deliberately NOT
  added either).
- Uniqueness: `unique(plan_id, source_subject_offering_id)` — at most
  one mapping row per source Offering per plan; Plan identity is
  already globally UUID-unique, so `school_id` does not need to be
  part of this index.

## 4. Year validation — DB vs. application split (accepted division)

`docs/students/PHASE-1G-0-SUBJECT-ENROLLMENT-ROLLOVER-ARCHITECTURE.md`
§5's "Recommended (not implemented) schema strengthening" suggested a
future `subject_offerings_id_school_academic_year_unique UNIQUE (id,
school_id, academic_year_id)` composite, enabling an additional FK on
this table that would pin `academic_year_id` against the plan's
declared source/target year at the database level. This checkpoint
**deliberately does not implement that** — doing so would require
denormalizing the plan's `source_academic_year_id`/
`target_academic_year_id` onto every mapping row purely to build a
three-column composite FK, which is exactly the kind of speculative
denormalization ruled out for this checkpoint.

The accepted division of responsibility instead is:

- **Database-structural**: same-School integrity via the composite FKs
  in §3 above.
- **Application-validated**: `EnrollmentRolloverPlanService::upsertSubjectMapping()`
  checks `$source->academic_year_id === $plan->source_academic_year_id`
  and (when non-null) `$target->academic_year_id === $plan->target_academic_year_id`
  before any write, throwing `InvalidSubjectMappingYearException`
  otherwise. This mirrors the exact division
  `enrollment_rollover_mappings`' own migration already documents for
  its analogous Section/Grade-vs-declared-year consistency gap.

Campus/GradeLevel/ElectiveGroup consistency between source and target
is intentionally never checked, at either layer, in this checkpoint —
see §7.

## 5. Elective-only rule

Subject mappings represent explicit elective carry-forward only.
`upsertSubjectMapping()` rejects:

- a source `SubjectOffering` where `is_required = true`;
- a non-null target `SubjectOffering` where `is_required = true`;

both via `RequiredSubjectOfferingRolloverMappingException` — a
**distinct** exception from `RequiredSubjectOfferingEnrollmentException`
(which rejects an attempted Student *enrollment*, a different call path
with a different actor and corrective action). Required-offering
rosters remain always derived from the target `StudentEnrollment`
(`SubjectOfferingRosterReadService`), never explicitly mapped.

## 6. Configuration mutation surface

Extends the **existing** `App\Domain\Students\Application\EnrollmentRolloverPlanService`
— no new, parallel configuration aggregate/service:

```php
upsertSubjectMapping(
    EnrollmentRolloverPlan $plan,
    SubjectOffering $source,
    ?SubjectOffering $target,
    ?User $actor = null,
): EnrollmentRolloverSubjectMapping

removeSubjectMapping(
    EnrollmentRolloverPlan $plan,
    SubjectOffering $source,
    ?User $actor = null,
): void
```

The caller never supplies `school_id`, source year, or target year —
everything is derived from the authoritative `$plan`/`$source`/`$target`
models. `$target === null` in `upsertSubjectMapping()` is a valid,
distinct state (explicit omit), never "unconfigured".
`removeSubjectMapping()` is the only way back to unconfigured — it
deletes the row entirely, distinct from an omit row.

Both methods:

- call the SAME shared `assertConfigurable()` guard every other
  configuration mutation on this service uses (`draft`/`validated`
  Plan status only — `RolloverPlanNoLongerConfigurableException`
  otherwise);
- validate same-School (`CrossSchoolSubjectMappingException`) before
  any write;
- lock the Plan row (`lockForUpdate()`) inside a `DB::transaction()`,
  matching `upsertMapping()`/`setItemDecision()`'s exact concurrency
  pattern;
- bump the SAME `configuration_version` column, in the SAME
  transaction as the write, on a real change — never a second,
  parallel version counter. A real change also implicitly invalidates
  `validated_configuration_version` (no explicit nulling needed — see
  `EnrollmentRolloverPlan::isValidatedForCurrentConfiguration()`).

## 7. Deliberately NOT validated in this checkpoint

Per the accepted architecture, source and target `SubjectOffering` are
**never** required to share Campus, GradeLevel, or ElectiveGroup. A
rollover plan may legitimately promote a Grade or change Campus/
electives across the rollover. Per-Student target compatibility against
the Student's actual resolved target `StudentEnrollment` is deferred
entirely to Phase 1G.2's dry-run — this checkpoint's mutation service
checks only same-School, source/target year membership, and the
elective-only rule (§4/§5).

Source-Offering *status* (active/inactive) is likewise not checked here
— source-year configuration may reference historical data; target
Offering activity is a dry-run-time concern (Phase 1G.2), not a
configuration-time block.

## 8. Idempotency

Both mutation methods are idempotent by design — deliberately
**stronger** than `upsertMapping()`'s own always-bump precedent for
Grade/Section mapping, because this checkpoint's brief explicitly calls
for a true no-op here:

- `upsertSubjectMapping()` with the identical target (including
  omit → omit) makes no write, bumps no `configuration_version`, and
  records no audit event.
- `removeSubjectMapping()` on an already-unconfigured source Offering
  is a no-op — no version bump, no audit.
- Any REAL change (mapped → different target, mapped → omit,
  omit → mapped, or removal of an existing row) always bumps the
  version and writes exactly one audit event.
- A failed mutation (cross-School, wrong year, required offering, or a
  no-longer-configurable Plan) writes no row, bumps no version, and
  records no audit event — proven transactionally in
  `EnrollmentRolloverSubjectMappingServiceTest::a_failed_mutation_creates_no_row_bumps_no_version_and_writes_no_audit`.

## 9. Audit

Reuses the existing `enrollment_rollover_plan.configuration_changed`
event type (the SAME one `upsertMapping()`/`setItemDecision()` already
use), with a `change` metadata discriminator:

```
change: 'subject_mapping'          upsertSubjectMapping()
change: 'subject_mapping_removed'  removeSubjectMapping()
```

Metadata carries only: `change`, `subjectMappingId`,
`sourceSubjectOfferingId`, `targetSubjectOfferingId` (present, possibly
null, only for the `subject_mapping` change). No Student PII —
explicit omit is represented purely by `targetSubjectOfferingId: null`,
no free-text reason.

## 10. Migration rollback

Proven via exact single-file `--path` targeting (not a bare
`--step=N`), per the migration-ordering hazard already documented for
this feature lineage:

```
php artisan migrate:rollback --database=pgsql_admin \
  --path=database/migrations/2026_09_03_090100_create_enrollment_rollover_subject_mappings_table.php --force

php artisan migrate --database=pgsql_admin \
  --path=database/migrations/2026_09_03_090100_create_enrollment_rollover_subject_mappings_table.php --force
```

Both proved clean against the Phase 1G-isolated PostgreSQL instance —
fresh migrate, precise single-file rollback, and re-migrate all PASS.

## 11. A note on cross-connection integrity tests

`Tests\Feature\Postgres\EnrollmentRolloverIntegrityTest`'s existing
pattern (build fixtures via the ordinary `pgsql` Eloquent connection —
inside the per-test wrapping transaction `Tests\TestCase`'s
`DatabaseTransactions` never commits — then attempt a deliberately
cross-School raw insert via the unwrapped `pgsql_admin` connection to
prove a composite-FK rejection) works safely only when the row being
checked is a genuine mismatch: PostgreSQL resolves a foreign-key check
against an uncommitted row from another session without blocking,
whether or not the key matches. It does **not** extend safely to
proving a *unique-constraint* conflict this way — a unique-index
insert that finds a same-key row still in another session's
uncommitted transaction must wait for that transaction's outcome
before it can decide whether to raise the violation, and since the
`pgsql` wrapping transaction never resolves until test teardown, that
wait is permanent. The `(plan_id, source_subject_offering_id)`
uniqueness violation is instead proven at the Eloquent/same-connection
level in `EnrollmentRolloverSubjectMappingServiceTest`. The two
delete-policy tests (cascade, restrict) also had to move off
`pgsql_admin` for the same underlying reason — `pgsql_admin` cannot see
`pgsql`'s uncommitted fixture rows at all, which would make a
`pgsql_admin`-issued restrict/cascade check silently look "successful"
regardless of whether the real constraint fired; both now delete
through the ordinary `pgsql` connection (via
`TenantContext::withSchool()`), the same connection/session the
fixtures were created on, matching how the real app role would perform
the operation anyway.

## 12. Deferred to later checkpoints

- **Phase 1G.2 — Dry-Run Integration**: extends
  `EnrollmentRolloverDryRunService::run()` with the new
  `missing_subject_mapping`/`elective_target_inactive`/
  `elective_target_required`/`elective_target_context_mismatch`/
  `elective_target_group_conflict`/`elective_target_existing_conflict`/
  `legacy_source_anchor_ambiguous`/`elective_already_enrolled_match`/
  `elective_explicitly_omitted` reason codes. Not implemented here.
- **Phase 1G.3 — Item Execution & Concurrency**: extends
  `EnrollmentRolloverItemExecutionService::execute()` to actually call
  `StudentSubjectEnrollmentService::enroll()` per resolved mapping,
  inside the same transaction as placement. Not implemented here.
- **Phase 1G.4 — Rollover API/UI Integration**: extends the existing
  Phase 1B.7E/1B.7F rollover API/UI surface. Not implemented here.
- **Legacy null-anchor rule (recorded, not implemented)**: a legacy
  active `StudentSubjectEnrollment` with `student_enrollment_id = NULL`
  may be associated with a rollover Item only if (1) exactly one
  candidate `StudentEnrollment` exists for the Student/source year,
  (2) that candidate is academically compatible with the source
  `SubjectOffering`, and (3) that candidate is exactly the Item's
  `source_enrollment_id`. Otherwise: `legacy_source_anchor_ambiguous`.
  Never inferred by name/date/order. This is a Phase 1G.2 dry-run
  concern — no resolution logic exists in this checkpoint.

## 13. No new capability, no domain-map change

Reuses the existing `enrollments.rollovers.view`/
`enrollments.rollovers.manage` pair unchanged — this checkpoint adds no
route/controller, so no capability check exists yet either (that
arrives with Phase 1G.4's API). `docs/architecture/DOMAIN-MAP.md`'s
Students/SIS row is unchanged — this checkpoint introduces no new
top-level aggregate or cross-module dependency.
