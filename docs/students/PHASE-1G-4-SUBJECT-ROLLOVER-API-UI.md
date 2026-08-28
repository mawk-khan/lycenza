# Phase 1G.4 — Subject Rollover API & Existing Rollover UI Integration

> Extends the existing Phase 1B.7E administrative JSON API and Phase
> 1B.7F rollover Plan workspace UI to configure and inspect the
> explicit elective subject mappings introduced in
> `docs/students/PHASE-1G-1-SUBJECT-ROLLOVER-MAPPING-FOUNDATION.md`,
> validated in
> `docs/students/PHASE-1G-2-SUBJECT-ROLLOVER-DRY-RUN.md`, and executed
> in `docs/students/PHASE-1G-3-SUBJECT-ROLLOVER-EXECUTION.md`. No new
> schema, no new capability, no parallel workflow — this checkpoint is
> presentation only. General Student elective management, ElectiveGroup
> administration UI, and roster-management UI remain out of scope
> (Phase 1H+).

## 1. API routes

Both added directly under the existing `enrollment-rollovers/{rollover}`
resource hierarchy in `routes/api.php` (JSON API,
`App\Domain\Students\Http\Controllers\EnrollmentRolloverSubjectMappingController`)
and `routes/web.php` (Inertia, `App\Http\Controllers\App\EnrollmentRolloverSubjectMappingController`):

```
PUT    .../enrollment-rollovers/{rollover}/subject-mappings/{subjectOffering}
DELETE .../enrollment-rollovers/{rollover}/subject-mappings/{subjectOffering}
```

No `/subject-rollovers` (or any other) parallel top-level resource
exists. `{subjectOffering}` is the SOURCE SubjectOffering's own id —
the natural (Plan, source Offering) identity that
`enrollment_rollover_subject_mappings`' own uniqueness already
establishes (Phase 1G.1) — never an internal mapping row UUID the
client would otherwise have to look up first.

Reading the mapping list, the discovery ("needs configuration") list,
and the source/target Offering picker options remains embedded in the
existing Plan detail/show response — no separate list/search endpoint
was added, mirroring the existing Grade/Section mapping's identical
"whole thing embedded in Plan detail" convention rather than inventing
a live-search architecture.

## 2. Authorization

Both mutation routes carry the SAME dual `capability:` middleware
every other rollover mutation route already requires:
`enrollments.manage` AND `enrollments.rollovers.manage`. Reads
(embedded in Plan detail) authorize `enrollments.view` AND
`enrollments.rollovers.view` inline, matching
`EnrollmentRolloverController::show()`'s identical pattern. **No new
capability was created** — no `enrollments.rollovers.subjects.*`, and
no academic-domain capability (`academics.subjects.*`) is ever
required merely because SubjectOfferings are being selected. This was
verified directly: `EnrollmentRolloverSubjectMappingApiTest::no_academic_capability_is_ever_required`
grants ONLY the rollover capabilities and successfully upserts a
mapping.

## 3. Three-state API semantics

| HTTP call | Body | Meaning |
| --- | --- | --- |
| `PUT .../subject-mappings/{source}` | `target_subject_offering_id: <uuid>` | Explicit MAP |
| `PUT .../subject-mappings/{source}` | `target_subject_offering_id: null` | Explicit OMIT |
| `DELETE .../subject-mappings/{source}` | — | UNCONFIGURED (row deleted) |

`target_subject_offering_id: null` and `DELETE` are never conflated —
the former is a durable, deliberate "do not carry forward" record; the
latter removes all trace of a decision. Both routes return a
`RolloverSubjectMappingMutationResult` envelope (source Offering,
current `state`, target Offering if any, and the Plan's own
`configurationVersion`/`validatedConfigurationVersion`/
`isValidatedForCurrentConfiguration`) so the UI can update in place
without a full reload.

## 4. Controller/service boundary

