# E21 — Production Retention Decision Request (as issued)

> ## STATUS (2026-10-01, E21.2): ANSWERED FOR IMPLEMENTATION — PENDING FINAL LEGAL/COMPLIANCE RATIFICATION
>
> The owner adopted a provisional policy for every decision E21-D0–D13 in
> `docs/security/E21-RETENTION-DETERMINATION.md` (project-adopted, not a
> legal opinion). E21 stays open until that policy is implemented and
> ratified. The original request text below is unchanged.
>
> ## Original status: OPEN — OWNER / LEGAL / COMPLIANCE DECISIONS REQUIRED
>
> This document is the E21.1 evidence audit and decision contract
> (2026-10-01, baseline `dbcf4ff`). It is a **request**, not a decision.
> Engineering has set **no** retention period, purge interval, legal basis,
> statutory duration, erasure exemption, archive duration or backup period,
> and answers none of the questions below. The authorized reviewer's
> answer is recorded separately in
> `docs/security/E21-RETENTION-DETERMINATION.md` (§9; not yet created),
> after which ADR 0058 E21 can move by a dated, reviewed change.

- **Issued:** 2026-10-01 (E21.1, docs only). It follows the
  `LMS-SUBMISSION-LEGAL-REVIEW-REQUEST.md` precedent for an issued question
  set. The future answer follows the
  `STUDENTMARK-CHILDRENS-DATA-DETERMINATION.md` precedent for a recorded
  determination.
- **Governing sources:**
  - ADR 0058 §4.12 and §6 (row E21);
  - ADR 0042 §7 and §13 items 4–6;
  - ADR 0050 §8–§9; ADR 0051 §1, §17, §22;
  - ADR 0055 §21; ADR 0056 §13; ADR 0059 §21; ADR 0063 §39.5;
  - `DATA-CLASSIFICATION.md` ("Retention").
- **Separate gates** this document does not answer: TCH-L1 (ADR 0058
  E33), E17, E30, E31 and E32.

## 1. E21 — authoritative definition and closure semantics

**Row E21 (ADR 0058 §6):**

| Field | Value |
|---|---|
| Control | Retention decisions for v1 categories (mail, webhook deliveries, Documents, audit, others used in v1) |
| Source | §4.12; ADR 0042; `DATA-CLASSIFICATION.md` |
| Category | Legal |
| Mandatory | Mandatory |
| Status | `LEGAL_REVIEW_REQUIRED` |
| Required evidence | **"Qualified decision recorded; settings implemented where they exist"** |
| Evidence location | Future legal record + ADR amendment |
| Owner | Legal + Owner |
| Blocks O1 | **Yes** |

**ADR 0058 §4.12 (decision A):**
- The legal retention decisions are "**mandatory before Phase 0O
  closeout**, not deferred to go-live".
- "O1 cannot close until a qualified legal/compliance review records the
  production retention policy for the categories **actually used in v1**."
- "Engineering never invents the periods. **Implementing the recorded
  periods, where the repository has a setting or a prune path, is part of
  closeout.**"

**What closes E21.**
1. A qualified decision recorded for every v1 category (§4 below).
2. The recorded periods implemented wherever a setting or prune path
   already exists:
   - `MAIL_RETENTION_DAYS` (`platform:email-prune`);
   - `WEBHOOKS_DELIVERY_RETENTION_DAYS`
     (`platform:webhook-deliveries-prune`).

   This includes fixing the latent prune defects in §5.2 first.
3. **Open:** where a decided period needs a mechanism the repository does
   not have, ADR 0058 does not say whether that mechanism must exist before
   O1 or may follow on a recorded date. That is decision **E21-D0**.

O1 blocks authorizing any production deployment (ADR 0058 §2), so E21 is a
**platform-wide** production blocker. It is not a TCH-specific gate.

## 2. Five concepts (never conflated)

