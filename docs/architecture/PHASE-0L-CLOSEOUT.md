# Phase 0L — Oversight: Closeout

**Status: COMPLETE (2026-09-24).** Phase 0L delivered a published domain
contract and a published foundation for each of its three Layer 5
modules — Analytics, Compliance and Automation. Everything not built is
either optional future scope or blocked on a recorded owner, legal,
product or security decision (section 6). Closing the phase approves,
schedules or implies none of those capabilities.

Roadmap entry: `docs/roadmap/MASTER-ROADMAP.md`, "Phase 0L — Oversight"
(*"Compliance, Analytics, Automation (Layer 5) — read-mostly consumers of
everything built so far"*). Layer 5 rules: `docs/architecture/DOMAIN-MAP.md`.

## 1. How Phase 0L unfolded

The roadmap entry was a single paragraph. ADR 0040 split it into three
separate checkpoints, each needing its own contract before any code. The
order and numbering after Phase 0L.2 were set by the product owner
(ADR 0042 and ADR 0043 record those decisions).

| Subphase | Title | Status | Published on `main` |
|---|---|---|---|
| 0L.1 | Analytics Domain Contract | Complete (2026-09-05) | `2a0d14c` (ADR renumbered to 0040 in `09ce0c1`) |
| 0L.2 | Analytics Foundation (readiness gate `0f05fcb`; built as 0L.2-1 `1cf2a5f`; regression fixes `8eb5b74`) | Complete (2026-09-23) | closeout `93cc44e` |
| 0L.3 | Compliance Domain Contract | Complete (2026-09-24) | `bcafd2e` (commit `3e9a936`) |
| 0L.4 | Compliance Foundation: School Audit-Log Review | Complete (2026-09-24) | `b67aecc` (commit `ce73de5`) |
| 0L.5 | Automation Domain Contract | Complete (2026-09-24) | `7d1dc13` (commit `1f04653`) |
| 0L.6 | Automation Foundation: Academic Year Setup Review | Complete (2026-09-24) | `8a9825f` (commit `0eb8a16`) |

| Area | Contract | Foundation | Status | Remaining gates |
|---|---|---|---|---|
| Analytics | 0L.1, ADR 0040 | 0L.2 (Curriculum Coverage) | Foundation complete | Section 6.1 |
| Compliance | 0L.3, ADR 0042 | 0L.4 (School audit-log review) | Foundation complete | Section 6.2 |
| Automation | 0L.5, ADR 0043 | 0L.6 (academic year set-up review) | Foundation complete | Section 6.3 |

## 2. What was built

### Analytics (ADR 0040; `docs/modules/ANALYTICS.md` §13–§14; `PHASE-0L2-ANALYTICS-FOUNDATION-CLOSEOUT.md`)

- `App\Domain\Analytics` — read-only: `AnalyticsReadGate`, read-model
  declarations and registry, `CohortSuppressionPolicy`.
- One non-person report: Curriculum Coverage
  (`/app/analytics/curriculum-coverage`), reading Curriculum Delivery's
  aggregate contract.
- `analytics.view` for School Admin and Principal; `analytics.export`
  seeded, granted to no role, no export exists; `analytics.platform.view`
  not seeded.
- Any read model that counts people fails closed (HTTP 503) while
  `analytics.minimum_person_cohort_size` is unset — it has no default.
- Single-School; no cross-School Analytics, cache, snapshot, warehouse or
  event consumer.

### Compliance (ADR 0042 and its 0L.4 amendment; `docs/modules/COMPLIANCE.md` §8)

- `App\Domain\Compliance\Application\AuditLogReviewService` — read-only;
  its only write is its own access-audit event
  (`compliance.audit_log.viewed`, once per review).
- The ledger's read contract `App\Support\Audit\SchoolAuditEventReader`:
  one School, envelope columns only (`metadata` never selected), keyset
  pagination, no platform ledger.
- `/app/compliance/audit-log` under `school.audit.view`, held by School
  Admin and Principal only. School audit records treated as Highly
  Sensitive for this surface (conservative v1); metadata allowlist empty.
- The page makes no legal-compliance claim. `compliance.*` capabilities
  remain reserved and unseeded.

### Automation (ADR 0043 and its 0L.6 amendment; `docs/modules/AUTOMATION.md` §9)

- `App\Domain\Automation`: code catalog with one rule type,
  `academic_year.setup_review`, triggered by the existing
  `academic_year.activated.v1` event; its only effect is a tier 0
  informational review item in Automation's own table.
- School opt-in flag `automation.rules`, default off (Demo School on,
  Annexe off in DDEV). `automation.view` for School Admin and Principal,
  `automation.manage` for School Admin only; `automation.platform.view`
  not seeded.
- Every execution re-verifies the accountable owner (active account,
  active membership, `automation.manage`, `academics.years.view`);
  failure skips and suspends the rule, with no fallback actor. No service
  identity.
- One outbox consumer (`automation-trigger`), `RunAutomationExecutionJob`
  with a lease claim and bounded domain retry,
  `automation:executions-redispatch`; UNIQUE executions and review items;
  loop guard for Automation-originated events; daily cap.
- Four tenant-owned tables with RLS and composite `(id, school_id)`
  foreign keys; attempts and review items append-only.
- No tier 1–4 action exists.

## 3. Tenancy and security model (common to all three)

- Layer 5 consumers only: no Layer 0–4 module depends on them
  (architecture guard tests for Compliance and Automation).
- Single-School under the normal isolation stack — `SchoolScope`,
  forced RLS, tenant-aware jobs — with no `BYPASSRLS`, superuser path or
  implicit Platform Super Admin access. No cross-School or platform view
  exists in any of the three.
- Reads go through source modules' contracts (Curriculum Delivery's
  aggregate contract, the audit ledger's read contract, the outbox);
  none reads another module's tables.
- Authorization by capability only; access to derived or evidence data
  never implies access to source records, and the reverse
  (`docs/security/AUTHORIZATION.md`, Layer 5 principle).
- Classification follows `docs/security/DATA-CLASSIFICATION.md`:
  derived data inherits the strictest source tier; audit records
  Highly Sensitive for the review surface; Automation records hold
  ids, codes and timestamps only.

## 4. Regression evidence

Authoritative full regression on `main` at `8a9825f` (2026-09-24),
isolated Compose project with PostgreSQL, Redis and real MinIO:
**5,457 tests, 5,456 passed, 0 failed, 1 skipped** (the deliberate
ESI-12 legal skip), 27,842 assertions. Per area: Analytics 62, Compliance
17, Automation 30, feature-flag/worker-scope 5, real-process concurrency
80, PostgreSQL/RLS 770, real MinIO 6. In DDEV, one long-running queue
worker processed alternating Demo/Annexe activation events with opposite
flag states in two rounds; every execution landed in the correct School,
with no cross-School row and no failed job. No repository change was
needed.

## 5. Intentionally absent from Phase 0L

Person-counting Analytics, Analytics export, cross-School Analytics,
dashboards beyond Curriculum Coverage, caching/snapshots/warehouse;
Compliance filters/search/export, platform audit review, statutory
reporting views, retention/erasure/legal-hold tooling; Automation tier
1–3 actions, schedule triggers, manual run, further rule types,
cross-School Automation and Automation export. None is a Phase 0L
closure requirement.

## 6. Gated future capabilities (not incomplete foundation work)

### 6.1 Analytics (`ANALYTICS-SMALL-COHORT-POLICY-GATE.md` §7)

| Gate | Status |
|---|---|
| 7.1 Minimum person-cohort size | OPEN — blocks every person-counting read model |
| 7.2 Suppression mode and granularity | OPEN |
| 7.3 Threshold policy scope | OPEN |
| 7.4 Counsel review for Student-data Analytics | OPEN |
| Cross-School Analytics ADR | Not started |
| Section coverage ↔ teacher linkage | Not proposed; needs a privacy/classification review first |
| Analytics export | Optional; `analytics.export` ungranted |

### 6.2 Compliance (ADR 0042 §13, `COMPLIANCE.md` §5)

Permanent classification of all audit records (the review surface uses
the v1 Highly Sensitive treatment); platform audit review; retention
periods **[LEGAL REVIEW REQUIRED]**; legal holds and audit evidence
surviving School deletion **[LEGAL REVIEW REQUIRED]**; data-subject
requests, correction, erasure **[LEGAL REVIEW REQUIRED]**; statutory
reporting beyond Payroll's data preparation **[LEGAL REVIEW REQUIRED]**;
consent/processing-authorization evidence views; cross-School
Compliance (own ADR); Compliance exports.

### 6.3 Automation (ADR 0043, `AUTOMATION.md` §5)

Tier 1 internal notifications through Communications (authorship and
approval decision); tier 2 automation-safe source commands (per-command
decision); tier 3 approval-required actions (human-approval design shared
with Phase 0M); execution-history retention **[LEGAL/POLICY REVIEW
REQUIRED]**; platform/cross-School Automation (own ADR); Automation
exports. Tier 4 actions stay prohibited.

## 7. Known technical debt — not part of Phase 0L closure

Recorded, not fixed by this closeout.

**Repository technical debt**

- `CommunicationAudienceResolverRegistry` (singleton) holds a
  `TenantContext` through `IndividualMembersAudienceResolver` — the same
  pattern as the `FeatureFlagResolver` defect fixed in 0L.6, but not
  reachable from any long-running process today (only
  `AnnouncementService` uses it; no queue job or outbox consumer does).
- `App\Listeners\RecordQueueHeartbeat` registered twice.
- `domain_event_outbox.status` is never set to `failed`.
- `OperationalStatusService` does not watch the Communications scheduler
  heartbeats or the `notifications` queue.
- `docs/architecture/EVENTS.md` envelope table omits `correlationId`,
  `causationId`, `requestId`; several controllers emit events despite its
  guidance.
- `App\Models\ServiceIdentity`'s docblock cites a non-existent ADR.
- In-page JSON `fetch()` helpers show their own error after a session
  ends (the next Inertia navigation performs the safe transition;
  `docs/security/AUTHORIZATION.md`).

**Unrelated roadmap debt**

- Stale wording that still describes statutory Payroll (9.6) as gated
  (`docs/modules/PAYROLL.md`, the Phase 0J roadmap paragraphs, two code
  comments).
- The `students.processing_authorizations` feature flag is seeded but
  checked nowhere.

## 8. Next roadmap dependency — Phase 0M

`MASTER-ROADMAP.md`: **"Phase 0M — AI Platform: Real Agents"** — first
real model-provider integration (ADR 0013), first agent/tool with a
Laravel-side write effect, first human-approval workflow for
financial/irreversible actions.

Its gate: the **[LEGAL/COMPLIANCE REVIEW REQUIRED]** provider
data-handling review (`docs/security/DATA-CLASSIFICATION.md`, "AI
prompts/context" row; `docs/ai/AI-SECURITY.md`: provider retention and
training-data use are "a per-provider legal/contractual concern to
evaluate before integrating any real provider") must be completed first.

No Phase 0M readiness-gate document exists yet. The next task is to
write one — the decision request that sets out what the legal/compliance
review must answer before any provider is integrated (in the style of
`ANALYTICS-SMALL-COHORT-POLICY-GATE.md` and
`LMS-SUBMISSION-LEGAL-REVIEW-REQUEST.md`) — not to start Phase 0M.
*(Follow-up: written the same day as
`docs/security/AI-PROVIDER-LEGAL-COMPLIANCE-GATE.md`; Phase 0M remains
BLOCKED.)*
