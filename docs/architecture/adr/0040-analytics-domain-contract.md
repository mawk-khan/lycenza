# ADR 0040: Analytics Domain Contract (Phase 0L.1)

- Status: Accepted
- Date: 2026-09-05 (Phase 0L.1)

## Context

`docs/roadmap/MASTER-ROADMAP.md`'s "Phase 0L — Oversight" entry is a
single paragraph: *"Compliance, Analytics, Automation (Layer 5) —
read-mostly consumers of everything built so far; deliberately
sequenced after there's meaningful data/events for them to work
with."* `docs/architecture/DOMAIN-MAP.md`'s Layer 5 table adds one more
line: *"Analytics | Cross-module reporting, dashboards | Reads from any
Layer 0–4 module | Same rule as Compliance."* No ADR, module document,
data model, tenant-boundary decision, capability namespace, or
data-classification treatment exists anywhere for Analytics. This is
materially less specified than every other Layer/module boundary this
codebase has built against — HR (ADR 0028), Finance (ADR 0030),
Examinations (ADR 0032), Payroll (ADR 0034/0036) — each got a dedicated
architecture-contract decision before any schema existed.

This ADR is Phase 0L.1: a documentation/architecture-only checkpoint
that freezes the Analytics bounded-context contract before any
migration, read model, API, dashboard, export, or projection is built
— exactly as ADR 0028 did for HR, ADR 0032 did for Examinations, and
ADR 0039 (LMS) did most recently, on the same repository. It makes no
code change. Ten questions had to be resolved: Analytics' own
responsibility and boundary against Compliance/Automation;
source-of-truth ownership; module-access rules and degraded-source
behavior; the tenant boundary; the authorization model; aggregate/
derived-data classification and small-cohort re-identification risk;
the read-model strategy; the transactional outbox's role; temporal
semantics; and the performance/scaling boundary.

A pre-implementation audit (conducted this same session, prior to this
ADR) confirmed by direct repository inspection: `origin/main`'s
`app/Domain/` tree contains no Analytics/Reporting/Compliance
directory; `CapabilityAndRoleSeeder.php` contains no `analytics.*`/
`reporting.*`/`compliance.*` capability; `packages/contracts/openapi/
school-os-api.yaml` contains no analytics/reporting path; and the one
real analytics-shaped precedent in this codebase —
`App\Domain\Communications\Application\CommunicationDeliveryAnalyticsReadModel`
(Phase 5A.11) — is Communications-internal only, never generalized into
a cross-module convention.

## Decision

### 1. Analytics is an internal Layer 5 bounded context — `App\Domain\Analytics`

The approved Phase 0L.1 target is an **internal Analytics bounded
context inside the modular monolith**, namespaced `App\Domain\Analytics`,
matching every other module's `app/Domain/<Module>` convention (ADR
0001). This ADR governs Analytics only.

**Analytics vs. Compliance vs. Automation** — `DOMAIN-MAP.md` bundles
all three under "Layer 5 — Oversight," but they are three separate
future checkpoints, not one:
- **Compliance** (regulatory reporting, statutory record-keeping) is
  explicitly out of scope for this ADR and for Phase 0L.1. A future
  Compliance checkpoint gets its own contract.
- **Automation** (rules/workflow engine reacting to domain events, ADR
  0010) is explicitly out of scope for this ADR and for Phase 0L.1. A
  future Automation checkpoint gets its own contract.
- **Analytics** (cross-module reporting, dashboards) is this ADR's only
  subject. Nothing here authorizes, designs, or reserves namespace for
  Compliance or Automation.

**What Analytics owns**: analytical reads, dashboards, reports, derived
metrics (counts/totals/averages/percentages/trends/grouped statistics),
and exports of those derived values.