| Concept | Meaning here |
|---|---|
| 1. Operational lifecycle | The row's status: active, ended, closed, withdrawn, cancelled, voided, revoked, archived |
| 2. Logical archive / close / end | A status transition. **No v1 "archive" moves or removes data**; every archived row and object stays in place |
| 3. Database deletion | A physical `DELETE`. The runtime role keeps DELETE unless `TenantRls::revokeDelete`/`makeAppendOnly` removed it; referential cascades bypass those revokes |
| 4. Legal retention | How long a category must, or may at most, be kept. **Undecided for every business category** |
| 5. Physical purge / anonymization | A scheduled or manual destruction or de-identification. It exists only for the technical categories in §3; there is no anonymization code anywhere |

"Archived", "closed", "ended", "inactive", "withdrawn" and "voided" never
mean legally retained or physically deleted.

## 3. Existing mechanisms

### 3.1 Every automated cleanup (`apps/platform/routes/console.php`)

There is no `Prunable`/`MassPrunable` model, and nothing schedules
`model:prune`, `queue:prune-failed` or `sanctum:prune-expired`.

| Command | Resource | Period | Source of the period | Trigger | Tests |
|---|---|---|---|---|---|
| `platform:idempotency-prune` | expired `api_idempotency_keys` (stored response bodies) | 48 h (`IDEMPOTENCY_DEFAULT_TTL_HOURS`; not in `.env.example`) | Phase 0C.2 replay window (`RELIABILITY.md`) | daily 02:10 | `IdempotencyPruneCommandTest` |
| `platform:account-recovery-prune` | `account_recovery_requests` | 24 h (hard-coded) | ADR 0056 §13: "technical data; the audit is separate" | hourly | `AccountRecoveryPlatformTest` |
| `platform:staff-account-credentials-prune` | `account_activation_credentials`; `staff_account_invitations` (+ roles) | 24 h and 7 d (class constants) | ADR 0059 §21: "Technical cleanup, not legal retention" | hourly | architecture guards only; **no behavioural test** |
| `platform:email-prune` | finished `email_messages` (+ attempts), processed `email_events` | `MAIL_RETENTION_DAYS`, **no default** — deletes nothing while unset | ADR 0055 §21 `[LEGAL REVIEW REQUIRED]` | daily 02:30 | **no behavioural test** |
| `platform:webhook-deliveries-prune` | terminal `webhook_deliveries` (+ attempts by cascade) | `WEBHOOKS_DELIVERY_RETENTION_DAYS`, **no default** — deletes nothing while unset (`--days` for a manual run) | ADR 0042 §7; `INTEGRATIONS.md` | daily 02:20 | `PruneWebhookDeliveriesTest` |
| `platform:email-messages-redispatch` | purges `email_messages.sealed_content` at expiry (row kept) | 72 h standard TTL (hard-coded) | ADR 0055 (content purge database-enforced) | every minute | — |

Other technical lifetimes:
- the cross-host handoff ticket, 60 s in Redis (ADR 0054);
- the capability cache, 60 s;
- the session lifetime, 120 min;
- personal access tokens, which expire at 30 d by default and 90 d at most
  (expired rows are never pruned).

### 3.2 Protections (not retention)

| Protection | Tables |
|---|---|
| **Append-only** (`makeAppendOnly`: no UPDATE/DELETE) | `school_audit_events`, `platform_audit_events`, `journal_entries`/`journal_lines`, `payments`, `payment_allocations`, `payment_provider_events`, `payment_receipts`, payroll postings and adjustments, `compensation_assignment_values`, communication recipients, attempts, policy decisions and consent events, `email_submission_attempts`, `webhook_delivery_attempts`, automation attempts and review items, `staff_account_invitation_roles`, LMS Section audiences |
| **No DELETE** (`revokeDelete`) | `schools`, platform/group/membership role assignments, `school_groups`, `school_elevations`, `school_domains`, `api_clients`/credentials, `teaching_assignments`, `charges`, the fee and late-fee tables, `ledger_accounts`, `payment_receipt_counters`, `canteen_orders`, `email_suppressions` |
| **History or immutability triggers** | `teaching_assignments`, `membership_role_assignments`, LMS owner, `charges`, `fee_structures`, `payment_receipts`, `student_processing_authorizations` (rejects UPDATE/DELETE except by cascade), `email_suppressions`, `email_messages` content purge |
| **No DELETE privilege protection at all** (the runtime role holds DELETE; "never deleted" rests on there being no code path) | `users`, `school_memberships`, `students`, `guardians`, `student_enrollments`, `employees`, `employment_records`, `attendance_*`, `curriculum_deliveries`, `documents`, `employee_documents`, `visitor_visits`, rule-73 academic reference rows, `domain_event_outbox`, `failed_jobs` |

