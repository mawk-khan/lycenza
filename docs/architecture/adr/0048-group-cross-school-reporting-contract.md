# ADR 0048: Group Cross-School Reporting Contract (Phase 0N.10)

- Status: Accepted; foundation implemented in Phase 0N.11 (see
  "Implementation amendment" at the end)
- Date: 2026-09-25 (Phase 0N.10); amended 2026-09-25 (Phase 0N.11)
- Relationship to other ADRs: this is the dedicated cross-School ADR that
  ADR 0040 §4 requires, **for exactly one registered report
  (`curriculum.coverage`) under Group authority** — Phase 0N and Analytics
  decide it jointly here, so there is one authorization path, not two.
  It amends ADR 0045 §4 (a third Group capability) and §14 (Group
  reporting, narrowly). It does not touch ADR 0042 (Compliance), ADR 0043
  (Automation) or ADR 0023 (AI): none of those gains any cross-School path.

## Context

D15 (Group / cross-School reporting) is the last open Phase 0N decision
(`docs/architecture/PHASE-0N-READINESS.md`). The roadmap scopes Phase 0N
as "Group/Trust cross-school administration **and reporting**". Every
Layer 5 contract deferred cross-School reads to its own ADR: ADR 0040 §4
(Analytics: "how the privileged read path is constructed without
`BYPASSRLS`, what capability gates it, and how the aggregate/derived-data
classification rules … apply once data crosses a tenant boundary"),
ADR 0042 (Compliance), ADR 0043 (Automation); ADR 0045 §14 excluded Group
reporting pending D15. **On 2026-09-25 the product owner decided D15**
(section 1). This ADR is the contract; it changes no code, adds no
capability or migration, and seeds nothing.

### What exists (verified on `fa453cd`, code and DDEV)

| Fact | Evidence |
|---|---|
| One Analytics execution path: `AnalyticsReadGate::read()` checks `analytics.view` **in that School for that actor**, the cohort policy, the closed registry, closed filters, then computes inside `TenantContext::withSchool()`; audits `analytics.report_viewed` (School ledger) only for Sensitive/Highly Sensitive tiers; nothing cached | `app/Domain/Analytics/Application/AnalyticsReadGate.php` |
| Closed registry `AnalyticsReadModelRegistry::READ_MODELS` = `[CurriculumCoverageReadModel]`; a guard test refuses an unregistered or person-counting read model | `AnalyticsReadModelRegistry`, `Tests\Feature\Analytics\AnalyticsArchitectureGuardTest` |
| `curriculum.coverage` (`CurriculumCoverageReadModel::KEY`): tier **Confidential**, `countsPeople: false`, source module CurriculumDelivery (through `CurriculumCoverageReadService` only), one filter `academic_year_id` (one of the School's own years; default the active year, **else the first year listed**) | `CurriculumCoverageReadModel` |
| Its figures: `planned` (active units of a required, active Offering × active Sections sharing its context), `completed`, `inProgress`, `notStarted` = max(0, planned − completed − inProgress), `coveragePercent` = completed / planned (integer arithmetic, one decimal, half up; `null` when planned = 0); plus `offerings` and `offeringsWithoutSyllabus` (counts of Offerings). Output also carries the School's academic-year list, and per Offering/Section/grade level: subject code and name, grade-level name, campus name, Section name and code, internal ids | same |
| No figure or denominator counts a person; the source rows store no Student, Employee or teacher identity | `CurriculumCoverageReadService` docblock; `CurriculumDeliveryArchitectureGuardTest` |
| Sources `SyllabusUnit`, `CurriculumDelivery`: Confidential; derived data inherits the strongest source tier; a downgrade needs the (unset) cohort threshold among other things | `DATA-CLASSIFICATION.md` |
| `analytics.view` → `school_admin`, `principal`; `analytics.export` seeded, granted to nobody, no export; `analytics.platform.view` spelling frozen, unseeded | ADR 0040 amendment 4 |
| Group scope: `group_admin` holds exactly `group.schools.view`, `group.schools.elevate`; Group capabilities come only from an unrevoked grant in an **active** Group, are never cached, resolved only by `CapabilityResolver::canInGroup()` / `groupCapabilities()`; a role holds only its own scope's namespace (`trg_role_capabilities_scope`) | ADR 0045 §4 and amendment; CLAUDE.md rules 25, 84 |
| Group membership `school_group_members` (`UNIQUE (school_group_id, school_id)`, no size limit); a School may be in several Groups; the Group view (`/app/groups/{schoolGroup}`, `SchoolGroupController`) shows member id/name/status and refuses with an unaudited 404; Group events are `platform.school_group.*` / `platform.school_group_grant.*` on the platform ledger | schema; ADR 0045 |
| DDEV demo: one Group ("Lycenza Demo Trust", active) with 2 Schools; no evidence of expected production Group sizes | DDEV |
| `TenantContext` holds exactly one School; `withSchool()` restores the previous context (none, on a context-neutral route); RLS is forced on every tenant table; the runtime role is `NOBYPASSRLS` | TENANCY.md, ADR 0021, CLAUDE.md rule 26 |
| School lifecycle: only `active` is operational; `SchoolOperationalGuard::holdOperational()` reads the School row FOR SHARE for execution-time checks | ADR 0047 amendment |

**Conclusion of the audit:** `curriculum.coverage` counts syllabus units
(objects/process), not people, and is Confidential — it qualifies as the
first Group-safe report. Its **current output is not Group-safe as is**:
the fallback to a non-active year, the internal ids and the per-School
structural names (grade levels are not comparable across Schools) mean the
Group path needs its own minimal DTO (section 7). No conflict requiring a
stop was found.

## Decision

### 1. Owner decisions (2026-09-25)

1. Group reporting is **Group-scoped**: explicit Group grant + a new Group
   capability `group.reporting.view` — never implied by Platform Super
   Admin, Platform Auditor, School Admin, multi-School membership or
   elevation.
2. **No generic cross-tenant query**: no RLS change, no `BYPASSRLS`, no
   multi-School session, no UNION over tenant tables, no "all Schools"
   `TenantContext`, no raw model access, no report builder.
3. **Source-owned report contracts only**, through a code-registered
   Group-safe allowlist; `analytics.view` alone never makes a report
   cross-School.
4. **Analytics owns** report definition, execution, metric and aggregation
   semantics and suppression; **Phase 0N owns** Group authorization, Group
   membership and School eligibility. No duplication.
5. **First report: `curriculum.coverage`** (verified, section "What
   exists"). Nothing else.
6. People-counting Analytics stays closed; Group reporting bypasses no
   Analytics gate.
7. Per-School execution, one School at a time, context cleared after each.
8. A dedicated Group-safe read path — no fake membership, hidden role,
   elevation or per-School `analytics.view`.
9. Only `active` Schools contribute; others are shown as unavailable; no
   stale cached results.
10. Membership re-checked at execution; revocation fails closed.
11. One Group authority per request; no pooling across Groups.
12. Aggregation only over approved per-School DTOs, with source-defined
    math.
13. Per-School rows + Group totals; no drill-through.
14. Strictest source classification; identifiers-only provenance.
15. No persisted report payload in v1.
16. No export in v1.
17–19. No Compliance, Automation or AI cross-School access.
20. Current MFA assurance required.
21. Platform-ledger audit of every review.
22–24. Failure, consistency and scale semantics (sections 11–13).
25–28. Route, Platform Super Admin, Platform Auditor and School users
    (sections 5, 14).

### 2. Authorization model

A Group report request is authorized only when **all** hold, checked in
this order on every request:

1. authenticated, account not disabled;
2. the request names exactly **one** School Group (route parameter
   `{schoolGroup}`), and that Group is `active`;
3. the actor holds `group.reporting.view` in **that** Group through an
   unrevoked `group_role_assignments` grant, resolved fresh by
   `CapabilityResolver::canInGroup()` (never cached, rule 84); the grant
   used is identified (`group_role_assignment_id`) for audit;
4. current MFA assurance (section 10);
5. the report key is in the Group-safe registry (section 6).

Nothing else authorizes it: `platform_super_admin` and every `platform.*`
capability (including `platform.school_groups.*` governance and
`platform.audit.view`), School roles and `analytics.view` in any number of
Schools, multi-School membership and an active elevation all grant **no**
Group report. A Platform Super Admin who needs a Group report needs a real
Group grant under ADR 0045 governance (granted by another root — no
self-grant). `platform.cross_school_report.view` and
`analytics.platform.view` are **not** used; the latter stays unseeded and
reserved for a future platform-wide decision.

Per-School authority: the Group-safe read path (section 7) authorizes a
School's contribution by **valid Group grant + `group.reporting.view` +
School currently a member of that Group + School `active` + report
Group-safe** — never by a School membership or School capability of the
actor, and it never exposes `AnalyticsReadGate::read()` or any read model
generically.

### 3. The Group capability

`group.reporting.view` (namespace `group`, scope `group`): view the
registered Group-safe reports of one assigned Group. Not seeded here.

**v1 holder: the `group_admin` system role**, which then holds
`group.schools.view`, `group.schools.elevate` and `group.reporting.view`.
Consistency with ADR 0045: §4 kept Group capabilities "deliberately two"
because reporting was excluded pending D15 (§14), not because a third was
rejected; the Group Admin is already the Group's representative, may
already see its member Schools' identity and enter them one at a time, and
a Confidential object-count report is a narrower disclosure than
elevation. A separate reporting-only Group role was considered and not
created (no evidence of need; `SchoolGroupGovernanceService::grant()`
already accepts a role key if one is ever added). ADR 0045 §4 and §14 are
amended by cross-reference.

### 4. No generic cross-tenant query

`TenantContext` remains exactly one School, always. There is no RLS
change, no `BYPASSRLS`, no superuser path, no PostgreSQL session holding
several School ids, no SQL spanning Schools' tenant rows, no "view all
Schools" context, no direct access to source models from the Group layer,
and no generic cross-School report builder, query parameter or report id
passthrough. This ADR answers ADR 0040 §4's three questions: the read path
is **N ordinary single-School reads** through the existing tenant stack
(section 7); the gate is `group.reporting.view` (section 3); the
classification rule is section 9.

### 5. Route and surface (future)

`GET /app/groups/{schoolGroup}/reports/curriculum-coverage` — under the
existing Group UI (`/app/groups/{schoolGroup}`), `auth`, **no**
`school-context` middleware (context-neutral, like every Group page; added
to `SchoolContextRouteGuardTest`'s allowlist), authorized in the service
(section 2), read-only, no API route. The page shows the Group's identity,
`generated_at`, one row per member School (name and status from Group
metadata; figures only for contributing Schools; an "unavailable" or "no
active academic year" marker otherwise) and the Group totals. No
drill-through, no links into School pages, no other tenant metadata, no
filters in v1.

### 6. Source-owned Group-safe report registry (Analytics)

A new, closed, code-registered **Group-safe report registry owned by
Analytics** (e.g. `App\Domain\Analytics\Application\Group\GroupSafeReportRegistry`),
separate from `AnalyticsReadModelRegistry`: being a registered School read
model does not make a report Group-safe. Each entry declares:

| Field | `curriculum.coverage` (v1, the only entry) |
|---|---|
| Report identifier | `curriculum.coverage` |
| Source owner | Analytics (read model), sourcing CurriculumDelivery via `CurriculumCoverageReadService` |
| Dimensions | **none across Schools** — one row per School, no cross-School grouping (grade-level, subject, campus and Section names are School-defined and not comparable) |
| Metrics | `planned`, `completed`, `inProgress`, `notStarted`, `coveragePercent`, `offerings`, `offeringsWithoutSyllabus` |
| Per-School selection | the School's **active** academic year only (no fallback, no filter) |
| Classification | Confidential (inherited; section 9) |
| Counts people | no (`countsPeople: false`, enforced) |
| Aggregation | sums of counts; percentage recomputed from summed counts (section 8) |
| Suppressed / nullable | no suppression (not person-counting); `coveragePercent` is `null` when `planned` = 0 |
| Approval | ADR 0048 (owner decision, 2026-09-25) |

A guard test (0N.11) refuses any registry entry that counts people, any
second entry without a new ADR amendment, and any Group-layer code that
names a read model class.

### 7. Per-School execution and the Analytics integration contract

**Division of labour.** The Phase 0N Group layer (e.g.
`App\Domain\Platform\Application\Groups\GroupReportService`) authorizes the
request (section 2), lists the Group's current member Schools from its own
platform tables, and orchestrates. Analytics (e.g.
`App\Domain\Analytics\Application\Group\GroupSafeReportGate`) executes the
registered report for one School and aggregates. Layer 6 (Multi-School)
calls Layer 5 (Analytics) — never the reverse; Analytics never reads
`school_groups` / `school_group_members`, and the Group layer never reads a
read model, a source service or a tenant table.

**For each member School, one at a time, in one short read-only
transaction:**

1. the Group layer re-reads, `FOR SHARE`, the actor's grant (still
   unrevoked, role still holds `group.reporting.view`), the Group (still
   `active`) and the School's membership row (still a member) — the same
   rows `SchoolGroupGovernanceService` changes, so a concurrent removal,
   revocation or archive serializes with this School's read;
2. it calls the Analytics gate with an immutable authorization value
   (actor, Group id, grant id, report key) that only it constructs (guard
   test), plus the School;
3. Analytics re-checks the capability through `CapabilityResolver::canInGroup()`
   (defence in depth), checks the report is in the Group-safe registry and
   does not count people, and asserts **no School context is already set**;
4. Analytics holds the School row `FOR SHARE` through
   `SchoolOperationalGuard::holdOperational()`; not `active` → the School
   is **unavailable**, no tenant read;
5. Analytics enters `TenantContext::withSchool()` for that School only,
   computes the **Group-safe summary** (below) through the read model and
   `CurriculumCoverageReadService` (SchoolScope + RLS as always), and
6. leaves the context — `withSchool()` restores "no School" on the success
   and the failure path (the implementation additionally asserts the
   context is empty before the next School, try/finally style);
7. only the summary DTO is kept; the transaction ends; next School.

School A's and School B's contexts are never held together, and there is
never a context naming more than one School.

**Group-safe summary — the smallest Analytics contract extension.** The
current `compute()` output is a School page payload (ids, year list,
per-Offering/Section names, fallback year). Analytics adds one method on
the read model (e.g. `CurriculumCoverageReadModel::groupSafeSummary(School)`)
returning only: `hasActiveAcademicYear`, the active year's `name` and
`code` (School-owned, Confidential), and the School-level `planned`,
`completed`, `inProgress`, `notStarted`, `offerings`,
`offeringsWithoutSyllabus` (integers) — computed by the same code as
`compute()`'s `totals` for the active year, never from rendered
percentages. No ids, no names below School level, no year list. A School
with no active academic year contributes the marker, not zeros.

`analytics.view` is not required and not checked on this path (ADR 0040
§5's "derived access is its own grant" principle, applied to the Group
grant); `AnalyticsReadGate::read()` is unchanged and still the only
School-scoped path.

### 8. Aggregation semantics (source-defined)

Analytics defines, and only Analytics implements:

- Group `planned`, `completed`, `inProgress`, `notStarted`, `offerings`,
  `offeringsWithoutSyllabus` = **sums** over contributing Schools
  (unit×Section counts and Offering counts are additive; `notStarted` per
  School is already `planned − completed − inProgress` ≥ 0, so the sum
  equals the Group-level difference);
- Group `coveragePercent` = `CurriculumCoverageReadModel::percent(Σ
  completed, Σ planned)` — **unit-weighted, recomputed from counts; never
  an average of per-School percentages**; `null` when Σ planned = 0;
- per-School `coveragePercent` = `percent(completed, planned)` as today;
- Schools that are unavailable, removed, or without an active academic
  year are **excluded** from the sums and counted separately
  (`contributingSchools`, `unavailableSchools`, `noActiveYearSchools`).

The Group total means "unit coverage across each contributing School's
own current academic year" — the years are not aligned across Schools and
the page says so. No other derived metric exists in v1.

### 9. Classification

| Record | Tier |
|---|---|
| Group curriculum-coverage report output (per-School rows + Group totals) | **Confidential** — the strictest source tier (SyllabusUnit, CurriculumDelivery; the School identity shown is the already-Confidential Group-view metadata). Aggregation does **not** lower it. Per-School rows add no person data and no new category; they do reveal one School's coverage to its Group's reporting holders, which is exactly what the grant authorizes |
| Group report access evidence (`platform.school_group_report.*`) | **Highly Sensitive** (the ADR 0046 platform audit treatment) |

Provenance recorded (identifiers/codes only): actor id, Group id, grant
id, report key, contributing/unavailable School ids, counts, outcome code —
never figures, curriculum values, names or payloads.

### 10. MFA

**Current MFA assurance is required** (an enrolled factor and
`mfa_verified_at` within `mfa.assurance_window_minutes`), exactly like the
platform audit log review (ADR 0046 amendment refinement 1: the
`RequireMfa` checks and codes, `403 mfa_required_not_enrolled` / `401
mfa_step_up_required`). No fresh code per page or refresh; no new MFA
mechanism. Reason: it is the only surface that reads several Schools'
tenant-derived data in one request.

### 11. Failure and partial-result semantics

| Case | Behaviour |
|---|---|
| A. Member School not `active` (provisioning, suspended, archived), including one suspended during the report | **unavailable** marker from Group metadata; no context entered, no tenant read; excluded from totals |
| A2. Active School with no active academic year | marker "no active academic year"; excluded from totals |
| B. School removed from the Group before its turn | **omitted** — not read, not shown; the report covers current members |
| C. Grant revoked, capability removed, Group archived, account disabled — before or during execution | the whole request **fails closed** (no report shown); Schools already read are recorded in a `failed` event |
| D. Unexpected source error for any School | the whole request **fails** ("report unavailable"); **no partial aggregate, no stale substitute** |

A partial Group result is shown only for the expected cases A, A2 and B.

### 12. Consistency model

A Group report is **a bounded sequence of per-School observations**, not
a simultaneous snapshot: holding several Schools at once would violate the
one-School model, and RLS is never weakened to get simultaneity. It
records one `generated_at` and a per-School `observed_at`, and the page
says the figures were read School by School.

### 13. Scale and bounded execution

No School-count limit is set (no evidence of Group sizes beyond the
2-School demo). The implementation must nevertheless: keep only the
constant-size per-School summary (memory O(Schools), never source rows),
bound each School's work to the read model's existing fixed query set,
clean up context after every School, stop at the first failure (section
11), and log per-School timing without School data. If large Groups ever
need queued execution, that is a separate decision that keeps this
per-School isolation contract and section 15 (no persisted payload
without a classification/retention decision).

### 14. Audit

Platform ledger (`AuditRecorder::platform()`), subject = the School Group:

| Event | When | Metadata (identifiers/codes only) |
|---|---|---|
| `platform.school_group_report.viewed` | every successful review | `group_role_assignment_id`, `report_key`, `contributing_school_ids`, `unavailable_count`, `no_active_year_count` |
| `platform.school_group_report.failed` | a request that failed after authorization (case C or D) | `group_role_assignment_id`, `report_key`, `outcome_code` (`authority_lost`, `source_error`), `read_school_ids` |

No figures, curriculum values, School or year names, filters or payloads.
Refusals **before** any School is read (no grant, no capability, no MFA,
inactive Group) follow the existing Group-view convention — the same
non-disclosing 404 (or the MFA 401/403), not audited — so there is no
"audit every 403". No School-ledger event is written: the tier is
Confidential (Analytics audits School reads only from Sensitive up), and
ADR 0046 §11 keeps platform-/Group-access provenance out of School audit
review in v1. The platform auditor sees these events as envelopes only.

### 15. Persistence, cache and export

- **No persistence in v1**: computed on demand; no snapshot, warehouse,
  materialized view, multi-School cache entry or report-history table. The
  Group path uses no cache at all (Analytics caches nothing today; the
  capability check is never cached). Any future persistence needs its own
  classification and retention decision.
- **No export in v1**: no CSV, XLSX, PDF, email attachment, download,
  print-optimized export or scheduled delivery; `analytics.export` does not
  apply. Any export needs a later capability/classification/retention
  decision.

### 16. Exclusions (unchanged gates)

- **People-counting Analytics** stays closed: no minimum person-cohort
  size, suppression mode, threshold scope, Student-data counsel decision or
  teacher-linkage review is made or bypassed here; a report that counts
  people can never enter the Group-safe registry.
- **Compliance cross-School: NOT AUTHORIZED** (ADR 0042) — no School audit
  logs, evidence, legal records, subject requests or retention/legal-hold
  data; `platform.audit.view` authorizes none of it.
- **Automation cross-School: NOT AUTHORIZED** (ADR 0043) — no execution
  data read, no rule triggered, no review item created, no Group metric as
  a trigger.
- **AI cross-School: NOT AUTHORIZED** (ADR 0023, Phase 0M gate) — no
  multi-School AI context, no Group report fed to AI, no Group AI tool, no
  cross-School RAG.
- Every other Analytics report, Student/attendance/employee counts, finance
  and payroll totals: not authorized. Each needs its own ADR amendment.

### 17. Invariants

1. Group reporting needs explicit Group authority (`group.reporting.view`
   on one active grant in one active Group).
2. No platform role, platform capability or elevation implies it.
3. No School membership, School role or `analytics.view` implies it.
4. `TenantContext` is exactly one School, always.
5. RLS stays forced on every tenant table.
6. No `BYPASSRLS`, superuser or other privileged database path.
7. Only reports in Analytics' Group-safe registry participate
   (v1: `curriculum.coverage`).
8. The source domain (Analytics) owns metric and aggregation semantics.
9. The Group layer never reads tenant models, read models or source
   services.
10. Non-active Schools contribute no tenant data.
11. Group membership, grant and Group status are re-checked per School at
    execution time.
12. One Group authority per request; no pooling across Groups.
13. No persisted report payload in v1.
14. No export in v1.
15. No Compliance, Automation or AI cross-School access.
16. Audit carries identifiers and codes, never report values.
17. Unexpected source failures never yield a silent partial aggregate.
18. A Group report is a sequence of per-School observations, not a
    simultaneous snapshot.

CLAUDE.md rule 84's "a Group page reads only platform tables" becomes, at
implementation (0N.11): "…except the registered Group-safe report
executor, which reads one School at a time through Analytics' Group-safe
path"; the rule is amended in that checkpoint, not before.

### 18. Proposed Phase 0N.11 — Group Curriculum Coverage Reporting Foundation (not implemented)

1. Seed `group.reporting.view` (scope `group`) and grant it to
   `group_admin`; forget affected Group capability resolution (never
   cached anyway).
2. Analytics: `GroupSafeReportRegistry` (only `curriculum.coverage`),
   `GroupSafeReportGate`, `CurriculumCoverageReadModel::groupSafeSummary()`,
   the aggregation function; guard tests (no people-counting entry, no
   second entry, Group layer names no read model, no cache).
3. Group layer: `GroupReportService` (authorization, per-School FOR SHARE
   re-checks, orchestration, failure semantics, audit events), route
   `/app/groups/{schoolGroup}/reports/curriculum-coverage`, MFA assurance,
   Group-view link, Vue page (per-School rows, totals, markers,
   `generated_at` / `observed_at`), no API route, no export.
4. Tests: allow/deny for Group Admin with and without the capability,
   Platform Super Admin, Platform Auditor, School Admin in several member
   Schools, elevated actor, other Group's admin, archived Group, revoked
   grant; MFA 401/403; exactly-one-School context during each read and none
   after (including after a source exception); RLS proof that the path
   reads each School only under its own context; lifecycle (suspended,
   provisioning, archived member; suspension racing a report); membership
   removal and grant revocation racing a report (real two-process); two
   Groups sharing a School; aggregation math (sums, recomputed percentage,
   `null` when nothing planned, exclusions); no active academic year; no
   persistence or cache writes; audit events and metadata; DDEV review.
5. Documentation and CLAUDE.md rule 84 amendment.

Not in it: any other report, export, persistence, queued execution,
filters, drill-through, platform-wide Analytics, Compliance, Automation or
AI.

### 19. Phase 0N completion

After this ADR every Phase 0N decision (D1–D18) is decided. The roadmap's
scope is "cross-school administration **and reporting**", so a contract
alone does not complete it: **Phase 0N is architecture-complete but
implementation-in-progress until Phase 0N.11 builds and verifies the first
Group report.**

## Alternatives considered

1. **`analytics.platform.view` for platform staff.** Rejected: platform
   governance is not Group reporting authority, and a platform-wide view is
   a different, larger disclosure (ADR 0040 §4).
2. **Grant `analytics.view` (or a hidden role) in every member School to
   Group Admins.** Rejected: fake School authority, and it would open every
   School-scoped Analytics page, not one report.
3. **Reuse elevation.** Rejected: elevation grants no School capability
   (ADR 0044 D8) and is one School for 30 minutes, not a report path.
4. **A cross-School SQL query or a multi-School RLS session.** Rejected:
   violates the one-School model and the no-bypass rule.
5. **Average per-School percentages.** Rejected: mathematically wrong for
   different-sized Schools; the source counts are summed instead.
6. **Reuse `compute()`'s full output per School.** Rejected: carries a
   fallback year, internal ids and School-defined names the Group view
   does not need.
7. **Separate Analytics and Phase 0N ADRs.** Rejected: two documents would
   describe one authorization path; this ADR is both, cross-referenced from
   ADR 0040 and ADR 0045.
8. **Persist nightly Group snapshots.** Rejected for v1 (section 15).
9. **Audit every refused request.** Rejected: follows the Group-view
   convention; only reads that touched tenant data are always recorded.

## Consequences

- All Phase 0N decisions are recorded; one narrow cross-School read path is
  authorized, for one Confidential object-count report.
- The Analytics read model gains a second, Group-safe output shape.
- Group Admins will see coverage figures for every active member School of
  their Group.
- Large Groups may later need queued execution — a separate decision.

## References

ADR 0004, ADR 0017, ADR 0021, ADR 0023, ADR 0037, ADR 0040, ADR 0042,
ADR 0043, ADR 0044, ADR 0045, ADR 0046, ADR 0047;
`docs/modules/ANALYTICS.md`; `docs/security/ANALYTICS-SMALL-COHORT-POLICY-GATE.md`;
`docs/security/DATA-CLASSIFICATION.md`; `docs/security/AUTHORIZATION.md`;
`docs/architecture/TENANCY.md`; `docs/architecture/PHASE-0N-READINESS.md`;
CLAUDE.md rules 24–28, 83–86.

## Implementation amendment (Phase 0N.11, 2026-09-25)

Implemented as specified: one Group-safe report (`curriculum.coverage`),
no export, no persistence, no other report.

| Contract | Implementation |
|---|---|
| Capability (§3) | `group.reporting.view` (namespace `group`) seeded; `group_admin` holds exactly `group.reporting.view`, `group.schools.elevate`, `group.schools.view`; no platform or School role holds it |
| Registry (§6) | `App\Domain\Analytics\Application\Group\GroupSafeReportRegistry::REPORTS` = `['curriculum.coverage' => CurriculumCoverageReadModel::class]`, separate from `AnalyticsReadModelRegistry`; `GroupSafeReportDeclaration` (refuses `countsPeople: true` at construction); `GroupSafeReport`, `GroupSafeSchoolSummary` |
| Group-safe path (§7) | `GroupSafeReportGate` (registry, fresh `CapabilityResolver::canInGroupById()`, no-context assertion before and after, `SchoolOperationalGuard::holdOperational()`, `TenantContext::withSchool()`); `GroupReportAuthority` (identifiers only); `GroupReportAuthorityLostException` |
| Summary (§7) | `CurriculumCoverageReadModel::groupSafeSummary()` → `CurriculumCoverageSchoolSummary` (active year only; name/code; `planned`, `completed`, `inProgress`, `notStarted`, `offerings`, `offeringsWithoutSyllabus`; `coveragePercent` derived); `compute()` and the School page are unchanged |
| Aggregation (§8) | `CurriculumCoverageReadModel::aggregateGroup()`: sums; `percent(Σ completed, Σ planned)` |
| Group layer | `App\Domain\Platform\Application\Groups\Reporting\GroupCurriculumCoverageReportService` (authorization, MFA assurance, per-School transaction with Group → grant → membership FOR SHARE, orchestration, failure semantics, audit), `GroupReportFailedException` (503); `GroupReportController`; route `GET /app/groups/{schoolGroup}/reports/curriculum-coverage` (`app.groups.reports.curriculum-coverage`), page `App/Groups/CurriculumCoverageReport`, link on the Group view when the capability is held |
| Audit (§14) | `platform.school_group_report.viewed` (`group_role_assignment_id`, `report_key`, `contributing_school_ids`, `unavailable_count`, `no_active_year_count`); `platform.school_group_report.failed` (`group_role_assignment_id`, `report_key`, `outcome_code` `authority_lost`/`source_error`, `read_school_ids`) |

**Refinements made while implementing:**

1. **Lock order Group → grant → membership → School** in each
   observation — the order `SchoolGroupGovernanceService::archive()` takes
   the Group and its grants — so a racing archive, revocation or removal
   waits instead of deadlocking. Proven with real two-process races
   (`GroupReportConcurrencyTest`): a removal or revocation that commits
   first is seen (School omitted / report fails closed); one that arrives
   while an observation holds the rows waits, and the completed report
   was authorized throughout.
2. **Id-based Group capability check.** Analytics may not import the
   School Group model (ADR 0040 §3 guard), so `CapabilityResolver` gained
   `groupCapabilitiesById()` / `canInGroupById()` (same query, uncached);
   `groupCapabilities()` delegates to it.
3. **The summary reuses the source contract, not `compute()`:**
   `groupSafeSummary()` sums the same `CurriculumCoverageReadService`
   counts `compute()` sums for `totals`; a test pins the two equal for the
   active year, and `compute()`'s fallback-year behaviour is unchanged
   (tested).
4. **Row order and states.** Schools are observed in School-id order
   (deterministic) and shown by name; states `included`,
   `no_active_academic_year`, `unavailable`. `generatedAt` and per-School
   `observedAt` are shown; the page says the Schools are read one at a
   time.
5. **Failure responses.** Lost authority (revoked grant, capability
   removed, Group archived mid-report) → the Group view's non-disclosing
   404; a source failure → 503 page with no figures. Both audited; neither
   returns anything partial. A request refused before any read (no grant,
   no capability, inactive Group, MFA) is not audited.
6. **Guards.** `Tests\Feature\Analytics\GroupSafeReportGuardTest`
   (one registry entry; ordinary registration ≠ Group-safe; no person
   dependency; Group layer never touches sources, Analytics never reads
   Group tables; only the service mints `GroupReportAuthority`; only the
   gate calls `groupSafeSummary()`; `group.reporting.view` only on
   `group_admin`; one read-only route, no export, cache, persistence or
   side effect; no Compliance/Automation/AI path). The existing Analytics
   layering guard now allows exactly the Layer 6 reporting directory to
   depend on Analytics; the Group guard forbids the reporting code from
   setting a School context.
7. CLAUDE.md rule 84 is amended with the one narrow exception.