**What Analytics is explicitly NOT**:
- **Not operational queries.** An operational query serves one
  business transaction's own UI/API need, inside the owning module,
  potentially at single-row granularity (e.g. "show this Charge's
  detail page"). An analytical query is cross-cutting, aggregate-shaped,
  and computed across many rows/one or more modules for a
  dashboard/report — never used to render an operational workflow
  screen, and never a substitute for a module's own read service.
- **Not operational/observability metrics.** `App\Support\Observability\
  MetricsRecorder`/`OperationalStatusService` measure system health
  (queue depth, outbox lag, connectivity) — infrastructure, not
  business data, and outside Analytics' scope entirely. Analytics never
  reuses `MetricsRecorder` to emit business figures, and
  `OperationalStatusService` never gains a business-data aggregation
  method.
- **Not a reusable generic reporting engine, in v1.** See decision 7.
- **Not a transactional system of record.** Analytics has no write path
  to any business fact. It has no `analytics.*.manage`-shaped mutation
  capability (only `.view`/`.export`, decision 5). Every value Analytics
  ever returns is derived, computed fresh from the owning module's
  transactional tables at read time (decision 2) — Analytics never
  becomes the thing a source module or any other consumer must read
  back to know a business fact actually happened.

**Layers 0–4 must never depend on Analytics** (`DOMAIN-MAP.md` rule 2,
restated and reconfirmed for this checkpoint): core ERP function must
work with Analytics entirely unavailable. Analytics may read from any
Layer 0–4 module's Application-layer read contract (decision 3); no
Layer 0–4 module may call into Analytics, subscribe to an
Analytics-emitted event, or have its own behavior altered by whether
Analytics is installed, healthy, or has ever run.

### 2. Source-of-truth ownership: transactional data vs. derived reporting data

**Transactional modules remain the sole authoritative owner of their
own data**, unconditionally. Analytics may derive counts, totals,
averages, percentages, trends, grouped statistics, dashboard summaries,
and report outputs from that data — but a derived value is never
authoritative for the underlying transactional fact it was computed
from, and no source module ever reads back an Analytics-computed value
to determine its own state.

**Explicit distinction, used throughout this ADR and
`docs/modules/ANALYTICS.md`:**
- **Transactional source data** — the row(s) a module's own migration/
  model/Application service owns and mutates (e.g. `AttendanceRecord`,
  `charges`, `communication_deliveries`). This is what "source of
  truth" means everywhere else in this codebase.
- **Derived reporting data** — a number or shape Analytics computes
  FROM transactional source data, at read time, for display. It exists
  only as the output of a query; it has no independent mutation path
  and no independent lifecycle. If the underlying transactional data
  changes, the next Analytics read reflects that change automatically
  — there is no separate reporting copy to keep in sync (decision 7
  rules out a duplicate/materialized store for v1).

### 3. Module-boundary rules: how Analytics may consume other modules

Analytics **must** consume each source module through that module's own
established Application-layer/read-service contract — the exact
discipline `DOMAIN-MAP.md` rule 3 already states for every other
module, restated here because Analytics is the module most tempted to
violate it (a naive "just query the table" instinct is exactly what a
reporting feature reaches for by default). Analytics never reads
another module's Eloquent models or raw tables directly, and never
gains a bespoke, Analytics-only read path into a source module that
bypasses that module's own tenant scoping or authorization.

Where a module already exposes a purpose-built read service (e.g.
`SubjectOfferingRosterReadService`, `StudentEnrollmentReadService`,
`ChargeReadService`), Analytics calls it. Where no such service exists
yet for a metric Analytics wants, adding one is that source module's
own Application-layer work, reviewed under that module's own rules —
Analytics does not get a shortcut that bypasses the owning module's own
architectural discipline merely because "it's just a read."

**Degraded/absent-source behavior — explicit, not incidental:**
- **A source module is unavailable or not installed**: the
  corresponding Analytics read surface is not registered at all
  (feature-detected at composition/route-registration time), never a
  runtime exception surfaced to an end user on every request. This is
  the same symmetry `DOMAIN-MAP.md` rule 2 already states in the other
  direction (Layers 0–4 must work with Analytics unavailable) — Layer 5
  in turn must degrade gracefully, never crash, when one of its Layer
  0–4 sources is absent.
- **A source module's feature is unpublished** (implemented on an
  unmerged branch, not yet on `main`): Analytics is built only against
  what actually exists on the branch/environment it ships from. It
  never speculatively wires up a read path against a not-yet-merged
  module's schema — doing so would create an undeclared dependency on
  an unpublished branch, which this checkpoint's own baseline-safety
  rules (and CLAUDE.md generally) forbid.
- **A metric depends on incomplete source-domain functionality** (e.g.
  Examinations without marks/results yet): Analytics surfaces only what
  the source domain stably and completely provides today. It never
  approximates, backfills, or infers a shape the source domain has not
  yet committed to — an incomplete metric is omitted, not guessed at.

