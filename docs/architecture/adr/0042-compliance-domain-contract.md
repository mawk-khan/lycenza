# ADR 0042: Compliance Domain Contract (Phase 0L.3)

- Status: Accepted
- Date: 2026-09-24 (Phase 0L.3)
- See also: ADR 0046 (resolves §13 item 2 — platform audit review: `platform.audit.view`, a separate platform-ledger viewer; `compliance.platform.view` stays reserved for §13 item 9)

## Context

`docs/architecture/DOMAIN-MAP.md` defines **Compliance** in one Layer 5
row: *"Regulatory reporting, statutory record-keeping | Reads from any
Layer 1–4 module via explicit read contracts/events | Must remain a
consumer — no Layer 1–4 module may require Compliance to function."*
ADR 0040 §1 separated Compliance from Analytics and Automation and said
*"A future Compliance checkpoint gets its own contract."* ADR 0034
("Payroll vs. Compliance ownership") and ADR 0036 decided that Payroll
owns statutory *calculation* and that "a future Compliance module
consumes Payroll's already-calculated results read-only, for regulatory
reporting/filing — it never gates or participates in calculation."

After Phase 0L.2 the roadmap said *"Compliance and Automation remain
unscoped. No later Phase 0L checkpoint is defined or authorized"* and
gave no order between them. **The product owner decided on 2026-09-24
that the next checkpoint is "Phase 0L.3 — Compliance Domain Contract",
documentation only, ahead of Automation.** This ADR is that checkpoint.
It makes no code change, like ADR 0040 (Phase 0L.1) and ADR 0039 (LMS)
before it.

A pre-contract audit on `main` at `d50c246` (inventory:
`docs/modules/COMPLIANCE.md` §2) found:

- **No Compliance code**: no `app/Domain/Compliance`, no `compliance.*`
  capability, route or table.
- **Compliance-relevant evidence already exists, owned elsewhere**: the
  append-only audit ledgers (`platform_audit_events`,
  `school_audit_events`, written only by `App\Support\Audit\AuditRecorder`);
  immutable Finance/Payments/Payroll records; the Student
  processing-authorization registry (ADR 0038); Communications consent
  events; statutory Payroll data-preparation exports (ADR 0036).
- **No general audit review surface**: `school.audit.view` is seeded and
  granted to `school_admin` and `principal` but checked nowhere. The only
  audit reader is Communications' own `CommunicationAuditReadModel`.
- **No Payroll read contract for a Compliance consumer**: the "consumes
  results read-only" relationship exists in documents only.
- **Retention is undecided repository-wide**
  (`docs/security/DATA-CLASSIFICATION.md`, **[LEGAL REVIEW REQUIRED]**);
  no data-subject-request, erasure, anonymization or legal-hold design
  exists anywhere.

## Decision

### 1. Compliance is an internal Layer 5 bounded context — `App\Domain\Compliance`

Namespace reserved, not created by this checkpoint. It follows the
`app/Domain/<Module>` convention (ADR 0001) and the Layer 5 rules in
`DOMAIN-MAP.md`: a consumer only; no Layer 0–4 module may depend on it
or break when it is absent.

**What Compliance owns** — nothing but review surfaces over evidence
other modules already keep:

- **Compliance evidence views**: read-only, access-controlled views
  that let accountable staff inspect evidence a School may need to show
  (who did what and when; which records exist and in what state).
  The first is a School audit-log review surface over the audit ledger
  (§10).
- **Regulatory-reporting views**: read-only presentations of results a
  source module has already produced (e.g. finished statutory Payroll
  results), through that module's own read contract.

**What Compliance does NOT own or do:**

- **Source records.** Audit events, journal entries, payroll results,
  statutory calculations, processing authorizations, consent events,
  documents and invitations stay owned by their modules. Compliance
  never creates, edits, corrects, reverses, archives or deletes them.
- **Statutory calculation or filing files.** Payroll owns calculation
  (ADR 0034) and its data-preparation exports (ECR, ESIC worksheet,
  Form 138 draft — ADR 0036). Compliance never recalculates, regenerates
  or re-formats them, and never submits anything to a government portal.
