# ADR 0035 — GradeScale / GradeBand percentage-to-grade mapping

**Status:** Accepted (Phase 0H.4C)

Supersedes ADR 0032 §"Provisional future sequence" row "0H.4C —
GradeScale," which is now RATIFIED / implemented rather than
PROVISIONAL. ADR 0032 itself is not rewritten.

## Context

ADR 0032 anticipated GradeScale as a later, independent checkpoint:
*"a School-owned percentage-to-grade-label mapping... has no dependency
on the Examination chain and could ship in parallel."* This ADR is that
checkpoint, following a read-only architecture gate and a subsequent
correction gate that closed several open design questions before any
code was written. It implements exactly the corrected design, no more.

## Decision

### 1. The fact: an ordered set of lower-bound percentage thresholds

> **GradeScale — a named, School-owned mapping that converts a
> normalized percentage (0.00–100.00) into a discrete grade outcome
> through its ordered GradeBands.**

A GradeScale is standalone reference data: no AcademicYear, no
Examination/ExaminationPaper, no GradeLevel, no Subject relationship of
any kind. `docs/architecture/adr/0032-examinations-decomposition-and-foundation-fact.md`
already anticipated this independence explicitly.

Each GradeBand stores ONLY a lower-bound threshold (`min_percentage`)
and a `label` — deliberately no upper bound and no sequence column. A
percentage `P` maps to the band with the greatest `min_percentage <=
P`. This is the single design choice that eliminates the need for
PostgreSQL range types, exclusion constraints, or `btree_gist` — a
class of machinery this codebase has already twice rejected as
premature complexity (for `academic_years` and `employment_records`
overlap concerns). With thresholds-only:

- **No overlap invariant to enforce** — a plain `UNIQUE
  (grade_scale_id, min_percentage)` constraint is sufficient, since two
  distinct bands can never claim the same floor.
- **No gap invariant to compute** — the domain is provably covered
  end-to-end (0.00–100.00) if and only if a band exists at
  `min_percentage = 0.00`. This single check is simultaneously the
  coverage check and the gap-freedom check; no separate algorithm
  exists or is needed.

### 2. Lifecycle: `draft | active | inactive`, exactly three legal transitions

`draft -> active`, `active -> inactive`, `inactive -> active`. Every
other transition — including every no-op (`draft -> draft`, `active ->
active`, `inactive -> inactive`) — is illegal, mirroring
`CurriculumDeliveryService`'s `NoOpTransitionException` precedent of
rejecting a no-op rather than silently succeeding. `inactive` is
reachable ONLY via `active`, so it structurally means "this scale was
previously active" — no separate `ever_activated` column is needed.

A scale may transition to `active` only if it has a GradeBand at the
`0.00` threshold (the completeness/coverage/gap-freedom check from §1).
GradeBands may be created, updated, or deleted ONLY while the parent is
`draft`. Once a scale has ever been `active`, its bands are frozen
forever, including while later `inactive` — a grading-policy change is
represented by creating a NEW GradeScale, never by rewriting bands on
one that has ever been active. This is what makes an active or
previously-active GradeScale a permanently stable historical reference,
safe for a future Result to hold a real foreign key against.

`code` is immutable after creation (never accepted by the update
contract); `name` may be changed at any lifecycle stage, since it
carries no invariant.

### 3. Concurrency: parent-row `lockForUpdate()`, not `TenantLock`

The read-only architecture gate's first recommendation — a School-wide
`TenantLock::forSchool()` around activation — was corrected during the
architecture correction gate: `TenantLock` is scoped too broadly (it
would needlessly serialize unrelated GradeScales within the same
School against each other) and, more importantly, does nothing to
serialize a concurrent, *unlocked* GradeBand mutation racing the same
activation.

The adopted protocol: every mutating `GradeScaleService` method that
targets an EXISTING GradeScale reloads it with
`GradeScale::query()->whereKey($id)->lockForUpdate()->firstOrFail()`
inside `DB::transaction()`, before checking status or mutating
anything. This is a genuine PostgreSQL row lock scoped to exactly one
GradeScale aggregate — the same mechanism
`CurriculumDeliveryService::transition()` already established as this
codebase's precedent for aggregate-local CAS, reused here rather than
reinvented. No database trigger backstops this; the row-lock protocol,
`GradeScaleService`'s sole-write-path status, and
`GradeScaleArchitectureGuardTest` together are the sanctioned
protection.

Proven with two REAL, separate OS processes (`Symfony\Component\Process\Process`
spawning independent `php` CLI invocations against real PostgreSQL, not
a sequential simulation) in `Tests\Feature\Examinations\GradeScaleConcurrencyTest`:

- **Scenario A** — one process activates a scale while a second
  concurrently removes its `0.00` GradeBand. Whichever operation's
  transaction commits first determines the outcome; the loser is
  refused (`GradeBandNotMutableException` if activation won,
  `GradeScaleIncompleteException` if the removal won). The single
  outcome proven impossible in every run: an active GradeScale with no
  `0.00` GradeBand.
