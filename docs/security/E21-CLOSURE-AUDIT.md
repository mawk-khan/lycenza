# E21 Closure Audit (E21.2G)

> ## STATUS: E21 — OPEN / LEGAL DECISION REMAINS (E21 ENGINEERING COMPLETE, E21.3F)
>
> *History: E21.2G recorded "OPEN / TECHNICAL BLOCKERS REMAIN"; E21.3A–E21.3F
> resolved every technical blocker.*
>
> **PROJECT POLICY IMPLEMENTED (where marked) — PENDING FINAL
> LEGAL/COMPLIANCE RATIFICATION.**
>
> Final legal/compliance ratification is **deferred to the pre-production
> project closeout**. That is intentional, not an engineering failure.
>
> No period here is claimed to be statutory, and no legal clearance is
> claimed. This document consolidates the repository state at the E21.2G
> audit (2026-10-01), updated at E21.3A/E21.3A2, E21.3B, E21.3C, E21.3D,
> E21.3E and E21.3F (2026-10-02).
> It is the project reference until the pre-production review.

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
| What engineering blockers remain? | **None.** D8 Finance is IMPLEMENTED (E21.3A + E21.3A2, ADR 0064). E21.3B (Student-linked evidence and modules), E21.3C (Admissions and Guardian lifecycle markers), E21.3D (year-bound academic operations) and E21.3E (communications and platform residuals) are IMPLEMENTED. **E21.3F resolved the last one, the D8 × D9 payroll intersection** (ADR 0064 §21 amended): posted payroll evidence expires 8 y after final separation, an emptied run releases its journal entries to D8, and paid Employees are no longer kept indefinitely. No retention-mechanism checkpoint remains (§7). |
| What final legal/compliance ratification remains? | All of D0–D13 and the E21.2G decisions (§10). Deferred to the pre-production closeout. |
| Can E21 close now? | **No.** Engineering is complete (E21.3F), but E21 closes on a qualified decision per v1 category (ADR 0058): User-identity erasure needs a legal decision (I5) and final ratification is pending. |
| What must happen before E21 can close? | The User-identity erasure legal decision (I5), production configuration (§9) and final ratification (§10). Tenant purge additionally needs explicit authorization (§5). No engineering checkpoint remains. |

## 2. D0–D13 matrix