Both controllers (API and App) are thin: request-shape validation only
(`$request->validate()`, inline `Rule::exists(...)->where('school_id', ...)`
for the body-supplied target id), then a single call to
`EnrollmentRolloverPlanService::upsertSubjectMapping()`/
`removeSubjectMapping()` — the exact Phase 1G.1 methods, unchanged. No
controller ever mutates a mapping model directly, bumps
`configuration_version` itself, or re-derives year/group validation.
The route path parameter (`{subjectOffering}`, the SOURCE id) is
resolved tenant-safely through the ambient `SchoolScope`
(`SubjectOffering::query()->findOrFail()`) — a foreign-School id 404s
identically to a random uuid, exactly like
`EnrollmentRolloverMappingController::update()`'s `{mapping}`
resolution; the body-supplied target id 422s via `Rule::exists()`,
matching how every other body-supplied cross-entity id in this
controller family is validated.

Domain exceptions (`RolloverPlanNoLongerConfigurableException`,
`CrossSchoolSubjectMappingException`, `InvalidSubjectMappingYearException`,
`RequiredSubjectOfferingRolloverMappingException`) already extend
`StudentException` and render automatically through the existing global
JSON error envelope for the API; the App/Inertia controller translates
the same set into `ValidationException` so `form.errors` renders them
inline, matching `EnrollmentRolloverMappingController` (App)'s
established pattern. No new error-mapping infrastructure was built.

DELETE deliberately carries no `idempotent` middleware —
`removeSubjectMapping()` is ITSELF a true no-op against an
already-unconfigured source Offering (Phase 1G.1), the same
natural-idempotency rationale already documented on
`DELETE /student-guardian-relationships/{relationship}`.

## 5. Read projection

`EnrollmentRolloverReadService::detail()` now eager-loads
`subjectMappings.sourceSubjectOffering`/`targetSubjectOffering` (with
their `subject`/`gradeLevel`/`campus`/`electiveGroup` relations). Both
Plan-detail presenters (API and App) add:

- `subjectMappings`: one entry per EXISTING mapping row (`mapped` or
  `omit` state — UNCONFIGURED is never represented here, since it is
  the absence of a row);
- `unmappedSourceSubjectOfferings`: the new
  `EnrollmentRolloverReadService::unmappedSourceSubjectOfferings()`
  bounded discovery adapter.

### 5.1 Discovery adapter

`unmappedSourceSubjectOfferings()` reads the Plan's own
`EnrollmentRolloverItem` rows (never re-implements candidate
eligibility), collects every active `StudentSubjectEnrollment` — both
normally anchored (`student_enrollment_id` in the Plan's Item source
ids) and legacy NULL-anchor rows for the Plan's source year — and
returns the DISTINCT elective (`is_required = false`) SubjectOfferings
among them that have NO row in `enrollment_rollover_subject_mappings`
yet. It is bounded by the number of DISTINCT elective SubjectOfferings
actually referenced (School reference-data cardinality, never Student-
row cardinality), issues no writes, and infers no desired target. It is
deliberately inclusive of ambiguous legacy rows — this is a "what needs
a decision" list, not a re-implementation of
`SubjectRolloverResolution`'s strict per-Item candidate proof; an
ambiguous legacy row still surfaces its source Offering here, and still
produces a correct `legacy_source_anchor_ambiguous` dry-run reason once
validated.

Because `EnrollmentRolloverItem` rows are only created by dry-run's own
population pass, a Plan's very first configuration pass (before any
dry-run has ever run) sees an empty discovery list — this is expected,
not a defect; the general "Add mapping" form (§7) covers configuring
ahead of the first dry-run.

## 6. Offering pickers

`App\Http\Controllers\App\EnrollmentRolloverController::show()` passes
two new bounded (`limit(100)`, matching the repo's existing default/max
picker convention), elective-only (`is_required = false`) option lists
as page props — `sourceSubjectOfferings` (Plan source year) and
`targetSubjectOfferings` (Plan target year) — mirroring
`sectionOptionsForYear()`'s exact shape (a whole-year list, not
search-as-you-type) rather than inventing a live-search endpoint. No
`academics.subjects.*` capability is required to render them — they
are gated by the SAME `enrollments.rollovers.view` capability as the
rest of the page, deliberately never reusing the unrelated
AcademicStructure `SubjectOfferingController::index()` endpoint (which
requires an academic capability this workflow must not need).