## 4. Retention inventory (v1 categories present in the repository)

Classification is from `DATA-CLASSIFICATION.md`: HS = Highly Sensitive,
S = Sensitive, C = Confidential, I = Internal.
"Purge" means an existing physical deletion. **No category below has a
decided legal retention period.**

| # | Category (main tables) | Class. | Personal / Student / Employee / Financial | Lifecycle (concept 1–2) | DB deletion today (concept 3) | Purge (concept 5) | Documented retention |
|---|---|---|---|---|---|---|---|
| 1 | **Audit ledgers** — `school_audit_events` (metadata), `platform_audit_events` (+ IP and user agent) | HS for review (ADR 0042 item 1 open) | Y / Y / Y / refs | append-only | none at runtime. School audit CASCADEs with its School; actor `users` SET NULL | none | ADR 0042 §7: "Recorded fact, not a decision … no retention at all" |
| 2 | **Transactional email** — `email_messages` (encrypted recipient, sealed content purged at submission or expiry, **plaintext `subject` kept**), `email_submission_attempts`, `email_events`, `email_provider_references`, `email_suppressions` (HMAC fingerprints) | S | Y / Y / Y / N | message state graph; suppression released, never deleted | prune path exists, unset | content purge only | ADR 0055 §21 `[LEGAL REVIEW REQUIRED]` (E21) |
| 3 | **Communications** — announcements (`body`), threads and messages (`body`), recipients, `communication_deliveries` (**plaintext recipient email** in `destination_snapshot`), attempts (`failure_message`), consent events, preferences | (no dedicated row) | Y / Y / Y / N | draft, published, cancelled; threads archived or closed | draft targeting rows and unsent attachments are hard-deleted; the rest are not deleted | none | none |
| 4 | **Webhook delivery history** — `webhook_deliveries`, `webhook_delivery_attempts` (no payload, body or headers stored), `webhook_endpoints` (URL, encrypted secrets) | no dedicated row (endpoint secrets HS as authentication secrets) | N / N / N / N | delivery states; endpoints disabled | prune path exists, unset; subscriptions hard-deleted | none | `[LEGAL REVIEW REQUIRED]` (`INTEGRATIONS.md`) |
| 5 | **Domain-event outbox** — `domain_event_outbox` (jsonb payload, actor, request id; no RLS), `event_consumer_receipts` | per payload | depends on payload | dispatched | none | none | deferred (ADR 0025) |
| 6 | **Documents** — `documents` and `employee_documents` metadata, plus object bytes (versioned bucket) | up to HS | Y / Y / Y / Y | active → archived (status only; bytes and metadata kept) | none in the app; owner FKs CASCADE; DELETE not revoked | compensation on a failed upload only; no orphan reaper | `DOCUMENTS.md`: `[LEGAL REVIEW REQUIRED]`; ADR 0050 §8: no lifecycle expiry while unresolved |
| 7 | **Authority-bearing history** — role grants (school, platform, group), `school_elevations`, Group membership, `employees.user_id` link (**audit-only history**), `teaching_assignments`, LMS owner and Section audiences | S | Y / N / Y / N | revoked, ended or expired rows kept; link overwritten on unlink | none (most are delete-revoked); `school_group_members` is hard-deleted on removal (platform-audited) | none | ADR 0063 §39.5; `TEACHING-ASSIGNMENTS.md` "pending E21" |
| 8 | **Student/SIS** — `students`, `guardians`, `guardian_contacts`, `student_guardian_relationships` (**hard-deleted on unlink**, audit-only history), account links, `student_enrollments`, `student_subject_enrollments`, applicants and admission applications, `student_processing_authorizations`, rollover plans | S–HS | Y / Y / N / N | status transitions (withdrawn, transferred, completed, revoked, superseded) | relationship unlink and draft rollover mappings only | none | none, except ADR 0038 "indefinite by default" = no deletion path, **not** a policy |
| 9 | **Academic history** — `attendance_sessions`/`_records` (a correction **overwrites** status; prior value audit-only), `curriculum_deliveries`, syllabus, examinations, grade scales (draft bands deletable), timetable, LMS content and assignments, academic structure (rule 73 deactivate-not-delete) | C–S | Y / Y / Y / N | deactivate, close or archive | draft grade bands only | none | none |
| 10 | **Finance** — ledger, journals, charges, payments, allocations, provider events, receipts, fee tables, concessions, adjustments, late fees, canteen orders | HS (setup C) | Y / Y / N / Y | corrections are reversals, cancellations or voids, never edits | draft-only working rows (run items, draft lines); committed records none (append-only or delete-revoked) | none | ADR 0062: "Retention … E21". E30 is receipt form/GST, **not** retention |
| 11 | **HR and payroll** — `employees`, `employment_records`, assignments, statutory identifiers, tax and bank details, payroll runs, results and postings, `employee_documents` | S–HS | Y / N / Y / Y | archive, separation, period close | **personal sub-records hard-deleted** (addresses, emergency contacts, notes, qualifications, experience, certifications; audited); draft payroll results | none | none |
| 12 | **Operational modules** — transport, hostel, library, inventory (`stock_movements`), visitor and visits, canteen operations, automation execution logs | C–S (per module row) | Y / Y / partly / partly | ended, returned, checked-out | canteen recipe requirement only | none | `VISITOR.md` and `AUTOMATION.md` defer to this gate |
| 13 | **Identity and security** — `users`, `school_memberships`, MFA factors and recovery codes, `personal_access_tokens`, sessions, `account_recovery_requests`, staff and Guardian invitations | S; authentication secrets HS | Y / Y / Y / N | disable, suspend, revoke, expire | no app path deletes a User or membership; tokens and MFA deleted on credential events | technical TTLs (§3.1); Guardian invitations and expired tokens never pruned | technical TTLs ADR-decided; legal open |
| 14 | **Operational telemetry and transient payloads** — logs (stderr in production), metrics, `failed_jobs` (full payload and exception, unencrypted except account-recovery jobs), `api_idempotency_keys` (response bodies) | per content | possible | — | idempotency TTL | idempotency only; **`failed_jobs` never pruned** | ADR 0051: logs 30 d / metrics 90 d as **minimums**, no maximum stated |
| 15 | **Backups and object versions** — PostgreSQL PITR, independent object copy, bucket versions | all | all | — | — | — | ADR 0050 §9: **35-day operational window** ("does not authorize deleting business records"; a future erasure or retention decision must say how backups are handled); object-copy retention unstated |

