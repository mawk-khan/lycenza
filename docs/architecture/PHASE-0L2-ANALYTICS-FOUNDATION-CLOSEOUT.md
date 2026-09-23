# Phase 0L.2 — Analytics Foundation: Closeout

**Status: COMPLETE (2026-09-23)** as a **non-person** Analytics
Foundation with one source. **Person-counting Analytics remains
BLOCKED** (section 7). Closing this phase does not approve, schedule or
imply any person-counting report, export, cross-School Analytics, or a
minimum cohort size.

Roadmap entry: `docs/roadmap/MASTER-ROADMAP.md`, "Phase 0L — Oversight".
Contract: ADR 0040 (`docs/architecture/adr/0040-analytics-domain-contract.md`)
and its 2026-09-23 amendment. As built: `docs/modules/ANALYTICS.md` §13.
Open policy decisions: `docs/security/ANALYTICS-SMALL-COHORT-POLICY-GATE.md` §7.

## 1. Publication history

| Step | Published on `main` | Content |
|---|---|---|
| Phase 0L.1 — Analytics Domain Contract | `2a0d14c` (ADR renumbered to 0040 in `09ce0c1`) | ADR 0040, `ANALYTICS.md`; architecture only |
| Phase 0L.2 readiness & small-cohort policy gate | `0f05fcb` | `ANALYTICS-SMALL-COHORT-POLICY-GATE.md`; no code |
| Phase 0L.2-1 — Analytics Foundation + Curriculum Coverage | `1cf2a5f` | `App\Domain\Analytics`, capabilities, the Curriculum Coverage report |
| Full regression checkpoint after 0L.2-1 | `8eb5b74` | Test-environment fixes; Section/teacher privacy note (`ANALYTICS.md` §13). 5,379 tests, 0 failed, 1 deliberate legal skip (ESI-12), real MinIO |
| Phase 0L.2 closeout | this document | Documentation only; no code change |

## 2. What the contract requires of Phase 0L.2

