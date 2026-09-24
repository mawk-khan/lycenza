# Compliance (Phase 0L.3 contract)

Status: **domain contract only — nothing is implemented.** Decision
record: ADR 0042 (`docs/architecture/adr/0042-compliance-domain-contract.md`).
This document is the living reference: what exists today, the boundary
in detail, the open gates and the implementation plan. No migration,
model, service, route, capability seed or page exists for Compliance.

## 1. Scope in one paragraph

Compliance is the Layer 5 module for regulatory reporting and
statutory record-keeping (`docs/architecture/DOMAIN-MAP.md`). It owns
read-only, access-controlled **evidence views** and **regulatory-reporting
views** over records other modules already keep. It owns no source
record, calculates nothing, generates no filing, deletes nothing, sets
no policy and never claims that anyone is compliant with a law
(ADR 0042 §1).

## 2. Existing compliance-relevant functionality (audit of `d50c246`, 2026-09-24)

Compliance reads these; it owns none of them.

| Area | What exists | Owner | Nature |
|---|---|---|---|
| Audit ledgers | `platform_audit_events` (no `school_id`, no RLS) and `school_audit_events` (RLS; indexes on `school_id`, `(school_id, event_type)`, `occurred_at`, `(school_id, subject_type, subject_id)`). Only writer: `App\Support\Audit\AuditRecorder` (`platform()`, `school()`). Append-only via `TenantRls::makeAppendOnly()` (UPDATE/DELETE revoked from `school_os_app`); not hash-chained. `school_audit_events.school_id` cascades on School deletion. | Cross-cutting infrastructure (ADR 0017) | Existing |
| Audit review | `App\Domain\Communications\Application\CommunicationAuditReadModel` (one Announcement at a time, metadata allowlist, `communications.audit.view`); HR's `EmployeeActivityTimelineService`. **No general audit viewer.** `school.audit.view` is seeded, granted to `school_admin`/`principal`, and checked nowhere. Nothing reads `platform_audit_events`. | Communications, HR | Existing (module-local) / missing (general) |
| Authentication audit | `auth.login_succeeded`, `auth.login_failed`, `auth.login_denied_disabled_user`, `auth.logout`, MFA events (`App\Support\Auth\Mfa\MfaAuditActions`) — platform ledger. Lockout fires `Illuminate\Auth\Events\Lockout` with no listener: **not audited**. | Identity & Access | Existing, one gap |
| Denied-access audit | Required by ADR 0017 "in later phases"; not implemented (`EnsureCapability` writes no audit). | Identity & Access | Missing |
| Authorization changes | Role/membership/platform-role assignments are written only by seeders; no runtime grant/revoke path exists, so none is audited. Scope triggers enforce school vs platform roles (CLAUDE.md rule 25). | Identity & Access | Missing (no write path yet) |
| Sensitive reads | Audited reads: `document.sensitive_*` (Highly Sensitive only), `hr.employee_document.sensitive_viewed`, `payroll.payslip.viewed`, `payroll.run.results_viewed`, `payroll.compensation.values_viewed`, `payroll.statutory.identifier.revealed`, Finance/Payments/Fees list/detail views, `analytics.report_viewed`, `communication_attachment.downloaded`. Guardian contact and HR personal-detail reads are not audited. Searchable encrypted PII: ADR 0041. | Each owning module | Existing, partial |
| Integrations | `webhook_deliveries` (prunable), `webhook_delivery_attempts` (append-only), `integrations.webhook_*` audit events; `payment_provider_events` (append-only, Payments). | Integrations / Payments | Existing |
| Immutable financial records | `journal_entries`/`journal_lines` (append-only, balanced-posting triggers, single reversal); `charges` (immutability triggers); `payments`, `payment_allocations`; Payroll postings, adjustments, frozen results. | Finance, Fees, Payments, Payroll | Existing |
| Approvals | Communications approval requests (fingerprinted, self-approval refused); Payroll run approval (`payroll.runs.approve`, self-approval refused). | Communications, Payroll | Existing |
| Consent and legal basis | `student_processing_authorizations` (ADR 0038; immutable by triggers; `StudentProcessingAuthorizationReadService`; feature flag `students.processing_authorizations` seeded but not checked anywhere); `communication_domain_consent_events` (append-only); Guardian invitation/activation records. | Students, Communications, Identity | Existing |
| Statutory Payroll | Calculation owned by Payroll (ADR 0034/0036); data-preparation exports `StatutoryEcrExportService`, `StatutoryEsiContributionWorksheetExportService`, `StatutoryTdsDraftStatementExportService` (audited, `payroll.statutory.exports.generate`, granted to no role). ESI-12 disability threshold deferred for legal clarification. **No read contract for a Compliance consumer exists.** | Payroll | Existing / contract missing |
| Retention | `platform:idempotency-prune` (48 h TTL, daily 02:10); `platform:webhook-deliveries-prune` (no default period — deletes nothing, daily 02:20). Nothing else is pruned; audit ledgers, outbox, documents and processing authorizations are kept indefinitely. | Owning modules | Mechanism only; periods undecided |
| Exports | Payroll statutory exports only. `analytics.export` seeded, granted to no role, unimplemented. | Payroll, Analytics | Existing / absent |
| DSR, erasure, anonymization, legal hold | Nothing, anywhere. | — | Missing, legally blocked |
| Compliance code | None (`app/Domain/Compliance`, `compliance.*` capabilities, routes, tables all absent). | — | Missing |

