# Compliance (Phase 0L.3 contract; Phase 0L.4 School audit-log review)

Status: **one read-only surface is implemented — the School audit-log
review (Phase 0L.4, §8).** Decision record: ADR 0042
(`docs/architecture/adr/0042-compliance-domain-contract.md`, including
its 2026-09-24 Phase 0L.4 amendment). This document is the living
reference: what exists, the boundary in detail, the open gates and the
plan. Compliance owns no table, migration or capability seed. Phase 0L
closed on 2026-09-24 (`docs/architecture/PHASE-0L-CLOSEOUT.md`); the §6
candidates remain gated and unscheduled.

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
| Retention | Technical TTLs: `platform:idempotency-prune` (48 h, daily 02:10), `platform:account-recovery-prune` (24 h, hourly), `platform:staff-account-credentials-prune` (24 h / 7 d, hourly), and the email sealed-content purge at submission or expiry. E21 project-adopted periods (pending legal ratification), each with no default, so they delete nothing while unset: `platform:webhook-deliveries-prune` (daily 02:20; delivered 30 d, failed/abandoned 90 d), `platform:email-prune` (daily 02:30; 180 d), `platform:outbox-prune` (daily 02:40; 30 d) and `platform:failed-jobs-prune` (daily 02:50; 30 d). `RETENTION_HOLD_SCHOOL_IDS` exempts held Schools. E21.2B adds, through narrow database retention functions (the runtime role has no DELETE on these ledgers): `platform:audit-prune` (daily 03:00; both audit ledgers, 7 calendar years), `platform:email-suppressions-prune` (03:10; released suppressions, 1 year) and `platform:authority-history-prune` (03:20; revoked School/Group/platform grants, ended TeachingAssignments and finished elevations, 7 years). `RETENTION_HOLD_PLATFORM` holds School-less records. E21.2C adds `platform:communications-prune` (03:40; content 3 years after the end of its sending Academic Year, ambiguous years kept; delivery telemetry 1 year after the terminal state) and `platform:storage-orphans-prune` (04:10; proven orphan objects in the managed keyspace, 30 d). E21.2D adds `platform:student-retention-prune` (04:30; from a Student's dated final exit: attendance records, rollover items and Guardian relationships after 7 years, then the Student with its placements, subject enrollments and Documents after 25 years, only when no other retained row references it). E21.2E adds `platform:employee-retention-prune` (04:50; from an Employee's final separation: addresses, emergency contacts, notes, qualifications, experience and certifications after 2 years; Payroll's compensation and statutory rows, then the Employee with its employment records, assignments, personal details and Documents after 8 years, only when no other retained row references it). Finance (D8) is never pruned: every balance is derived from all postings and no financial-year close exists yet. E21.2F adds reviewed data-subject erasure cases (operator console `platform:erasure-case-*`; execution removes only what an adopted period already released; closed cases expire after 7 years via `platform:audit-prune`) and School Close/Reopen with read-only `platform:school-closure-status` (no tenant purge exists). E21.2G (closure audit, `docs/security/E21-CLOSURE-AUDIT.md`) adopted a period for every remaining category; until their mechanisms ship (E21.3B–E21.3E) Guardian and LMS Documents, processing authorizations, Admissions, consent, School academic operations and operational modules are kept, and Finance stays kept until a financial-year close exists (E21.3A). *(Corrected by E21.1, 2026-10-01: this row listed only the first two prunes; see `docs/security/E21-RETENTION-DECISION-REQUEST.md` §3.1.)* | Owning modules | Mechanism only; legal periods undecided (ADR 0058 E21) |
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
| Tier of audit records; metadata keys each audience may see | ADR 0017 ("audit data itself is sensitive… must be classified") | Product + security | **Decided for v1 (2026-09-24)**: Highly Sensitive, conservatively, for the School audit-log review; metadata allowlist **empty**. A broader, permanent classification of every audit event type remains open |
| Confirm `school.audit.view` grant | Seeded for `school_admin`, `principal` | Product owner | **Decided (2026-09-24)**: School Admin and Principal only |
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

### Phase 0L.4 — Compliance Foundation: School Audit-Log Review — COMPLETE (2026-09-24, as built in §8)

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

- ~~`school.audit.view` is seeded and granted but enforced nowhere~~ —
  closed by Phase 0L.4.
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

## 8. Phase 0L.4 — School audit-log review (as built, 2026-09-24)

Owner-approved decisions (ADR 0042 amendment): the School audit ledger is
treated as **Highly Sensitive** for this surface (a conservative v1
treatment, not a permanent classification of every event type); the
metadata allowlist is **empty**; only first-class envelope columns are
shown; `school.audit.view` is held by School Admin and Principal only; no
platform or cross-School access; every review is audited; Compliance is
read-only.

### Envelope fields (`school_audit_events`)

| Field | First-class column? | Classification concern | Shown in v1? | Reason |
|---|---|---|---|---|
| `id` | Yes (UUIDv7) | None — opaque | **Yes** (`id`) | Identifies the event; ties evidence to one row |
| `occurred_at` | Yes | None | **Yes** (`occurredAt`, ISO 8601) | When it happened |
| `event_type` | Yes | Names the action (a code, not data) | **Yes** (`eventType`) | What happened |
| `actor_user_id` | Yes (nullable) | An id, not a name | **Yes** (`actorUserId`; null shown as "system") | Who acted. The actor's **name is not shown**: it is not a ledger column, and actors include Guardians and Students |
| `subject_type` | Yes (nullable) | Reveals only the record category | **Yes** (`subjectType`, class basename only) | What kind of record; the internal class path is never sent |
| `subject_id` | Yes (nullable) | Opaque id; resolving it needs the source module's own capability | **Yes** (`subjectId`) | Which record |
| `request_id` | Yes (nullable) | Diagnostic correlation id, never used for authorization | **Yes** (`requestId`) | Groups events from one request |
| `school_id` | Yes | Server-derived tenant key | No | Never exposed; the School is the trusted session context |
| `metadata` | Yes (jsonb) | Arbitrary per-event detail, may hold Sensitive/Highly Sensitive values | **No — never selected** | Allowlist is empty in v1 |
| `created_at` | Yes | None | No | Insert time; `occurred_at` is the event time |

### Architecture

- **Read contract** (Platform audit primitives, next to `AuditRecorder`):
  `App\Support\Audit\SchoolAuditEventReader::page(School, ?cursor)` returns a
  `SchoolAuditEventPage` of `SchoolAuditEventEntry` DTOs (the closed field
  list `SchoolAuditEventEntry::FIELDS`). It runs inside
  `TenantContext::withSchool()` on the normal runtime connection (SchoolScope
  + RLS + an explicit `school_id` predicate), selects only the envelope
  columns (`metadata` is never loaded), and never reads
  `platform_audit_events`.
- **Compliance** (`App\Domain\Compliance\Application\AuditLogReviewService`):
  checks `school.audit.view` for the actor in the School, reads one page,
  then records one `compliance.audit_log.viewed` School audit event
  (metadata `paged`, `resultCount` only — nothing from the rows shown). That
  event is Compliance's only write; it is recorded after the read, so it
  appears on the next review, never recursively.
- **HTTP**: `GET /app/compliance/audit-log` (`app.compliance.audit-log`),
  `App\Http\Controllers\App\Compliance\AuditLogController` — thin; the only
  accepted parameter is an opaque `cursor` (validated); no School selected
  -> 403. Page `resources/js/Pages/App/Compliance/AuditLog.vue`; Dashboard
  link `nav.canViewAuditLog`. Signed-in pages are already `no-store, private`.
- **Pagination**: newest first by `occurred_at DESC, id DESC`; keyset cursor
  over `(occurred_at, id)`; fixed 50 rows per page (+1 look-ahead). Offset
  pagination was rejected: each review appends an event, which would shift
  every offset and repeat rows. A forged or foreign cursor is either
  rejected (validation) or positions within the selected School only.
- **Query plan** (DDEV demo data, 2026-09-24): the existing
  `school_audit_events_occurred_at_index` is scanned backward with an
  incremental sort — about 85 rows read for a 51-row page, 0.14 ms. No
  migration was added. Trigger to revisit: many large Schools, where the
  School filter would discard many rows per page — then add
  `(school_id, occurred_at, id)`.
- **MFA**: not required. MFA is opt-in per route in this repository and is
  used only by the processing-authorization routes and platform operations;
  other Highly Sensitive reads (payslips, statutory identifier reveal,
  Highly Sensitive documents) use capability + access audit, which this
  surface matches. The envelope carries no source record's data.

Not in v1 (deliberately): filters, search, metadata display, actor-name
resolution, export, JSON API/OpenAPI, platform ledger, cross-School view,
retention or deletion controls.

### Tests

- `Tests\Feature\Compliance\AuditLogReviewTest` — School Admin and Principal
  allowed; teacher, student, guardian, HR & Payroll, every demo desk and
  Platform Super Admin (with or without a School) refused with no access
  event; only `school_admin`/`principal` roles hold `school.audit.view`;
  guest and no-School cases; Demo School/Annexe/multi-School isolation;
  exact field list and a leak canary for metadata values and class paths;
  exactly one access event per review, none recursive, no viewed content;
  120-event paging stable under appended reviews; foreign and forged
  cursors; Dashboard link.
- `Tests\Feature\Compliance\ComplianceArchitectureGuardTest` — allowed imports
  only, no writes/ledger models/jobs/notifications in Compliance, no other
  module depends on it, the reader never selects `metadata` or touches the
  platform ledger.
- `Tests\Feature\Postgres\SchoolAuditEventsRlsIsolationTest` — RLS enabled and
  forced; raw reads isolated per School and empty without context; the
  reader ignores an ambient context for another School.

Negative controls: removing the capability check fails the refusal test;
removing the access audit fails the exactly-one-event test.