The only repository definition of Phase 0L.2 is ADR 0040's Consequences:
*"Phase 0L.2 (Analytics Foundation — a real, minimal, capability-gated,
single-School read surface against **one or two** already-stable Layer
0–4 sources) may proceed once this contract exists, subject to decision
6's small-cohort threshold gate for any read model that could produce a
small-cohort cell"*, plus ADR 0040's decisions that name Phase 0L.2
(§5 capability seeding "at implementation time (Phase 0L.2)", §7 "one
thin, purpose-built, capability-gated read model + dashboard per
concrete need", §8 "not a Phase 0L.1 or Phase 0L.2 deliverable").

Precise answers:

| Question | Answer | Source wording |
|---|---|---|
| Exactly one source required? | No — **one or two** | ADR 0040 Consequences |
| Is one source sufficient? | **Yes** | "one or two"; gate doc §8 0L.2-5: "second source only if 7.6 chose two" — 7.6 chose one (Curriculum Delivery) |
| Two sources required before closing? | **No** | nothing requires a second source |
| Export required before closing? | **No** — optional | gate doc §3: "Only if in chosen scope (7.6)"; §8 0L.2-4: "Export (if in scope)"; owner's 7.6 decision: no export |
| Person-counting Analytics required before closing? | **No** — conditional and blocked | ADR 0040 Consequences: "subject to decision 6's small-cohort threshold gate for any read model that could produce a small-cohort cell"; amendment item 2 |
| Any other mandatory item missing? | **No** | matrix below |

## 3. Completeness matrix

| Phase 0L.2 requirement | Source | Status | Evidence |
|---|---|---|---|
| Internal Layer 5 bounded context `App\Domain\Analytics`, no write path | ADR 0040 §1 | COMPLETE | `app/Domain/Analytics/Application/*`; no migration, model or mutation; `AnalyticsArchitectureGuardTest` |
| Layers 0–4 never depend on Analytics | ADR 0040 §1; DOMAIN-MAP rule 2 | COMPLETE | `AnalyticsArchitectureGuardTest` (no Layer 0–4 reference to Analytics) |
| Derived values only, computed at read time | ADR 0040 §2 | COMPLETE | `CurriculumCoverageReadModel::compute()` — no stored copy |
| Source consumed only through the source module's own Application read contract | ADR 0040 §3 | COMPLETE | `App\Domain\CurriculumDelivery\Application\CurriculumCoverageReadService` (Curriculum Delivery's own module); `analytics_reads_source_modules_only_through_their_application_contracts` |
| Only stable, published sources; incomplete metrics omitted | ADR 0040 §3 | COMPLETE | Curriculum Delivery published in Phase 0H.3B; no examination-performance metric (StudentMark not implemented) |
| Absent source → surface not registered | ADR 0040 §3 | SATISFIED | Curriculum Delivery is part of the monolith and always present; the closed `AnalyticsReadModelRegistry` refuses anything not listed (503) |
| Single-School only; no `BYPASSRLS`, no cross-School path | ADR 0040 §4 | COMPLETE | `AnalyticsReadGate` computes inside `TenantContext::withSchool()` for the trusted session School; source service filters by School id under SchoolScope + RLS; `analytics_has_no_cache_snapshot_outbox_or_cross_school_path`; `one_school_never_sees_another_schools_coverage`; `a_multi_school_member_sees_only_the_selected_school` |
| `analytics.view` / `analytics.export` seeded at implementation | ADR 0040 §5 | COMPLETE | `CapabilityAndRoleSeeder`; `AnalyticsCapabilityRegistryTest` |
| `analytics.view` granted per owner decision 7.5 | Amendment item 4 | COMPLETE | `school_admin`, `principal` only (`only_school_admin_and_principal_system_roles_receive_analytics_view`) |
| `analytics.export` granted to no role; no export | Amendment item 4 | COMPLETE | `no_system_role_receives_analytics_export`; no export route (`the_analytics_route_surface_is_exactly_one_read_only_page`) |
| `analytics.platform.view` not seeded | ADR 0040 §4, §5 | COMPLETE | `cross_school_analytics_is_not_seeded` |
| Source access ≠ analytics access (both directions) | ADR 0040 §5; AUTHORIZATION.md | COMPLETE | gate checks `analytics.view` only; `every_other_actor_is_refused`; no drill-down exists |
| Classification by tier inheritance; audit of Sensitive/Highly Sensitive reads | ADR 0040 §6; ADR 0017 | COMPLETE | `ReadModelDeclaration` tier; Curriculum Coverage = Confidential (both sources Confidential) → not access-audited; `sensitive_and_highly_sensitive_reads_are_audited_confidential_reads_are_not` |
| Small-cohort gate for person-counting cells | ADR 0040 §6 + amendment items 1–2 | COMPLETE (fail closed) | `CohortSuppressionPolicy` (503 while unset, before any source read); `config/analytics.php` has no default; `no_registered_read_model_counts_people_while_the_cohort_policy_is_undecided`; `the_shipped_configuration_has_no_minimum_person_cohort_size` |
| Only declared read models execute; undeclared filters refused | gate doc §6.2; amendment item 1 | COMPLETE | `an_unregistered_read_model_is_refused`; `undeclared_filters_are_refused_before_compute`; `read_models_are_executed_only_through_the_gate` |
| One thin read model + dashboard per concrete need; no generic engine | ADR 0040 §7 | COMPLETE | `CurriculumCoverageReadModel` + `CurriculumCoverageController` (thin) + `App/Analytics/CurriculumCoverage` Inertia page |
| No warehouse/ETL/BI/materialization/cache | ADR 0040 §7, §10 | COMPLETE | architecture guard; no Analytics table |
| No outbox consumer | ADR 0040 §8 | COMPLETE | architecture guard |
| No snapshots; current-state reporting only | ADR 0040 §9 | COMPLETE | live query per request; only filter is `academic_year_id` |
| Tests at the required layers (allow + deny, tenancy) | CLAUDE.md rules 13, 28 | COMPLETE | section 5 |
| Reviewable in DDEV | CLAUDE.md rule 82 | COMPLETE | `docs/development/DDEV-DEMO-REVIEW.md` section 12 step 14 and the feature table |
| Export | ADR 0040 §1, §5; gate doc §8 0L.2-4 | NOT IN SCOPE (optional; deferred) | section 6 |
| Second source | ADR 0040 Consequences ("one or two") | NOT REQUIRED | section 2 |
| Person-counting / Student-data Analytics | ADR 0040 §6; amendment items 2, 5 | BLOCKED (future gate, not a 0L.2 closure requirement) | section 7 |

The gate document's §8 proposed five sub-checkpoints. As built, Phase
0L.2-1 delivered the proposed 0L.2-1 (policy and capabilities), 0L.2-2
(source read contract) and 0L.2-3 (read model and page) together for the
one non-person source. The proposed 0L.2-4 (export) was not chosen
(decision 7.6). This document is the proposed 0L.2-5 (closeout), with
no second source because 7.6 chose one.

## 4. As built (summary)

- **Module:** `App\Domain\Analytics\Application` — `AnalyticsReadGate`
  (the only execution path: `analytics.view` 403 → cohort policy 503 →
  registry 503 → closed filters → `withSchool()` → compute → tier-based
  audit), `AnalyticsReadModel`, `ReadModelDeclaration`,
  `ClassificationTier`, `CohortSuppressionPolicy`,
  `AnalyticsReadModelRegistry`, `AnalyticsReportUnavailableException`.
- **Source boundary:** Curriculum Delivery owns
  `CurriculumCoverageReadService` and its `Coverage\*` DTOs (unit counts
  only, no dates, no person field). Analytics never touches
  `curriculum_deliveries` or `syllabus_units`.