## 3. Boundary

| | Compliance |
|---|---|
| Owns | Evidence views; regulatory-reporting views; its own access-audit events (`compliance.*`) |
| Reads (via read contracts only) | Audit ledger (new `App\Support\Audit` read contract); finished statutory Payroll results (new Payroll contract); processing authorizations (`StudentProcessingAuthorizationReadService`); later, other modules' contracts as each view needs them |
| Never | Reads another module's models/tables; writes, corrects, reverses, archives or deletes source records; calculates statutory values; generates or submits filings; writes audit other than its own access events; grants roles/capabilities; sets or executes retention; produces statistics about people; schedules, alerts or notifies; asserts legal compliance |
| Tenant scope | One School per request, normal RLS; `platform_audit_events` never in a School view; cross-School and platform views deferred |
| Writes | Read-only in v1. A Compliance-owned record (e.g. a filing-acknowledgment register) needs an ADR 0042 amendment first |

## 4. Authorization

- Audit-log review: existing `school.audit.view` (owner confirms the
  `school_admin`/`principal` grant before it ships).
- Reserved, unseeded: `compliance.view`, `compliance.export`,
  `compliance.platform.view` (spelling only).
- A Compliance surface never shows more, and never requires less
  (capability, MFA, access audit), than the source module does for the
  same data. Compliance access and source access never imply each other
  (`docs/security/AUTHORIZATION.md`, Layer 5 principle).

## 5. Legal, product and security gates

"Existing evidence" is where the repository already records the open
question. Nothing below is decided by ADR 0042.

| Decision | Existing evidence | Approval needed | Can engineering proceed without it? |
|---|---|---|---|
| Tier of audit records; metadata keys each audience may see | ADR 0017 ("audit data itself is sensitive… must be classified"); no row in `DATA-CLASSIFICATION.md` | Product + security | The contract, yes. The audit-log view only with the interim Highly Sensitive treatment and an approved allowlist (v1 plan shows no metadata) |
| Confirm `school.audit.view` grant | Seeded for `school_admin`, `principal`; never exercised | Product owner | No, for the audit-log view |
| Platform audit review audience | `platform_audit_events` unread; auth/MFA events there | Product + security | Yes; nothing platform-level built |
| Retention periods per category, incl. audit ledgers and statutory records | `DATA-CLASSIFICATION.md` "Retention"; `INTEGRATIONS.md`; `PHASE-0C-CLOSEOUT.md`; ADR 0038 ("indefinite by default") | Legal | Yes; nothing is deleted |
| Audit evidence surviving School deletion; legal holds | `school_audit_events` cascade; legal hold asked in `DOCUMENTS.md` and LMS legal review, never decided | Legal | Yes; behaviour unchanged |
| Data-subject requests, correction, erasure, anonymization | Posed only in `LMS-SUBMISSION-LEGAL-REVIEW-REQUEST.md` (Q7/Q8); no design anywhere | Legal | Yes; none built |
| Statutory reporting beyond ADR 0036 (filing registers, due dates, board/UDISE+ or other returns) | ADR 0036 "data preparation only"; `INTEGRATIONS.md` (UDISE+, DigiLocker not built); `ORGANIZATION.md` (government identifiers not modeled) | Legal + product | Yes, for audit review |
| Consent/processing-authorization evidence views | ADR 0038; `STUDENTMARK-CHILDRENS-DATA-DETERMINATION.md` (production enablement not approved) | Legal + product | Yes |
| Cross-School/group Compliance | `TENANCY.md`; ADR 0040 §4; Phase 0N | Architecture (own ADR) | Yes; deferred |
| Compliance export | ADR 0040 §5 view/export split; HR CSV formula-injection P3 | Product + security | Yes; none built |

