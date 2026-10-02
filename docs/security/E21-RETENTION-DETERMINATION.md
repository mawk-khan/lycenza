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

> **E21.2G closure audit (2026-10-01):** E21 — **OPEN / TECHNICAL BLOCKERS
> REMAIN**.
> - D8 Finance is **blocked by architecture**: a financial-year close is
>   required (E21.3A).
>
> **E21.3A (2026-10-02, ADR 0064):** the close foundation exists.
>
> **E21.3A2 (2026-10-02, ADR 0064 §14–§24):** **D8 is IMPLEMENTED.**
> - The production reads use carry-forward + later detail.
> - Settled, dependency-safe Finance units expire 8 calendar years after
>   their period's CLOSE, through `platform:finance-retention-prune`. It is
>   off unless `FINANCE_RETENTION_ENABLED` and `FINANCE_RETENTION_YEARS`
>   (>= 8) are set, and it runs one database-floored function.
> - Payroll-linked detail stays while D9 payroll evidence references it
>   (the recorded D8 × D9 intersection, ADR 0064 §21).
> - The period stays project-adopted, pending ratification.
> - The periods adopted at the audit for every remaining category ship in
>   E21.3B–E21.3E.
> - Final ratification is deferred to the pre-production project closeout.
> - The consolidated reference, with matrices, the D8 brief and the
>   ratification package, is `docs/security/E21-CLOSURE-AUDIT.md`. Its §8
>   records the E21.2G project decisions, which supersede the "recorded
>   gaps" notes below.

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
- Platform-level rows (no School) are not School-holdable. Since E21.2B,
  `RETENTION_HOLD_PLATFORM=true` holds every School-less record as one
  group: the platform audit, email suppressions, Group and platform grants,
  identity-level email and events, School-less outbox rows and failed jobs.
  Nothing School-less is ever purged without a hold key.
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
- **Class B → E21.2B: IMPLEMENTED.**
  - **Command:** `platform:audit-prune`, with `AUDIT_RETENTION_YEARS=7`
    calendar years from `occurred_at`. The boundary is strict (a row
    exactly at the cutoff is kept), and 29 February never overflows.
  - **Privileged path:** the narrow retention functions in §5.1, with a
    database age floor of 7 years. The runtime role still has no DELETE on
    either ledger.
  - **Integrity:** the ledgers have no hash chain, sequence or parent link,
    so expiry is a plain deletion with no tombstone.
  - **Actor ids:** no separate proactive actor anonymization. An actor id
    leaves with its event at expiry, and erasure before expiry stays D10
    (E21.2F).

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
- **Released-suppression expiry: class B → E21.2B, IMPLEMENTED.**
  - **Command:** `platform:email-suppressions-prune`, with
    `MAIL_RELEASED_SUPPRESSION_RETENTION_YEARS=1` calendar year after
    `released_at` (never `created_at`).
  - **Privileged path:** a narrow retention function with a 1-year floor.
    The runtime role still has no DELETE on `email_suppressions`.
  - **Active suppressions** are never eligible.
  - **Provider events:** the event a deleted suppression referenced is
    freed, and `platform:email-prune` deletes it on its next run once it is
    past 180 days.
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
- **Class B → E21.2C, IMPLEMENTED.** `platform:communications-prune`
  (`--only=content|deliveries`, `--dry-run`), with
  `COMMUNICATIONS_CONTENT_RETENTION_YEARS=3` and
  `COMMUNICATIONS_DELIVERY_RETENTION_YEARS=1`:

  | Unit | Trigger | Purged with it | Fails closed when |
  |---|---|---|---|
  | Published announcement | the School-local date of `published_at` → the end of the one Academic Year containing it | its message, recipients, deliveries, attempts, policy decisions, audience, channels, cohorts, approval requests, attachments (metadata, then bytes) | the date is in no year or in two (`unresolved`, kept) |
  | Thread | every message's School-local `created_at`; the LATEST year end wins | participants, messages and everything under them, attachments | any message date is in no year or in two (`unresolved`, kept) |
  | Delivery (telemetry) | `greatest(delivered_at, read_at, failed_at)` for `delivered`/`read`/`failed`/`bounced`/`rejected`/`expired` | its attempts | never pending/queued/sending/accepted/sent |
  | Policy decision | `created_at` (final when written) | — | through the narrow retention function (§5.1), 1-year database floor |

  - **The Academic Year is the one that contains the sent date**, never
    today's active year. Both ends are inclusive. A gap or an overlap
    (non-overlap is an application rule only) keeps the unit.
  - **Boundary:** strict. A unit whose year ended exactly on the cutoff
    date is kept. Calendar years, with no 29 February overflow.
  - **Concurrency:** each purge locks its unit row and recomputes
    eligibility after the lock. A message committed into a thread during
    the purge keeps the thread, and a message arriving after the purge
    is refused by its FK. Both orders are proven with two real processes.
  - **Bytes after metadata:** attachment bytes are deleted only after the
    database commit. A failed byte delete leaves an unreferenced object
    that the orphan run (D5) removes (`byte delete errors` count).
  - **Recorded gaps (kept, never guessed):**
    - a never-sent announcement (no `published_at`/message) and a thread
      with no message have no sent anchor (`never sent`, kept);
    - a `cancelled` delivery records no terminal time (`no terminal time`,
      kept);
    - *(E21.2G decided: closure audit §8 C1–C7.)* Consent events and
      domain preferences are consent evidence, not content. No content purge reaches them (no FK), and they are kept.
      No adopted decision names their period, so it is recorded for the
      E21.2G closure audit, never guessed here;
    - the Phase 0 `notifications` table is outside D3. It is noted for
      the E21.2G closure audit.

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
- **E21.2E:** Employee-owned Documents and HR `employee_documents` go with
  their Employee's D9 evidence purge (bytes after commit). No Finance
  Documents exist.