- **Audit writing.** `AuditRecorder` remains the only writer of the audit
  ledgers (ADR 0017). Compliance adds no second audit store.
- **Authorization ownership.** Roles, capabilities, memberships and
  their grants stay with Identity & Access. Compliance may *report* on
  them through an Identity read contract; it never grants or revokes.
- **Retention execution or policy.** See §7.
- **Statistics.** Counts, rates and trends are Analytics (ADR 0040),
  under its cohort policy. See §11.
- **Workflow.** Schedules, alerts, reminders, tasks and escalation are
  Automation (§12).
- **Legal judgement.** Compliance displays evidence; it never asserts
  that a School or School OS *is compliant* with any law. No screen,
  label, export or API response may say so (the same non-claim
  discipline as `DATA-CLASSIFICATION.md` and `PAYROLL.md`).

**Writes.** v1 Compliance is read-only. The only writes it performs are
its own access-audit events (§6). A Compliance-owned record of its own
— for example a register acknowledging that a School filed a statutory
return outside School OS — is conceivable later, but is **not
authorized**: it needs an amendment to this ADR that names the record,
its classification, its capability and its legal basis.

### 2. Consumption rules: read contracts only

Compliance reads other modules only through their Application-layer
read contracts, never their Eloquent models or tables — `DOMAIN-MAP.md`
rule 3, restated because a compliance view is the feature most tempted
to "just query everything". Where a needed contract does not exist, the
owning module adds it under its own rules (as Curriculum Delivery did
for Analytics, ADR 0040 amendment). Specifically:

- **Audit ledger**: a read contract in `App\Support\Audit` — the
  Platform module's audit primitives (Layer 0, ADR 0017), next to their
  only writer — never `SchoolAuditEvent` queried from Compliance.
- **Payroll statutory results**: a Payroll-owned read contract that
  exposes finished (approved/posted) results only. None exists today.
- **Processing authorizations**: `StudentProcessingAuthorizationReadService`
  (ADR 0038), never the table.

Degraded/absent sources behave as in ADR 0040 §3: a view whose source
module is absent is not registered; Compliance is built only against
what is on `main`; incomplete source data is omitted, never inferred.

### 3. Tenant boundary: single-School v1; platform and cross-School deferred

Every Compliance read runs inside the normal tenant isolation stack
(`docs/architecture/TENANCY.md`): `SchoolScope`/`BelongsToSchool`, forced
RLS, tenant-aware plumbing. This ADR introduces no `BYPASSRLS`, no
superuser path, no Compliance-specific RLS exception, and no implicit
Platform Super Admin database privilege (ADR 0021, CLAUDE.md rule 26).

- **`platform_audit_events`** (no `school_id`: sign-in, logout, MFA,
  service identities) is **not** shown in any School view. A platform
  audit review surface is a separate, later decision (§13 item 2).
- **Cross-School / group / platform-wide Compliance** is deferred, like
  cross-School Analytics (ADR 0040 §4) and group reporting (Phase 0N).
  It needs its own ADR saying how the privileged read path is built
  without `BYPASSRLS`, which capability gates it, and how classification
  applies across tenants.
- **Platform Super Admin** receives no School Compliance access by
  virtue of the platform role. Entering a School's context remains the
  unbuilt, audited elevation path (`AUTHORIZATION.md`).

### 4. Authorization: `compliance.*` reserved; `school.audit.view` for audit review

Namespace `compliance.*`, spelling frozen, **not seeded** by this
checkpoint:

| Capability | Scope |
|---|---|
| `compliance.view` | View one School's Compliance regulatory-reporting and evidence views |
| `compliance.export` | Export Compliance output for one School — distinct from view, as `analytics.export` is |
| `compliance.platform.view` | Future cross-School/platform view — spelling only; unusable until §3's deferral is resolved |