- **First read model:** `curriculum.coverage` — Confidential,
  `countsPeople: false`, filter `academic_year_id` (this School's years
  only). Planned / completed / in progress / not started unit × Section
  pairs and coverage % (integer arithmetic, one decimal, half up) by
  School, grade level, Subject Offering and Section.
- **Surface:** `GET /app/analytics/curriculum-coverage`, session
  authenticated; Dashboard link shown only with `analytics.view`. No API,
  no OpenAPI path, no export, no cache, no snapshot, no outbox consumer,
  no migration.
- **Section and teacher privacy:** the report and its source carry no
  teacher identity, the roles holding `analytics.view` already see the
  same Section-level progress through `curriculum.delivery.view`, and
  nothing ranks or compares teachers. Linking Section coverage to a
  named teacher, teacher performance, a ranking or staff evaluation
  requires a new privacy/data-classification review first
  (`ANALYTICS.md` §13).

## 5. Tests

| Layer | Tests |
|---|---|
| Architecture/boundary | `Tests\Feature\Analytics\AnalyticsArchitectureGuardTest` |
| Capabilities | `Tests\Feature\Analytics\AnalyticsCapabilityRegistryTest` |
| Gate: authorization, fail-closed policy, registry, filters, tenancy, audit | `Tests\Feature\Analytics\AnalyticsReadGateTest` |
| Cohort policy | `Tests\Feature\Analytics\CohortSuppressionPolicyTest` |
| Read model | `Tests\Feature\Analytics\CurriculumCoverageReadModelTest` |
| Source contract incl. cross-School isolation | `Tests\Feature\CurriculumDelivery\CurriculumCoverageReadServiceTest` |
| UI allow/deny, guest, multi-School, filter validation, Dashboard link | `Tests\Feature\App\CurriculumCoverageAnalyticsUiTest` |

All passed in the full regression at `8eb5b74` (section 1). Analytics
owns no table, so there is no Analytics RLS test; its only data access
is the source contract, which runs under the source tables' existing
RLS.

## 6. Intentionally absent

- **Export.** `analytics.export` is seeded and granted to no role; no
  export route, job or file exists. Export is optional in the contract
  and was not chosen for this foundation (decision 7.6). A future export
  checkpoint must follow the gate document §6.8 (suppressed output only,
  `analytics.export`, audited) and is not scheduled by this closeout.
- **Second source**, a JSON API/OpenAPI path, drill-down, caching,
  snapshots, outbox consumers, scheduled reports, a generic reporting
  engine.
- **Cross-School/platform Analytics** (`analytics.platform.view`
  unseeded; needs its own ADR, ADR 0040 §4).
- **Compliance and Automation** (separate Layer 5 contracts).

## 7. Future gates — not Phase 0L.2 closure requirements

Phase 0L.2 closes with these decisions still **open**. They block future
person-counting Analytics; they do not block this non-person
foundation, because the contract only requires that such reports fail
closed, which they do.

| Gate | Status | Blocks |
|---|---|---|
| 7.1 Minimum person-cohort size | OPEN — no value approved; `config/analytics.php` has no default | every person-counting read model |
| 7.2 Suppression mode and granularity (below-threshold display) | OPEN | same; no per-cell/complementary suppression is implemented |
| 7.3 Policy scope (platform-wide vs. School-stricter) | OPEN | same |
| 7.4 Counsel review for Student-data Analytics | OPEN | any Student-data source reaching real Schools |
| Cross-School Analytics ADR | NOT STARTED | `analytics.platform.view` |
| Section coverage ↔ teacher linkage | NOT PROPOSED | requires a new privacy/data-classification review first |

"Phase 0L.2 is complete" and "person-counting Analytics remains blocked"
are both true. Registering the first person-counting read model is a
new, separately gated checkpoint: it needs 7.1–7.3 (and 7.4 for Student
data), its own suppression implementation, and a change to
`AnalyticsArchitectureGuardTest`.

## 8. DDEV smoke (closeout, 2026-09-23)

After `ddev demo-reset --build`, going through the normal login and
School-activation flow:

| Persona | Result |
|---|---|
| `school.admin@example.test`, `principal@example.test` | 200; Dashboard link shown; 36.7% coverage (33 of 90 unit × Section pairs, 12 in progress, 18 Offerings); no person keys in the payload |
| `teacher@`, `finance.officer@`, `hr.payroll@example.test` | 403 |
| `annexe.admin@example.test` | 200 with the Annexe's own empty report; no Demo School identifiers |

No failed jobs. The closeout also corrected two capability counts in
`docs/development/DDEV-DEMO-REVIEW.md` that predated `analytics.view`
(School Admin 109, Principal 79, as seeded).

## 9. Publication

Documentation-only closeout on branch
`feature/phase-0l2-analytics-foundation-closeout`, published through the
normal `integration/*` gate. No code, configuration, migration, seeder or
test changed. Counted as unit 1 since the full regression checkpoint at
`8eb5b74`.