Both lists deliberately include INACTIVE Offerings (no `status`
filter) — exactly like `sectionOptionsForYear()` already does for
Sections — so a durable mapping's existing target remains inspectable
even after the Offering itself later became inactive; each option
carries its `status` so the Vue picker can render an inactive warning
without hiding it. This does not contradict backend semantics: a
mapping's validity is determined at dry-run time, not at configuration
time (Phase 1G.1 §16).

## 7. Plan workspace UI

`resources/js/Pages/App/EnrollmentRollovers/SubjectMappingsPanel.vue`
is a new component, added to the existing Plan workspace
(`Show.vue`) directly below the Grade/Section `MappingsPanel` — no new
top-level page, no new navigation entry. It renders three sections:

1. **Configured mappings** — one row per existing mapping, showing
   Mapped/Omit state and an inline Edit (change target/omit) plus a
   separate, visually distinct "Reset to unconfigured" action (calls
   `DELETE`) — never conflated with changing a mapped source to Omit
   (calls `PUT` with `target_subject_offering_id: null`).
2. **Needs configuration** — one row per `unmappedSourceSubjectOfferings`
   entry, with an inline "Configure" action offering the same
   Omit-or-Map choice.
3. **Add mapping** (manual) — lets an operator configure ANY elective
   source Offering not yet mapped, for the case where a Plan is being
   configured before its first dry-run (when the discovery list above
   is necessarily still empty).

The three-state choice is a single `<select>` per row: "Omit next year"
(a fixed sentinel, never confused with an empty/unset value) or "Map to
&lt;target&gt;" — "Not configured" has no select state at all, since it is
the absence of a row, matching the accepted three-state model exactly.
No state is distinguishable by color alone (text labels carry every
distinction).

A real mapping mutation is not followed by an automatic dry-run
trigger — the existing Plan workspace's "Run Validation" button and its
`isValidatedForCurrentConfiguration`/stale-validation messaging
(unchanged, generic across every kind of configuration change) already
make clear that revalidation is needed, exactly the same way a
Grade/Section mapping change already does. No subject-specific
staleness UI was added because none was needed.

## 8. Dry-run reason rendering

`resources/js/rolloverReasons.ts` gained the ten Phase 1G.2 reason
codes (`missing_subject_mapping`, `legacy_source_anchor_ambiguous`,
`elective_target_inactive`, `elective_target_required`,
`elective_target_context_mismatch`, `elective_target_group_conflict`,
`elective_target_existing_conflict`, `elective_target_duplicate_mapping`,
`elective_already_enrolled_match`, `elective_explicitly_omitted`) —
the EXACT codes implemented in
`App\Domain\Students\Application\SubjectRolloverResolution::REASON_PRECEDENCE`
and `EnrollmentRolloverDryRunService::SUBJECT_REASON_RESULT`, not the
illustrative names from the original 1G.0 architecture sketch.

No other rendering change was needed: `EnrollmentRolloverItem.validationReason`
is already a single free-form string rendered through
`rolloverReasonLabel()` by the existing `ItemsPanel.vue`
(`StatusBadge` for `validationResult`, the reason label beside it) —
subject-level reasons compose into the SAME field the placement-level
reasons already use, via `SubjectRolloverResolution::REASON_PRECEDENCE`'s
deterministic ranking, so blocking (`review`/`blocked` results) vs
non-blocking is already the backend's own classification the existing
UI already renders. The Vue layer never recomputes validity — it only
renders whatever `validationResult`/`validationReason` the server
returns. `elective_already_enrolled_match` and `elective_explicitly_omitted`
are non-blocking success paths that are, in the current implementation,
never actually persisted as a `validationReason` string (they only
ever cause `SubjectRolloverResolution::resolve()` to silently continue
past that source Offering) — their labels are included per this
checkpoint's brief for completeness/future-proofing, not because they
are reachable strings today.