**Audit-log review uses the existing `school.audit.view`** ("View school
audit log"), not a new `compliance.*` key: it already names exactly this
permission, and two capabilities for one permission would drift. It is
granted today to `school_admin` and `principal`; because nothing checks
it yet, that grant has never been exercised, and **the owner confirms
the grant before the first implementation ships** (§13 item 3).

Rules that bind every Compliance surface:

- **Compliance access ≠ source access, both ways** — the Layer 5
  principle in `docs/security/AUTHORIZATION.md` applies unchanged.
- **Never wider than the source.** A Compliance surface never shows a
  value the viewer could not see through the source module itself, and
  never requires less than the source does for the same data — the same
  capability, the same MFA assurance (`mfa` middleware, ADR 0037) and
  the same access audit. For audit evidence this is the
  `CommunicationAuditReadModel` rule: "audit privilege must never become
  a wider route to content than the viewer already has".
- No role-name checks (CLAUDE.md rule 24); controllers stay thin.

### 5. Data classification

- **Compliance output inherits the strongest tier of its sources**, the
  same rule `DATA-CLASSIFICATION.md` applies to derived Analytics data.
  A view is never a downgrade path.
- **Audit records have no classification row today**, although ADR 0017
  says audit data is sensitive and must be classified. Their tier is an
  open **[PRODUCT/SECURITY REVIEW REQUIRED]** decision (§13 item 1).
  **Until it is decided, every Compliance surface treats audit records
  as Highly Sensitive** — the strictest tier, a fail-safe default, not a
  classification decision.
- **Audit metadata is exposed by allowlist only**: per event type, the
  keys a view may show, and never a key whose value the viewer could not
  see at the source. Free text, raw personal data, amounts, identifiers
  and secrets are never displayed from metadata. Event type, actor,
  subject reference and time are the default display.

### 6. Access audit

Every Compliance read that shows Sensitive or Highly Sensitive evidence
records a School audit event through `AuditRecorder` (`compliance.*`
event types, e.g. `compliance.audit_log.viewed`), carrying ids and
filter values only — never displayed values. Every export (when one is
authorized) is audited. Reading the audit log is itself audited: the
reviewer's access is evidence too.

### 7. Retention

Compliance never deletes, prunes, anonymizes or archives anything, and
never sets or invents a retention period. Retention periods are an open
**[LEGAL REVIEW REQUIRED]** decision per data category
(`DATA-CLASSIFICATION.md`). When one is decided, the **owning module**
implements it — as `platform:webhook-deliveries-prune` already does for
webhook delivery history (a mechanism with no default period). Compliance
may later *display* which categories have a decided period and whether
the owning job runs; that is a view, not a policy engine.

Recorded fact, not a decision: `school_audit_events` rows are deleted
when their School is deleted (`cascadeOnDelete`), and the audit ledgers
otherwise have no retention at all. Whether audit evidence must outlive
a School, or needs a legal hold, belongs to the retention/legal-hold
gate (§13 items 4–5).

### 8. Exports and regulatory filings

No Compliance export exists or is authorized by this ADR. A future
export requires `compliance.export`, produces only what the viewer may
see on screen (never raw metadata), is audited, and guards spreadsheet
output against formula injection (the open HR P3). Government filing
data stays with its owning module: Payroll's statutory exports remain
Payroll's, with their own capabilities and audit.

### 9. Events, outbox, caching, snapshots

- Compliance v1 consumes no domain events and publishes none. A
  Compliance event would never be webhook-subscribable by default
  (`WebhookEventRegistry`, CLAUDE.md rule 45).
- No cache, projection or snapshot table. Views are synchronous and
  on-demand. Point-in-time evidence comes from the source's own
  immutable records (frozen payroll results, append-only ledgers,
  journal entries), never from a Compliance copy. A snapshot or
  projection needs its own amendment with a measured reason
  (ADR 0040 §7/§10 triggers apply).

### 10. UI/API scope

Session-authenticated Inertia pages under `/app/compliance/...` only.
No JSON API, OpenAPI path or shared-types change in the first
implementation. Every page carries the §4 checks in its route middleware
and the §6 audit in its read service.

**First implementation (not started by this ADR): School audit-log
review** — a paginated, filterable (event type, date range, actor,
subject type) view of one School's `school_audit_events`, gated by
`school.audit.view`, reading through a new `App\Support\Audit` read
contract with an event-type metadata allowlist, audited on read, and
treating the ledger as Highly Sensitive. It needs no migration. It is
blocked on §13 items 1 and 3. Plan: `docs/modules/COMPLIANCE.md` §6.

### 11. Relationship to Analytics

Independent. Compliance does not consume Analytics, register Analytics
read models or reuse `analytics.*` capabilities, and Analytics does not
read Compliance. The two differ in kind:

- **Analytics** answers statistical questions over many records, and
  any cell or denominator that counts people is under the minimum-cohort
  policy — which is still unset, so person-counting fails closed.
- **Compliance** shows individual evidence records to accountable staff
  who are already entitled to see each record at its source.

A Compliance surface therefore never presents a count, rate or
breakdown of people as its own output. A total of the rows the viewer
is already looking at (e.g. "42 events match this filter") discloses
nothing new and is allowed; any other person count is Analytics and
follows ADR 0040. Existing Analytics restrictions are unchanged: no
approved cohort size, Student-data Analytics gated, no cross-School
Analytics, `analytics.export` ungranted.

### 12. Relationship to Automation

Automation (`DOMAIN-MAP.md`, ADR 0010) is unscoped and needs its own
contract. The division:

- **Compliance defines** what condition is reportable, what evidence
  shows it, and how to read that evidence (read contracts/views).
- **Automation may later** evaluate those conditions on a schedule,
  raise alerts, send reminders or create tasks — calling Compliance's
  read contracts like any other authorized caller, with no bypass
  (`DOMAIN-MAP.md` Automation row).

Compliance v1 has no scheduler entry, queued job, notification or
listener. A due-date or reminder feature belongs to Automation.

### 13. Open gates (owner/legal decisions, not engineering choices)

| # | Decision | Blocks | Can engineering proceed without it? |
|---|---|---|---|
| 1 | Classification tier of audit records, and which metadata keys each audience may see | The audit-log review surface | Contract yes; the surface ships only with the fail-safe Highly Sensitive treatment and an approved allowlist |
| 2 | Platform audit review (`platform_audit_events`: auth, MFA, service identities) — audience and capability | Any platform audit view | Yes; nothing platform-level is built |
| 3 | Confirm `school.audit.view` for `school_admin` and `principal` | Audit-log review surface | No — confirm before it ships |
| 4 | Retention period per data category, incl. audit ledgers and statutory records **[LEGAL REVIEW REQUIRED]** | Any retention view or purge | Yes; nothing is deleted |
| 5 | Whether audit evidence must survive School deletion; legal holds **[LEGAL REVIEW REQUIRED]** | Changing `school_audit_events`' cascade; any hold | Yes; behaviour unchanged |
| 6 | Data-subject requests (access, correction, erasure), anonymization **[LEGAL REVIEW REQUIRED]** | Any DSR/erasure workflow | Yes; none is built |
| 7 | Statutory-reporting scope beyond ADR 0036's data preparation (filing registers, due dates, other returns such as board/UDISE+) **[LEGAL REVIEW REQUIRED]** | Regulatory-reporting views | Yes, for audit review |
| 8 | Consent/processing-authorization evidence views and their audience (ADR 0038 registry; production enablement is still gated) | Those views | Yes |
| 9 | Cross-School / group Compliance | `compliance.platform.view` | Yes; deferred |
| 10 | Sensitive exports (`compliance.export`) | Any export | Yes; none is built |

No retention period, jurisdiction, statutory obligation or cohort size
is set by this ADR. India is the assumed market
(`DATA-CLASSIFICATION.md`); that is context, not a compliance claim.

## Rationale

- Freezing the boundary before any code follows every prior contract
  (ADR 0028, 0030, 0032, 0034, 0039, 0040).
- Evidence views over records other modules already keep give Compliance
  real content without duplicating ownership; the audit ledger and an
  unexercised `school.audit.view` are the clearest existing gap.
- Keeping retention, erasure and filing out of Compliance until legal
  decisions exist matches this codebase's refusal to invent policy
  (retention, cohort size, LMS Submission).
- Reusing `school.audit.view` avoids two keys for one permission.

## Alternatives considered

1. **A generic compliance/rules engine** (obligation catalogue, checks,
   scoring). Rejected: no second consumer, no approved obligations to
   encode, and it would invent legal policy — CLAUDE.md rule 2.
2. **Compliance owns retention and purging centrally.** Rejected: it
   would delete other modules' records, breaking ownership; the owning
   module implements a decided period (webhook-prune precedent).
3. **Compliance builds on Analytics.** Rejected: evidence records are
   not statistics, and routing them through Analytics would either break
   the cohort gate or hide individual evidence behind it.
4. **Compliance generates statutory filing files.** Rejected: Payroll
   already owns them (ADR 0036); duplicating would create two diverging
   sources for a government filing.
5. **A new `compliance.audit.view` capability.** Rejected in favour of
   the existing, identically-scoped `school.audit.view`.
6. **Platform/cross-School Compliance now.** Rejected for the same
   reasons as ADR 0040 §4: no privileged cross-tenant read path exists,
   and one must not appear as a side effect.

## Consequences

- `docs/modules/COMPLIANCE.md` is the living reference: inventory,
  boundary detail, gates and the implementation plan.
- `DOMAIN-MAP.md`'s Compliance row points here; its dependency column
  is unchanged.
- `DATA-CLASSIFICATION.md` records the audit-record classification as an
  open gate with the fail-safe interim treatment.
- `MASTER-ROADMAP.md` gains the Phase 0L.3 entry; the Phase 0J sentence
  "Compliance module involvement expected here" is superseded by ADR 0034
  (Payroll owns calculation; Compliance only consumes).
- `AUTHORIZATION.md` notes the reserved `compliance.*` keys and the
  "never wider than the source" rule; `ANALYTICS.md` points here.
- No code, migration, route, capability seed or configuration change.

## Amendment — 2026-09-24 (Phase 0L.4, owner-approved decisions)

Recorded from the product owner's approval for Phase 0L.4 — Compliance
Foundation: School Audit-Log Review. It resolves §13 items 1 and 3 **for
this surface only** and records how §10 was built. As built:
`docs/modules/COMPLIANCE.md` §8.

1. **Classification (§5, §13 item 1).** School audit records are treated
   as **Highly Sensitive** for the audit-log review. This is a
   conservative v1 treatment pending a broader decision; it is not a
   claim that every audit event type is permanently Highly Sensitive.
2. **Metadata allowlist: empty.** No metadata, nested payload,
   before/after value, request body, source-domain payload or event
   context is exposed. `metadata` is not even selected by the read
   contract.
3. **Envelope only.** v1 shows exactly the first-class columns `id`,
   `occurred_at`, `event_type`, `actor_user_id`, `subject_type` (class
   basename only), `subject_id` and `request_id`. `school_id`,
   `created_at` and `metadata` are not exposed, and no actor or subject
   name is resolved (not a ledger column).
4. **Authorization (§4, §13 item 3).** `school.audit.view` is confirmed for
   `school_admin` and `principal` only — not teachers, students,
   guardians, operations desks or Platform Super Admin. No implicit
   platform or cross-School access.
5. **Access audit (§6).** Each successful review records one
   `compliance.audit_log.viewed` event (metadata `paged`, `resultCount`),
   after the read, so it appears on the next review and never recurses.
6. **Scope narrowed from §10.** §10 listed filters (event type, date
   range, actor, subject type); v1 ships **without filters or search**.
   Pagination is keyset over `(occurred_at, id)`, 50 per page, because
   every review appends an event and offset pages would shift. MFA is not
   required: in this repository MFA is opt-in per route, and the
   surface's controls (capability, tenancy, access audit, `no-store`)
   match the other Highly Sensitive reads.

