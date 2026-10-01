# E21 Closure Audit (E21.2G)

> ## STATUS: E21 — OPEN / TECHNICAL BLOCKERS REMAIN
>
> **PROJECT POLICY IMPLEMENTED (where marked) — PENDING FINAL
> LEGAL/COMPLIANCE RATIFICATION.**
>
> Final legal/compliance ratification is **deferred to the pre-production
> project closeout**. That is intentional, not an engineering failure.
>
> No period here is claimed to be statutory, and no legal clearance is
> claimed. This document consolidates the repository state at the E21.2G
> audit (2026-10-01). It is the project reference until the pre-production
> review.

- **Inputs:**
  - `E21-RETENTION-DETERMINATION.md`, the adopted decisions D0–D13 and
    the E21.2G decisions in §8 below;
  - `E21-RETENTION-DECISION-REQUEST.md`, the inventory;
  - the code, migrations and tests at the E21.2G commit.
- **Repository state wins over earlier reports.** Where this audit
  corrected something, it says so.

## 1. Answers

| Question | Answer |
|---|---|
| What is technically implemented? | D0–D7, D9 (classified data), D10 (reviewed erasure cases), D11 (freeze and readiness), D12 and D13 (repository side). See §2. |
| What owner/project decisions remain unresolved? | **One:** erasing a User identity, i.e. anonymising audit actors and authority history. It needs a qualified legal decision (§8, I5). Every other category has a project decision. |
| What engineering blockers remain? | **D8 Finance**: a financial-year close is required (§6, checkpoint E21.3A). Plus four bounded mechanism checkpoints for periods adopted here: E21.3B–E21.3E (§7). |
| What final legal/compliance ratification remains? | All of D0–D13 and the E21.2G decisions (§10). Deferred to the pre-production closeout. |
| Can E21 close now? | **No.** It is technically open: D8 is blocked, and the E21.3B–E mechanisms are not built. |
| What must happen before E21 can close? | E21.3A, then E21.3B–E21.3E. After those: production configuration (§9) and final ratification (§10). |

## 2. D0–D13 matrix

| D | Adopted project policy | State | Mechanism | Remaining |
|---|---|---|---|---|
| D0 | Finite periods need an enforceable, tested mechanism | **PARTIALLY IMPLEMENTED** | Every implemented period has a command and tests | D8; E21.3B–E mechanisms |
| D1 | Audit 7 y after `occurred_at` | **IMPLEMENTED** | `audit-prune` → narrow functions (7-y DB floor) | Ratification |
| D2 | Email metadata 180 d after terminal state; released suppressions 1 y after release | **IMPLEMENTED** | `email-prune`; `email-suppressions-prune` → narrow function | Ratification |
| D3 | Content 3 y after its Academic Year ends; telemetry 1 y after terminal | **IMPLEMENTED** for sent content and telemetry; cancelled deliveries go with their content. **E21.2G:** never-sent cancelled/rejected announcements and empty threads 1 y | `communications-prune` (+ policy-decision function) | Never-sent and empty-thread mechanism (E21.3E) |
| D4 | Delivered 30 d, failed/abandoned 90 d; outbox 30 d after `processed_at` | **IMPLEMENTED** | `webhook-deliveries-prune`, `outbox-prune` | Ratification |
| D5 | Documents inherit their owner; orphans 30 d | **IMPLEMENTED** for Student and Employee owners and orphans. Guardian/LMS Documents follow their parents' E21.2G periods | `storage-orphans-prune`; parent purges | Parents' mechanisms (E21.3C/D) |
| D6 | Authority 7 y after it ends | **IMPLEMENTED** (grants, TeachingAssignments, elevations). **E21.2G:** ended API credentials 7 y | `authority-history-prune` | API credential mechanism (E21.3E) |
| D7 | Core 25 y and operational 7 y after final exit | **IMPLEMENTED** for classified D7 data. **E21.2G:** Library, Transport and Hostel student rows are D7 operational; consent, preferences, processing authorizations and converted admissions go with the core record | `student-retention-prune` | E21.3B |
| D8 | 8 y after the financial year closes | **BLOCKED BY ARCHITECTURE** | none (`FinanceRetentionGuardTest` forbids it) | E21.3A |
| D9 | Ancillary 2 y; evidence 8 y after final separation | **IMPLEMENTED** for classified D9 data | `employee-retention-prune` (HR + Payroll) | Paid staff wait for D8; teaching staff wait for E21.3D |
| D10 | Reviewed cases; retention wins; 30-day target | **IMPLEMENTED** (orchestration) | `erasure-case-*` (operator) | User-identity erasure: legal decision (I5) |
| D11 | Freeze → retain → controlled purge; no hard-delete | **IMPLEMENTED** as freeze and readiness. Tenant purge **NOT AUTHORIZED** | Close/Reopen; `school-closure-status` | Purge prerequisites (§5) |
| D12 | Backups 35 d; noncurrent object versions ≤ 35 d | **IMPLEMENTED** in the repository (ADR 0050 amended, runbook, checklist) | Deployment configuration | **PRODUCTION CONFIGURATION** (PITR window, bucket lifecycle) |
| D13 | Logs 30 d, security 180 d, metrics 90 d, failed jobs 30 d, transient payloads ≤ 7 d | **IMPLEMENTED** for failed jobs. Completed jobs are removed on completion. Logs and metrics are backend configuration | `failed-jobs-prune` | **PRODUCTION CONFIGURATION** (E07 log/metric backend) |