Analytics has no persisted read models, no materialized views, no cache and
no exports. Derived values take their source's tier.

## 5. Findings

### 5.1 Immediate retention-safety defects

**NONE.**
- No scheduled job deletes business records.
- The only scheduled deletions are ADR-recorded technical lifetimes (§3.1).
- The legally gated prunes fail closed (no default).
- No ordinary application path deletes a User, School, membership,
  Employee, Student, Guardian, Document, financial record or audit row, and
  none cascades history.
- The application hard deletes in §4 (rows 3, 7, 8, 9, 11) are documented,
  deliberate and audited, or limited to draft rows.

### 5.2 Latent implementation defects

These are **not** fixed in E21.1: no data is at risk while the period is
unset. They must be fixed **before `MAIL_RETENTION_DAYS` is configured**,
inside the E21 implementation checkpoint.

- **L1** — `platform:email-prune` iterates Schools only. It never enters
  `PlatformEmailScope`, so identity-level `email_messages`
  (`account_recovery`, `security_notice`; `school_id` NULL) are never
  pruned.
- **L2** — `email_provider_references` (a plain id, no FK) is never pruned.
  It is orphaned once its message is deleted.
- **L3** — A processed `email_events` row referenced by
  `email_suppressions.source_event_id` cannot be deleted.
  - The FK is `ON DELETE SET NULL`, but
    `email_suppressions_guard_update` refuses any `source_event_id` change
    (and any change to a released row).
  - So the prune's single bulk `DELETE` would abort as a whole once a
    suppression exists.
  - This is verified by reading the migration and command; it is not
    executed.