| D | Adopted project policy | State | Mechanism | Remaining |
|---|---|---|---|---|
| D0 | Finite periods need an enforceable, tested mechanism | **IMPLEMENTED** for every adopted period (E21.3F: the payroll D8 × D9 intersection too) | Every implemented period has a command and tests | Ratification |
| D1 | Audit 7 y after `occurred_at` | **IMPLEMENTED** | `audit-prune` → narrow functions (7-y DB floor) | Ratification |
| D2 | Email metadata 180 d after terminal state; released suppressions 1 y after release | **IMPLEMENTED** | `email-prune`; `email-suppressions-prune` → narrow function | Ratification |
| D3 | Content 3 y after its Academic Year ends; telemetry 1 y after terminal | **IMPLEMENTED** for sent content and telemetry; cancelled deliveries go with their content. **E21.3E (implemented):** never-sent cancelled/rejected announcements 1 y after `cancelled_at` / the rejection decision; empty threads 1 y after `last_activity_at` | `communications-prune` (+ `--only=residual`; policy-decision function) | Ratification |
| D4 | Delivered 30 d, failed/abandoned 90 d; outbox 30 d after `processed_at` | **IMPLEMENTED** | `webhook-deliveries-prune`, `outbox-prune` | Ratification |
| D5 | Documents inherit their owner; orphans 30 d | **IMPLEMENTED** for Student, Employee, (E21.3C) Guardian and (E21.3D) LMS Learning Content/Assignment owners, and orphans | `storage-orphans-prune`; parent purges | Ratification |
| D6 | Authority 7 y after it ends | **IMPLEMENTED** (grants, TeachingAssignments, elevations). **E21.2G:** ended API credentials 7 y after LEAST(revoked_at, expires_at) (**E21.3E, implemented**). **E21.3B (I2, implemented):** ended portal invitations 7 d after they ended | `authority-history-prune`; `portal-invitations-prune` | Ratification |
| D7 | Core 25 y and operational 7 y after final exit | **IMPLEMENTED** for classified D7 data. **E21.3B (implemented):** returned Library loans and ended Transport/Hostel assignments are D7 operational (an open one keeps the Student); processing authorizations, converted admission applications (with applicants that have nothing else), the Student subject's consent events and domain preferences, and the Guardian relationships an authorization names go with the core record in its unit | `student-retention-prune` (via `StudentRetention`); two core-floored functions (`retention_expire_student_processing_authorizations`, `retention_expire_student_consent_events`) | Ratification; Guardian-subject rows and rejected/withdrawn applications (E21.3C) |
| D8 | 8 y after the financial period closes (`closed_at`) | **IMPLEMENTED** (E21.3A + E21.3A2, ADR 0064) | carry-forward reads; `finance-retention-prune` → `retention_expire_finance_unit` (closed period, 8-calendar-year DB floor, settled units, holds, accounting proof) | Ratification. Payroll-linked detail leaves only once Payroll's D9 expiry released it AND its period is 8 y closed (E21.3F, ADR 0064 §21 amended) |
| D9 | Ancillary 2 y; evidence 8 y after final separation | **IMPLEMENTED** for classified D9 data. **E21.3F (implemented):** posted payroll evidence (results, lines, statutory results, adjustments, LWF charges) 8 y after final separation once every run and posting is that old; emptied runs release their journal entries to D8 | `employee-retention-prune` (HR + Payroll configuration); `payroll-retention-prune` → `retention_expire_payroll_employee_evidence`, `retention_expire_payroll_run` (separation- and 8-year-floored) | Ratification; teaching references are released as their academic rows expire (E21.3D) |
| D10 | Reviewed cases; retention wins; 30-day target | **IMPLEMENTED** (orchestration). **E21.3C:** a Guardian case now follows G1 (related, unresolved, running, blocked, held or eligible) | `erasure-case-*` (operator) | User-identity erasure: legal decision (I5) |
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
| 03:20 | `authority-history-prune` (E21.3E: + ended API credentials) | D6 | `AUTHORITY_HISTORY_RETENTION_YEARS` | yes | ≤5000 | School + platform | narrow functions |
| 03:40 | `communications-prune` (E21.3E: + `residual`) | D3, C1, C2 | `COMMUNICATIONS_*_RETENTION_YEARS` | yes | 500; 1 unit per transaction | School | RLS + narrow function |
| 04:10 | `storage-orphans-prune` | D5 | `STORAGE_ORPHAN_RETENTION_DAYS`, scan limit | yes | 10000/School | School | runtime; references re-checked |
| 04:20 | `portal-invitations-prune` (E21.3B) | I2 | `PORTAL_INVITATION_RETENTION_DAYS` | yes | 500, `SKIP LOCKED`; 1 batch per transaction | School | RLS, runtime |
| 04:30 | `student-retention-prune` (E21.3B: + Library/Transport/Hostel; core evidence) | D7 | `STUDENT_*_RETENTION_YEARS` | yes | 500; 1 Student per transaction | School | RLS, runtime; core evidence through two narrow functions |
| 04:35 | `admissions-retention-prune` (E21.3C) | AD2 | `ADMISSIONS_TERMINAL_RETENTION_YEARS` | yes | 500; 1 applicant per transaction | School | RLS, runtime |
| 04:40 | `guardian-retention-prune` (E21.3C) | G1 | `GUARDIAN_RETENTION_YEARS` (+ `AUTHORITY_HISTORY_RETENTION_YEARS` for revoked links) | yes | 500; 1 Guardian per transaction | School | RLS, runtime; Guardian consent through one narrow function |
| 04:45 | `academic-retention-prune` (E21.3D) | A1 | `ACADEMIC_OPERATIONS_RETENTION_YEARS` (+ `AUTHORITY_HISTORY_RETENTION_YEARS` for teacher-owned LMS) | yes | 500 rows per transaction (`SKIP LOCKED`); 1 LMS resource per transaction | School | RLS, runtime; LMS through two narrow functions |
| 04:48 | `operations-retention-prune` (E21.3E) | O2, O3, O4 | `OPERATIONS_*_RETENTION_YEARS` | yes | 500 rows (`SKIP LOCKED`); 1 visitor per transaction | School | RLS, runtime |
| 04:50 | `employee-retention-prune` | D9 | `EMPLOYEE_*_RETENTION_YEARS` | yes | 500; 1 Employee per transaction | School | RLS, runtime |
| 05:10 | `finance-retention-prune` (E21.3A2) | D8 | `FINANCE_RETENTION_ENABLED` + `FINANCE_RETENTION_YEARS` (≥ 8) | yes | per-School unit limit; 1 unit per transaction | School | narrow function (period-floored) |
| 05:20 | `payroll-retention-prune` (E21.3F) | D9 payroll evidence | `EMPLOYEE_EVIDENCE_RETENTION_YEARS` (≥ 8) | yes | 500; 1 Employee / 1 run group per transaction | School | two narrow functions (separation- and 8-year-floored) |
| hourly | `account-recovery-prune` | technical TTL (24 h) | fixed (ADR 0056) | **yes (E21.2G)** | 5000 | exempt by design¹ | runtime |
| hourly | `staff-account-credentials-prune` | technical TTL (24 h / 7 d) | fixed (ADR 0059) | **yes (E21.2G)** | 5000 | exempt by design¹ | RLS, runtime |
| operator | `erasure-case-execute` | D10 | the domain periods above | yes | 100; 1 subject | School/platform | domain purges |
| operator | `lifecycle-markers-backfill` (E21.3C) | AD2/G1 markers | none (evidence only) | yes | 500 | not needed (writes no deletion) | RLS, runtime; database refuses any other marker write |
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
| `STUDENT_OPERATIONAL_RETENTION_YEARS` | 7 | calendar years after final exit (E21.3B: also returned Library loans and ended Transport/Hostel assignments) |
| `STUDENT_CORE_RETENTION_YEARS` | 25 | calendar years after final exit (E21.3B: with processing authorizations, converted admissions, consent and preferences; database floor 25) |
| `PORTAL_INVITATION_RETENTION_DAYS` | 7 | days after an ended portal invitation ended (accepted, revoked, or expired unaccepted) (E21.3B) |
| `ADMISSIONS_TERMINAL_RETENTION_YEARS` | 1 | calendar year after a rejected/withdrawn application's `terminal_at` (UTC timestamp) (E21.3C) |
| `ACADEMIC_OPERATIONS_RETENTION_YEARS` | 7 | calendar years after the authoritative Academic Year's `ends_on` (School-local date) (E21.3D; LMS database floor 7) |
| `COMMUNICATIONS_ABANDONED_RETENTION_YEARS` | 1 | calendar year after a never-sent announcement's cancellation/rejection or an empty thread's `last_activity_at` (E21.3E) |
| `OPERATIONS_DRIVER_ASSIGNMENT_RETENTION_YEARS` | 7 | calendar years after `ends_on` (E21.3E) |
| `OPERATIONS_VISIT_RETENTION_YEARS` | 1 | calendar year after `checked_out_at` (E21.3E) |
| `OPERATIONS_AUTOMATION_RETENTION_YEARS` | 1 | calendar year after `completed_at` (E21.3E) |
| `GUARDIAN_RETENTION_YEARS` | 1 | calendar year after `guardians.no_relationship_since` (UTC timestamp) (E21.3C; database floor 1 for its consent evidence) |
| `EMPLOYEE_ANCILLARY_RETENTION_YEARS` | 2 | calendar years after final separation |
| `EMPLOYEE_EVIDENCE_RETENTION_YEARS` | 8 | calendar years after final separation (E21.3F: also posted payroll evidence; `payroll-retention-prune` refuses less than 8; one D9 setting) |
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
  - Exactly seventeen `SECURITY DEFINER` retention functions exist, all
    `retention_expire_*` (ten at E21.2G; E21.3A2 added the Finance unit;
    E21.3B the two Student-core evidence functions; E21.3C the Guardian
    consent function; E21.3D the two LMS resource functions; E21.3E the API
    credential function): fixed table and predicate, a database floor (1, 7,
    8 or 25 years), tenant tie where
    School-scoped, a bounded batch or unit, pinned `search_path`, EXECUTE
    for the runtime role only, never PUBLIC. (The one other definer
    function, `finance_assign_journal_entry_period`, is ADR 0064's
    backfill assignment, not retention.)
  - **E21.3B.** `student_processing_authorizations` keeps runtime
    UPDATE/DELETE privileges (row locks need them), but its trigger refuses
    every direct DELETE unless the transaction-local
    `app.student_core_retention` flag is set AND the current user holds the
    table owner's privileges (only inside the definer function).
    `communication_domain_consent_events` has no runtime DELETE (now pinned
    in `NO_RUNTIME_DELETE`). Both functions re-prove the Student core floor
    themselves: tenant context, Student row locked, `inactive`, no active
    Subject Enrollment, every non-cancelled placement ended before a cutoff
    at least 25 calendar years before the School-local date.
  - `platform:verify-database` (`retention_functions_narrow`,
    `runtime_destructive_privileges_restricted`, `tenant_tables_force_rls`)
    proves it. There is no generic or tenant-delete function.