## 3. Commands and scheduler

All entries are daily (except where stated) with `withoutOverlapping`, and
OBS-06 staleness alerts cover them. E21 jobs walk suspended and closed
Schools (`SchoolLifecycleArchitectureGuardTest` allowlist). Correctness never
depends on the order.

| Time | Command | Category | Period setting | Dry run | Batches | Hold | Privilege path |
|---|---|---|---|---|---|---|---|
| 02:10 | `idempotency-prune` | technical TTL | `IDEMPOTENCY_DEFAULT_TTL_HOURS` (48) | **yes (E21.2G)** | 500/School | exempt by design¹ | RLS, runtime |
| 02:20 | `webhook-deliveries-prune` | D4 | `WEBHOOKS_*_RETENTION_DAYS` | yes | 500 | School + platform | RLS, runtime |
| 02:30 | `email-prune` | D2 | `MAIL_RETENTION_DAYS` | yes | 500 | School + platform | RLS, runtime |
| 02:40 | `outbox-prune` | D4 | `OUTBOX_RETENTION_DAYS` | yes | 500 | School + platform | runtime |
| 02:50 | `failed-jobs-prune` | D13 | `FAILED_JOBS_RETENTION_DAYS` | yes | 1000 (framework) | platform | runtime |
| 03:00 | `audit-prune` (+ closed erasure cases) | D1, D10 | `AUDIT_RETENTION_YEARS`, `ERASURE_CASE_RETENTION_YEARS` | yes | ≤5000/function call | School + platform | narrow functions |
| 03:10 | `email-suppressions-prune` | D2 | `MAIL_RELEASED_SUPPRESSION_RETENTION_YEARS` | yes | ≤5000 | platform | narrow function |
| 03:20 | `authority-history-prune` | D6 | `AUTHORITY_HISTORY_RETENTION_YEARS` | yes | ≤5000 | School + platform | narrow functions |
| 03:40 | `communications-prune` | D3 | `COMMUNICATIONS_*_RETENTION_YEARS` | yes | 500; 1 unit per transaction | School | RLS + narrow function |
| 04:10 | `storage-orphans-prune` | D5 | `STORAGE_ORPHAN_RETENTION_DAYS`, scan limit | yes | 10000/School | School | runtime; references re-checked |
| 04:30 | `student-retention-prune` | D7 | `STUDENT_*_RETENTION_YEARS` | yes | 500; 1 Student per transaction | School | RLS, runtime |
| 04:50 | `employee-retention-prune` | D9 | `EMPLOYEE_*_RETENTION_YEARS` | yes | 500; 1 Employee per transaction | School | RLS, runtime |
| hourly | `account-recovery-prune` | technical TTL (24 h) | fixed (ADR 0056) | **yes (E21.2G)** | 5000 | exempt by design¹ | runtime |
| hourly | `staff-account-credentials-prune` | technical TTL (24 h / 7 d) | fixed (ADR 0059) | **yes (E21.2G)** | 5000 | exempt by design¹ | RLS, runtime |
| operator | `erasure-case-execute` | D10 | the domain periods above | yes | 100; 1 subject | School/platform | domain purges |
| operator | `school-closure-status` | D11 | read-only | n/a | n/a | reported | read-only |

