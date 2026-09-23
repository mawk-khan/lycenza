# Phase 0L.2 Analytics Foundation — Readiness & Small-Cohort Policy Gate

**Status: BLOCKED — decision required.** Recorded 2026-09-23 on `main`
at `f074c01`. This is a readiness and policy-gate record, not an
implementation checkpoint: no Analytics code, migration, route,
capability or configuration value exists or is introduced here.

**Phase 0L.2 cannot expose any aggregate that could produce a
small-cohort cell until the decisions in section 7 are made and
recorded.** Nothing in this document makes those decisions, and nothing
here may be read as an approved threshold.

Governing sources: ADR 0040 (`docs/architecture/adr/0040-analytics-domain-contract.md`),
`docs/modules/ANALYTICS.md`, `docs/security/DATA-CLASSIFICATION.md`
("Aggregated / derived / statistical data (Analytics)" and "Children's
data specifically" rows), `docs/security/AUTHORIZATION.md`,
`docs/architecture/TENANCY.md`.

---

## 1. What the repository already establishes

| Topic | Established | Source |
|---|---|---|
| Phase 0L.2 purpose | "one or two already-stable Layer 0–4 sources, a real, minimal, capability-gated, single-School read surface, following ADR 0040 exactly — no dashboard/API/schema exists yet" | MASTER-ROADMAP Phase 0L; ADR 0040 Consequences |
| Module | `App\Domain\Analytics`, internal Layer 5; no write path to any business fact | ADR 0040 §1 |
| Source access | Only through each source module's own Application-layer read contract; adding a missing contract is the source module's own work | ADR 0040 §3 |
| Degraded sources | Missing source → surface not registered; unpublished feature → not used; incomplete source (e.g. no marks) → metric omitted | ADR 0040 §3 |
| Tenancy | Single-School only; no `BYPASSRLS`, no superuser, no Analytics RLS exception; cross-School deferred to its own ADR | ADR 0040 §4 |
| Capabilities | `analytics.view`, `analytics.export`, `analytics.platform.view` (spelling frozen, **not seeded**; seeded "at implementation time (Phase 0L.2)") | ADR 0040 §5 |
| Access separation | Source-record access ≠ analytics access (both directions); drill-down also needs the row's own source capability; export ≠ view | ADR 0040 §5; AUTHORIZATION.md |
| Classification | A derived value inherits the strongest tier of its sources; downgrade only if the cell meets the threshold, no dimension combination re-identifies, and it does not disclose Highly-Sensitive membership | ADR 0040 §6; DATA-CLASSIFICATION.md |
| Suppression RULE | A cell below the minimum-cohort threshold is **omitted, bucketed ("fewer than N"), or the grouping declined — never an exact small number**; it is the CELL size, not the total, that must clear the threshold | ADR 0040 §6 |
| Suppression THRESHOLD | **Not set.** `[LEGAL/PRODUCT/SECURITY REVIEW REQUIRED]`. "No Analytics read model capable of producing a small-cohort cell may ship … until this threshold is set." | ADR 0040 §6; ANALYTICS.md §6; DATA-CLASSIFICATION.md |
| Read models | Synchronous, on-demand, from current source data; one thin purpose-built read model + dashboard per need; no warehouse/ETL/BI/materialization/snapshots/caching | ADR 0040 §7, §9, §10 |
| Outbox | Approved future seam only; no consumer in 0L.2 | ADR 0040 §8 |
| Student data processing | Any module processing Student data outside StudentMark's approved scope "must still be reviewed by qualified counsel before it ships to real schools" | DATA-CLASSIFICATION.md, "Children's data specifically" |

## 2. Small-cohort policy evidence (repository-wide search)

Searched all code, config, migrations, tests and docs for: minimum
group/cohort/cell size, small-cohort suppression, statistical disclosure,
low-count suppression, k-anonymity, de-identification/anonymisation,
re-identification, "fewer than N", and any `min_cohort`-style key.

**Result: category D — a legal-review placeholder only.** The rule's
shape is frozen (ADR 0040 §6), but there is:
- no numeric threshold anywhere (category A — absent);
- no configuration key or code for one, even unset (B — absent);
- no per-classification thresholds (C — absent);
- no suppression code of any kind.

Adjacent precedent: `App\Domain\Communications\Application\CommunicationDeliveryAnalyticsReadModel`
(Phase 5A.11) returns exact grouped delivery counts with no suppression.
It is Communications-internal delivery telemetry that predates ADR 0040,
which left it outside the Analytics context; it is not a precedent for
suppression and is not changed here.

## 3. Phase 0L.2 scope, reconstructed

| Requirement | Source | Current state | Needed for 0L.2 |
|---|---|---|---|
| `App\Domain\Analytics` bounded context | ADR 0040 §1 | Absent | Create |
| Seed `analytics.view` / `analytics.export` | ADR 0040 §5 | Not seeded | Seed; **which roles receive them is a product decision (7.5)** |
| `analytics.platform.view` | ADR 0040 §5 | Spelling only | **Not in 0L.2** — cross-School deferred |
| Source read contract(s) for 1–2 stable sources | ADR 0040 §3 | No aggregate read contract exists in any module; Attendance, Examinations, CurriculumDelivery, Inventory have no read service at all | The chosen source module(s) add aggregate read methods under their own rules |
| One thin read model + dashboard per need | ADR 0040 §7 | Absent | Build for the chosen source(s) |
| Small-cohort suppression on every cell that can be small | ADR 0040 §6 | Threshold unset; no code | **Blocked** (section 7) |
| Fail-closed tenancy (RLS, TenantContext) | ADR 0040 §4 | Platform-wide | Reuse; add isolation tests |
| Export | ADR 0040 §1, §5 | Absent | Only if in chosen scope (7.6); distinct capability |
| Audit of Sensitive/Highly-Sensitive access | ADR 0017; DATA-CLASSIFICATION "Audit" | Mechanism exists (`AuditRecorder`) | Audit analytics reads/exports of such data |
| Counsel review before Student-data Analytics reaches real schools | DATA-CLASSIFICATION, Children's data | Not requested | **Separate gate** (7.4) — independent of the threshold |
| Cross-School analytics, Compliance, Automation, snapshots, caching, outbox consumers | ADR 0040 §1, §4, §8, §9, §10 | Deferred | **Not 0L.2** (later checkpoints) |
| Examination performance analytics | ADR 0040 §3 | Marks/results not implemented (StudentMark legally gated) | **Not possible** — metric omitted |

## 4. Analytics source data and classification

"Default aggregate tier" applies ADR 0040 §6: the strongest tier of the
sources, where Students are children ("Children's data specifically" =
Highly Sensitive). Tier names are `DATA-CLASSIFICATION.md`'s.

| Dataset | Row tier | Default aggregate tier | Tenant scope | Small-cohort risk | Existing authorization | Read contract today |
|---|---|---|---|---|---|---|
| Student enrollment / headcounts by grade, section | Student data: Sensitive; children: Highly Sensitive | Highly Sensitive | School (RLS) | High (a Section/Grade cell can be tiny) | `students.view`, `enrollments.view` | `StudentEnrollmentReadService` (row/paginated only) |
| Student "demographics" | Only name, date of birth, status exist (no gender/religion/caste fields) | Highly Sensitive | School | High (age × section) | `students.view` | none for aggregates |
| Student attendance | Sensitive (explicitly not Highly Sensitive); children | Highly Sensitive | School | High ("attendance rate for a very small class" is ADR 0040's own example) | `attendance.view` | **none** (only submission/correction services) |
| Admissions applicants | Applicants are children | Highly Sensitive | School | High | `admissions.view` | `AdmissionApplicationReadService` (row/paginated) |
| Examination results / performance | — | — | — | — | — | **Not implemented** (StudentMark) → omitted |
| Examination definitions, papers, grade scales | Confidential | Confidential | School | None (no data subjects) | `examinations.*.view` | none |
| Curriculum delivery (syllabus coverage) | Confidential | Confidential | School | None (cells are units/Sections, no data subjects) | `curriculum.delivery.view` | none |
| Timetable | Sensitive (teacher assignment) | Sensitive | School | Medium (per-teacher cells) | `timetable.*.view` | none |
| Fees / charges / payments | Financial data: Highly Sensitive (+ children) | Highly Sensitive | School | High ("students with outstanding fees" is ADR 0040's example) | `finance.charges.view`, `finance.payments.view` | `ChargeReadService`, `PaymentReadService` (row/paginated) |
| Finance ledger | Financial data: Highly Sensitive | Highly Sensitive | School | Low for account totals; not person-level | `finance.ledger.view` | `LedgerReadService` (row/paginated) |
| HR employees | Sensitive (HS for identifiers, bank, health) | Sensitive or higher | School | High (small departments) | `hr.employees.view` | `EmployeeDirectoryService` |
| Payroll | Financial data: Highly Sensitive | Highly Sensitive | School | Very high ("payroll statistics for a small department" is ADR 0040's example) | `payroll.*` | Payroll read services (row-level) |
| Communications delivery | **No row in DATA-CLASSIFICATION.md** | **Unclassified** | School | Medium | `communications.*` | Communications-internal read models |
| Library loans, transport, hostel residency, visitor | Sensitive (children for loans/transport/hostel) | Highly Sensitive (student ones) / Sensitive (visitor) | School | High | module `.view` capabilities | none |
| Canteen orders | Highly Sensitive | Highly Sensitive | School | High | `canteen.orders.view` | none |
| Inventory stock | Confidential (no personal data) | Confidential | School | None | `inventory.*.view` | none |

**Should NOT be part of the initial Analytics Foundation** (independent of
the threshold choice): payroll and fees/payments/charges and canteen
orders (Highly Sensitive financial/children's data and ADR 0040's own
worst-case examples); examination performance (source not implemented);
communications (unclassified — needs a classification decision first);
anything cross-School.

## 5. Engineering assumptions that are PROHIBITED

- Choosing, defaulting, or "temporarily" hard-coding any threshold value
  (including in tests fixtures presented as policy, seeders, `.env.example`
  or DDEV config).
- Treating an unset threshold as "no suppression".
- Treating a School-wide total, or a percentage/average, as safe merely
  because it is not a raw count.
- Reading another module's tables/models directly for Analytics.
- Granting `analytics.*` to a role, or assuming source access implies
  analytics access (or the reverse).
- Shipping Student-data Analytics to a real School before the counsel
  review in 7.4.
- Adding caching, materialization, snapshots or an outbox consumer.

## 6. Technical suppression-policy contract (proposed; values undecided)

This is the software shape Phase 0L.2 would implement once section 7 is
decided. It deliberately contains no numeric value.

1. **Where the policy lives.** A single platform-level configuration
   (e.g. `config/analytics.php`) read by one Analytics suppression
   component. **Unset = fail closed**: any read model that declares
   person-derived cells refuses to register/serve (feature unavailable),
   and a boot/architecture test fails if such a read model exists without
   a configured value. Whether a School may make it stricter (never
   looser) is decision 7.3.
2. **Per read model declaration.** Every Analytics read model declares:
   its source datasets and resulting tier; whether its cells count data
   subjects (people); its grouping dimensions; the suppression mode it
   uses. An architecture guard test fails for any read model without
   the declaration.
3. **Below threshold.** Per ADR 0040, only: omit the cell, bucket it
   ("fewer than N"), or decline the grouping. The API returns an explicit
   marker (e.g. `{ "value": null, "suppressed": true, "reason":
   "below_minimum_cohort" }`), never the count. The mode is decision 7.2.
4. **Totals and complementary disclosure.** A shown total must not let
   a suppressed cell be recovered by subtraction: if exactly one cell in
   a group is suppressed, a second cell (the next smallest) is also
   suppressed, or the total is withheld. The same applies across rows and
   columns of a cross-tab.
5. **Derived metrics.** Rates, averages and percentages are computed only
   for cells whose underlying count (numerator population and
   denominator) clears the threshold; a suppressed cell's derived values
   are suppressed too.
6. **Filters and differencing.** Filter granularity (date windows,
   dimension combinations) is limited so that two overlapping queries
   cannot be subtracted to expose a small cell; suppression is evaluated
   on every response, after all filters. The permitted granularity is
   part of decision 7.2.
7. **Drill-down.** Never into a suppressed cell. Any drill-down requires
   the row's own source capability in addition to `analytics.view`
   (ADR 0040 §5).
8. **Exports.** Produce exactly the suppressed output (never raw
   values), require `analytics.export`, and are audited.
9. **APIs.** Same suppression and markers as the UI; no parameter can
   disable suppression.
10. **Caching.** None in v1 (ADR 0040 §10). If ever added: cache only
    post-suppression output, `TenantCache`-namespaced.
11. **Audit.** Analytics reads/exports of Sensitive or Highly Sensitive
    aggregates are audited (actor, School, read model, filters — never
    the values) per ADR 0017.
12. **Authorization and tenancy.** `analytics.view`/`.export` through the
    ordinary capability middleware; RLS + TenantContext; cross-School
    isolation tests for every read model.
13. **Tests.** Threshold boundary (N−1 suppressed, N shown); complementary
    suppression; derived metrics; filter differencing; fail-closed when
    unset; export parity with the UI; API cannot bypass; tenant isolation;
    authorization allow/deny for view vs export vs drill-down.

## 7. Decisions required (options, not recommendations)

| # | Decision | Options (from this repository's architecture) | Privacy impact | Product impact | Engineering impact | Approval |
|---|---|---|---|---|---|---|
| 7.1 | **Minimum cohort size (N)** | (a) one value for all person-derived cells; (b) separate values for Sensitive vs Highly Sensitive aggregates; (c) no value yet — restrict 0L.2 to sources with no data subjects (7.7) | (a)/(b) enable person-level analytics with a defined disclosure floor; (c) no person-derived output at all | (a)/(b) allow attendance/enrollment dashboards; small Schools/Sections see more suppressed cells as N rises; (c) delays people analytics | (a) one value; (b) tier lookup; (c) none now | **LEGAL/privacy** (children's data, DPDP) + **PRODUCT** + **SECURITY** |
| 7.2 | **Suppression mode and granularity** | omit the cell / "fewer than N" bucket / decline the grouping (ADR 0040's three); plus allowed filter granularity (e.g. only month-level windows, limited dimension combinations) | bucket shows existence; omit/decline hide it; coarser filters reduce differencing | readability vs. detail | same engine either way | **PRODUCT** + **SECURITY** |
| 7.3 | **Policy scope** | platform-wide only / platform floor that a School may make stricter (never looser) | a School-lowered value would weaken protection, so "stricter only" or none | per-School flexibility | small | **SECURITY** + **PRODUCT** |
| 7.4 | **Counsel review for Student-data Analytics** (required by the Children's-data row before real Schools) | request review now for the chosen Student-data metrics / build behind a default-off feature flag and review before enabling / exclude Student data from 0L.2 | review gates real-School processing of children's data | Student dashboards unavailable to real Schools until reviewed | feature flag already exists (`FeatureFlagResolver`, as for P2) | **LEGAL** |
| 7.5 | **Who holds `analytics.view` / `analytics.export`** | not granted to any system role (demo/custom roles only) / `school_admin` / `school_admin` + `principal`; export separately | wider grant = wider exposure of aggregates | who sees dashboards | seeder change | **PRODUCT** + **SECURITY** |
| 7.6 | **First source(s) and whether export is in 0L.2** | Candidates with a stable source: enrollment headcounts; attendance rates; curriculum-delivery coverage; inventory stock; (not: payroll, fees, canteen orders, exam results, communications — section 4) | depends on tier (section 4) | first visible dashboard | each source needs its own aggregate read contract first | **PRODUCT** |
| 7.7 | **Does the small-cohort gate apply to cells with no data subjects?** ADR 0040 defines a cohort as "the set of underlying rows" and requires suppression for any grouping that "could produce a small cell", while its rationale is individual re-identification. | (a) gate applies to all cells (then Confidential sources such as curriculum coverage and inventory are also blocked); (b) gate applies only to person-derived cells (Confidential, no-data-subject sources could ship before N is set) | (b) no individual can be identified from syllabus-unit or stock counts | (b) permits a first 0L.2 dashboard now | (b) needs the per-read-model "counts data subjects" declaration (6.2) | **SECURITY** + **PRODUCT** (an interpretation of ADR 0040; record it as an ADR 0040 amendment) |

**What engineering can safely do independently (not done here):** build
the suppression component with a fail-closed "unset" path and its tests,
the per-read-model declaration and architecture guard, and source-module
aggregate read contracts — none of which exposes any aggregate. Seeding
capabilities or grants, shipping any dashboard, and choosing any value
all wait for the decisions above.

## 8. Proposed Phase 0L.2 checkpoints (after the decisions)

ADR 0040 does not define sub-checkpoints; this breakdown is a proposal.

| Checkpoint | Scope | Likely files | Migrations | Capabilities | Tests | DDEV | Depends on |
|---|---|---|---|---|---|---|---|
| **0L.2-1 Suppression policy & capabilities** | `config/analytics.php` (fail-closed), suppression component, read-model declaration contract + architecture guard; seed `analytics.view`/`.export` with the grants from 7.5 | `app/Domain/Analytics/Application`, `config`, `CapabilityAndRoleSeeder` | none | seed 2 | boundary, complementary, derived, fail-closed, guard | none visible | 7.1–7.3, 7.5, 7.7 |
| **0L.2-2 Source read contract** | aggregate read method(s) in the first source module (e.g. `StudentEnrollmentReadService` or a new Attendance/CurriculumDelivery read service), under that module's rules | source module `Application/` | none | source module's own | tenancy, authorization, correctness | none | 7.6 |
| **0L.2-3 First read model + dashboard** | one thin read model + Inertia page + optional `/api/v1` read; audit of Sensitive/Highly-Sensitive reads; feature-flagged if Student data (7.4) | `app/Domain/Analytics`, `resources/js/Pages/App/Analytics`, routes, OpenAPI + shared types | none (feature-flag row only if used) | uses `analytics.view` | suppression end-to-end, isolation, allow/deny, API cannot bypass | demo persona(s) per 7.5 | 0L.2-1, 0L.2-2, 7.4 |
| **0L.2-4 Export (if in scope)** | suppressed export, `analytics.export`, audit | Analytics controllers | none | uses `analytics.export` | export parity, deny without export | yes | 7.6 |
| **0L.2-5 Closeout** | second source only if 7.6 chose two; docs, DDEV review, closeout report | docs | none | — | full regression if due | yes | above |

No migration is expected (read models are on-demand; ADR 0040 §7), apart
from a feature-flag catalog row if 7.4 chooses a flag.

## 9. What unblocks implementation

A recorded decision (owner, date, and the approving product/privacy/
security role(s)) for **7.1, 7.2, 7.3, 7.5, 7.6 and 7.7**, plus — for any
Student-data source — the route chosen under **7.4**. Record it as an
amendment to ADR 0040 §6 (and `DATA-CLASSIFICATION.md`'s Analytics row),
then Phase 0L.2 checkpoint 0L.2-1 can start.