- **Scenario B** — two processes concurrently attempt to activate the
  same draft scale. Exactly one succeeds; the other observes the
  now-active scale and is refused as an illegal/no-op transition.
  Exactly one `examinations.grade_scale.activated` audit event exists
  afterward.

### 4. Schema: two tables, no new machinery

`grade_scales`: `id`, `school_id`, `code`, `name`, `status`,
timestamps. `unique(id, school_id)` (tenant-pinning precedent for
future children). `grade_scales_status_check` CHECK constraint.
Case-insensitive code uniqueness via an unconditional expression index
`grade_scales_school_id_code_ci_unique` on `(school_id, upper(code))` —
mirroring `examinations_year_code_ci_unique` exactly, including
remaining unconditional across statuses (an inactive scale still
reserves its code).

`grade_bands`: `id`, `school_id`, `grade_scale_id`, `min_percentage`
(`DECIMAL(5,2)`), `label`, timestamps. `unique(id, school_id)`.
`unique(grade_scale_id, min_percentage)` (the overlap-freedom
guarantee from §1). Composite FK `grade_bands_grade_scale_fk` on
`(grade_scale_id, school_id)` → `grade_scales(id, school_id)`
`RESTRICT` — the standard tenant-pinned-parent pattern, preventing both
cross-tenant references and hard deletion of a GradeScale that still
carries bands. `grade_bands_min_percentage_range_check` CHECK
constraint (`min_percentage >= 0 AND <= 100`).

No range type, no exclusion constraint, no `btree_gist`, no database
trigger. Both tables use `App\Support\Tenancy\TenantRls` exactly like
every other tenant-owned table.

### 5. Audit: bounded metadata, School-authored values excluded by rule

`examinations.grade_scale.created`, `.activated` (covers BOTH first
activation and reactivation, distinguished by a `priorStatus` field —
no separate `.reactivated` action), and `.updated` (name changes, band
additions/removals, and deactivation all fold into this one action,
distinguished by `changedFields`).

Binding privacy rule from the architecture correction gate, now fully
implemented: **GradeBand `label` values and GradeScale `name` values
are never included by value in audit metadata** — a mutation is
recorded by naming the changed field (`changedFields: ['name']` or
`['bands']`), never by including the School-authored text itself.
Status VALUES remain safe to audit (a bounded, closed enum), so
`before`/`after` status snapshots ARE included for ordinary updates and
deactivation.

### 6. API and web surface: exactly seven operations, one delete route

`GET/POST /schools/{schoolId}/grade-scales`, `GET/PATCH
/schools/{schoolId}/grade-scales/{gradeScaleId}`, `POST/PATCH/DELETE
.../bands[/{gradeBandId}]` — seven operations, proven exactly by
`GradeScaleArchitectureGuardTest::the_registered_route_surface_is_exactly_the_sanctioned_one`
and cross-checked against the OpenAPI contract by
`GradeScaleOpenApiCoverageTest`. There is no GradeScale DELETE route;
GradeBand removal is the sole delete anywhere in the surface, itself
rejected by the service unless the parent is `draft`. `status` is never
a raw mass-assignable field on the update contract — it is always
interpreted by `GradeScaleService` as a guarded lifecycle transition
request.

## Consequences

- StudentMark (and any future Result/report-card feature) can proceed
  to reference `grade_bands`/`grade_scales` as a stable, School-owned
  classification once its own legal-review blocker clears — GradeScale
  itself carries no Student/Enrollment/marks data and shipped fully
  independently of that blocker.
- The threshold-only design permanently forecloses "gap warnings" or
  "overlap visualizations" as anything other than a UI convenience
  derived from the same single completeness check — there is no richer
  server-side representation to build such a feature on without a
  schema change.
- A grading-policy change is always a NEW GradeScale, never an edit to
  a scale that has ever been active. Consumers referencing a
  GradeScale by id therefore see a permanently stable definition.

## Alternatives considered (and rejected)

- **`TenantLock::forSchool()` for activation** — rejected during the
  architecture correction gate: wrong scope (School-wide instead of
  aggregate-local) and insufficient (does not serialize a concurrent,
  unlocked band mutation). See §3.
- **PostgreSQL range types / exclusion constraints / `btree_gist`** —
  rejected for the same reason this codebase already rejected them
  twice elsewhere: the threshold-only representation makes the
  overlap/gap invariants reducible to a plain `UNIQUE` constraint and a
  single existence check, so the added machinery would buy nothing.
- **A database trigger enforcing band immutability post-activation** —
  rejected; the row-lock protocol plus `GradeScaleService`'s
  sole-write-path status plus the architecture guard test together are
  sufficient, and this codebase has no precedent for enforcing
  application-level state-machine rules via triggers.
- **A separate `.reactivated` audit action** — rejected; `priorStatus`
  on the single `.activated` action already distinguishes first
  activation from reactivation without doubling the action vocabulary.