¹ **Technical TTLs are exempt from retention holds by design.** They
delete expired one-time security secrets and a replay cache that the
request path itself treats as non-existent once expired
(`IdempotencyGuard`). Keeping them would be the risk. The held evidence is
the audit and the authoritative records. They are short technical
lifetimes, not retention periods.

## 4. Settings

Every retention period has **no default**: unset deletes nothing. Values
are validated as positive whole numbers; an invalid value is a command
failure.

| Setting | Adopted value | Unit and trigger |
|---|---|---|
| `MAIL_RETENTION_DAYS` | 180 | days after terminal state |
| `MAIL_RELEASED_SUPPRESSION_RETENTION_YEARS` | 1 | calendar years after release |
| `WEBHOOKS_DELIVERY_RETENTION_DAYS` | 30 | days (delivered) |
| `WEBHOOKS_FAILED_DELIVERY_RETENTION_DAYS` | 90 | days (failed/abandoned) |
| `OUTBOX_RETENTION_DAYS` | 30 | days after `processed_at` |
| `FAILED_JOBS_RETENTION_DAYS` | 30 | days after `failed_at` |
| `AUDIT_RETENTION_YEARS` | 7 | calendar years after `occurred_at` |
| `AUTHORITY_HISTORY_RETENTION_YEARS` | 7 | calendar years after authority end |
| `COMMUNICATIONS_CONTENT_RETENTION_YEARS` | 3 | calendar years after the Academic Year end |
| `COMMUNICATIONS_DELIVERY_RETENTION_YEARS` | 1 | calendar year after terminal |
| `STORAGE_ORPHAN_RETENTION_DAYS` | 30 | days after last modification |
| `STUDENT_OPERATIONAL_RETENTION_YEARS` | 7 | calendar years after final exit |
| `STUDENT_CORE_RETENTION_YEARS` | 25 | calendar years after final exit |
| `EMPLOYEE_ANCILLARY_RETENTION_YEARS` | 2 | calendar years after final separation |
| `EMPLOYEE_EVIDENCE_RETENTION_YEARS` | 8 | calendar years after final separation |
| `ERASURE_CASE_RETENTION_YEARS` | 7 | calendar years after the case closed |
| `RETENTION_HOLD_SCHOOL_IDS` | empty unless a hold is ordered | — |
| `RETENTION_HOLD_PLATFORM` | false unless a hold is ordered | — |
| `RETENTION_PRUNE_BATCH_SIZE` / `RETENTION_ORPHAN_SCAN_LIMIT` | 500 / 10000 (defaults) | — |

**Period arithmetic.**
- Calendar years always use `subYearsNoOverflow`. 29 February minus or
  plus a year is 28 February, and a Feb-29 trigger becomes eligible on
  1 March; tests pin this for D1, D7 and D9.
- Days stay days.
- The boundary is always strict: a trigger exactly at the cutoff is kept.
- School-local dates are used for date triggers (Academic Year, exit,
  separation); UTC for timestamps.

## 5. Safety audit