Jurisdiction: the repository assumes India (`DATA-CLASSIFICATION.md`;
statutory Payroll names Telangana, ADR 0036). That is context only; no
Compliance view may claim conformity with any law.

## 6. Implementation plan (proposed; each checkpoint needs its own go-ahead)

### Phase 0L.4 — Compliance Foundation: School Audit-Log Review (proposed next)

- **Objective**: accountable staff can review one School's audit
  evidence, safely. The smallest real Compliance surface; it closes the
  unexercised `school.audit.view` gap.
- **Source**: `school_audit_events` only, through a new read contract in
  `App\Support\Audit` (next to `AuditRecorder`). No platform events.
- **Migrations**: none. Existing indexes cover School + event type +
  time; add one only if measured (CLAUDE.md index rule).
- **Services**: `App\Domain\Compliance` skeleton; an audit-log review
  service that calls the read contract, applies filters (event-type
  prefix, date range, actor, subject type), paginates, and records
  `compliance.audit_log.viewed` (ids and filters only). v1 displays
  event type, time, actor, subject type/id and request id — **no
  metadata**; allowlisted keys are added per event type only after
  review.
- **Capabilities**: `school.audit.view` (existing). No new seed.
- **UI**: `/app/compliance/audit-log` Inertia page; Dashboard link shown
  only with the capability.
- **Tests**: allow (School Admin, Principal) and deny (HR & Payroll,
  teacher, student, guardian, desks, Platform Admin without School),
  guest redirect; School A never sees School B (Eloquent and raw-SQL/RLS
  test); platform events never appear; no metadata reaches page props;
  a Highly Sensitive event (e.g. `payroll.statutory.identifier.revealed`)
  shows no value; the view is audited exactly once per read with
  filters only; filter validation; an architecture guard that Compliance
  never touches `SchoolAuditEvent` except through the read contract and
  never writes.
- **DDEV review**: School Admin and Principal → Compliance: Audit log →
  filter by `announcement.` and a date range; Annexe admin sees only
  Annexe events; HR & Payroll gets 403.
- **Gates**: §5 rows 1–2 (interim treatment + allowlist; grant
  confirmation).

### Candidates, not scheduled (each blocked as stated)

- **Statutory reporting register** — finished statutory Payroll results
  per period via a new Payroll read contract. Blocked on the
  statutory-reporting scope gate; any filing-acknowledgment record needs
  an ADR 0042 amendment.
- **Consent/processing-authorization evidence** — per-Student legal-basis
  evidence through `StudentProcessingAuthorizationReadService`, for
  holders of `students.processing_authorizations.view`. Blocked on its
  gate row.
- **Retention status view** — which categories have a decided period
  and whether the owning job runs. Useful only after the first retention
  decision.
- **Platform audit review**, **cross-School Compliance**, **export** —
  their own gates and, for cross-School, their own ADR.

Automation-shaped work (scheduled checks, reminders, due dates, tasks)
is not a Compliance checkpoint; it waits for the Automation contract.

## 7. Findings recorded, not fixed by Phase 0L.3

Owned by other modules; listed so they are not lost. None blocks the
contract.

- `school.audit.view` is seeded and granted but enforced nowhere (closed
  by Phase 0L.4 when built).
- Lockout (`Illuminate\Auth\Events\Lockout`) and denied-access decisions
  are not audited (ADR 0017's carried-forward requirement).
- Role/capability grants have no runtime write path; when one is built,
  Identity & Access must audit grants and revocations (CLAUDE.md
  rule 11).
- The `students.processing_authorizations` feature flag is seeded but
  checked nowhere.
- Stale wording that still describes statutory Payroll (9.6) as gated
  although it is published (ADR 0036): `docs/modules/PAYROLL.md` (status
  lines near the top, and the 9.6 sections), `docs/roadmap/MASTER-ROADMAP.md`
  Phase 0J paragraphs, and comments in `CapabilityAndRoleSeeder.php` and
  the `create_salary_components_table` migration.
- JSON `fetch()` helpers after a session ends: already recorded in
  `docs/security/AUTHORIZATION.md` ("When a session ends without
  logout", remaining limits).