### 4. Tenant boundary: single-School v1; cross-School explicitly deferred

**Phase 0L.1 Analytics is single-School (tenant-local) only.** Every
Analytics read runs inside the ordinary, already-mandatory tenant
isolation stack (`docs/architecture/TENANCY.md`): Layer 1
(`App\Support\Tenancy\SchoolScope`/`BelongsToSchool`), Layer 2
(PostgreSQL RLS, enabled and forced on every tenant table), and Layer 3
(tenant-aware queue/cache/storage/log plumbing where applicable). This
ADR introduces **none** of the following, and none may be introduced
under this contract without a fresh ADR:
- `BYPASSRLS` on any role;
- database superuser access for any runtime code path;
- an "Analytics-specific" RLS escape or exception table (ADR 0025's
  `domain_event_outbox`/`event_consumer_receipts` central-table
  exception is a reviewed, narrowly-scoped precedent for a
  non-business-data table — it does not extend to Analytics' own
  business-data reads by analogy);
- an implicit Platform Super Admin database-privilege bypass —
  confirmed directly against `docs/architecture/adr/0021-postgresql-
  runtime-vs-migration-roles.md`: `school_os_app` (the sole runtime
  connection role) is `NOSUPERUSER NOBYPASSRLS`, and "Platform Super
  Admin" is an application-layer capability concept only
  (`docs/security/AUTHORIZATION.md`), never a database privilege
  escalation (CLAUDE.md rule 26);
- any other undocumented privileged database path.

**Cross-School/platform-wide Analytics is explicitly deferred** to a
separate, future, explicitly-reviewed architecture checkpoint.
`docs/architecture/TENANCY.md` already states the closest thing to
precedent this repository has: *"Cross-tenant reporting (Layer 5...) is
possible because it's one shared database, but every such code path is
explicit and treated as privileged — never the default query behavior
any module reaches for. No such reporting path is implemented yet."*
This ADR changes nothing about that — it remains true after Phase
0L.1. No cross-School aggregate, no platform-wide dashboard, and no
"enter a School's Analytics as a group/platform admin" action exists or
is authorized by this contract. Building one later requires its own
ADR addressing, at minimum: how the privileged read path is
constructed without `BYPASSRLS`, what capability gates it, and how the
aggregate/derived-data classification rules in decision 6 apply once
data crosses a tenant boundary (a cross-School count is a materially
different disclosure risk than a single-School one).

### 5. Authorization model: `analytics.*` namespace, reserved not seeded