- **E21.2D:** Student-owned Documents now go with their Student's D7 core
  purge (`DocumentParentRetention`), metadata in the purge transaction and
  bytes after commit; a failed byte delete is left to the orphan run.
  Guardian and LMS Documents stay kept (E21.2G adopted their parents'
  periods, G1 and A1; the mechanisms are E21.3C and E21.3D).
- **Class C (inherited) → E21.2C, IMPLEMENTED as a closed deferral.**
  `App\Domain\Documents\Application\Retention\DocumentRetentionEligibility`
  maps every arm of `documents_exactly_one_owner_check` to the checkpoint
  that decides it: Employee → E21.2E (D9); Student and Guardian → E21.2D
  (D7); Learning Content and Assignment → E21.2D (D7) with the D6
  owner/audience minimum. Every owner is deferred today, so `mayPurge()` is
  false for every Document. No Documents code encodes a duration, and no
  retention command deletes or updates a `documents` row (architecture
  test). An archived Document is retained exactly like an active one.
  Communications attachments are not Documents (they go with their
  communication, D3).
- **Orphans: class B → E21.2C, IMPLEMENTED.** `platform:storage-orphans-prune`
  (`--dry-run`), with `STORAGE_ORPHAN_RETENTION_DAYS=30` and a per-School
  scan cap `RETENTION_ORPHAN_SCAN_LIMIT` (default 10000). An object is
  deleted only when ALL hold:
  - it is in a managed keyspace of an existing School
    (`schools/{id}/documents/…` on the Documents disk,
    `schools/{id}/communications/…` on the attachments disk). Nothing
    outside these prefixes, and no unknown School's prefix, is listed;
  - its object-store last-modified time is older than 30 days (strict);
  - no committed `documents`, `communication_attachments` or
    `employee_documents` row names it (same disk and path), re-checked
    immediately before the delete;
  - its School is not held (a held School only counts).
  - An upload whose metadata has not committed yet is protected by the
    30-day floor and its never-reused UUIDv7 key (proven with a real
    second process holding the insert open).

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
- **Class B → E21.2B, IMPLEMENTED.** `platform:authority-history-prune`,
  with `AUTHORITY_HISTORY_RETENTION_YEARS=7` calendar years, deletes through
  the narrow retention functions:

  | Record | Authority-end trigger | Mechanism | Pruned now? |
  |---|---|---|---|
  | School role grants (`membership_role_assignments`) | `revoked_at` | `retention_expire_membership_role_assignments` | **Yes**. Active grants never |
  | TeachingAssignments | `ends_on`, the last effective day, on the School-local date | `retention_expire_teaching_assignments` | **Yes**. Open and future rows never |
  | Elevations (`school_elevations`) | `ended_at`, else `expires_at`; finished only | `retention_expire_school_elevations` | **Yes**, once no School audit event references them |
  | Group grants (`group_role_assignments`) | `revoked_at` | `retention_expire_group_role_assignments` | **Yes**, once no elevation references them |
  | Platform role grants | `revoked_at` | `retention_expire_platform_role_assignments` | **Yes**. Active grants never |
  | Employee↔User link | the unlink/link audit time | the audit ledger (D1) | **Through D1**. No separate history table |
  | Group membership (`school_group_members`) | removal | already hard-deleted on removal; history is platform audit | **Through D1** |
  | LMS owner and Section audience | — | kept with the parent resource | **No, deferred.** Never removed while the parent survives |
  | Revoked API client credentials | — | — | **Deferred to E21.2G**: integration lifecycle, and no production partner scopes exist |

