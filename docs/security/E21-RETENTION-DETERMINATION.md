# E21 Retention Determination

> ## STATUS: PROJECT-ADOPTED FOR IMPLEMENTATION — PENDING FINAL LEGAL/COMPLIANCE RATIFICATION
>
> These are **project/owner retention decisions used to complete
> engineering**. They are **not** represented as a qualified,
> jurisdiction-specific legal opinion, and no period below is claimed to be
> a statutory requirement.
>
> **Production O1 remains blocked** until final authorized legal/compliance
> ratification (ADR 0058 E21). The final pre-production review may amend
> any decision. Any amendment must be implemented before O1 clears.

- **Decision date:** 2026-10-01.
- **Decision authority:** the project owner (E21.2 instruction), for
  implementation. Final ratification: the authorized legal/compliance
  reviewer (role), before production.
- **Answers:** `docs/security/E21-RETENTION-DECISION-REQUEST.md` (E21.1),
  decisions E21-D0 to E21-D13. The inventory, existing mechanisms and
  findings there stay authoritative and are not repeated here.
- **Jurisdiction / policy scope:** to be confirmed by the final reviewer.
  The project policy applies to every v1 category in the request §4.
- **Separate gates, unaffected:** E33 / TCH-L1 (no production `teacher` role
  grants while it is open, ADR 0063 §40), E17, E30, E31 and E32.

## 1. Global rules

1. **Longest applicable period wins.** A record that falls under several
   categories (for example, financial evidence that is also authority
   history) is kept for the longest applicable active period.
2. **Legal/compliance hold overrides all normal expiry.**
3. **Deterministic triggers only** (§3). A record missing its trigger is
   never approximated with `created_at` unless that choice is documented
   for the category.
4. **Retention is enforced only by scheduled or explicit maintenance
   commands**, never as a side effect of an ordinary request. Archive,
   close and end stay domain operations.
5. **Destructive commands:**
   - they report counts only (inspected, eligible, deleted, held,
     errors), never personal payloads;
   - they offer `--dry-run`;
   - they are tenant-safe: each School is processed explicitly inside its
     own context, never under ambient context;
   - they are retry-safe: the predicate is re-applied in the `DELETE`.
6. **Backups are disaster-recovery copies, not legal archives.** Purged
   data may stay inaccessible in rolling backups until the normal 35-day
   expiry (D12). A legal hold uses a separate preservation process, never
   longer backups.
7. **E21-D0, finite periods must be enforceable.** E21 is technically
   complete only when every finite period that applies to an implemented
   v1 category has an enforceable, tested mechanism. Rules tied to tenant
   lifetime, a legal hold or a parent category's policy need no arbitrary
   purge job of their own.

## 2. Legal-hold foundation

No legal-hold model existed. The first purge tranche (E21.2A) adds the
narrow exclusion seam `RETENTION_HOLD_SCHOOL_IDS` (`config/retention.php`):
a School listed there is skipped by every tenant-scoped retention command.
Its rows are counted as held, and nothing of it is deleted.
- This is a predicate, not case management.
- Platform-level rows (no School) are not School-holdable.
- Finer holds (per subject, per record) are designed in the checkpoint
  whose category needs them (§5).

## 3. Triggers