**Namespace**: `analytics.*`, depth-2 dotted, matching every other
module's convention (`examinations.definitions.*`, `curriculum.
delivery.*`, `lms.assignments.*`). Conceptual capabilities this ADR
freezes the spelling of:

| Capability | Scope |
|---|---|
| `analytics.view` | View Analytics dashboards/reports for one School |
| `analytics.export` | Export Analytics output (e.g. as a file) for one School |
| `analytics.platform.view` | Future cross-School/platform-wide Analytics view — named here only to freeze its spelling; unusable until decision 4's cross-School deferral is separately resolved |

**Not seeded in `CapabilityAndRoleSeeder` by this checkpoint** —
capability catalog registration happens at implementation time (Phase
0L.2), per every prior module's own precedent (LMS/ADR 0039 froze
`lms.*` the same way before Phase 0I.2 seeded it).

**Explicit, load-bearing distinction — this ADR's own required
resolution**: permission to view a module's own source records is a
**separate authorization concept** from permission to view Analytics
built from that data, and neither implies the other:
- **`source-record access = analytics access` is FALSE.** Holding
  `attendance.view` does not grant `analytics.view` of an attendance-rate
  dashboard — a School might reasonably want a Principal to see
  aggregate attendance trends without seeing every individual Student's
  daily record, or vice versa.
- **`analytics access = source-record access` is FALSE.** Holding
  `analytics.view` never lets an actor drill through to an individual
  Student's/Employee's underlying record — an Analytics read surface
  that needs row-level drill-down must additionally check that row's
  own source-module capability, exactly as
  `CommunicationDeliveryAnalyticsReadModel::deliveryDetail()` already
  does today (it is reached through `communications.manage`, the
  underlying delivery data's own capability, not a separate
  analytics-only check that would leak detail without the source
  module's own gate).
- **`analytics.export` is a distinct grant from `analytics.view`** — an
  actor may be trusted to see a dashboard on-screen without being
  trusted to extract it as a file (the same "view vs. export/download"
  distinction `docs/modules/DOCUMENTS.md`'s content-vs-metadata
  authorization already draws elsewhere in this codebase).

### 6. Aggregate/derived-data classification and small-cohort re-identification

`docs/security/DATA-CLASSIFICATION.md` is silent on aggregated/derived/
statistical data today — every existing row classifies a raw entity
type. This ADR adds the missing policy (mirrored into
`DATA-CLASSIFICATION.md` itself as a Consequence of this ADR, per
decision below):

**Default rule: a derived/aggregate value inherits the strongest
classification tier among the source data it was computed from.** A
count of Students with an outstanding fee balance is computed from
Sensitive-tier Student data and Highly-Sensitive-tier financial data
(`DATA-CLASSIFICATION.md`'s "Financial data" row) — the aggregate
defaults to Highly Sensitive, not merely Sensitive, exactly as the
existing "Canteen Order / Order line data" row already establishes the
same combinatorial-elevation principle for a single record ("the
combination of a child's identity and a monetary amount is exactly this
tier's own 'highest harm potential' criterion").

**A derived value may be downgraded from that default only when ALL of
the following hold**, and the downgrade is recorded at the point the
specific Analytics read model is built, not assumed generally:
1. The aggregate is computed over a cohort at or above the
   minimum-cohort-size threshold (see below — currently unresolved,
   not this ADR's to set).
2. No combination of the aggregate's own grouping dimensions can
   re-derive an individual's identity or category membership (e.g.
   grouping simultaneously by Grade + Section + Gender + fee-status may
   produce a cell of size 1 even when the School-wide total is large —
   the CELL size is what must clear the threshold, not the total
   population).
3. The aggregate does not itself disclose membership in a
   Highly-Sensitive category (e.g. "3 students have a recorded health
   condition" is Highly-Sensitive-shaped as a count, independent of
   cohort size, by the same reasoning `DATA-CLASSIFICATION.md`'s Health
   row already applies to the underlying record).

**Small-cohort / re-identification policy framework** (the architecture
rule, per this checkpoint's explicit instruction to define the rule
without inventing the number):
- Every Analytics read model that groups by a dimension capable of
  producing a small cell (Section, Grade, a narrow date window,
  department, or any combination thereof) MUST implement a suppression
  rule: a cell whose underlying row count is below the minimum-cohort
  threshold is either omitted, merged into a broader bucket ("fewer
  than N"), or the read model declines to return that grouping at all
  — never rendered as an exact small number.
  Examples explicitly in scope for this rule, matching this
  checkpoint's own brief: attendance rate for a very small class; count
  of Students with outstanding fees; disciplinary counts for a tiny
  group; payroll statistics for a small department; examination results
  for a small cohort.
- **This ADR does NOT set the numeric threshold.** Doing so would be
  exactly the kind of invented compliance decision
  `docs/modules/DOCUMENTS.md`'s own prior finding warned against
  ("Fabricated compliance/retention policy invented to 'solve' [a gap]
  without legal review... Explicitly not done this checkpoint"). The
  exact minimum-cohort number is recorded as an explicit, unresolved
  **[LEGAL/PRODUCT/SECURITY REVIEW REQUIRED]** gate — this sits squarely
  inside the same children's-data DPDP-Act territory
  `DATA-CLASSIFICATION.md` already flags (most School data subjects are
  minors), so the same qualified-review discipline applies. **No
  Analytics read model that could produce a small-cohort cell may ship
  in Phase 0L.2 or any later checkpoint until this threshold is set.**
- **Aggregation must never become an implicit privacy bypass.** A
  developer must not treat "it's just a count, not the individual
  record" as automatically safe — this decision's own combinatorial and
  small-cohort rules exist precisely because that assumption is false in
  general, and every future Analytics read model must be reviewed
  against both before it ships.

### 7. Read-model strategy: synchronous, on-demand, no speculative infrastructure

**Phase 0L.1 adopts synchronous, on-demand Analytics read models
computed from current transactional source data** — the same pattern
`CommunicationDeliveryAnalyticsReadModel` already proves works in this
codebase: a small, fixed number of grouped aggregate queries per
request, issued directly against source-of-truth tables through the
owning module's read contract (decision 3), with no duplicate summary
table and no independent write path. Every future Analytics read model
follows this same shape unless a later, explicitly-justified ADR
changes it.

**Explicitly NOT introduced by this ADR, and not to be introduced
without a demonstrated current requirement and its own review:**
analytics warehouse; data lake; ETL pipeline; generic BI engine;
dedicated reporting database; materialized-analytics framework;
event-sourced Analytics projection system; Analytics-specific message
broker; generic report-builder framework. None of these has a
demonstrated need today — building any of them now would be exactly
the speculative infrastructure the root `CLAUDE.md` rule 2 forbids.

**Dashboards vs. reusable reporting infrastructure**: Phase 0L.2 (the
first implementation checkpoint, not this ADR) should build one
thin, purpose-built, capability-gated read model + dashboard per
concrete need — matching `CommunicationAnalyticsController`'s shape —
never a generic "reporting engine" abstraction layer. A general
abstraction is only justified once a SECOND, materially different
consumer demonstrates the same shape is actually reusable; inventing
one preemptively for "the next module" is the same speculative-build
CLAUDE.md rule 2 already forbids elsewhere in this codebase.

### 8. Transactional outbox: approved future seam, not used in Phase 0L.1

ADR 0025's transactional outbox (`domain_event_outbox`) already names
its own extraction path for exactly this situation: *"If a genuine need
for a distributed broker emerges later (e.g. a separately-deployed
analytics service needing to consume every domain event in near-real-
time without polling School OS's API), the extraction path is:
`DispatchOutboxEvents` becomes a WAL/outbox-publisher... `domain_event_
outbox` remains the durable source of truth... No current module needs
to be redesigned in anticipation of this — the outbox table itself is
already the seam a future broker would attach to."*

This ADR **adopts that language as Analytics' own future extension
seam** and changes nothing about it: if a later checkpoint demonstrates
a genuine need for asynchronous aggregation, materialized projections,
near-real-time Analytics, or an external Analytics consumer, the outbox
is the approved mechanism to build against. **Phase 0L.1 implements no
outbox consumer** — no new `App\Support\Events\EventConsumer`, no new
projection table, no subscription to any domain event. This is a
documented future option, not a Phase 0L.1 or Phase 0L.2 deliverable.

### 9. Temporal semantics: current-state and historical-event reporting only; no snapshots in v1

Three distinct temporal concepts, explicitly separated per this
checkpoint's brief:

1. **Current-state Analytics** ("how many active Students right now") —
   **in scope.** An ordinary on-demand query against present
   transactional data.
2. **Reporting over historical events already retained by source
   modules** ("attendance rate for last month," computed from
   `AttendanceRecord` rows a source module already permanently
   retains) — **in scope.** This is not a snapshot mechanism; it is an
   ordinary query with a date-range filter against rows the source
   module already durably owns (the same shape
   `CommunicationDeliveryAnalyticsReadModel::schoolOverview()` already
   uses with its `fromUtc`/`toUtc` window).
3. **Point-in-time/as-of snapshots** (Analytics itself capturing and
   durably storing a frozen view — e.g. "enrollment exactly as it stood
   at the start of Term 1" — for later retrieval after the live data
   has since changed) — **explicitly NOT in scope for v1.** No
   snapshot table, no scheduled snapshot job, no "as-of" query
   parameter that reconstructs a past state the source module itself
   does not already retain. If a future need for snapshots is
   demonstrated, it is new infrastructure requiring its own ADR — this
   contract does not imply it is coming, does not reserve schema for
   it, and does not silently design around eventually needing it.

### 10. Performance/scaling boundary: documented triggers, not solved now

Synchronous/on-demand read models (decision 7) are sufficient for
Phase 0L.1 and are expected to remain sufficient for some time. This
ADR documents, without solving, the triggers that would justify
revisiting that decision in a future, separately-scoped checkpoint:
unacceptable query latency on a real (non-trivial) tenant dataset;
expensive aggregation repeated across many concurrent requests; a very
large single-tenant dataset where an on-demand scan becomes
impractical; a genuine near-real-time cross-module dashboard
requirement; the historical-snapshot requirement from decision 9, if it
ever materializes; or an external Analytics consumer needing
lower-latency access than polling an API provides. None of these
conditions exists today. This ADR does not add caching, background
pre-computation, read replicas, or any other performance mechanism in
anticipation of them.

## Rationale

- Freezing scope, boundary, classification, and read-model strategy
  before any migration is written follows the identical discipline
  every prior Layer/module contract in this codebase has used (ADR
  0028, 0030, 0032, 0034, 0039) — a checkpoint that gets these wrong is
  expensive to unwind once schema and API surface exist.
- Extending `DATA-CLASSIFICATION.md`'s combinatorial-elevation
  reasoning (already proven for the Canteen Order row) to derived/
  aggregate data, rather than inventing a new framework, keeps one
  classification model consistent across raw and derived data.
- Explicitly refusing to invent a small-cohort numeric threshold, while
  still defining the architectural suppression RULE, follows the exact
  conservative posture this codebase already applies to retention
  periods (`DATA-CLASSIFICATION.md`'s own "not yet made" retention
  flag) and to LMS Submission (ADR 0039) — a real, structurally-enforced
  gap is recorded as a gap, not silently resolved by engineering
  judgment alone.
- Deferring cross-School Analytics rather than building a "just this
  once" privileged path avoids exactly the undocumented-bypass risk
  ADR 0021/CLAUDE.md rule 26 already guard against, and keeps the real
  architectural question (how does a privileged cross-tenant read path
  get built safely, if ever) visible rather than hidden behind a
  one-off Analytics-specific shortcut.

## Alternatives considered

1. **Build a generic reporting/BI abstraction layer now, since multiple
   future modules will eventually want Analytics.** Rejected: no second
   consumer exists yet to prove the abstraction's shape is actually
   right, and this codebase's own precedent
   (`CommunicationDeliveryAnalyticsReadModel`) is a single-purpose read
   model, not a generic engine — building one preemptively is
   speculative infrastructure CLAUDE.md rule 2 forbids.
2. **Treat aggregate/derived data as automatically lower-risk than its
   source (e.g. always Confidential regardless of source tier), since
   it's "just a count."** Rejected outright — this is precisely the
   assumption decision 6 identifies as false in general (small-cohort
   re-identification, combinatorial elevation), and this codebase's own
   Canteen Order precedent already rejects the analogous assumption for
   raw records.
3. **Invent a specific minimum-cohort-size number now (e.g. "5" or
   "10") so Phase 0L.2 isn't blocked later.** Rejected — the brief
   explicitly forbids this, and doing so would fabricate a compliance
   decision this document is not authorized to make, the same
   reasoning `docs/modules/DOCUMENTS.md`'s own retention-policy finding
   already established for a structurally identical situation.
4. **Allow Analytics to read source modules' Eloquent models/tables
   directly, since it is "just reading."** Rejected — this would
   silently create the exact bidirectional/uncontrolled coupling
   `DOMAIN-MAP.md` rule 3 exists to prevent, and would make Analytics
   invisible to each source module's own tenant-scoping/authorization
   review.
5. **Design a cross-School/platform Analytics capability now, since
   Platform Super Admin already exists as a concept.** Rejected — no
   privileged cross-tenant read path exists anywhere in this codebase
   today (confirmed directly against `TENANCY.md`/ADR 0021), and
   inventing one as a side effect of an Analytics contract would be
   exactly the kind of undocumented privileged path CLAUDE.md rule 26
   forbids; it deserves its own dedicated review whenever it is
   actually pursued.

## Consequences

- `docs/modules/ANALYTICS.md` becomes the living operational reference
  for every future Phase 0L Analytics checkpoint's schema/API/
  authorization/classification decisions, mirroring how
  `ACADEMIC-STRUCTURE.md`/`HR.md`/`docs/modules/LMS.md` are treated —
  this ADR is the decision record, that document is the detail.
- `docs/architecture/DOMAIN-MAP.md`'s Analytics row gains a Notes entry
  pointing here; its dependency column is unchanged (already correct —
  "Reads from any Layer 0–4 module").
- `docs/security/DATA-CLASSIFICATION.md` gains an explicit "Aggregated /
  derived / statistical (Analytics) data" policy (decision 6), including
  the recorded, still-open small-cohort-threshold gate.
- `docs/security/AUTHORIZATION.md` gains a short, general principle
  addition (source-record access vs. analytics access are separate
  concepts) — a principle broader than Analytics alone, but first
  needed here.
- `docs/architecture/TENANCY.md` gains a one-line cross-reference
  confirming this ADR's single-School v1 boundary matches its own
  pre-existing "no such reporting path is implemented yet" statement.
- `docs/roadmap/MASTER-ROADMAP.md`'s Phase 0L stub gains a Phase 0L.1
  status paragraph, matching the annotation style already used for
  Phase 0I.1 (LMS) and Phase 0J's HR/Payroll sub-entries.
- Phase 0L.2 (Analytics Foundation — a real, minimal, capability-gated,
  single-School read surface against one or two already-stable
  Layer 0–4 sources) may proceed once this contract exists, subject to
  decision 6's small-cohort threshold gate for any read model that
  could produce a small-cohort cell. Compliance and Automation remain
  wholly unscoped by this ADR and require their own future contracts.
- No code, migration, route, or capability-catalog entry is introduced
  by this ADR.

## Amendment — 2026-09-23 (Phase 0L.2-1, owner-approved interim scope)

Recorded from the product owner's approved interim scope for Phase
0L.2-1. It **amends decision 6's scope** and records the first
implementation decisions. It does **not** set the minimum-cohort number,
which remains an open **[LEGAL/PRODUCT/SECURITY REVIEW REQUIRED]** gate.

1. **Scope of the cohort-size rule (clarifies decision 6).** Small-cohort
   suppression applies wherever a cell value **or its denominator**
   counts people, or could disclose information about people (Students,
   Guardians, Employees, applicants, visitors, ...). **Pure non-person
   object/process aggregates** — e.g. syllabus-unit coverage — are **not
   subject to the minimum-cohort-size threshold**, but remain fully
   subject to classification (tier inheritance), authorization
   (`analytics.view`), tenancy (single-School, RLS) and, where the tier
   requires it, access audit. Every read model declares which it is
   (`ReadModelDeclaration::$countsPeople`).
2. **Person-counting Analytics stays blocked.** No minimum person-cohort
   size has been approved. Any read model whose cells or denominators
   count people fails closed (HTTP 503, before any source data is read)
   while `analytics.minimum_person_cohort_size` is unset
   (`App\Domain\Analytics\Application\CohortSuppressionPolicy`), and
   `Tests\Feature\Analytics\AnalyticsArchitectureGuardTest` refuses to
   let any registered read model count people at all until the policy
   (number and suppression mode) is decided.
3. **First source**: Curriculum Delivery / Syllabus coverage, consumed
   only through Curriculum Delivery's own aggregate read contract
   (`App\Domain\CurriculumDelivery\Application\CurriculumCoverageReadService`),
   per decision 3.
4. **Capabilities (decision 5)**: `analytics.view` and `analytics.export`
   are now seeded. `analytics.view` is granted to the `school_admin` and
   `principal` system roles only. `analytics.export` is registered but
   granted to no role, and **no export exists**. `analytics.platform.view`
   remains unseeded; cross-School Analytics remains deferred (decision 4).
5. **Student-data Analytics** remains outside implementation until the
   separate privacy/legal decision (DATA-CLASSIFICATION.md, "Children's
   data specifically") is completed.

As built: `docs/modules/ANALYTICS.md` §13. Still-open decisions:
`docs/security/ANALYTICS-SMALL-COHORT-POLICY-GATE.md` §7.

## Amendment — 2026-09-25 (Phase 0N.10, ADR 0048)

Decision 4's cross-School deferral is resolved **for exactly one report**:
ADR 0048 (Group Cross-School Reporting Contract) is the separate ADR
decision 4 requires, for `curriculum.coverage` under Group authority only.
Its answers to decision 4's three questions: the read path is a bounded
sequence of ordinary single-School reads (one `TenantContext::withSchool()`
per active member School, no RLS change, no `BYPASSRLS`); the gate is the
Group capability `group.reporting.view` (not `analytics.view`, not
`analytics.platform.view`, which stays unseeded); classification stays the
strictest source tier (Confidential for this report — aggregation lowers
nothing). Analytics owns a separate Group-safe report registry, the
Group-safe summary and the aggregation math (sums of counts; percentage
recomputed from sums). Every other report, platform-wide Analytics,
person-counting Analytics and export remain governed by this ADR unchanged.
Nothing is built until Phase 0N.11.