- **Privileges.**
  - The runtime role has **no DELETE** on `schools`, either audit ledger,
    email suppressions, any authority history, erasure cases, policy
    decisions, API clients/credentials or any posted Finance table.
  - Exactly ten `SECURITY DEFINER` functions exist, all
    `retention_expire_*`: fixed table and predicate, 1- or 7-year
    database floor, tenant tie where School-scoped, batch cap 5000, pinned
    `search_path`, EXECUTE for the runtime role only, never PUBLIC.
  - `platform:verify-database` (`retention_functions_narrow`,
    `runtime_destructive_privileges_restricted`, `tenant_tables_force_rls`)
    proves it. There is no generic or tenant-delete function.
- **Closed lists.**
  - `RetentionExpiry` is the only function caller.
  - `ReferencingRows` reads the FK catalog: any new referencing table
    blocks D7/D9 purges until classified (Student and Employee
    classification tests).
  - `TenantRetentionCatalog` lists every tenant table literally; a new one
    makes readiness fail closed (`TenantClosureReadinessTest`).
  - The erasure adapters are a closed map.
  - `FinanceRetentionGuardTest` keeps all retention code off the ledger.
  - The Documents seam serves two owners only.
- **Dry run, batches, retries.** Every destructive command has a dry run
  using the same predicate or the same per-unit eligibility. Each deletes
  in bounded batches in deterministic id order (`SKIP LOCKED` in the
  functions), and is retry-safe because the predicate is re-applied at
  delete time. D3, D7 and D9 hold one unit per transaction, never a
  School-wide transaction.
- **Ordinary lifecycle deletes (not retention), all intentional:**
  - draft/working-state rebuilds: announcement audience snapshots, draft
    fee and payroll result recomputation, draft structures, runs, plans
    and grade bands;
  - user-initiated removals: attachment removal, HR sub-record CRUD,
    Guardian unlink, webhook unsubscribe, recipe requirement removal;
  - security and technical paths: credential and MFA changes, upload
    compensation, idempotency reuse.

  None is age-based.
- **Concurrency (real two-process tests):**
  - Communications thread purge vs a new message;
  - Documents orphan run vs an uncommitted upload;
  - Student purge vs re-enrollment and reactivation;
  - Employee purge vs rehire (both phases);
  - erasure vs re-enrollment and rehire;
  - closure vs in-flight operational work and a later claim.

  Authority-history and email prunes delete only immutable terminal
  history under row locks with the predicate re-applied, so no
  eligibility race exists. **No material gap.**
- **Clocks.**
  - Database-floored functions refuse a cutoff younger than the floor
    measured by PostgreSQL `now()`. If the application clock runs ahead,
    a run is **refused**: observable, retried next run, never early.
  - Application-side purges use the application clock, so an unsynchronised
    host clock running far ahead could delete early. NTP-synchronised
    clocks are therefore a **production requirement** (§9).
  - Test-only flakes from frozen transaction time are fixed in tests, not
    by weakening a floor.
- **Metrics cardinality.**
  - `lycenza_retention_rows_total.operation` has 20 values, **exactly the
    generic guard ceiling**. E21.3A–E must consolidate operations (for
    example one value per D-category) instead of raising the ceiling.
  - `scheduled_task` (23) has its own justified ceiling of 30: it is
    pinned to the code-defined schedule.
- **Migrations.**
  - E21.2B–2F migrations: each `down()` removes only the mechanism.
  - Migrate, rollback and re-apply were verified on DDEV per checkpoint
    (schema identical).
  - E21.2A and 2E added none.
- **Tenant purge prerequisites (D11).** A destructive tenant purge needs
  ALL of:
  - final category coverage (E21.3A–E done);
  - D8 resolved;
  - no hold;
  - no unresolved dependency;
  - every period satisfied;
  - final ratification;
  - an explicit audited purge authorization.

  Readiness reports two permanent gates until then, so a School is never
  purge-ready. **Tenant destruction: NOT AUTHORIZED.**

## 6. D8 — Finance retention: BLOCKED (architecture brief for E21.3A)

**Status: BLOCKED BY ARCHITECTURE.** Nothing financial is deleted, by
design.