- **Closed lists.**
  - `RetentionExpiry` is the only function caller.
  - `ReferencingRows` reads the FK catalog: any new referencing table
    blocks D7/D9 purges until classified (Student and Employee
    classification tests). E21.3B extends it to the newly expired rows
    (Library, Transport, Hostel, admissions, applicants, consent,
    preferences, processing authorizations, portal invitations).
  - `StudentRetention` is the one composition of the Student-linked purges
    (the scheduled run and an erasure case); the core participants
    (`StudentCoreParticipant`) are its closed list. Each newly expired table
    is deleted from by exactly one service
    (`StudentLinkedRetentionArchitectureGuardTest`).
  - `TenantRetentionCatalog::PENDING_ROWS` kept the E21.3C rows of E21.3B
    tables `mechanism_pending`; E21.3C implemented them and the list is now
    empty. `UNRESOLVED_ROWS` (E21.3C) reports undated legacy terminal
    applications and unmarked Guardians without a relationship as
    `unresolved` (gate `retention_trigger_unresolved`): kept until resolved.
  - **E21.3C markers are database-owned.** `admission_applications.terminal_at`
    is set by a trigger in the terminal transition and is immutable;
    `guardians.no_relationship_since` is maintained by a trigger on every
    relationship writer (link, unlink, conversion, D7 retention, cascades)
    under the Guardian row lock. Application code writes either only in the
    evidence backfill, which the database limits to a past time on a
    still-unset row (`AdmissionsGuardianRetentionArchitectureGuardTest`).
    `GuardianRetentionClassificationTest` pins every Guardian reference.
  - `TenantRetentionCatalog` lists every tenant table literally; a new one
    makes readiness fail closed (`TenantClosureReadinessTest`).
  - **E21.3E.** Never-sent announcements and empty threads (Communications),
    driver assignments (Transport), visits (Visitor), automation executions
    (Support, because the Automation module may not use the query builder)
    and API credentials (`RetentionExpiry`, D6) are a closed list reached
    only by their commands; `ResidualRetentionClassificationTest` pins every
    reference (owned children must be ON DELETE CASCADE) and
    `ResidualRetentionArchitectureGuardTest` pins that memberships, their
    preferences and Inventory are never age-pruned, that notifications still
    have no production producer, and that Canteen orders follow Finance only.
  - **E21.3D.** The year clock is only the authoritative Academic Year's
    `ends_on` (AcademicYearRetention), and `academic_years` dates are now
    immutable for every role (trigger), so the clock cannot move. Three row
    services go through `RetentionBatch` (FK-catalog "unreferenced" check,
    `SKIP LOCKED`), the LMS unit through `LmsResourceRetention` and two
    floored functions; `AcademicRetentionClassificationTest` and
    `AcademicRetentionArchitectureGuardTest` pin the closed list and that
    syllabus and examination configuration are never expired.
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
  - E21.3B: Library expiry vs re-enrollment; core evidence vs a new
    processing authorization (both orders); portal-invitation expiry vs a
    revocation holding the row (two connections). Returned loans and ended
    assignments are never reopened, so no other transition race exists;
  - E21.3E: empty-thread expiry vs a new message (both orders); never-sent
    expiry vs editing a rejected announcement; visitor expiry vs a new visit
    (two processes). Ended driver assignments, checkouts, completed
    executions and ended credentials are one-way (pinned; credentials by the
    database);
  - E21.3D: an empty register header vs a late attendance record, and a
    timetable entry vs a late register header (two connections: the
    in-flight child keeps its parent; an expiry first makes the late
    child fail); an LMS resource vs a new Document (two processes, both
    orders). An audience can never be added to an existing resource;
  - E21.3C: Guardian purge vs a new relationship (both orders) and vs a new
    account link; Admissions purge vs a new application for the same
    applicant (both orders). A rejected/withdrawn application can never be
    converted or reopened, so no conversion race exists for an eligible
    row;
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
  - `lycenza_retention_rows_total.operation` was consolidated to nine
    stable families in E21.3A2. E21.3B and E21.3C add categories only inside
    existing families. E21.3D adds one deliberate family, `academic` (School
    teaching evidence is neither Student- nor Employee-rooted); E21.3E adds
    `operations`: eleven values, far below the ceiling of 20.
  - `scheduled_task` (29 since E21.3E) has its own justified ceiling of
    30: it is pinned to the code-defined schedule.