- **L4** — `platform:email-prune` and `platform:staff-account-credentials-prune`
  have no behavioural test.

*E21.2A (2026-10-01):*
- L1–L3 are **fixed**. L3 was confirmed by execution: the original command
  aborted on a suppression-referenced event.
- `platform:email-prune` now has a behavioural test
  (`Tests\Feature\Email\EmailRetentionPruneTest`).
- The staff-credentials prune test remains open (technical TTL, not E21).

*E21.2B (2026-10-01):* D1, D2 (released suppressions) and D6 are
implemented through narrow database retention functions (determination
§5.1). The runtime role still has no DELETE on any protected ledger.

### 5.3 Documentation contradictions and latent schema conflicts

Recorded, not silently reconciled:
- **C1 — ADR 0047 §12.** It says School deletion "would cascade through 147
  foreign keys, erasing … the elevation/Group history". In fact:
  - `school_elevations.school_id` and `school_domains.school_id` are
    **RESTRICT**, so a School with an elevation cannot be deleted even
    administratively;
  - a static count of `school_id` CASCADE FKs is about 172.

  This is fail-closed and needs no code change. *(Corrected in ADR 0047
  §12 by E21.2.)*
- **C2 — `DOCUMENTS.md`, "Never hard-deleted".**
  - This holds for every application path, but `documents` keeps the
    runtime DELETE grant and every owner FK is CASCADE.
  - An out-of-band owner delete would drop the metadata and orphan the
    bytes.
  - It is latent (no app path) and feeds D5/D11. *(Clarified in
    `DOCUMENTS.md` by E21.2.)*
- **C3 — `membership_role_assignments.assigned_by/revoked_by_user_id`.**
  - These are `ON DELETE SET NULL`, but the history guard refuses that
    change, so a User delete is refused rather than nulled.
  - It is fail-closed and feeds D10. *(ADR 0063 §39.5 corrected by
    E21.2.)*
- **C4 — `docs/modules/COMPLIANCE.md` "Retention" row.** It listed two
  prunes and said "Nothing else is pruned". **Corrected by E21.1**: the
  account-recovery, staff-credential and email prunes exist.
- **C5 — Logs.** `DATA-CLASSIFICATION.md` says "logs 30 days, metrics 90
  days" flatly, while ADR 0051 states them as minimums with no maximum.
  `config/logging.php`'s unused `daily` channel keeps 14 files. *(Resolved
  by E21.2: DATA-CLASSIFICATION now separates the ADR 0051 minimums from
  the adopted D13 maxima.)*
- **C6 — `LMS-SUBMISSION-LEGAL-REVIEW.md`.** Its "no hits for erasure /
  anonymiz" is a dated point-in-time search. It is historical and
  unchanged.

## 6. Decision register

For each item: the decision needed, the decision owner type, neutral
options, consequences, and the engineering that would follow. **No option is
selected.**