**Problem: the balance model.** Every balance is derived from the complete
posting history (`FINANCE.md`, "Balance derivation"):
- account balances are the sum of all journal lines;
- a charge's outstanding amount is derived from its allocations,
  adjustments and concessions;
- Student dues and statements are derived from all of these;
- payroll results post to the same ledger.

There is no accounting period, close, lock or carried-forward opening
balance. Deleting any closed year's evidence would silently change today's
balances. The financial-year start month (`fee_settings`) is fixed only
once receipts exist.

**E21.3A — Financial Year Close & Retention Foundation** (a Finance
architecture checkpoint with its own ADR, not a generic prune) must
provide:

1. **An explicit financial year/period entity** per School (start/end
   from the adopted start month), with open, closing and closed states.
   Each posting gets an immutable period identity (backfilled from
   `posted_at` and the start month).
2. **Close validation:**
   - balanced;
   - no draft or unposted work in the period;
   - receipt series sealed;
   - charges either settled or carried;
   - an immutable close timestamp and actor;
   - audit evidence.
3. **A period lock.** No posting into a closed period. Late corrections
   post into the open period as explicit adjusting or reversing entries
   referencing the closed original; there is no back-dating.
4. **Carried-forward opening balances:**
   - per ledger account;
   - per open charge (outstanding carried as an opening receivable item
     with its Student/fee identity);
   - per Student due.

   They reproduce the closed state **exactly** (bcmath).
5. **Balance reconstruction after detail expiry.** Every read model
   (account balance, charge outstanding, Student dues, statements, payroll
   ledger) must compute from opening balances plus retained detail.
6. **Receipt-series compatibility.** The financial-year series key matches
   the period.
7. **Migration and backfill.** Assign existing postings to periods and
   compute historical opening balances. Run a dual-read verification
   (old vs new balance equality) before any close is allowed.
8. **Retention integration.** A narrow, floored function per ledger table
   deletes the detail of a period closed more than 8 calendar years ago.
   It runs only after opening balances are verified, with holds,
   `--dry-run` and batches, inside `FinanceRetentionGuardTest`'s rewritten
   contract.

**Acceptance requirements.**
- Expiring a closed period's detail changes:
  - no account balance;
  - no charge's outstanding amount;
  - no Student's dues;
  - no payroll ledger total.
- Reversals and adjustments across closed periods stay auditable.
- Opening balances reproduce the carried state exactly.
- Every guarantee is proven by real-PostgreSQL tests, including
  concurrent posting vs close.

**Production migration risks:**
- backfill correctness on live ledgers;
- schools whose start month changed before their first receipt
  (ambiguous periods must fail closed);
- long-running backfill transactions;
- read-model cutover.

**Consequences while D8 is blocked:**
- Finance rows keep their Students (D7) and Employees (D9 via payroll
  results). **This is the same D8 blocker, not a separate one.**
- Canteen orders and their stock consumptions follow Finance.

## 7. Follow-up mechanism checkpoints (periods adopted in §8)

These are bounded checkpoints, not one "finish retention" phase. Each adds
mechanisms only, reusing the E21.2D/E patterns (closed lists, one unit per
transaction, holds, dry run, real-process races). Each must keep the
retention metric within its ceiling by consolidating operations.

| Checkpoint | Scope |
|---|---|
| **E21.3A** | Financial Year Close & Retention Foundation (§6) |
| **E21.3B — Student-linked evidence and modules** | D7 operational participants for Library loans, Transport and Hostel assignments. Consent events, domain preferences, processing authorizations and converted admission applications (with their applicant) go with the Student core record, through a narrow path because consent and authorization rows are append-only or undeletable. Ended portal invitations 7 d after they ended. |
| **E21.3C — Admissions and Guardian lifecycle markers** | A decision timestamp for rejected/withdrawn applications (expired 1 y after it; undated history stays unresolved). A durable Guardian "no relationship since" marker, then Guardian personal data, contacts, Documents, account links and consent 1 y after it, unless a retained dependent remains. |
| **E21.3D — Year-bound academic operations** | Curriculum deliveries, timetable entries, LMS content and assignments (with audiences, Documents and the D6 owner minimum), and attendance register headers (only once empty): 7 y after the end of their Academic Year. This releases teaching Employees. |
| **E21.3E — Communications and platform residuals** | Cancelled/rejected never-sent announcements (1 y after cancellation or rejection decision); empty threads (1 y after last activity); visits (1 y after check-out) and visitors once no visit remains; automation records (1 y after completion); driver assignments (7 y after they end); ended API credentials (7 y; narrow path). |