- **Migrations.**
  - E21.2B–2F and E21.3B migrations: each `down()` removes only the
    mechanism (E21.3B restores the original processing-authorization
    guard verbatim); E21.3A2's refuses once lineage exists, and E21.3C's
    once any lifecycle marker carries evidence. E21.3D and E21.3E remove
    only functions/triggers. E21.3F's removes its functions, trigger
    allowances and marker, and refuses once any payroll run carries the
    marker.
  - Migrate, rollback and re-apply were verified on DDEV per checkpoint
    (schema identical).
  - E21.2A and 2E added none.
- **Tenant purge prerequisites (D11).** A destructive tenant purge needs
  ALL of:
  - final category coverage (E21.3A–F done);
  - D8 resolved;
  - no hold;
  - no unresolved dependency;
  - every period satisfied;
  - final ratification;
  - an explicit audited purge authorization.

  Readiness reports two permanent gates until then, so a School is never
  purge-ready. **Tenant destruction: NOT AUTHORIZED.**

## 6. D8 — Finance retention: BLOCKED (architecture brief for E21.3A)

**Status (E21.3A, 2026-10-02): FOUNDATION READY — RETENTION CUTOVER STILL
REQUIRED.** ADR 0064 implements items 1–4, 6 and 7 below:
- the period entity and posting identity;
- close validation, as amended in ADR 0064 §4.3;
- the period lock;
- account and charge baselines (Student dues are their sum);
- receipt-series compatibility;
- the backfill and the dual-read check.