## 9. Execution

No execution-specific UI or endpoint was added. The existing "Start
Rollover"/"Resume Rollover" controls and their
`executionSummary`/item-status rendering already reflect subject
rollover outcomes, because Phase 1G.3 composed subject-elective
application into the SAME per-Item execution the existing controls
already drive — there is no separate "run subject rollover" action, no
separate progress/cursor state.

## 10. OpenAPI / generated types

`packages/contracts/openapi/school-os-api.yaml` gained:

- the two new paths (`put`/`delete` on
  `.../subject-mappings/{subjectOffering}`);
- `RolloverSubjectOfferingRef`, `RolloverSubjectMapping`,
  `RolloverSubjectMappingUpsertInput`,
  `RolloverSubjectMappingMutationResult` schemas;
- `RolloverPlan.subjectMappings`/`unmappedSourceSubjectOfferings`
  fields.

`packages/shared-types/src/generated/school-os-api.ts` was regenerated
via the repository-standard `npm run generate`
(`openapi-typescript`) — never hand-edited. Regenerating a second time
in place produced a byte-identical file, confirming the generation
step is deterministic.

## 11. Security / privacy

- No `withoutGlobalScope(s)` anywhere in the new code — every query
  relies on the ambient `SchoolScope`/RLS exactly like the rest of the
  rollover controllers.
- No role-name authorization — both controllers use the
  `capability:`/`authorizeCapability()` boundary exclusively.
- No caller-supplied `school_id` is ever trusted — School always comes
  from the route binding (API) or `TenantContext` (App).
- No direct `EnrollmentRolloverSubjectMapping`/`configuration_version`
  mutation in a controller.
- No Student/Guardian PII anywhere in the new API/UI surface — Subject
  name/code, GradeLevel, Campus, ElectiveGroup, and Offering `status`
  are the only fields exposed, matching
  `docs/security/DATA-CLASSIFICATION.md`'s non-PII configuration-data
  classification.
- No controller-level audit event — `EnrollmentRolloverPlanService`
  already emits `enrollment_rollover_plan.configuration_changed` for
  every real mutation (Phase 1G.1); this checkpoint does not duplicate
  it.

## 12. Phase 1H boundary (explicitly out of scope here)

This checkpoint adds ONLY rollover-mapping configuration and
rollover-validation/execution inspection. It does NOT add: a Student
detail elective tab, manual elective enroll/withdraw/transfer UI,
general SubjectOffering roster-management UI, or ElectiveGroup
CRUD administration outside the rollover workflow. Those remain
Phase 1H+.

## 13. Testing

- `tests/Feature/StudentEnrollment/EnrollmentRolloverSubjectMappingApiTest.php` —
  authorization matrix (including "no academic capability required"),
  three-state upsert/delete semantics, idempotent no-op behavior,
  cross-School/cross-year/required-offering rejections, plan-isolation
  (same source Offering mapped under two different Plans never
  collides), the embedded read projection (`subjectMappings`/
  `unmappedSourceSubjectOfferings`), and a full configure → dry-run →
  execute end-to-end proof (zero academic writes during dry-run, exactly
  one active target `StudentSubjectEnrollment` after execution).
- `tests/Feature/App/EnrollmentRolloverSubjectMappingUiTest.php` —
  Inertia page props (no PII leakage), dual-capability enforcement on
  both mutation routes, cross-School 404, and that a real mutation
  bumps `configuration_version`/invalidates `isValidatedForCurrentConfiguration`
  exactly like every other rollover configuration change.

Full existing rollover regression (288 tests / 1181 assertions),
Phase 1F regression (160 tests / 324 assertions), roster/Communications
regression (40 tests / 120 assertions), and RLS/tenant-isolation
regression (121 tests / 273 assertions) all pass unmodified.