## 8. E21.2G project decisions

**PROJECT-ADOPTED FOR IMPLEMENTATION — PENDING FINAL LEGAL/COMPLIANCE
RATIFICATION.**

**Communications**
- **C1.** Live drafts, pending-approval, approved and scheduled
  announcements are working state with no retention clock; they are kept
  while live. A never-published **cancelled** (`cancelled_at`) or
  **rejected** (approval `decided_at`) announcement is abandoned working
  state: **1 calendar year** after that time. Mechanism: E21.3E.
- **C2.** An **empty thread** (no message; possible because
  `createThread` adds participants only) holds no communication content:
  **1 calendar year after `last_activity_at`**. That is the documented
  trigger: for an empty thread it is its creation or last change.
  Mechanism: E21.3E.
- **C3. Cancelled deliveries** have no terminal time, so they have no
  separate clock. They go with their content (D3 content purge cascades).
  **Implemented.** `updated_at` is never used.
- **C4.** **Consent events** are evidence of the processing choice behind
  historical communications. They are kept with their subject's record:
  the Student core record (25 y), or Guardian personal data (C/G1).
  Mechanism: E21.3B/E21.3C.
- **C5.** **Domain preferences** (current processing choice per subject)
  follow the same rule as C4.
- **C6.** **Membership email preferences** (`communication_preferences`)
  are an ordinary account preference. They follow the membership (tenant
  lifetime).
- **C7.** **`notifications`** is a Phase 0 demo with no production
  producer (`AUTOMATION.md`): **not applicable**. Any future producer must
  adopt a D13 period first.

**Academic**
- **A1.** Year-bound academic operations go **7 calendar years after the
  end of their Academic Year**:
  - curriculum deliveries;
  - timetable entries;
  - LMS learning content and assignments, with Section audiences and their
    Documents; the D6 owner minimum applies;
  - attendance register headers, only once no record remains.

  These are School teaching evidence, never tied to a Student's exit.
  Mechanism: E21.3D.
- **A2.** Syllabus units, examinations and examination papers are School
  academic configuration without personal data: **tenant lifetime**.

**Guardians and Students**
- **G1.** **Guardian personal data** (Guardian, contacts, Documents, account
  links, consent) goes **1 calendar year after the Guardian has had no
  relationship and no retained dependent**. Relationships are hard-deleted
  by unlink or by D7, so a durable marker is needed. Mechanism: E21.3C.
  Until then the data is kept (erasure reports `mechanism_pending`).
- **P1.** **Processing authorizations** (legal-basis evidence for
  processing the academic record) are kept **with the Student core record**
  (25 y after final exit). Mechanism: E21.3B.
- **S1. No earlier partial minimisation.** A retained Student's identity,
  or a retained Employee's personal details, goes with its record. Erasure
  reports `retained_with_core_record` / `retained_with_evidence`.

**Admissions**
- **AD1.** A **converted** application and its applicant are supporting
  Student history, kept with the Student core record. Mechanism: E21.3B.
- **AD2.** A **rejected or withdrawn** application goes **1 calendar year
  after the decision**. There is no decision timestamp today, and
  `updated_at` is never used, so undated history stays kept. Mechanism:
  E21.3C. Draft, submitted and accepted applications are live working
  state.

**Operational modules**
- **O1.** **Library loans and Transport/Hostel student assignments** are
  D7 operational history: 7 y after the Student's final exit. An open
  (unreturned or active) one keeps the Student. Mechanism: E21.3B.