Item 5 (read models computing from baselines) and item 8 (expiry) are
**E21.3A2**. Nothing financial is deleted, by design.

**Status (E21.3A2, 2026-10-02): IMPLEMENTED** (ADR 0064 §14–§24, runbook
`docs/operations/FINANCE-RETENTION.md`). Items 5 and 8 below are done:
- the production reads use carry-forward + later detail;
- settled, dependency-safe units of periods closed >= 8 calendar years ago
  expire through one fixed-purpose, database-floored function, with holds,
  a dry run and an accounting proof per unit.

The golden test proves every account balance, charge outstanding, Student
due, payroll total and receipt series unchanged across expiry. Payroll-linked
detail stays (the D8 × D9 intersection, ADR 0064 §21) until E21.3F's
payroll expiry releases it; the E21.3F matrix test proves the same readings
unchanged across payroll expiry and the later Finance expiry.

The original brief follows (status at E21.2G: BLOCKED BY ARCHITECTURE).

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
| **E21.3A** | Financial Year Close & Retention Foundation (§6): **done** (ADR 0064) |
| **E21.3A2 — Finance Retention Cutover & Historical Expiry** | **Done** (ADR 0064 §14–§24): carry-forward reads; settled-unit expiry 8 calendar years after the period close; DB-floored function; holds; dry run; per-unit accounting proof; rewritten `FinanceRetentionGuardTest` contract. Residual: the payroll D8 × D9 intersection (§21) |
| **E21.3B — Student-linked evidence and modules** | **Done (2026-10-02).** D7 operational: returned Library loans, ended Transport assignments and ended Hostel residencies, 7 y after final exit (open ones keep the Student). With the core record (25 y): processing authorizations and the Student subject's consent events through two core-floored functions; converted admission applications (with applicants that have nothing else) and domain preferences; Guardian relationships an authorization names. Ended portal invitations 7 d after they ended (`portal-invitations-prune`). No new parent owns Documents. Guardian-subject rows and rejected/withdrawn applications stay for E21.3C. |
| **E21.3C — Admissions and Guardian lifecycle markers** | **Done (2026-10-02).** A database-owned, immutable `terminal_at` for rejected/withdrawn applications (1 y after it; undated legacy rows unresolved and kept; evidence backfill from the transition's own audit event). A durable Guardian `no_relationship_since` maintained by a relationship trigger (cleared on re-link, restarted on the next final unlink; evidence backfill only from complete audit history). Guardian personal data (Guardian, contacts, Documents, revoked account links past D6, its own consent and preferences) 1 y after it, unless a retained dependent remains. |
| **E21.3D — Year-bound academic operations** | **Done (2026-10-02).** Curriculum deliveries, attendance register headers (once empty), timetable entries (once no header references them) and LMS Learning Content/Assignments (with audiences and Documents, past the D6 owner/audience minimum) 7 y after the end of their authoritative Academic Year (`platform:academic-retention-prune`); Academic Year dates frozen; Employee teaching references released by these rows' own expiry. Syllabus and examination configuration stay (A2). |
| **E21.3E — Communications and platform residuals** | **Done (2026-10-02).** Never-sent cancelled/rejected announcements 1 y after `cancelled_at` / the rejection decision (`decided_at` of the latest, rejected, approval request); empty threads 1 y after `last_activity_at` (`communications-prune --only=residual`); ended driver assignments 7 y after `ends_on`, visits 1 y after `checked_out_at` (a visitor with its last visit), completed automation executions 1 y after `completed_at` (`operations-retention-prune`); ended API credentials 7 y after LEAST(revoked_at, expires_at) (`authority-history-prune`, narrow function). No new lifecycle marker was needed. Memberships, membership preferences and Inventory pinned tenant lifetime; notifications re-verified not applicable. |
| **E21.3F — Payroll Evidence Retention & Employee Release** | **Done (2026-10-02).** The recorded D8 × D9 intersection (ADR 0064 §21 amended). Posted payroll evidence (results with lines and statutory results, adjustments, LWF charges) 8 calendar years after the Employee's final separation, once every run holding it and every posting is that old (`payroll-retention-prune`, `retention_expire_payroll_employee_evidence`); an emptied regular run with its corrections then loses its postings and runs (`retention_expire_payroll_run`), releasing its journal entries to D8, which expires them only once their period is 8 y closed. Paid Employees are released by the next `employee-retention-prune`. Payroll periods are tenant lifetime. **The last retention-mechanism checkpoint.** |

## 8. E21.2G project decisions

**PROJECT-ADOPTED FOR IMPLEMENTATION — PENDING FINAL LEGAL/COMPLIANCE
RATIFICATION.**

**Communications**
- **C1.** Live drafts, pending-approval, approved and scheduled
  announcements are working state with no retention clock; they are kept
  while live. A never-published **cancelled** (`cancelled_at`) or
  **rejected** (approval `decided_at`) announcement is abandoned working
  state: **1 calendar year** after that time. Mechanism: E21.3E (**implemented**;
  a rejected announcement is still editable, so the status is rechecked
  under its lock).
- **C2.** An **empty thread** (no message; possible because
  `createThread` adds participants only) holds no communication content:
  **1 calendar year after `last_activity_at`**. That is the documented
  trigger: for an empty thread it is its creation or last change.
  Mechanism: E21.3E (**implemented**).
- **C3. Cancelled deliveries** have no terminal time, so they have no
  separate clock. They go with their content (D3 content purge cascades).
  **Implemented.** `updated_at` is never used.
- **C4.** **Consent events** are evidence of the processing choice behind
  historical communications. They are kept with their subject's record:
  the Student core record (25 y), or Guardian personal data (C/G1).
  Mechanism: E21.3B (Student subject, **implemented**)/E21.3C (Guardian).
- **C5.** **Domain preferences** (current processing choice per subject)
  follow the same rule as C4.
- **C6.** **Membership email preferences** (`communication_preferences`)
  are an ordinary account preference. They follow the membership (tenant
  lifetime).
  **E21.3E:** pinned: no independent clock (architecture guard).
- **C7.** **`notifications`** is a Phase 0 demo with no production
  producer (`AUTOMATION.md`): **not applicable**. Any future producer must
  adopt a D13 period first.
  **E21.3E:** re-verified: the only writer is the Phase 0C demo consumer;
  its emitter (`SchoolSettingsService`) has no production caller (guard).

**Academic**
- **A1.** Year-bound academic operations go **7 calendar years after the
  end of their Academic Year**:
  - curriculum deliveries;
  - timetable entries;
  - LMS learning content and assignments, with Section audiences and their
    Documents; the D6 owner minimum applies;
  - attendance register headers, only once no record remains.

  These are School teaching evidence, never tied to a Student's exit.
  Mechanism: E21.3D (**implemented**).
- **A2.** Syllabus units, examinations and examination papers are School
  academic configuration without personal data: **tenant lifetime**.

**Guardians and Students**
- **G1.** **Guardian personal data** (Guardian, contacts, Documents, account
  links, consent) goes **1 calendar year after the Guardian has had no
  relationship and no retained dependent**. Relationships are hard-deleted
  by unlink or by D7, so a durable marker is needed. Mechanism: E21.3C
  (**implemented**: `guardians.no_relationship_since`; erasure follows it).
- **P1.** **Processing authorizations** (legal-basis evidence for
  processing the academic record) are kept **with the Student core record**
  (25 y after final exit). Mechanism: E21.3B (**implemented**).
- **S1. No earlier partial minimisation.** A retained Student's identity,
  or a retained Employee's personal details, goes with its record. Erasure
  reports `retained_with_core_record` / `retained_with_evidence`.

**Admissions**
- **AD1.** A **converted** application and its applicant are supporting
  Student history, kept with the Student core record. Mechanism: E21.3B
  (**implemented**; the applicant goes only once no application of it
  remains).
- **AD2.** A **rejected or withdrawn** application goes **1 calendar year
  after the decision**. There is no decision timestamp today, and
  `updated_at` is never used, so undated history stays kept. Mechanism:
  E21.3C (**implemented**: `terminal_at`; undated legacy rows stay
  unresolved and kept). Draft, submitted and accepted applications are
  live working state.

**Operational modules**
- **O1.** **Library loans and Transport/Hostel student assignments** are
  D7 operational history: 7 y after the Student's final exit. An open
  (unreturned or active) one keeps the Student. Mechanism: E21.3B
  (**implemented**). Library has no fines or Finance link; no Finance row
  references a Transport or Hostel assignment.
- **O2.** **Driver (route) assignments** go 7 y after `ends_on`.
  Mechanism: E21.3E (**implemented**).
- **O3.** A **visit** goes 1 y after `checked_out_at`; a visitor goes once
  no visit remains. Mechanism: E21.3E (**implemented**).
- **O4.** **Automation executions, attempts and review items** go 1 y
  after `completed_at`. Mechanism: E21.3E (**implemented**).
- **O5.** **Inventory balances and stock movements** carry no personal
  data: tenant lifetime. **Canteen stock consumptions** follow their
  orders (D8).
  **E21.3E:** pinned: no age-based Inventory expiry (guard).

**Identity**
- **I1.** **Memberships** are authority provenance, suspended and never
  deleted (rule 92): tenant lifetime. **Ended staff invitations** keep
  their implemented 7-day TTL.
  **E21.3E:** pinned: no membership is ever deleted (guard).
- **I2.** **Ended portal invitations** (`identity_account_invitations`)
  go 7 days after they ended. Mechanism: E21.3B (**implemented**: the end
  is `accepted_at`, `revoked_at`, or `expires_at` while still pending;
  never `updated_at`; held Schools are kept).
- **I3.** **Ended API credentials** (revoked, superseded or expired) are
  D6: 7 y. Mechanism: E21.3E (**implemented**: authority ends at
  LEAST(revoked_at, expires_at); revocation is final).
- **I4.** **Student account links** go with the Student core record
  (implemented). **Guardian links** go with G1.
- **I5. User-identity erasure: POLICY UNRESOLVED.** It means anonymising
  audit actors and authority history, a qualified legal decision. This
  is a final-ratification item; it adds no finite period. **E21-L1
  (2026-10-03):** the decision package is
  `E21-L1-USER-IDENTITY-DECISION-REQUEST.md` (inventory, fail-closed
  behaviour, finding F1, options A–D, blank decision record). **Still
  awaiting the qualified decision.**

**Employees**
- **E1.** **Payroll results** were the D8 blocker. Since E21.3A2 they are the recorded D8 × D9 intersection: D8 exists, but no Payroll D9 mechanism expires payroll results and postings, so they and their journal entries stay (ADR 0064 §21). **RESOLVED by E21.3F:** Payroll's own D9 expiry removes posted evidence and releases emptied runs' journal entries to D8 (`E21-RETENTION-DETERMINATION.md` §5.10).
- **E2.** **Teaching references** (attendance sessions, timetable, LMS) are
  released by A1 (**implemented**, E21.3D: by the rows' own expiry; the
  Employee then follows its own D9 clock). **Transport** by O2, **Visitor hosts** by O3.
- **E3.** A **manager reference** from another Employee's assignment is
  that Employee's employment history. It is never nulled for retention,
  and it is released when the referencing Employee's evidence expires.

## 9. Production configuration required before O1 (external)

**Repository implementation is complete for these; the values are
environment-specific.**

1. **Retention settings:** every value in §4, plus `FINANCE_RETENTION_ENABLED`
   (D8). Code complete is not production configured: setting
   `EMPLOYEE_EVIDENCE_RETENTION_YEARS` now also enables posted payroll
   evidence expiry (E21.3F).
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

**O1 cannot clear today.** Besides E21 (the User-identity legal decision,
ratification and §9; engineering is complete), ADR 0058's register keeps separate mandatory gates open. Among them:
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