- **Longest period wins, enforced by the database.** An elevation is
  skipped while an audit event references it, and a Group grant while an
  elevation does (RESTRICT FKs).
- **LMS:** a future parent purge must also satisfy
  `EmbeddedAuthorityRetention::mayRemoveWithParent()`.

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
- **Class B/C → E21.2D, IMPLEMENTED.** `platform:student-retention-prune`
  (`--only=operational|core`, `--dry-run`), with
  `STUDENT_OPERATIONAL_RETENTION_YEARS=7` and
  `STUDENT_CORE_RETENTION_YEARS=25`:
  - **Final exit (the trigger).** The repository has no dated "left the
    School" record: `students.status` is only `active`/`inactive`, has no
    date and can be reactivated, and re-admission is deferred
    (`docs/students/PHASE-1E-0-STUDENT-LIFECYCLE-ARCHITECTURE.md`). The
    dated facts are Enrollment terminal transitions (`ends_on`, inclusive).
    `StudentRetentionEligibility` (Students) says a Student has EXITED only
    when all of these hold:
    - the Student is `inactive`;
    - no non-cancelled placement is `active` or open, and no Subject
      Enrollment is `active`;
    - the latest non-cancelled placement ended `completed` or `withdrawn`.

    The exit date is that `ends_on`. A `cancelled` placement never ran, so
    it never dates an exit. Every other inactive state is **unresolved and
    kept**: never placed, only cancelled, still placed, last placement
    `transferred`, or an open end. Nothing is inferred from `updated_at`,
    attendance or inactivity.
  - **Re-entry.** A new Enrollment or a reactivation makes the Student
    current again. The exit is recomputed every run and again inside each
    purge after the Student row is locked. Across years, campuses and
    re-entries, the clock runs from the LAST departure.
  - **Core academic record (25 y).** These records are needed to
    reproduce the formal academic history:
    - the Student identity (number, names, date of birth);
    - its placements (`student_enrollments`, cancelled ones included as
      the administrative record);
    - its Subject Enrollments, the only academic outcome implemented.
      There are no marks, results, report cards or transcripts
      (Examinations holds windows, papers and grade scales only).

    The Student's Documents go with it (D5). Everything is deleted as one
    unit per Student, with the root LAST.
  - **Operational Student history (7 y).**
    - attendance records (Attendance);
    - enrollment rollover items, the per-Student workflow lines
      (Students);
    - Guardian relationships, ordinary SIS history (Guardians). The
      formal record reads without them.
  - **Longest period wins, by construction.** Any row in any other table
    that still references the Student, a placement, a Subject Enrollment
    or a relationship blocks the purge (`dependency_blocked`, kept). The
    check reads the live FK catalog (`ReferencingRows`), so a future table
    blocks until classified. Nothing is cascaded. This keeps the Student
    for, among others:
    - Finance (E21.2E);
    - retained Communications rows;
    - Admissions conversions;
    - processing authorizations;
    - Library, Transport, Hostel and Canteen rows;
    - identity invitations.

    A Student account link must be revoked and past the D6 authority
    period; otherwise it blocks. Unexpired operational rows block the core
    record, and one run clears them first.
  - **Per-table classification:** pinned against the catalog by
    `StudentRetentionClassificationTest`.
- **D7 scope findings (recorded, not guessed). E21.2G decided each of them:
  closure audit §8, A1–A2, G1, P1, AD1–AD2, O1.**
  - **Curriculum Delivery, Syllabus, Examinations and LMS Learning
    Content/Assignments are not Student-rooted.** They reference Sections
    and Offerings only and are School academic content, so D7 does not
    govern them. They are kept, with no adopted period (E21.2G). LMS
    owner/audience stays with its parent (D6 minimum,
    `EmbeddedAuthorityRetention`). Their Documents stay with them. LMS
    Submission stays cancelled.
  - **Attendance sessions** are the Section's register headers (Section,
    offering, teacher, period), not Student records. Only the per-Student
    records expire. Sessions are kept (E21.2G).
  - **Guardians.** The Guardian, its contacts and its Documents are
    Guardian personal data, not D7. They are kept: no adopted period
    exists (D10, E21.2F/G). Unlink stays a hard delete whose history is
    audit (D1). The formal record does not need relationship history, so
    there is no contradiction.
  - **Processing authorizations** are undeletable legal-basis evidence
    (`reject_direct_delete`) with no adopted period. A Student or
    relationship that holds them is kept indefinitely (E21.2G).
  - **Admissions:** applicants and non-converted applications have no
    Student exit trigger, and converted applications have no decided
    category. They are kept and block their Student (E21.2G).
  - **Draft rollover mappings** are School configuration, not Student
    data, and are deleted while draft as before (history in D1).