- **O2.** **Driver (route) assignments** go 7 y after `ends_on`.
  Mechanism: E21.3E.
- **O3.** A **visit** goes 1 y after `checked_out_at`; a visitor goes once
  no visit remains. Mechanism: E21.3E.
- **O4.** **Automation executions, attempts and review items** go 1 y
  after `completed_at`. Mechanism: E21.3E.
- **O5.** **Inventory balances and stock movements** carry no personal
  data: tenant lifetime. **Canteen stock consumptions** follow their
  orders (D8).

**Identity**
- **I1.** **Memberships** are authority provenance, suspended and never
  deleted (rule 92): tenant lifetime. **Ended staff invitations** keep
  their implemented 7-day TTL.
- **I2.** **Ended portal invitations** (`identity_account_invitations`)
  go 7 days after they ended. Mechanism: E21.3B.
- **I3.** **Ended API credentials** (revoked, superseded or expired) are
  D6: 7 y. Mechanism: E21.3E.
- **I4.** **Student account links** go with the Student core record
  (implemented). **Guardian links** go with G1.
- **I5. User-identity erasure: POLICY UNRESOLVED.** It means anonymising
  audit actors and authority history, a qualified legal decision. This
  is a final-ratification item; it adds no finite period.

**Employees**
- **E1.** **Payroll results** are the D8 blocker (same item).
- **E2.** **Teaching references** (attendance sessions, timetable, LMS) are
  released by A1. **Transport** by O2, **Visitor hosts** by O3.
- **E3.** A **manager reference** from another Employee's assignment is
  that Employee's employment history. It is never nulled for retention,
  and it is released when the referencing Employee's evidence expires.

## 9. Production configuration required before O1 (external)

**Repository implementation is complete for these; the values are
environment-specific.**

1. **Retention settings:** every value in §4.
2. **Holds:** `RETENTION_HOLD_*` as legal holds require.
3. **D12:**
   - PostgreSQL PITR with a 35-day window;
   - bucket versioning on, with noncurrent versions expiring after at most
     35 days and current objects never expired by lifecycle;
   - an independent encrypted copy and restore drills
     (`docs/operations/BACKUP-AND-RESTORE.md`).
4. **D13:**
   - log backend: ordinary logs 30 d, security/auth logs 180 d;
   - metrics backend: 90 d (ADR 0051 minimums met).
5. **NTP-synchronised clocks** on every application and database host. An
   application clock running far ahead is the only way an application-side
   purge could delete early.
6. **The scheduler running**, with OBS-06 alerts routed.

## 10. Final legal/compliance ratification package

**Deferred to pre-production project closeout.** The reviewer receives:
- the D0–D13 policies (§2) and every adopted period (§4);
- trigger definitions:
  - final exit (`StudentRetentionEligibility`);
  - final separation (`EmployeeRetentionEligibility`);
  - Academic Year containment (`AcademicYearCalendar`);
  - terminal states;
- legal-hold behaviour, including the technical-TTL exemption (§3);
- erasure policy (D10, I5) and tenant-closure policy (D11, §5);
- the D8 Finance exception (§6);
- the E21.2G decisions (§8);
- the backup and log policy (D12, D13) and production configuration (§9).

**Nothing in this repository claims that review has occurred.**

## 11. Other production gates (not E21)

**O1 cannot clear today.** Besides E21 (D8, E21.3B–E, ratification and
§9), ADR 0058's register keeps separate mandatory gates open. Among them:
- **E33 / TCH-L1** (teacher Attendance legal determination): no production
  `teacher` role grants while it is open (ADR 0063 §40);
- **E02:** final qualification on protected `main`;
- **E03:** `main` protection;
- **E07, E09, E10:** observability backend, production-equivalent
  environment, backups;
- **E15, E16:** promotion evidence and vulnerability exceptions;
- **E17:** email provider legal and processor review;
- **E30–E32:** fee receipt form/GST, fee regulation, RTE free seats.

See the register for the full list. E21 is not the only blocker.