| Category | Trigger |
|---|---|
| Audit | the event's `occurred_at` |
| Email | the message's terminal-state timestamp (`finished_at`); a suppression's `released_at` |
| Communications | the end of the academic year in which the communication was sent; delivery terminal state |
| Webhook delivery | the delivery's terminal-state timestamp (its last state change, `updated_at`, once terminal) |
| Outbox | `processed_at` (every consumer receipted) |
| Authority | the ended or revoked timestamp (`ended_at`/`ends_on`, `revoked_at`, elevation end, unlink audit time) |
| Student | the exit, withdrawal or graduation date (the enrollment's terminal transition) |
| Finance | the close of the School's financial year containing the record (`fee_settings.financial_year_start_month`, default April) |
| HR | the separation date (the terminal EmploymentRecord's `ends_on`) |
| Failed job | `failed_at` (terminal failure) |
| Backup / object version | backup or noncurrent-version creation time |

## 4. Decisions

Implementation classes:
- **A** — an existing prune/setting, configured or fixed now;
- **B** — a finite period whose mechanism is missing;
- **C** — inherited or conditional retention needing a broader lifecycle;
- **D** — already technically covered.

The checkpoints are listed in §5.

### E21-D0 — Closure semantics

- **Policy:** finite periods need an enforceable, tested mechanism before
  technical closeout (§1.7). Inherited, tenant-lifetime and hold-bound
  rules do not need their own purge job.
- **Engineering:** every B/C item below has a named checkpoint.

### E21-D1 — Audit ledgers (`school_audit_events`, `platform_audit_events`)

- **Policy:** 7 years from the event's `occurred_at`.
- **Integrity:** append-only during retention, with no runtime arbitrary
  DELETE. Audit immutability is not weakened.
- **At expiry:** purge or anonymize while preserving whatever integrity
  evidence the audit architecture requires. Direct actor identifiers are
  not kept indefinitely for convenience.
- **Legal hold:** overrides.
- **Erasure (D10):** an actor id is retained while its event is within
  retention, with access minimized; after expiry it is purged or
  anonymized with the event.
- **Tenant closure (D11):** School audit is retained per this period, not
  per School deletion. The current `school_id` CASCADE is never used as the
  closure path.
- **Class B → E21.2B.** This needs a narrowly privileged expiry path for
  append-only ledgers (the runtime role must stay unable to delete) and an
  actor-anonymization design.
- **Status:** not implemented.

### E21-D2 — Transactional email

- **Policy:**
  - sealed content: the existing submission/72-hour lifecycle, unchanged;
  - message metadata, delivery attempts and processed provider events,
    including the plaintext `subject`: **180 days** after the terminal
    state;
  - active suppression: kept while active;
  - released suppression: **1 year** after release.
- **Action at expiry:** delete the message, its attempts (cascade) and its
  provider references in one transaction. A provider event is deleted only
  when no suppression still references it.
- **Legal hold:** a held School's messages are kept.
- **Erasure:** follows the same periods.
- **Tenant closure:** periods continue.
- **Settings:** `MAIL_RETENTION_DAYS=180`.
- **Prerequisites:** the E21.1 findings L1–L4 must be fixed first. They
  were fixed and tested in E21.2A, so 180 is now the approved production
  value (pending ratification).
- **Released-suppression expiry: class B → E21.2B.** The runtime role has
  no DELETE on `email_suppressions`, by design: `DatabaseRoleVerifier` and
  the raw-SQL invariants pin it. So expiring released rows needs the same
  narrowly privileged expiry path as the audit ledgers (D1). Until then,
  released suppressions are kept, and so are the provider events they
  reference.
- **Class A → E21.2A (implemented).**

### E21-D3 — Communications

- **Policy:**
  - content (announcements, messages, owned attachments): **3 years**
    after the end of the academic year in which it was sent;
  - delivery telemetry (deliveries, attempts, policy decisions): **1 year**
    after the terminal delivery state;
  - attachments follow the content period unless an authoritative parent
    needs longer.
- **Exception:** evidence still referenced by another retained record (for
  example consent events) is not deleted.
- **Class B → E21.2C.**

### E21-D4 — Webhook delivery history and outbox

- **Policy:**
  - delivered webhook deliveries: **30 days** after the terminal state;
  - failed or abandoned terminal deliveries: **90 days** after the
    terminal disposition;
  - processed outbox rows (and their consumer receipts): **30 days** after
    `processed_at`, but never while a non-delivered webhook delivery still
    references the event (its retry or redelivery reads the payload);
  - pending, retrying or leased work is never removed by age.
  - Unprocessed and `failed` outbox rows are kept for operator attention.
    They are not age-pruned under this policy.
- **Settings:**
  - `WEBHOOKS_DELIVERY_RETENTION_DAYS=30` (delivered);
  - `WEBHOOKS_FAILED_DELIVERY_RETENTION_DAYS=90` (failed/abandoned);
  - `OUTBOX_RETENTION_DAYS=30`.
- **Class A/B → E21.2A (implemented).** The existing single webhook
  setting is narrowed to delivered rows, and the failed/abandoned period
  gets its own setting. `platform:outbox-prune` is new.

### E21-D5 — Documents

- **Policy:**
  - Documents inherit the retention of their authoritative owner
    (Employee → D9; Student/Guardian → D7; Learning Content/Assignment →
    D6/D7). Metadata and bytes are purged together once eligible.
  - Orphaned or unattached temporary objects: **30 days**.
  - Archive stays status-only.
  - Archived Documents are never globally purged by a single age.
  - Object-storage lifecycle expiry is never enabled independently of
    database eligibility (current objects).
- **Class C (inherited) → E21.2C.** Eligibility comes from owner type,
  owner retention and hold.
- **Orphans: class B → E21.2C.** No tracked orphan state exists today. A
  failed compensation delete only increments `StorageMetrics`, so orphan
  detection is part of that checkpoint.

### E21-D6 — Authority-bearing history

- **Policy:** **7 years** after the authority ends or is revoked. Legal hold
  overrides.
- **Scope:**
  - the Employee↔User link (audit-only today);
  - School, platform and Group role grants and revocations;
  - elevations and Group membership;
  - TeachingAssignments;
  - LMS owner and audience;
  - equivalent authorization provenance.
- **Composition with D1:** facts kept only in audit compose with D1, and
  the later expiry wins.
- **Constraint:** the ability to reconstruct who held authority at a
  historical point must never be destroyed before expiry.
- **Class B → E21.2B** (with D1).

### E21-D7 — Student / academic history

- **Core academic record:** **25 years** after the Student leaves the
  School.
  - These are the records needed to reproduce the formal academic record
    from the implemented modules: Student identity, enrollment history,
    and the academic outcomes that exist today.
  - No future RES or transcript records are invented.
- **Operational Student history:** **7 years** after graduation,
  withdrawal or exit. This covers attendance, operational enrollment
  history and ordinary SIS lifecycle history.
- **Overlaps:** finance or another longer category wins.
- **Class B/C → E21.2D.** The core-versus-operational split per table and
  the exit trigger must be proven first.

### E21-D8 — Finance

- **Policy:** **8 years** after the relevant financial year closes.
- **Scope:** the authoritative records: fees, charges, payments,
  allocations, reversals and cancellations, late-fee entries, receipts,
  journals and financial audit evidence.
- **Excluded:** draft and non-authoritative working rows keep their
  existing lifecycle.
- **Unchanged:** immutability and reversal semantics.
- **Legal hold:** overrides.
- **Class B → E21.2E.** The finance store is append-only by design, so
  expiry needs an archive-aware design.

### E21-D9 — HR and payroll

- **Policy:**
  - employment and payroll evidence: **8 years** after separation;
  - ancillary personal sub-records no longer needed as evidence: **2 years**
    after separation. These are the hard-deletable sub-records (addresses,
    emergency contacts, notes, qualifications, experience,
    certifications), after confirming each model.
  - Employment history, authority history, payroll evidence and audit
    evidence are never deleted under the short rule.
- **Overlaps:** the longest period wins.
- **Class B → E21.2E.**

### E21-D10 — Data-subject erasure and anonymization

- **Policy:** requests are **reviewed cases**, never "delete me and cascade
  everything". The process:
  1. identify the subject and tenant;
  2. inventory the data;
  3. apply holds and active retention;
  4. preserve what is still required;
  5. minimize access while retained;
  6. delete or anonymize what has no continuing basis;
  7. audit the action.
- **Service target:** **30 days** after an erasure action is approved,
  unless a dependency or hold needs longer. This is a project operational
  target, not a statutory deadline.
- **No automated User/Student/Employee cascade deletion.**
- **Class C → E21.2F.**

### E21-D11 — School / tenant closure

- **Policy:** production School hard-delete stays prohibited.
- **Closure:** disable/freeze → archive → retain per category → eventual
  controlled purge when every period and hold has expired.
- **Interactions:** where tenant closure meets another trigger, the later
  expiry wins. Core academic Student records stay under D7.
- **Constraint:** raw School deletion is never the closure workflow. A
  future tenant close is explicit and audited.
- **Class C → E21.2F.**

### E21-D12 — Backups and object versions

- **Policy:**
  - operational backups: the existing **35-day** window (ADR 0050 §9);
  - deleted or overwritten object versions: at most a **35-day** recovery
    window.
  - Backups are not legal archives.
- **Interactions:** current objects are never expired by lifecycle (D5).
  ADR 0050 §8 is amended accordingly.
- **Class D (deployment configuration, E10/E09).**

### E21-D13 — Logs, failed jobs, transient payloads

- **Policy:**
  - ordinary application and request logs: **30 days**;
  - security and authentication logs: **180 days**;
  - metrics: **90 days**;
  - `failed_jobs`: **30 days** after terminal failure;
  - completed transient queue payloads: **7 days** at most;
  - no unnecessary payload logging.
- **ADR 0051 consistency:** ADR 0051 sets minimums (logs ≥ 30 d, metrics
  ≥ 90 d). The adopted maxima equal or exceed them, so there is no
  conflict. The repository's unused `daily` channel (14 files) is shorter,
  which is acceptable.
- **Failed jobs:** setting `FAILED_JOBS_RETENTION_DAYS=30`.
- **Class:**
  - failed jobs: **A/B → E21.2A (implemented**, `platform:failed-jobs-prune`);
  - completed transient payloads: **D**. Completed Redis and database
    queue jobs are removed on completion, and no batching is used.
  - log and metric retention: **D (deployment backend configuration,
    E07)**.

## 5. Engineering checkpoints

| Checkpoint | Scope | State |
|---|---|---|
| **E21.2A** | Mail (L1–L4, 180 d), webhooks (30/90 d), outbox (30 d), failed jobs (30 d), the School hold seam | **Implemented** (E21.2A commit; full isolated regression) |
| E21.2B | Audit (D1), authority history (D6), released-suppression expiry (D2): a narrowly privileged expiry path for protected ledgers | Not started |
| E21.2C | Communications (D3), Documents and orphans (D5) | Not started |
| E21.2D | Student / academic (D7) | Not started |
| E21.2E | Finance (D8), HR and payroll (D9) | Not started |
| E21.2F | Erasure (D10) and tenant-closure orchestration (D11) | Not started |
| E21.2G | Final retention closure audit | Not started |

**Deployment-side (operator, ADR 0058 E07/E09/E10):**
- the log and metric backend retention (D13);
- backup and bucket noncurrent-version lifecycle (D12);
- setting the retention environment values in production (§6).

## 6. Production settings

The values below are fail-closed: a command deletes nothing while its value
is unset. Production must set them once their checkpoint ships.

| Setting | Adopted value | Checkpoint |
|---|---|---|
| `MAIL_RETENTION_DAYS` | 180 | E21.2A |
| (released suppressions, 365 days) | setting defined by E21.2B | E21.2B |
| `WEBHOOKS_DELIVERY_RETENTION_DAYS` | 30 (delivered) | E21.2A |
| `WEBHOOKS_FAILED_DELIVERY_RETENTION_DAYS` | 90 (failed/abandoned) | E21.2A |
| `OUTBOX_RETENTION_DAYS` | 30 | E21.2A |
| `FAILED_JOBS_RETENTION_DAYS` | 30 | E21.2A |
| `RETENTION_HOLD_SCHOOL_IDS` | empty unless a hold is ordered | E21.2A |

## 7. Ratification record (to be completed by the final reviewer)

```text
Ratifying authority:            (role)
Ratification date:
Decisions ratified unchanged:
Decisions amended (and new values):
Jurisdiction / policy basis:
Conditions:
Engineering changes required by amendments:
Outcome:                        RATIFIED / RATIFIED WITH AMENDMENTS / NOT RATIFIED
```
