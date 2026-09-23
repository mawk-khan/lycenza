# Analytics (Phase 0L.1)

**Status: architecture contract frozen (ADR 0040, 2026-09-05); zero
implementation.** No migration, model, controller, service, route,
capability, dashboard, export, or projection exists yet. This document
is the living operational reference for the Analytics bounded context;
the decision record is ADR 0040
(`docs/architecture/adr/0040-analytics-domain-contract.md`) — read that
first for the *why*, this document for the *what*, mirroring how
`ACADEMIC-STRUCTURE.md`/`HR.md`/`docs/modules/LMS.md` relate to their
own ADRs.

## 1. Module scope

`docs/architecture/DOMAIN-MAP.md` defines **Analytics** as Layer 5
("Oversight"): *"Cross-module reporting, dashboards | Reads from any
Layer 0–4 module | Same rule as Compliance."* `docs/roadmap/
MASTER-ROADMAP.md`'s Phase 0L groups Analytics with Compliance and
Automation as "read-mostly consumers... deliberately sequenced after
there's meaningful data/events for them to work with" — but each is a
separate checkpoint. **This document and ADR 0040 cover Analytics
only.**

| Layer | Name |
|---|---|
| Roadmap umbrella | Phase 0L — Oversight |
| This checkpoint | Phase 0L.1 — Analytics Domain Contract (architecture only) |
| Domain directory (planned) | `App\Domain\Analytics` |
| Capability namespace | `analytics.*` — `.view`/`.export` seeded in Phase 0L.2-1 (§13); `.platform.view` not seeded |

**In scope, eventually:** analytical reads, dashboards, reports,
derived metrics (counts/totals/averages/percentages/trends/grouped
statistics), and exports of those derived values — all single-School,
all synchronous/on-demand.

**Explicitly out of scope for Phase 0L.1, and for Phase 0L generally
absent their own future contracts:**
- Compliance (regulatory reporting, statutory record-keeping) — a
  separate Layer 5 module, its own future ADR.
- Automation (rules/workflow engine reacting to domain events) — a
  separate Layer 5 module, its own future ADR.
- Any migration, model, controller, service, route, capability seed,
  dashboard, Inertia page, OpenAPI path, shared-type generation,
  export, job, queue consumer, or outbox consumer.
- Cross-School/platform-wide Analytics of any kind.

**The governing invariant:**

> **Analytics never becomes a transactional system of record.** Every
> value it returns is derived, computed fresh from a source module's
> transactional data at read time. No source module ever reads back an
> Analytics-computed value to determine its own state, and Analytics
> has no mutation path to any business fact.

## 2. Terminology

| Term | Meaning |
|---|---|
| **Transactional source data** | The row(s) a module's own migration/model/Application service owns and mutates (e.g. `AttendanceRecord`, `charges`, `communication_deliveries`). The system of record. |
| **Derived reporting data** | A number or shape Analytics computes FROM transactional source data, at read time. No independent mutation path, no independent lifecycle. |
| **Operational query** | A query serving one business transaction's own UI/API need, inside the owning module, potentially at single-row granularity. Never Analytics' concern. |
| **Analytical query** | A cross-cutting, aggregate-shaped, read-only query computed across many rows (and possibly multiple modules) for a dashboard/report. Always read-only; never renders an operational workflow screen. |
| **Read model** | The Analytics-owned class computing one or more analytical queries against one or more source modules' read contracts — never a duplicate stored table. |
| **Cohort / cell** | The set of underlying rows an aggregate's specific grouping (e.g. one Section, one date range, one combination of dimensions) is computed over. The unit the small-cohort suppression policy (§6) applies to. |

## 3. Module-boundary rules

Analytics consumes each source module **only through that module's own
established Application-layer/read-service contract** —
`SubjectOfferingRosterReadService`, `StudentEnrollmentReadService`,
`ChargeReadService`, and so on — never another module's raw Eloquent
models or tables (`DOMAIN-MAP.md` rule 3). Where a needed read service
does not yet exist on the source module, adding it is that module's own
Application-layer work, reviewed under that module's own rules — not an
Analytics-side shortcut.

**Degraded/absent-source behavior:**
- **Source module unavailable/not installed** → the corresponding
  Analytics read surface is not registered (feature-detected at
  composition/route-registration time), never a per-request runtime
  exception.
- **Source feature unpublished** (on an unmerged branch) → Analytics is
  built only against what exists on the branch/environment it actually
  ships from; it never wires up a read path against an unpublished
  module's schema.
- **Metric depends on incomplete source-domain functionality** →
  Analytics surfaces only what the source domain stably and completely
  provides today; it never approximates or backfills a shape the source
  domain has not committed to.

## 4. Tenant boundary

**Single-School (tenant-local) only, v1.** Every Analytics read runs
inside the ordinary, mandatory tenant-isolation stack
(`docs/architecture/TENANCY.md`): Layer 1 (`SchoolScope`/
`BelongsToSchool`), Layer 2 (PostgreSQL RLS, enabled and forced), Layer
3 (tenant-aware infrastructure where applicable). No `BYPASSRLS`, no
database superuser access, no Analytics-specific RLS escape, no
implicit Platform Super Admin database bypass, and no other
undocumented privileged database path is introduced by this contract or
may be introduced under it without a fresh ADR.

**Cross-School/platform-wide Analytics is explicitly deferred** to a
separate future architecture checkpoint. `TENANCY.md` already states
cross-tenant reporting is theoretically possible but "not implemented
yet" — this contract changes nothing about that. See ADR 0040 §4 for
the full reasoning and what a future cross-School checkpoint would need
to resolve.

## 5. Authorization model

| Capability | Scope | Status |
|---|---|---|
| `analytics.view` | View Analytics dashboards/reports for one School | seeded Phase 0L.2-1; `school_admin`, `principal` |
| `analytics.export` | Export Analytics output for one School | seeded Phase 0L.2-1; granted to no role; no export exists |
| `analytics.platform.view` | Future cross-School/platform Analytics view | frozen (spelling only) — unusable until §4's cross-School deferral resolves |

Depth-2 dotted convention, matching every other module. **Not seeded in
`CapabilityAndRoleSeeder`** — registration happens at implementation
time (Phase 0L.2), per every prior module's precedent.

**Load-bearing distinction, never to be assumed either direction:**
- Holding a source module's own `.view` capability does **not** grant
  `analytics.view` of a dashboard built from that module's data.
- Holding `analytics.view` does **not** grant drill-down access to an
  individual underlying record — a read surface that needs row-level
  detail additionally checks that row's own source-module capability
  (`CommunicationDeliveryAnalyticsReadModel::deliveryDetail()` already
  does this today, reached through `communications.manage`, not a
  separate analytics-only check).
- `analytics.export` is a distinct grant from `analytics.view` — seeing
  a dashboard on-screen and extracting it as a file are different
  trust levels.

## 6. Aggregate/derived-data classification and small-cohort policy

See `docs/security/DATA-CLASSIFICATION.md`'s new "Aggregated / derived
/ statistical (Analytics) data" row for the authoritative policy.
Summary:

- **Default: a derived/aggregate value inherits the strongest
  classification tier among its source data.** A count touching
  Highly-Sensitive-tier data (e.g. financial, health, children's-data
  combinations) defaults Highly Sensitive, matching the existing
  "Canteen Order" combinatorial-elevation precedent.
- **Downgrade requires ALL of**: the cohort/cell meets the
  minimum-cohort-size threshold (see below); no combination of grouping
  dimensions can re-derive an individual's identity; the aggregate does
  not itself disclose Highly-Sensitive category membership.
- **Minimum-cohort-size threshold: `[LEGAL/PRODUCT/SECURITY REVIEW
  REQUIRED]` — not yet set.** The suppression RULE (small cells must be
  omitted, bucketed, or the grouping declined) is frozen by ADR 0040;
  the exact number is an explicit, open gate. **No Analytics read model
  capable of producing a small-cohort cell may ship until this is set.**
- **Aggregation is never an implicit privacy bypass** — "it's just a
  count" is not, by itself, a safety argument; every future read model
  is reviewed against both rules above before it ships.

## 7. Read-model strategy

**Synchronous, on-demand read models computed from current transactional
source data** — the proven `CommunicationDeliveryAnalyticsReadModel`
pattern: a small, fixed number of grouped aggregate queries per
request, issued through the owning module's read contract, no duplicate
summary table, no independent write path.

**Not introduced, and not to be introduced without a demonstrated
current need and its own review:** analytics warehouse, data lake, ETL
pipeline, generic BI engine, dedicated reporting database,
materialized-analytics framework, event-sourced Analytics projection
system, Analytics-specific message broker, generic report-builder
framework.

**Dashboards, not a reusable engine, in v1** — Phase 0L.2 builds one
thin, purpose-built, capability-gated read model + dashboard per
concrete need, matching `CommunicationAnalyticsController`'s shape. A
generic abstraction is justified only once a second, materially
different consumer proves the same shape is actually reusable.

## 8. Transactional outbox (approved future seam, not used yet)

ADR 0025 already names the exact extraction path for a future need:
"a separately-deployed analytics service needing to consume every
domain event in near-real-time... the outbox table itself is already
the seam a future broker would attach to." This contract adopts that
language unchanged. **Phase 0L.1 implements no outbox consumer** — no
new `EventConsumer`, no projection table, no event subscription. This
remains a documented future option only.

## 9. Temporal semantics

- **Current-state Analytics** ("active Students right now") — in
  scope; an ordinary query against present data.
- **Reporting over historical events already retained by source
  modules** ("attendance rate last month," from `AttendanceRecord` rows
  a source module already permanently keeps) — in scope; an ordinary
  date-range query, not a snapshot mechanism.
- **Point-in-time/as-of snapshots** (Analytics itself freezing and
  storing a view for later retrieval after live data changes) —
  **explicitly not in scope for v1.** No snapshot table, no scheduled
  snapshot job, no "as-of" reconstruction of a state the source module
  does not already retain.

## 10. Performance/scaling boundary

Synchronous/on-demand read models are sufficient today. Documented
future triggers for revisiting that (not solved now): unacceptable
query latency on a real dataset; expensive aggregation repeated across
many concurrent requests; a very large single-tenant dataset; a genuine
near-real-time cross-module dashboard requirement; the §9 snapshot
requirement, if it ever materializes; an external Analytics consumer
needing lower latency than API polling provides.

## 11. Not in this checkpoint (Phase 0L.1)

Any Analytics migration, model, controller, service, route, or
capability registration · dashboards/Inertia pages · OpenAPI paths ·
shared generated types · exports (CSV/PDF/etc.) · jobs/queue consumers
· outbox consumers · scheduled reports · a generic reporting engine ·
warehouse/ETL/data-lake infrastructure · LMS-specific or
Finance-specific Analytics · Compliance functionality · Automation
functionality · cross-School reporting.

## 12. Future

- **Phase 0L.2-1 — Analytics Foundation + Curriculum Coverage**: DONE
  (2026-09-23) — see §13. Person-counting Analytics remains blocked.
- **Phase 0L.2 — Analytics Foundation**: the first real implementation
  checkpoint — one or two already-stable Layer 0–4 sources, a
  capability-gated single-School read surface, following every
  decision recorded here. Blocked on §6's small-cohort threshold for
  any read model that could produce a small-cohort cell. The readiness
  gate (2026-09-23) and the exact owner decisions still needed are
  recorded in `docs/security/ANALYTICS-SMALL-COHORT-POLICY-GATE.md`
  — status BLOCKED; no threshold has been approved.
- **Compliance, Automation**: separate future Layer 5 checkpoints, each
  needing their own contract; not designed or scoped by this document.
- **Cross-School/platform Analytics**: a separate future architecture
  checkpoint per ADR 0040 §4.

## 13. Phase 0L.2-1 — as built (2026-09-23)

Owner-approved interim scope; recorded in ADR 0040's 2026-09-23
amendment. **No minimum person-cohort size has been approved and none is
recorded anywhere.**

**Recorded decisions**
- Person-counting Analytics is blocked while the minimum person-cohort
  size is unset — enforced, not merely documented (below).
- The cohort-size rule applies to cells or denominators that count
  people or could disclose information about people. Pure object/process
  aggregates (syllabus-unit coverage) are outside it, but still
  classified, capability-gated, tenant-scoped and audited where the tier
  requires.
- First source: Curriculum Delivery / Syllabus coverage.
- `analytics.view` → `school_admin`, `principal` only. `analytics.export`
  is seeded but granted to nobody; there is no export.
  `analytics.platform.view` is not seeded; no cross-School Analytics.
- Student-data Analytics stays gated on the separate privacy/legal
  decision.

**Module (`app/Domain/Analytics/Application`)**

| Piece | Role |
|---|---|
| `AnalyticsReadModel` | Interface: `declaration()` + `compute(School, filters)` |
| `ReadModelDeclaration` | key, `ClassificationTier`, `countsPeople`, source modules, closed filter list |
| `ClassificationTier` | Confidential / Sensitive / HighlySensitive; Sensitive+ reads are audited |
| `CohortSuppressionPolicy` | Reads `config('analytics.minimum_person_cohort_size')` (env `ANALYTICS_MINIMUM_PERSON_COHORT_SIZE`, **no default**); anything but a positive whole number = unset; a person-counting declaration is refused while unset |
| `AnalyticsReadGate::read()` | The only execution path: `analytics.view` (403) → cohort policy (503) → registry (503) → closed filters → `TenantContext::withSchool()` → compute → `analytics.report_viewed` audit for Sensitive/Highly Sensitive tiers (read model key + filters only) |
| `AnalyticsReadModelRegistry` | Closed catalog of served read models |
| `Exceptions\AnalyticsReportUnavailableException` | HTTP 503 fail-closed refusal |
| `ReadModels\CurriculumCoverageReadModel` | `curriculum.coverage`: Confidential, `countsPeople: false`, filter `academic_year_id` only |

Configuring a value is necessary but **not sufficient** for person
Analytics: 0L.2-1 implements no per-cell/complementary suppression
(the suppression mode is still undecided), and
`Tests\Feature\Analytics\AnalyticsArchitectureGuardTest` fails if any
registered read model counts people. The first person-counting read
model must bring suppression with it, after gate decisions 7.1–7.4.

**Source contract**: `App\Domain\CurriculumDelivery\Application\CurriculumCoverageReadService`
(Curriculum Delivery's own module) returns unit counts only
(`Coverage\*` DTOs): per required, active Subject Offering, its active
syllabus-unit count and, per active Section in its context, completed /
in-progress / not-started units — the same projection as the operational
`/app/syllabus-delivery` page. No dates, no entity collections, no person
field. It runs inside `withSchool()` and filters by School id; it does
not re-check `curriculum.delivery.view` because analytics access is
independent of source access (ADR 0040 §5) and the gate has already
checked `analytics.view`.

**Metrics**: planned = active units × active Sections; completed, in
progress, not started; coverage % = completed / planned (integer
arithmetic, one decimal, half up; `null` when nothing is planned).
Dimensions: School totals, grade level, Subject Offering, Section.

**Surface**: `GET /app/analytics/curriculum-coverage` (Inertia page
`App/Analytics/CurriculumCoverage`), Dashboard link "Analytics:
Curriculum Coverage" when the actor holds `analytics.view`. No API, no
export, no cache, no snapshot, no outbox consumer.

**Section-level coverage and teachers (verified 2026-09-23, regression
checkpoint after 0L.2-1).** A per-Section, per-subject figure may in
practice correspond to one teacher's classes. The current facts:
- the report and its source contract carry **no teacher identity** (no
  teacher, employee or user field in any `Coverage\*` DTO or report key
  -- asserted by `CurriculumCoverageReadServiceTest` and
  `CurriculumCoverageReadModelTest`);
- `curriculum_deliveries` stores **no teacher identity** at all
  (DATA-CLASSIFICATION.md "Curriculum delivery records";
  `CurriculumDeliveryArchitectureGuardTest` pins the column set);
- the roles holding `analytics.view` (School Admin, Principal) already
  held `curriculum.delivery.view`, which shows the same Section-level
  progress unit by unit on `/app/syllabus-delivery`;
- the report adds **no teacher ranking or comparison**: rows are ordered
  by grade, campus and subject code, never by coverage.

**Any future change that connects Section coverage to a named teacher,
to teacher performance, to a teacher ranking, or to individual staff
evaluation must trigger a new privacy/data-classification review before
it ships** -- it would make the metric information about a person, and
may bring it under the §6 person/cohort policy (and re-tier Curriculum
Delivery to Sensitive by DATA-CLASSIFICATION.md's Timetable reasoning).