### E21-D8 — Finance

- **Policy:** **8 years** after the relevant financial year closes.
- **Scope:** the authoritative records: fees, charges, payments,
  allocations, reversals and cancellations, late-fee entries, receipts,
  journals and financial audit evidence.
- **Excluded:** draft and non-authoritative working rows keep their
  existing lifecycle.
- **Unchanged:** immutability and reversal semantics.
- **Legal hold:** overrides.
- **Class B → E21.2E. AUDITED; EXPIRY BLOCKED (fail-closed), recorded for
  E21.2G.** E21.2E found that no posted financial evidence can expire
  safely yet. Nothing in Finance, Fees, Payments or the payroll ledger is
  deleted, and `FinanceRetentionGuardTest` keeps every retention path off
  those tables.
  - **Why: balances are derived from all history.** Every balance is the
    sum of all postings and allocations (`FINANCE.md`, "Balance
    derivation"): an account balance, a charge's outstanding amount, a
    Student's dues. There is no accounting-period close and no carried-
    forward opening balance (`FINANCE.md`: "Accounting-period close/lock …
    deferred"). Deleting a financial year's evidence would silently change
    today's balances. So expiry needs a financial-year close design first:
    closing entries, carried-forward balances and a locked period. That is
    a Finance correction contract (ADR) to design, not to guess here.
  - **Financial-year trigger (for that future design):**
    - The source is `fee_settings.financial_year_start_month` (default
      April).
    - The database refuses a change once any receipt exists
      (`payments_guard_receipt_numbering_settings`). Before the first
      receipt it can change, so rows created before then have no fixed
      financial year: **ambiguous, fail closed**.
    - Receipts carry their series key (`payments_fy_series_key`).
    - Journal entries carry only `posted_at`; there is no `effective_date`.
  - **Classification:**
    - **A, authoritative (journal-bound):**
      - journal entries and lines;
      - charges;
      - payments with their allocations, provider events and receipts;
      - fee assessments, adjustments and concessions;
      - late-fee assessments;
      - payroll run postings and statutory run postings.
    - **B, supporting:**
      - fee and late-fee assessment runs with their committed items;
      - optional selections;
      - receipt counters;
      - frozen payroll results and result lines.
    - **C, draft/working:**
      - draft run items;
      - draft fee structure lines and installments;
      - draft payroll results.

      These keep their existing draft-only delete lifecycle, unchanged.
      Their triggers already refuse deletes once posted.
    - **D, configuration (kept):**
      - ledger accounts;
      - fee heads, structures, late-fee rules and settings;
      - payroll accounting and statutory rule versions.
    - **E, telemetry:** none separate (provider events are authoritative
      evidence).
  - **No Finance Documents** exist (no Finance owner on `documents`).
  - **Dependencies:**
    - retained Finance keeps its Student (E21.2D `dependency_blocked`);
    - retained Finance keeps its Employee through payroll results
      (E21.2E);
    - Guardians are not referenced by Finance.

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
- **Class B → E21.2E, IMPLEMENTED.** `platform:employee-retention-prune`
  (`--only=ancillary|evidence`, `--dry-run`), with
  `EMPLOYEE_ANCILLARY_RETENTION_YEARS=2` and
  `EMPLOYEE_EVIDENCE_RETENTION_YEARS=8`:
  - **Final separation (the trigger).** `EmployeeRetentionEligibility`
    (HR) reads EmploymentRecords. `EmploymentService::end()` is the only
    path to a terminal status from the closed set (`separated`,
    `terminated`, `retired`, `deceased`), with an inclusive `ends_on`.
    - **Separated:** at least one record, ALL terminal, all dated. The
      date is the latest `ends_on`.
    - **Current:** any draft, pre-joining, active or notice-period record,
      i.e. a current or future employment.
    - **Unresolved and kept:** no record, or a terminal record without
      `ends_on`.
    - **Rehire** is a new record, so the clock runs from the LAST
      separation.
    - Archive (`record_status`), the User link and membership are never
      used.
  - **Ancillary (2 y),** the D9-named sub-records, each confirmed
    unreferenced and already hard-deletable by HR during employment:
    - addresses, emergency contacts and notes;
    - qualifications, experience and certifications.
  - **Evidence (8 y),** in two module purges:
    - **Payroll:** compensation assignments (their append-only values
      follow by FK cascade) and the statutory identifiers, tax, PF and ESI
      profiles. Only when no payroll result, adjustment or LWF charge
      references the employment.
    - **HR, then:** the Employee root with its employment records,
      assignments, personal details (date of birth and nationality are not
      in the ancillary list), HR documents (`employee_documents`) and
      Employee-owned Documents. Bytes are deleted after commit; a failure
      is left to the orphan run.
  - **Longest period wins, by construction.** Any other row referencing the
    Employee, an employment, an assignment or a compensation assignment
    blocks it (`dependency_blocked`, kept). The check reads the FK catalog
    (`ReferencingRows`); nothing is cascaded. This keeps the Employee for:
    - payroll results (ledger, D8);
    - TeachingAssignments (D6, 7 y after they end);
    - Attendance sessions, timetable entries and LMS ownership;
    - Transport and Visitor rows;
    - another Employee's assignment naming one of its assignments as
      manager.
  - **A linked User blocks it.** Retention never unlinks a User (D10,
    E21.2F).
  - **Pinned:** the per-table classification is checked against the
    catalog by `EmployeeRetentionClassificationTest`.
- **D9 findings (recorded, not guessed). E21.2G: closure audit §8 E1–E3;
  payroll results are the D8 blocker.**
  - **Paid staff are kept.** An Employee with payroll results stays until
    D8 can expire ledger evidence.
  - **Staff who taught are kept** while the Section-level teaching records
    that reference them have no adopted period (E21.2G).
  - **Personal details** mix identity (date of birth, nationality) with
    personal contact (email, phone). They stay with the 8-year evidence.
    Minimising the contact columns earlier would be column-level erasure
    (D10, E21.2F).
  - **`employees.work_email`/`work_phone`** stay with the root.

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
- **Class C → E21.2F, IMPLEMENTED as reviewed, retention-aware cases.**
  - **The case** (`erasure_cases`, a platform compliance record) holds
    scope, subject type and reference, request channel, review decision
    with a closed reason, 30-day target, execution state and a
    per-category outcome summary. It holds **no** copy of the subject's
    data.
    - Lifecycle: `requested` → `approved` | `partially_approved` |
      `denied` → `executing` → `completed`.
    - Operator console only: `platform:erasure-case-open`, `-decide`,
      `-execute [--dry-run]`, `-status`. Never a request path, never a
      schedule.
  - **Planner, no rule of its own.** `DataSubjectErasurePlanner` picks the
    subject type's closed-list adapter. Each adapter reuses its domain's
    canonical eligibility and locked purges:
    - **Student:** D7, 7 y operational and 25 y core after final exit;
    - **Employee:** D9 and Payroll, 2 y ancillary and 8 y evidence after
      final separation;
    - **Guardian:** nothing executable. E21.2G adopted G1, so the data is
      reported `retained_until` / `mechanism_pending` until E21.3C;
    - **User:** platform scope; never hard-deleted. `dependency_blocked`
      while any membership is active, otherwise policy unresolved.
  - **Outcomes** come from a closed set: `eligible`, `retained_until`
    (with the first eligible day), `legal_hold`, `dependency_blocked`
    (with the blocking table), `policy_unresolved`, `outside_scope`,
    `completed`, `error`.
  - **Execution removes only `eligible` categories.** Each is rechecked
    under the domain lock. An erasure request therefore never shortens an
    adopted period, never bypasses a hold, never touches Finance (D8 still
    blocks) and never unlinks a User, Employee or membership.
  - **Cross-School:** a School-scope case sees only its School; a User case
    reads no School's records.
  - **Target:** 30 calendar days after the decision, in the School's local
    date. Overdue is visibility only (`platform:erasure-case-status`).
  - **Case evidence:** a closed case expires **7 calendar years** after it
    closed (`ERASURE_CASE_RETENTION_YEARS`, project-adopted). It runs from
    `platform:audit-prune` through one narrow retention function. The
    runtime role has no DELETE.
  - **Recorded, not guessed. E21.2G decided S1, G1 and I5** (closure
    audit §8): no earlier minimisation; Guardian data per G1; User identity
    stays a legal decision.
    - minimising the identity of a retained Student or Employee, and a
      Guardian's personal data, have no adopted basis;
    - erasing or minimising a User identity (audit actors, authority
      history, links) has none either;
    - Admissions applicants are not a subject type (no adopted trigger).

### E21-D11 — School / tenant closure

- **Policy:** production School hard-delete stays prohibited.
- **Closure:** disable/freeze → archive → retain per category → eventual
  controlled purge when every period and hold has expired.
- **Interactions:** where tenant closure meets another trigger, the later
  expiry wins. Core academic Student records stay under D7.
- **Constraint:** raw School deletion is never the closure workflow. A
  future tenant close is explicit and audited.
- **Class C → E21.2F: freeze and readiness IMPLEMENTED; tenant destruction
  NOT AUTHORIZED.**
  - **Freeze.** `SchoolLifecycleService::close()` (Close, ADR 0047
    amendment: `platform.schools.manage`, confirmation, fresh MFA) puts an
    active or suspended School into the existing `suspended` status, so
    every School business effect is refused by `SchoolOperationalGuard`. It
    records the closure durably on the School (`closed_at`, a closed
    `closure_reason`, `closed_by_user_id`), and the database requires a
    closed School to stay suspended.
    - It deletes nothing: memberships, Students, Employees, Finance,
      Documents and audit all stay.
    - It is idempotent.
    - **Reopen** withdraws a mistaken closure (back to active, nothing
      replayed); a closed School cannot simply be resumed.
  - **Retention continues.** Every E21 command still walks closed Schools
    (the School-walker allowlist).
  - **Readiness.** `platform:school-closure-status` (read-only,
    `TenantClosureReadiness`) checks every tenant table against the closed
    `TenantRetentionCatalog` (an unknown table fails closed). Per category
    it reports one of:
    - `retained`, with the first possible day for audit, the Student core
      record and HR evidence;
    - `technical_blocker` (D8);
    - `policy_unresolved`;
    - `tenant_lifetime`;
    - `empty`.

    Its gates: not closed, legal hold, D8, unresolved policy, unclassified
    tables, running periods, plus two that always stand: final
    ratification and no authorized tenant purge. **A School is never
    reported purge-ready.**
  - **No School hard-delete exists.** The runtime role has no DELETE on
    `schools`. RESTRICT relationships are unchanged. No command deletes a
    School (architecture guard).
  - **Future destructive tenant purge needs ALL of:**
    - final E21 category coverage;
    - D8 Finance resolved (financial-year close);
    - no legal hold;
    - no unresolved dependency or policy;
    - every category period satisfied;
    - final legal/compliance ratification;
    - an explicit, audited purge authorization.

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
| **E21.2B** | Audit (D1), authority history (D6), released-suppression expiry (D2): a narrowly privileged expiry path for protected ledgers | **Implemented** (E21.2B commit; full isolated regression) |
| **E21.2C** | Communications (D3), Documents and orphans (D5) | **Implemented** (E21.2C commit; full isolated regression) |
| **E21.2D** | Student / academic (D7) | **Implemented** (E21.2D commit; full isolated regression) |
| **E21.2E** | Finance (D8), HR and payroll (D9) | **Implemented** for D9 (E21.2E commit; full isolated regression). D8 **audited, expiry blocked**: no financial-year close or carried-forward balances (recorded for E21.2G) |
| **E21.2F** | Erasure (D10) and tenant-closure orchestration (D11) | **Implemented** (E21.2F commit; full isolated regression). Reviewed retention-aware erasure cases; closure freeze and readiness. Tenant destruction **not authorized** |
| **E21.2G** | Final retention closure audit and blocker consolidation | **Implemented** (closure audit, decisions for every remaining category, technical-TTL dry runs, readiness `mechanism_pending`; `E21-CLOSURE-AUDIT.md`) |
| E21.3A | Financial Year Close & Retention Foundation (D8) | **Implemented** (ADR 0064; full isolated regression). Period entity, posting identity, close, baselines, dual-read verifier, backfill. No deletion |
| E21.3A2 | Finance Retention Cutover & Historical Expiry (D8) | **Implemented** (ADR 0064 §14–§24; full isolated regression). Carry-forward reads, D8 eligibility from `closed_at`, settled-unit expiry, holds, dry run, per-unit accounting proof. Residual: payroll D8 × D9 intersection |
| E21.3B | Student-linked evidence and modules (consent, preferences, processing authorizations, converted admissions, Library/Transport/Hostel, portal invitations) | Not started |
| E21.3C | Admissions decision timestamp; Guardian no-relationship marker and personal-data expiry | Not started |
| E21.3D | Year-bound academic operations (curriculum, timetable, LMS, attendance headers) | Not started |
| E21.3E | Communications and platform residuals (never-sent and empty threads, visitors, automation, driver assignments, API credentials) | Not started |

### 5.1 The privileged retention path (E21.2B)

- **Database functions.** Migration `2026_11_06_090000` adds one
  `SECURITY DEFINER` function per protected table: School audit, platform
  audit, released suppressions, School/Group/platform grants, elevations
  and TeachingAssignments. Each has:
  - a pinned `search_path` and schema-qualified tables;
  - a fixed table and eligibility predicate, with no caller-supplied SQL;
  - a database **age floor** (7 years, or 1 year for suppressions), so no
    caller can expire a row early;
  - for School tables, a **tenant tie**: the School must be the caller's
    own `app.current_school_id`;
  - a batch capped at 5000, `ORDER BY id`, `SKIP LOCKED`, and the predicate
    re-applied in the DELETE;
  - a `p_dry_run` count.
- **Grants.** EXECUTE is revoked from PUBLIC and granted to the runtime
  role only. The runtime role gains **no** DELETE: `DatabaseRoleVerifier`
  still refuses one, and its new `retention_functions_narrow` check proves
  the functions are the only executable `retention_*` ones and stay narrow.
- **Callers.** `App\Support\Retention\RetentionExpiry` is the only caller
  (architecture test). It uses a closed category map, the hold seam and the
  `lycenza_retention_rows_total{operation,outcome}` metric.
- **Execution.** The ordinary scheduler runs these as daily maintenance
  (`audit-prune` 03:00, `email-suppressions-prune` 03:10,
  `authority-history-prune` 03:20) with the runtime credentials. It needs
  no elevated database credential. No request path calls them.
- **Rollback.** `down()` drops only the functions, never history.
  Migrate/rollback/re-apply was verified on the DDEV database
  (`pg_dump --schema-only`: migrated = re-applied; rolled back = before).

### 5.2 Communications and orphan expiry (E21.2C)

- **Communications content and deliveries** are ordinary School rows the
  runtime role may delete under RLS. `CommunicationRetentionService` does
  it inside the School's own tenant context, in bounded batches
  (`FOR UPDATE SKIP LOCKED` for deliveries; one locked unit per
  transaction for content).
- **Policy decisions are append-only.** Migration `2026_11_07_090000` adds
  one more narrow function,
  `retention_expire_communication_delivery_policy_decisions`, with the
  same shape as §5.1 (tenant tie, 1-year floor, cap 5000, dry-run). It is
  called only through `RetentionExpiry`. `DatabaseRoleVerifier` lists it in
  `retention_functions_narrow`. Its `down()` drops only the function
  (migrate/rollback/re-apply verified on DDEV, as in §5.1).
- **Orphans:** `App\Support\Retention\StorageOrphanReaper`, see D5.
- **Execution:** `communications-prune` 03:40 and `storage-orphans-prune`
  04:10, daily, without overlap. The orphan run follows the content purge,
  so a failed byte delete is retried the same night once it is 30 days old.
- **Logs and metrics are counts only:** `lycenza_retention_rows_total`
  gains `communication_content`, `communication_delivery`,
  `communication_policy_decision` and `storage_orphan`, with the outcomes
  `eligible`/`deleted`/`held`/`skipped`/`unresolved`/`error`. No path,
  name, subject or body is logged.

### 5.3 Student and academic expiry (E21.2D)

- **No new database privilege.** Every D7 table is an ordinary School
  table the runtime role may delete under RLS. No migration, function or
  index was added: the existing `(school_id, student_id)` and FK indexes
  serve the scans. Processing authorizations stay undeletable.
- **One canonical loop.** `StudentRetentionEligibility::purgeExitedBefore()`
  walks one School's inactive Students in id order, in bounded chunks
  (`RETENTION_PRUNE_BATCH_SIZE`). It runs ONE transaction per Student:
  1. lock the Student row FOR UPDATE;
  2. recheck the exit;
  3. recheck the dependencies;
  4. delete the module's own rows;
  5. after commit, delete any bytes.

  A database failure on one Student rolls back only that Student, counts
  as `error` and is retried next run. Each module deletes only its own
  rows: Attendance, Guardians, Students and Documents (through the
  Student). Attendance and Guardians already depend on Students, so no
  dependency direction changes.
- **Dry run** uses the same rule and dependency check. When both phases
  run, it treats the operational rows as cleared when counting the core
  record, as the destructive run clears them first.
- **Concurrency:**
  - the Student-row lock conflicts with an Enrollment insert (FOR KEY
    SHARE) and with any Student update;
  - a re-entry or reactivation committed first keeps the Student;
  - a purge committed first makes a late re-enrollment fail on its FK;
  - all three are proven with two real processes.
  Section and Enrollment lock orders are untouched.
- **Execution:** `student-retention-prune`, daily 04:30, without overlap.
- **Metrics:** `lycenza_retention_rows_total` gains the operations
  `student_attendance`, `student_rollover_item`,
  `student_guardian_relationship` and `student_core`, and the outcome
  `dependency_blocked`. The units are Students. Logs and metrics carry
  counts only.

### 5.4 HR and payroll expiry (E21.2E)

- **No new database privilege.** Every D9 table is an ordinary School
  table under RLS. The append-only compensation values go only by their
  FK cascade from a compensation assignment. No migration, function or
  index was added. The existing indexes serve the scans: `employee_id`,
  `(employee_id, ends_on)`, and the Payroll profiles'
  `(school_id, employment_record_id, …)` composites (the RLS School
  predicate supplies `school_id`).
- **Shared discipline.** `App\Support\Retention\RetentionUnit` handles
  one unit per transaction for both D7 and D9:
  1. lock the root row (FOR UPDATE);
  2. recheck the trigger;
  3. recheck the dependencies;
  4. delete only the module's own rows;
  5. delete any bytes after commit.

  A per-unit failure counts as `error` and is retried next run.
- **Locking.** The Employee-row lock is the one
  `EmploymentService::create()` takes for every hire:
  - a rehire committed first keeps everything;
  - an evidence purge committed first makes a late rehire fail on its FK;
  - all of this is proven with two real processes.
- **Dry run.** It counts the HR root as if Payroll's purge of the same run
  had already cleared Payroll's rows.
- **Execution:** `employee-retention-prune`, daily 04:50, without overlap.
- **Metrics:** `lycenza_retention_rows_total` gains `employee_ancillary`,
  `payroll_employee_record` and `employee_evidence`. The units are
  Employees, and only counts are recorded.

### 5.5 Erasure cases and School closure (E21.2F)

- **Privilege.**
  - `erasure_cases` is a platform table: no tenant RLS
    (`NON_RLS_SCHOOL_TABLES`), no runtime DELETE (`NO_RUNTIME_DELETE`),
    expired only by `retention_expire_erasure_cases` (7-year floor,
    `retention_functions_narrow`).
  - `schools` gains only closure columns and two CHECKs; its DELETE stays
    revoked.
  - No generic erasure or tenant-delete function exists.
- **Migrations** `2026_11_08_090000` (closure columns) and `_090100`
  (cases): migrate, rollback and re-apply verified on DDEV (rolled back =
  before; re-applied = migrated).
- **Concurrency, with two real processes:**
  - **Closure:** it waits for in-flight operational work, and a claim
    arriving after it commits is refused.
  - **Erasure vs re-entry:** a re-enrollment or rehire committed first
    keeps the subject. An erasure committed first makes a late
    re-enrollment fail on its FK.
- **Metric:** `lycenza_erasure_case_transitions_total{state}`. Closure is
  in platform audit (`platform.school.closed`/`reopened`).

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
| `MAIL_RELEASED_SUPPRESSION_RETENTION_YEARS` | 1 (calendar year) | E21.2B |
| `AUDIT_RETENTION_YEARS` | 7 (calendar years) | E21.2B |
| `AUTHORITY_HISTORY_RETENTION_YEARS` | 7 (calendar years) | E21.2B |
| `RETENTION_HOLD_PLATFORM` | false unless a hold is ordered | E21.2B |
| `WEBHOOKS_DELIVERY_RETENTION_DAYS` | 30 (delivered) | E21.2A |
| `WEBHOOKS_FAILED_DELIVERY_RETENTION_DAYS` | 90 (failed/abandoned) | E21.2A |
| `OUTBOX_RETENTION_DAYS` | 30 | E21.2A |
| `FAILED_JOBS_RETENTION_DAYS` | 30 | E21.2A |
| `RETENTION_HOLD_SCHOOL_IDS` | empty unless a hold is ordered | E21.2A |
| `COMMUNICATIONS_CONTENT_RETENTION_YEARS` | 3 (calendar years after the Academic Year end) | E21.2C |
| `COMMUNICATIONS_DELIVERY_RETENTION_YEARS` | 1 (calendar year after the terminal state) | E21.2C |
| `STORAGE_ORPHAN_RETENTION_DAYS` | 30 | E21.2C |
| `RETENTION_ORPHAN_SCAN_LIMIT` | 10000 per School per run (default) | E21.2C |
| `STUDENT_OPERATIONAL_RETENTION_YEARS` | 7 (calendar years after final exit) | E21.2D |
| `STUDENT_CORE_RETENTION_YEARS` | 25 (calendar years after final exit; never shorter than operational) | E21.2D |
| `EMPLOYEE_ANCILLARY_RETENTION_YEARS` | 2 (calendar years after final separation) | E21.2E |
| `EMPLOYEE_EVIDENCE_RETENTION_YEARS` | 8 (calendar years after final separation; never shorter than ancillary) | E21.2E |
| `ERASURE_CASE_RETENTION_YEARS` | 7 (calendar years after a case closed) | E21.2F |
| *(D8 Finance)* | 8 years after the financial year closes: **no setting, no expiry yet** (needs a financial-year close) | E21.2E (blocked) |

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