The **option set O** below applies unless an item says otherwise:
- (a) retain indefinitely;
- (b) retain for a defined period from a stated trigger date;
- (c) retain until tenant closure + a period;
- (d) anonymize or pseudonymize after a period;
- (e) purge after a period;
- (f) keep only audit metadata after a period;
- (g) legal-hold override of any of these.

| ID | Decision required | Owner | Options | Consequences / notes | Engineering after decision |
|---|---|---|---|---|---|
| **E21-D0** | Closure semantics. Where a decided period has no repository mechanism yet, must the mechanism exist before O1, or may it follow by a recorded date? | Owner + Legal | before O1 / by a recorded deadline / not needed (retain-indefinitely outcomes) | Decides whether E21 closes in the policy phase or only after policy plus implementation | none, or scheduling of the checkpoints below |
| **E21-D1** | Audit ledgers: period per ledger. Must actor ids survive a User's erasure? Must School audit outlive the School? Legal hold? (ADR 0042 items 4–5) | Legal + Security | O; plus "actor kept / nulled / pseudonymized" | Changing the School-audit CASCADE, or the actor SET NULL, is a schema change | possible audit purge or archive job; FK change; legal-hold flag |
| **E21-D2** | Transactional email metadata (`MAIL_RETENTION_DAYS`), including the plaintext `subject`, identity-level rows, provider references and events; released suppressions | Legal + Operations | O (b)/(e) natural for metadata; suppressions (a) or (b) | Deliverability needs suppression history; the subject may contain personal context | set `MAIL_RETENTION_DAYS`; **fix L1–L3 first**; add L4 tests; a possible subject purge |
| **E21-D3** | Communications content and delivery records (announcement and message bodies, plaintext delivery destinations, consent events, preferences) | Legal + Product | O | Consent events may be evidence. Delivery destinations duplicate contact data | new prune or anonymization job, or none |
| **E21-D4** | Webhook delivery history (`WEBHOOKS_DELIVERY_RETENTION_DAYS`); the outbox and consumer receipts (ADR 0025) | Operations + Legal | O | No payload is stored in deliveries; the outbox holds payloads | set the env var; a possible outbox prune (new) |
| **E21-D5** | Documents: metadata and bytes, archived Documents, object versions, communication attachments | Legal + Owner | O; plus "archived → purge after a period" | ADR 0050 §8 forbids lifecycle expiry until decided | a purge or archive job removing object and row together; bucket lifecycle rules |
| **E21-D6** | Authority-bearing history: role grants, elevations, Group membership (currently hard-deleted), the Employee↔User link (audit-only), TeachingAssignments, LMS owner/audience | Legal + Security | O | Authority history is accountability evidence; it overlaps D1 | none if (a); otherwise a purge or anonymization job; a possible Group-membership history table |
| **E21-D7** | Student/SIS and academic history: Students, Guardians, relationships (hard-deleted on unlink), enrollments, admissions, processing authorizations, attendance (corrections overwrite), curriculum, examinations, LMS | Legal (children's data) + Owner | O; plus "until the Student leaves + a period" | Children's data; academic records may have statutory minimums; unlink and correction histories are audit-only | lifecycle-triggered purge or anonymization; possibly history rows for unlink and correction |
| **E21-D8** | Financial records (ledger, payments, receipts, fees, concessions, late fees, canteen orders, payroll postings) | Finance + Legal | O; statutory accounting periods if applicable | Append-only by design; a purge would conflict with ledger integrity unless designed as an archive | none if (a); otherwise an archive or export-then-purge design (new ADR) |
| **E21-D9** | HR and payroll records: Employee, employment, statutory identifiers, bank and tax data, payroll results, Employee documents; also the hard-deletion of personal sub-records | HR + Legal | O; plus "separation + a period" | Statutory payroll obligations may set minimums. Hard-deleted sub-records keep only audit | separation-triggered purge or anonymization; possibly a change to the sub-record deletion model |
| **E21-D10** | Data-subject requests: access, correction, erasure and anonymization for Users, Students, Guardians and Employees; legal-retention overrides; audit actor ids; historical transactions (ADR 0042 item 6) | Legal + Security + Owner | erasure / anonymization / restriction / refuse-with-basis, per category | The out-of-band User delete is refused by RESTRICT FKs and C3, and otherwise cascades memberships and nulls audit actors. There is no DSR workflow | DSR workflow; anonymization; FK changes |
| **E21-D11** | School/tenant closure: does closure eventually permit destroying all School data, and after what period? Audit and history survival; legal hold (ADR 0047 §12, ADR 0042 item 5) | Owner + Legal | O; plus "export to School then purge" | Deletion is impossible today (DELETE revoked; no `archived` transition; RESTRICT elevations and domains); C1 to correct | a tenant-closure workflow (new ADR); FK review |
| **E21-D12** | Backups and object versions: confirm the 35-day operational window against legal retention and erasure; object-copy retention; version retention | Operations + Legal | keep the operational window / align with D10 erasure / other | ADR 0050 §9 requires any erasure or retention decision to say how backups are treated | backup and bucket configuration only |
| **E21-D13** | Logs, telemetry and transient payloads: a maximum log and metric retention (ADR 0051 sets minimums only), `failed_jobs` payloads (never pruned), sessions, expired access tokens and Guardian invitations (never pruned) | Security + Operations + Legal | O | `failed_jobs` may hold personal data in clear | `queue:prune-failed` and `sanctum:prune-expired` scheduling; invitation prune; log-sink configuration |

**Already decided outside E21 — TECHNICAL TTL EXISTS, recorded by an ADR.**
The E21 determination should confirm that no legal minimum overrides these:
- idempotency keys, 48 h;
- account-recovery requests, 24 h;
- activation credentials, 24 h, and staff invitations, 7 d;
- email sealed-content purge at submission or expiry (72 h);
- the handoff ticket, 60 s;
- the session lifetime;
- access-token expiry.

**Operational periods decided, legal maximum still open:**
- ADR 0051: logs ≥ 30 d and metrics ≥ 90 d (D13);
- ADR 0050: a 35-day backup window (D12).

## 7. Classification is not a period

No tier implies a duration. Highly Sensitive does not mean a longer or
shorter period. The reviewer decides per category.

## 8. Relationship with other gates

- **TCH-L1 / E33** decides whether teacher Attendance may be used in
  production. It sets no retention.
- **E21** sets retention and does not decide TCH-L1.
- **E30** is the fee receipt form and GST, not retention. **E31** and
  **E32** cover fee regulation and RTE.
- These are independent rows in the ADR 0058 register.

## 9. Required form of the determination

Record it as `docs/security/E21-RETENTION-DETERMINATION.md`. It may be one
record per decision ID or one record covering several. Engineering fills in
nothing.

```text
Decision ID(s):                 E21-D0 … E21-D13 (as covered)
Decision date:
Decision authority:             (role of the authorized reviewer)
Jurisdiction / policy scope:
Data category:                  (as §4 row and table names)
Retention duration:
Trigger date:                   (e.g. creation, end, separation, Student leaving, tenant closure)
Archive period:                 (if any)
Purge / anonymization action:
Legal-hold exception:
Data-subject erasure interaction:
Tenant-closure interaction:
Backup treatment:
Audit evidence required:
Engineering implementation required:   (from §6, last column)
Implementation deadline (E21-D0):
Production approval status:     APPROVED / APPROVED WITH CONDITIONS / NOT APPROVED
Does not decide:                (anything outside the stated scope)
```

After the record exists, follow the ADR 0058 §6 procedure:
- a dated, reviewed change moves E21;
- the move is appended to `PHASE-0O-READINESS.md`;
- the periods are implemented where a setting or prune path exists (with
  §5.2 fixed first) in a separate engineering checkpoint.
