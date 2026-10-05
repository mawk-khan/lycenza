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
>
> **E21.3B (2026-10-02, §5.6):** the Student-linked residuals are
> **IMPLEMENTED**: Library loans and Transport/Hostel assignments as D7
> operational history (7 y; an open one keeps the Student); processing
> authorizations, converted admission applications, the Student subject's
> consent events and domain preferences with the Student core record
> (25 y); ended portal invitations 7 days after they ended. Guardian-subject
> rows and rejected/withdrawn applications stay for E21.3C. The payroll
> D8 × D9 residual is the bounded follow-up **E21.3F** (provisional). E21
> stays **OPEN**.
>
> **E21.3C (2026-10-02, §5.7):** rejected/withdrawn applications now carry a
> database-owned, immutable `terminal_at` and go 1 calendar year after it;
> Guardians carry a durable `no_relationship_since` and their personal data
> goes 1 calendar year after it when nothing retained needs them. Undated
> legacy rows stay unresolved and kept. Remaining engineering: E21.3D,
> E21.3E, E21.3F.
>
> **E21.3D (2026-10-02, §5.8):** curriculum deliveries, empty attendance
> register headers, unreferenced timetable entries and LMS Learning
> Content/Assignments go 7 calendar years after the end of their
> authoritative Academic Year (LMS past the D6 owner/audience minimum);
> Academic Year dates are frozen; syllabus and examination configuration
> stay. Remaining engineering: E21.3E, E21.3F.
>
> **E21.3E (2026-10-02, §5.9):** the communications and platform residuals
> are implemented: never-sent cancelled/rejected announcements and empty
> threads (1 y), ended driver assignments (7 y), visits (1 y), completed
> automation executions (1 y) and ended API credentials (D6, 7 y).
> Memberships, membership preferences and Inventory stay tenant lifetime;
> notifications stay not applicable. The only engineering left is E21.3F.
>
> **E21.3F (2026-10-02, §5.10, ADR 0064 §21 amended):** posted payroll
> evidence (results with lines and statutory results, adjustments, LWF
> charges) goes 8 calendar years after the Employee's final separation,
> once every run holding it and every posting is that old too; an emptied
> run then loses its postings and runs, which releases its journal entries
> to D8 (they expire only once their financial period is itself 8 years
> closed). Paid Employees are no longer kept indefinitely. **No
> retention-mechanism checkpoint remains: E21 engineering is complete.** E21
> stays **OPEN**: final ratification and the User-identity erasure legal
> decision (closure audit §8 I5) remain.
>
> **E21.4 (2026-10-03, §5.11):** the project owner adopted an India-aligned
> development position for User identity (E21-L1): category-specific
> minimization into a non-login tombstone, only through an approved
> platform erasure case and only when no current purpose and no hold
> remains anywhere. It is implemented, with the F1 fix (the runtime role
> can no longer delete a User). **This is a project position, not the
> qualified decision:** the E21-L1 decision record stays blank and
> qualified Indian legal/compliance ratification stays pending.
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
- **Database enforcement (HRX.6 hardening, ADR 0065 §27.10).**
  `retention_school_holds` mirrors the configured School holds in the
  database. `RetentionHolds::synchronize()` runs on the migration/owner
  connection before every HRX retention participant. The HRX purge
  functions refuse a held School themselves (`retention_hold`) and are not
  executable by the runtime role. The configuration stays the source; the
  hold policy and its semantics are unchanged.
  - The other E21 functions keep the original model (runtime EXECUTE, hold
    checked by the command), pending a separate E21 decision.
    *(E21-RH.4/RH.5: fifteen of them no longer do; see below.)*
- **E21-RH.3 (2026-10-04, ADR 0066 §11): PostgreSQL hold state is
  authoritative.**
  - Holds live in `retention_holds`, with indefinite history.
  - They are placed and released only through the audited operator
    commands (`docs/operations/RETENTION-HOLDS.md`).
  - Configuration may add holds during the transition
    (`platform:retention-holds-reconcile`). Configuration removal never
    releases one, and release requires an explicit operator action.
  - The database platform hold is global (School and School-less).
  - HRX enforces both in the database. The legacy commands read the same
    state in PHP, and fail closed when it cannot be read.
- **E21-RH.4 (2026-10-05, ADR 0066 §12): the eleven standalone legacy
  functions are hardened.**
  - They are executable only by the `school_os_retention` login and refuse
    any other session user:
    - School audit, School role grants, teaching assignments, School
      elevations, delivery policy decisions and API credentials (School);
    - platform audit, released suppressions, Group and platform grants and
      erasure cases (School-less).
  - Destructively, they refuse an active platform hold and, if
    School-scoped, an active hold of their School, in the database. A dry
    run still counts.
  - Periods, floors, triggers and dependencies are unchanged.
  - The eight coupled functions (Payroll evidence and run, LMS: RH.5;
    Finance unit, Student and Guardian core evidence: RH.6) keep runtime
    EXECUTE and the PHP-only hold for now.
  - A School hold does not hold `erasure_cases` (a platform record); the
    platform hold does. Runtime-writable eligibility columns of
    `erasure_cases` remain an RH.6 item.
- **E21-RH.5 (2026-10-05, ADR 0066 §13): the Payroll and LMS units are
  hardened.**
  - Payroll evidence and runs, and LMS Learning Content and Assignments, run
    whole as `school_os_retention` on its own connection, Documents
    included (through one database unit function).
  - Their functions refuse any other session user, and any active platform
    hold or hold of their School, dry runs included.
  - Periods, floors, triggers and dependencies are unchanged.
  - Still open (RH.6): the runtime-writable EmploymentRecord separation the
    D9 floor reads, and the runtime DELETE grants on `learning_content` /
    `assignments`.
  - Finance unit, Student and Guardian core evidence keep runtime EXECUTE
    and the PHP-only hold for now.
- **E21-RH.6 (2026-10-05, ADR 0066 §14): the remaining boundaries are
  hardened. E21-RH is NOT closed (E21-RH.7 below).**
  - No destructive retention function is executable by the runtime role:
    the Finance unit, Student processing authorizations, Student and
    Guardian consent functions moved to `school_os_retention` with the
    identity and hold prologue.
  - Every PHP retention unit (Students, Guardians, Employees, the academic
    and operational residuals, Communications, Admissions, portal
    invitations, the Finance unit, erasure execution, and the email, outbox,
    webhook-delivery and failed-job prunes) runs whole as the retention
    identity. PostgreSQL refuses each of its deletes while a platform hold,
    or a hold of an affected School, is active. Runtime DELETE is revoked
    wherever only retention used it.
  - Idempotency keys, account-recovery requests and staff credentials stay
    hold-exempt (owner decision, 2026-10-05).
  - **Recorded-date amendment (owner decision, 2026-10-05).** The D9
    separation and the D7 exit count from the LATER of `ends_on` and the
    database-recorded date of that end (`ended_recorded_at`). An end is
    recorded once and never changes. A separation or completion entered
    after the fact is therefore retained in full from when it was recorded.
    The periods themselves are unchanged; the rule only ever retains longer.
  - `erasure_cases` dates and transitions, and the Guardian and Admissions
    lifecycle-marker backfills, are database-guarded.
  - **Still open, E21-RH.7 (pre-production blocker):** other retention
    periods count from application-written times (`created_at`,
    `occurred_at`, `sent_at`, ...) that the runtime role can set. Every
    retention-relevant time must become database-stamped.
- **E21-RH.2 (2026-10-04, ADR 0066 §10).**
  - The HRX purge functions are executed only by the dedicated
    `school_os_retention` login.
  - The configured School holds are recorded in the database by the
    operator command `platform:retention-holds-sync`. A destructive HRX run
    refuses while one is unrecorded.
- **Target hold and identity model (E21-RH.1, ADR 0066; implemented by
  RH.2–RH.6, see above).**
  - PostgreSQL hold state becomes authoritative, for School and platform
    holds alike. It is placed and released only by an audited operator
    command; the configuration values become an add-only input; and
    destructive retention fails closed when hold state cannot be
    established.
  - Destructive functions move, slice by slice, to a dedicated retention
    identity. The runtime role keeps no EXECUTE.
  - The periods, triggers and ratification status are unchanged.

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
- **E21.3A2 / E21.3F:** D8 is implemented (closure audit, ADR 0064). Payroll
  run postings and their journal entries never expire through D8 while a
  payroll posting references them (`payroll_evidence_retained`). Since
  E21.3F, Payroll's own D9 expiry deletes the postings of an emptied run,
  and the entries become ordinary Finance units that D8 expires once their
  period is 8 years closed (§5.10). The longest period always wins.

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
    - payroll results, adjustments and LWF charges, until Payroll's own D9
      expiry removes them (E21.3F, §5.10);
    - TeachingAssignments (D6, 7 y after they end);
    - Attendance sessions, timetable entries and LMS ownership;
    - Transport and Visitor rows;
    - another Employee's assignment naming one of its assignments as
      manager.
    - HRX leave evidence (`leave_policy_assignments`,
      `leave_ledger_entries`, HRX.1; `leave_requests`, `leave_request_days`,
      `leave_decisions`, `leave_year_close_items`,
      `leave_year_close_reconciliations`, HRX.2; ADR 0065 §18/§22/§23.13).
      This is D9 employment evidence (`leave_evidence`, **adopted; HRX.6
      implemented**): Leave's own participant expires one Employee's unit
      8 y after final separation, earlier in the same
      `platform:employee-retention-prune` run, through
      `retention_expire_leave_employee_evidence` (tenant, the D9 Employee
      floor, the HRX locks, every row older than the cutoff; leaves first,
      causally complete), which the runtime role cannot execute and which
      refuses any held School or non-owner session (ADR 0065 §27.10).
      A decision made as another Employee's MANAGER
      belongs to that Employee's request: it stays, and keeps the deciding
      manager's Employee until that request itself expires. Leave
      configuration is tenant lifetime.
    - HRX staff attendance evidence (`staff_attendance_records`,
      `staff_attendance_corrections`, HRX.3; ADR 0065 §24.13). The same D9
      period (8 y after final separation), **adopted; HRX.6 implemented**:
      `retention_expire_staff_attendance_employee_evidence` removes each
      record with its whole correction history, in the same run, before
      HR's purge. A row written less than the period ago (a late
      correction or cancellation) is new evidence and keeps its unit
      (`dependency_blocked`). Payroll's `payroll_run_hrx_inputs` snapshot
      is Payroll evidence: it references no HRX row and leaves only with
      its payroll result (E21.3F).
  - **A linked User blocks it.** Retention never unlinks a User (D10,
    E21.2F).
  - **Pinned:** the per-table classification is checked against the
    catalog by `EmployeeRetentionClassificationTest`.
- **D9 findings (recorded, not guessed). E21.2G: closure audit §8 E1–E3;
  payroll results are the D8 blocker.**
  - **Paid staff are kept.** An Employee with payroll results stays until
    D8 can expire ledger evidence. **Resolved by E21.3F (§5.10):** posted
    payroll evidence has its own D9 expiry; the next
    `employee-retention-prune` then releases the Employee (other blockers
    still apply).
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
      history, links) has none either. **E21.4 (§5.11):** project-adopted
      development position, implemented: a User is minimized into a
      non-login tombstone (never deleted; history keeps its reference);
      the qualified decision is still pending;
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
| E21.3B | Student-linked evidence and modules (consent, preferences, processing authorizations, converted admissions, Library/Transport/Hostel, portal invitations) | **Implemented** (§5.6; full isolated regression) |
| E21.3C | Admissions decision timestamp; Guardian no-relationship marker and personal-data expiry | **Implemented** (§5.7; full isolated regression) |
| E21.3D | Year-bound academic operations (curriculum, timetable, LMS, attendance headers) | **Implemented** (§5.8; full isolated regression) |
| E21.3E | Communications and platform residuals (never-sent and empty threads, visitors, automation, driver assignments, API credentials) | **Implemented** (§5.9; full isolated regression) |
| E21.3F | Payroll Evidence Retention & Employee Release: Payroll D9 evidence expiry, releasing payroll-linked journal entries to D8 (ADR 0064 §21 amended) and paid Employees | **Implemented** (§5.10; full isolated regression). The last retention-mechanism checkpoint |
| E21.4 | User Identity Minimization & Database Safety (E21-L1 project-adopted position; F1) | **Implemented** (§5.11; full isolated regression). Qualified decision pending |

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

### 5.6 Student-linked evidence and operational modules (E21.3B)

Implements E21.2G O1, P1, AD1, C4, C5 and I2 (`E21-CLOSURE-AUDIT.md` §8).
Project-adopted, pending ratification.

- **One composition.** `App\Support\Retention\StudentRetention` composes
  every Student-linked purge for the scheduled run and for a reviewed
  erasure case (the same closed lists). Every rule and delete stays in the
  owning module; the final exit is still only
  `StudentRetentionEligibility`. Module dependencies stay one-way (each
  participant depends on Students, never the reverse).
- **Operational (7 y after final exit), terminal rows only:**
  - returned Library loans (`LibraryLoanRetentionService`). Library has no
    fines, fees or Finance link; titles and copies stay;
  - ended Transport assignments (`TransportAssignmentRetentionService`);
    routes, stops and vehicles stay; driver assignments are E21.3E;
  - ended Hostel residencies (`HostelResidencyRetentionService`); hostels,
    rooms and beds stay.

  An unreturned loan or an active assignment is never deleted on age and
  keeps the Student (the core purge treats it as a retained reference, and
  the dry run knows it). A returned loan is never reopened and an ended
  assignment never reactivated, and new ones are created active, so the
  re-entry race (Student-row lock) is the only material one.
- **With the core record (25 y after final exit), in its one-Student
  transaction, never on a clock of their own:**
  - **processing authorizations** (Students). There is no canonical end
    field and none is needed: the core record's eligibility is the
    trigger. "Active" authorization means the authorization of a current
    (or unresolved) Student, which is never eligible. A recorded,
    unterminated grant of a Student whose core period has passed explains
    only a record that leaves in the same unit, so it leaves with it;
  - **converted admission applications** (Admissions participant). The
    linkage is `converted_student_id`, set with the conversion
    (AdmissionConversionService) and tied to `status = 'converted'` by a
    CHECK. The applicant leaves too, but only once no other application of
    it remains. An application converted into another Student that names
    one of this Student's placements keeps it;
  - **the Student subject's consent events and domain preferences**
    (Communications participant). Consent is evidence, not a transient
    preference: it is kept exactly as long as the record it explains;
  - **Guardian relationships an authorization names** (Guardians
    participant): the relationship is the consent provider's identity, so
    it is the authorization's evidence. The operational phase now removes
    only relationships nothing references.
- **Privileged path.** Processing authorizations and consent events are
  append-only. Migration `2026_11_11_090000` adds
  `retention_expire_student_processing_authorizations` and
  `retention_expire_student_consent_events` (E21.2B pattern): definer,
  pinned `search_path`, one Student's rows, tenant tie, EXECUTE for the
  runtime role only. Each re-proves the core floor in the database (the
  Student row locked; `inactive`; no active Subject Enrollment; every
  non-cancelled placement ended before a cutoff at least 25 calendar years
  before the School-local date). The authorization guard trigger admits a
  delete only with the transaction-local flag AND the table owner's
  privileges (the E21.3A2 pattern). The runtime role gains no DELETE;
  `communication_domain_consent_events` is now pinned in
  `NO_RUNTIME_DELETE`. Rollback drops the functions and restores the
  original guard verbatim.
  The 25-year floor also binds configuration: a
  `STUDENT_CORE_RETENTION_YEARS` below 25 cannot remove this evidence (the
  database refuses; such a Student counts as `error` and is kept).
- **Ended portal invitations (I2).** `platform:portal-invitations-prune`
  (daily 04:20) deletes `identity_account_invitations`
  `PORTAL_INVITATION_RETENTION_DAYS` (7) after their canonical end:
  `accepted_at`, `revoked_at`, or `expires_at` while still pending. Never
  `updated_at`, never a usable invitation, never a terminal row without its
  timestamp. The secret was unusable from the moment it ended; nothing is
  reopened. Held Schools are counted only. Batches lock `SKIP LOCKED` and
  re-apply the predicate, so a row being accepted or revoked is skipped.
- **Kept for E21.3C:** Guardian-subject consent and preferences, Guardian
  account links, Guardian personal data, rejected/withdrawn (and live)
  applications. Readiness keeps them `mechanism_pending`
  (`TenantRetentionCatalog::PENDING_ROWS`).
- **Documents:** none of the new parents owns a Document, so D5 is
  unchanged (Student Documents still go with the core record).
- **Metrics:** new categories inside existing families only (`student`;
  portal invitations under `authority`): still nine `operation` values.

### 5.7 Admissions and Guardian lifecycle markers (E21.3C)

Implements E21.2G AD2 and G1 (`E21-CLOSURE-AUDIT.md` §8). Project-adopted,
pending ratification.

- **Admissions terminal states.** `rejected` and `withdrawn` are the only
  non-converted terminal states (AdmissionApplicationService: no transition
  leaves them; `converted` is the Student path, E21.3B). Migration
  `2026_11_12_090000` adds `admission_applications.terminal_at`:
  - set by the database trigger `admission_applications_guard_terminal_at`
    to the transaction time in the very UPDATE that enters the terminal
    state (so it is atomic with the transition and its audit event);
  - immutable once set; only a terminal row may carry it (CHECK);
  - no new reason field: the existing optional `decision_note` is kept as
    is.
- **Admissions expiry.** `platform:admissions-retention-prune` (daily 04:35,
  `ADMISSIONS_TERMINAL_RETENTION_YEARS`, adopted 1) deletes a rejected or
  withdrawn application strictly more than one calendar year after
  `terminal_at` (UTC timestamp; `subYearsNoOverflow`). The unit is the
  applicant (locked FOR UPDATE, then its applications): the applicant goes
  only once no application of it remains, so a shared applicant keeps its
  identity while another application (converted, live or not yet expired)
  exists. No Admissions row owns a Document, a Finance or a consent record;
  any other referencing row blocks (ReferencingRows).
- **Guardian marker.** `guardians.no_relationship_since` is NULL while any
  `student_guardian_relationships` row exists (an existing row is an active
  relationship: there is no end column, an unlink is a hard delete, D7
  removes a departed Student's rows after 7 years). The trigger
  `student_guardian_relationships_track_guardian` maintains it for every
  writer, in the writer's transaction: it locks the Guardian row first,
  then reads the remaining relationships with a fresh snapshot, and sets
  the statement time when the last one goes. A re-link clears it; the next
  final unlink restarts it. A Guardian created without a relationship starts
  its clock at creation. Direct writes are refused, except a past-dated
  backfill of a still-unset, unrelated Guardian. Lock order: Student (the
  writer's own) -> Guardian (the trigger); the Guardian purge takes only
  the Guardian.
- **Guardian expiry.** `platform:guardian-retention-prune` (daily 04:40,
  `GUARDIAN_RETENTION_YEARS`, adopted 1) deletes, one Guardian per
  transaction under its row lock, a Guardian with no relationship whose
  marker is strictly more than one calendar year old, and only when nothing
  retained needs it: its Documents (DocumentParentRetention, bytes after
  commit), its own consent events (append-only; the Guardian-floored
  `retention_expire_guardian_consent_events`) and domain preferences, its
  revoked account links past the D6 authority period, its contacts, and the
  root last. Kept (`dependency_blocked`): an active account link, or a
  revoked one younger than D6 (retention never unlinks a User; I5 stays
  open); retained Communications content naming the Guardian (D3); a usable
  portal invitation; any table added later until classified. A relationship
  named by a Student's processing authorization is Student-core evidence
  (E21.3B), so the Guardian stays related and no clock runs.
- **Backfill.** `platform:lifecycle-markers-backfill` (operator, dry run,
  rerunnable, counts only) sets a marker once from audit evidence only:
  - an application: exactly one `admission_application.rejected|withdrawn`
    event for it, matching its status; its `occurred_at`;
  - a Guardian without a relationship: its `guardian.created` event must
    survive (audit expiry removes the oldest events first, so the history
    is complete), and every `student_guardian.linked` relationship must
    have its own `student_guardian.unlinked` event; the marker is the last
    unlink (or the creation, when never linked).
  Everything else stays NULL: unresolved and kept; readiness reports it
  (`retention_trigger_unresolved`). Never `updated_at`.
- **Erasure.** A Guardian case follows G1 (related, unresolved, running with
  a not-before date, blocked, held, eligible) through the same purge. No
  generic Admissions erasure was added.
- **Documents:** Guardian is the third decided owner of
  DocumentParentRetention; no Admissions row owns a Document.
- **Metrics:** `admission_application` and `guardian_record` join the
  `student` family: still nine `operation` values.

### 5.8 Year-bound academic operations (E21.3D)

Implements E21.2G A1, A2 and E2 (`E21-CLOSURE-AUDIT.md` §8).
Project-adopted, pending ratification.

- **The clock.** Every row's year is its own `academic_year_id` (curriculum
  deliveries, timetable entries, attendance register headers), pinned by
  its composite foreign keys to its Section and SubjectOffering; for LMS it
  is the resource's SubjectOffering's year, and every audience row is
  pinned to that Offering and a Section of the same year, so a resource has
  exactly one year (no cross-year audience exists; pinned by a test). A row
  is past its period when that year's `ends_on` is strictly before the
  School-local date `ACADEMIC_OPERATIONS_RETENTION_YEARS` (7) calendar years
  ago (`subYearsNoOverflow`; a 29 February end goes on 1 March). Never
  `created_at`, a status, a session/due/start date or the current year.
  Because every row has a mandatory year by FK, no row is ambiguous.
- **The clock cannot move.** Migration `2026_11_13_090000` makes
  `academic_years.starts_on`/`ends_on` immutable for every role (no
  application path edits them; the API edits name/code only).
- **Rows, in dependency order** (`platform:academic-retention-prune`, daily
  04:45; bounded batches, `FOR UPDATE SKIP LOCKED`, predicate re-applied,
  any FK-referenced row kept as `dependency_blocked`):
  - curriculum deliveries (no Employee reference, no Document);
  - attendance register headers, only once no attendance record (D7, a
    Student's own 7-year clock) or other row references them; their teacher
    provenance goes with them;
  - timetable entries, only once no header references them; their teacher
    reference goes with them. Periods, rooms and other reusable
    configuration stay.
- **LMS** (`LmsResourceRetention`, one resource per transaction): Learning
  Content and Assignments, draft/published/archived alike, with their
  audiences and Documents (DocumentParentRetention; bytes after commit, a
  failed byte delete left to the orphan run). A teacher-owned resource also
  needs the D6 minimum: the owner's authority last applied at the later of
  the year's end and the end of the owner's TeachingAssignments over the
  audience Sections (an open one keeps it); both clocks are computed
  independently and the longer wins. The audience leaves only through
  `retention_expire_learning_content` / `retention_expire_assignment`, which
  re-prove in the database the tenant context, the 7-year year floor, the
  D6 floor and that no Document is left.
- **Tenant lifetime (A2):** syllabus units, examinations and examination
  papers. No E21 mechanism expires them (architecture guard).
- **Employees (E2).** Timetable, register-header and LMS-owner references
  are released only by those rows' own expiry; the Employee then follows
  its own D9 clock (`employee-retention-prune`). Payroll (E21.3F), D6
  TeachingAssignments and manager references still block.
- **Metrics:** one deliberate new family, `academic` (ten `operation`
  values, ceiling 20).

### 5.9 Communications and platform residuals (E21.3E)

Implements E21.2G C1, C2, O2, O3, O4 and I3, and re-verifies C6, C7, O5
and I1 (`E21-CLOSURE-AUDIT.md` §8). Project-adopted, pending ratification.
No new lifecycle marker was needed: every trigger already exists, is set in
the transition's own transaction, and is never `updated_at`.

- **Never-sent announcements (C1)** (`platform:communications-prune
  --only=residual`, `COMMUNICATIONS_ABANDONED_RETENTION_YEARS`, adopted 1):
  never published (`published_at` and `message_id` NULL, no message, no
  recipient), and either `cancelled` (trigger `cancelled_at`, set by the
  cancelling UPDATE; cancel is only possible from draft or scheduled) or
  `rejected` (trigger: `decided_at` of the latest approval request, which
  must be `rejected`; written with the status). A rejected announcement is
  still editable and editing returns it to draft, so the status is rechecked
  under the row lock. Undated rows are `unresolved` and kept. It goes with
  its draft audience, channels, cohorts, approval requests and attachments
  (bytes after commit).
- **Empty threads (C2):** no message and no attachment (a thread starts with
  participants only and no message is ever deleted on its own, so an empty
  thread never held content). Trigger `last_activity_at`, the documented
  activity time (creation or last change); NULL is unresolved. A new
  message takes FOR KEY SHARE on its thread and the purge locks the thread
  FOR UPDATE, so a message that committed first keeps it.
- **Driver assignments (O2)** (`platform:operations-retention-prune`,
  `OPERATIONS_DRIVER_ASSIGNMENT_RETENTION_YEARS`, adopted 7): `status =
  'ended'`, 7 calendar years after `ends_on` (UTC). Ending is one-way; a new
  assignment is a new row. The driver reference is released with the row.
- **Visits (O3)** (`OPERATIONS_VISIT_RETENTION_YEARS`, adopted 1):
  `checked_out`, 1 calendar year after `checked_out_at`; a checkout is
  one-way. One visitor per transaction: the visitor goes only with its last
  visit. A visit never checked out is unresolved and kept. Host references
  go with the visit.
- **Automation (O4)** (`OPERATIONS_AUTOMATION_RETENTION_YEARS`, adopted 1):
  `succeeded`/`skipped`/`failed`/`abandoned` with `completed_at`, 1 calendar
  year after it, with their attempts and review items (ON DELETE CASCADE).
  Pending, running and retrying executions are never eligible; a terminal
  execution is never reclaimed. Rule instances (configuration) stay.
- **API credentials (I3, D6)** (`platform:authority-history-prune`): an
  ended credential, 7 calendar years after its authority ended at
  LEAST(revoked_at, expires_at); a revocation is final and an expiry is
  never extended (database trigger), so a current credential is never
  eligible. The runtime role keeps no DELETE; migration `2026_11_14_090000`
  adds the narrow, 7-year-floored `retention_expire_api_client_credentials`.
  The secret is only ever a hash, unusable from that end. `last_used_at` is
  never the trigger; API clients stay.
- **Tenant lifetime, pinned by guards:** memberships (suspended, never
  deleted, rule 92), membership email preferences (follow the membership),
  Inventory balances and movements. **Notifications** stay not applicable:
  the only writer is the Phase 0C demo consumer, whose event emitter has no
  production caller. **Canteen** orders and stock consumptions follow
  Finance (D8) only.
- **Employees:** driver and host references are released only by those
  rows' own expiry; payroll evidence (E21.3F) still blocks.
- **Metrics:** never-sent and empty-thread in `communications`; credentials
  in `authority`; one deliberate new family `operations` (eleven values).

### 5.10 Payroll evidence and the D8 × D9 release (E21.3F)

Resolves the D8 × D9 intersection recorded by E21.3A2 (ADR 0064 §21,
amended). Project-adopted D9, pending ratification. Neither policy is
weakened: the longest period wins.

- **Inventory and classification:**

  | Record | Classification | Trigger / period | Expiry behaviour |
  |---|---|---|---|
  | `payroll_run_results` + `payroll_run_result_lines` | C/D: authoritative per-Employee result and its lines (posted runs) | final separation + 8 y, and every run/posting older than the cutoff | with the Employee's payroll unit; lines by cascade |
  | `payroll_statutory_calculation_results` | E: statutory payroll evidence (PF/ESI/PT/LWF/TDS per result) | as its result | by cascade with the result |
  | `payroll_adjustments` | F: manual overrides / correction deltas (how a result was determined) | as the Employee's results | with the Employee's unit, never on their own |
  | `payroll_lwf_annual_charges` | G: once-per-cycle LWF marker (payroll evidence; the amount is in the statutory result and the ledger) | as its result | with the Employee's unit |
  | `payroll_run_hrx_inputs` | HRX.5 (ADR 0065 §26.12): the HRX absence evidence captured beside a regular-run result (half-day units, fingerprint; no amount) | as its result | by cascade with the result, inside the same privileged expiry |
  | `payroll_run_postings`, `payroll_statutory_run_postings` | H: the journal linkage (run-level, all Employees) | the run is emptied, every run and posting older than the cutoff | with the emptied run group, reversals first |
  | `payroll_runs` (regular + its corrections) | run header (no amount, no personal data) | as its postings | with the emptied group |
  | `journal_entries` / lines | I: Finance evidence | D8 (8 y after its period closes) | **Finance only**, once no payroll posting references it |
  | `payroll_periods` | payroll calendar configuration | none | tenant lifetime (`payroll_calendar`) |
  | components, structures, accounting and statutory configuration, rule versions | A: configuration | none | tenant lifetime (unchanged) |
  | draft/calculated/approved runs and their rows | B: working state | none | existing lifecycle unchanged; their rows keep the Employee |
  | compensation assignments, statutory profiles | D9 per-employment configuration (E21.2E) | 8 y after separation | unchanged (`employee-retention-prune`), now reachable |

- **Trigger:** `EmployeeRetentionEligibility`, the one canonical final
  separation (latest `ends_on`, every EmploymentRecord terminal and dated;
  rehire restarts it; current, future and notice-period employment is
  current; undated is unresolved and kept). Never last pay, archive, login
  or User link. One D9 setting: `EMPLOYEE_EVIDENCE_RETENTION_YEARS` (adopted
  8). The payroll command refuses a value under 8 and deletes nothing while
  it is unset.
- **Evidence unit** (`platform:payroll-retention-prune`, phase 1), one
  Employee per transaction: the Employee's results (lines, statutory
  results), adjustments and LWF charges. The narrow function
  `retention_expire_payroll_employee_evidence` re-proves in the database:
  tenant; cutoff at least 8 calendar years back; the Employee and its
  EmploymentRecords locked FOR UPDATE, every record terminal and ended
  before the cutoff; every run holding the evidence locked, POSTED, posted
  before the cutoff, every posting (original, reversal, statutory) created
  before the cutoff, and a run with statutory results statutorily posted.
  A late reversal or correction is new evidence: it keeps the Employee's
  evidence until it is itself old enough. The run, its postings and journal
  entry stay; the run records `results_expired_at`.
- **Run release** (phase 2), one regular run with its corrections per
  transaction, once retention has emptied every run of the group and every
  run and posting predates the cutoff: the postings and runs are deleted by
  `retention_expire_payroll_run` (8-year database floor). This RELEASES the
  journal entries; Payroll never deletes one.
- **D8 × D9:**

  | D8 (period closed ≥ 8 y) | D9 (separation + 8 y, postings old) | Result |
  |---|---|---|
  | due | due | payroll evidence expires, the run is released, the NEXT `finance-retention-prune` removes the entries |
  | due | not due | everything stays; Finance reports the entry `dependency_blocked` (`payroll_evidence_retained`) |
  | not due | due | payroll evidence expires and the run is released; the entry stays with Finance until its period is 8 y closed |
  | not due | not due | everything stays |

- **Accounting:** nothing in the ledger changes; payroll totals are
  ledger-account totals carried in the baselines. Tests prove every account
  balance, charge outstanding, Student due and receipt series exactly
  unchanged across payroll expiry and the later Finance expiry.
- **Employees:** payroll retention never deletes an Employee. The next
  `employee-retention-prune` removes Payroll's per-employment configuration
  and then the Employee, unless another blocker remains (a User link,
  manager reference, D6 history, other retained rows, a hold).
- **After expiry:** a payslip of an expired result is an ordinary 404;
  the run page and API (`resultsExpiredAt`) say the run's results no longer
  cover the whole run; the three statutory exports refuse with 409
  `PAYROLL_RESULTS_EXPIRED` rather than return a partial filing. Nothing is
  reconstructed from Finance. Payslips are rendered on demand; no payroll
  Document exists.
- **Privileges:** no runtime privilege widened. Results, lines and
  statutory results stay frozen by trigger (a delete is allowed only inside
  the employee function: a transaction-local flag plus the owner's
  privileges); adjustments and postings stay append-only, now also listed
  in `DatabaseRoleVerifier::NO_RUNTIME_DELETE`. Migration `2026_11_15_090000`
  (rollback removes the mechanism and refuses once any run carries the
  marker).
- **Holds, dry run, batches:** a held School only counts; `--dry-run` uses
  the database's own verdict (phase by phase, against the current state);
  units are bounded and id-ordered; a rerun deletes nothing new.
- **Schedule:** daily 05:20, after the Employee (04:50) and Finance (05:10)
  runs; what it releases they take on their next run.
- **Metrics:** `payroll_evidence` and `payroll_run`, in the existing
  `employee` family (no new label value).

### 5.11 User identity minimization and database safety (E21.4)

Implements the **project-adopted, India-aligned development position** for
User erasure (E21-L1, owner authorization 2026-10-03). It is not the
qualified decision: `E21-L1-USER-IDENTITY-DECISION-REQUEST.md` §8 stays
blank, and qualified Indian legal/compliance ratification is pending.

- **Jurisdiction profile:** an India-first compliance baseline, with
  board- or state-specific deployment overlays where they differ. Nothing
  here hard-codes one board's administrative rules (for example CBSE) as a
  rule for all of India.
- **F1 fixed:** the runtime role has no DELETE on `users`
  (`DatabaseRoleVerifier::NO_RUNTIME_DELETE`, checked by
  `platform:verify-database`); a raw delete fails with a permission error
  even for a User nothing references. The FK actions (CASCADE memberships,
  SET NULL audit and grant actors) are therefore unreachable; they are not
  the minimization mechanism. Rollback keeps the revoke.
- **Lifecycle:** `users.minimized_at` (NULL: current; set: minimized). The
  database enforces the tombstone's shape (disabled, `Former user`,
  `minimized-<id>@users.invalid`, no verification time, no remember token),
  makes it immutable (never restored or re-enabled), and refuses it as a
  current principal again: no membership (invited/active), School role
  grant, account link, Employee link, platform or Group grant, elevation or
  personal access token. A returning person gets a new User through the
  normal invitation flows.
- **What minimization removes:** the name and email (replaced, not copied
  anywhere), verification time, password (replaced by the hash of a random
  secret), remember token, human personal access tokens, MFA factors and
  recovery codes, account-recovery and activation credentials, stored
  sessions; it bumps the credential version (every session on every host
  ends) and ends an active elevation. **What it keeps:** the row and its
  id, the stable non-login actor that every retained audit event, D6
  authority row, membership (tenant lifetime), Employee link, Finance and
  Payroll operator column and erasure case references, unchanged.
- **Classification:** all 87 foreign keys to `users` are classified in
  `UserReferenceCatalog` (6 active-purpose blockers, 77 retained
  references to the tombstone, 4 authentication children); a new one
  fails `UserReferenceCatalogTest` and makes minimization fail closed
  (`unclassified_user_reference`).
- **Eligibility (`UserActivePurposes`, every School the User belonged
  to):** blocked by an invited/active membership, a current Employee link,
  an active Guardian/Student account link, an unrevoked platform or Group
  grant, an active elevation, an enabled automation it owns; held by the
  platform hold or a hold on any of those Schools. Never inferred from a
  last login.
- **Execution:** only an approved platform-scope erasure case
  (`platform:erasure-case-*`, E21.2F) minimizes, after locking the User
  FOR UPDATE and rechecking everything; never scheduled, never by age.
  Outcomes for `user_identity`: `legal_hold`, `policy_unresolved`,
  `dependency_blocked` (one per purpose), `eligible`, `completed`
  (`minimized`), `error`; a rerun is safe. `user_record` is always
  `physical_deletion_not_authorized`. A School-scope case (Student,
  Guardian, Employee) never reaches the User or another School.
- **Presentation:** a minimized User reads as "Former user" with no
  address (staff directory, run pages and every name display); it is never
  mailed (`EmailAddressResolver`).
- **Not done:** physical User destruction (needs the expiry of every
  retained dependency, the qualified decision and a designed purge);
  platform audit IP address and user agent stay with their D1 event.

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
| `STUDENT_CORE_RETENTION_YEARS` | 25 (calendar years after final exit; never shorter than operational; E21.3B: with its core evidence, database floor 25) | E21.2D |
| `PORTAL_INVITATION_RETENTION_DAYS` | 7 (days after an ended portal invitation ended) | E21.3B |
| `ADMISSIONS_TERMINAL_RETENTION_YEARS` | 1 (calendar year after a rejected/withdrawn application's `terminal_at`) | E21.3C |
| `GUARDIAN_RETENTION_YEARS` | 1 (calendar year after the Guardian last had a Student relationship) | E21.3C |
| `ACADEMIC_OPERATIONS_RETENTION_YEARS` | 7 (calendar years after the end of the authoritative Academic Year) | E21.3D |
| `COMMUNICATIONS_ABANDONED_RETENTION_YEARS` | 1 (calendar year after a never-sent announcement's cancellation/rejection or an empty thread's last activity) | E21.3E |
| `OPERATIONS_DRIVER_ASSIGNMENT_RETENTION_YEARS` | 7 (calendar years after an ended driver assignment's `ends_on`) | E21.3E |
| `OPERATIONS_VISIT_RETENTION_YEARS` | 1 (calendar year after a visit's checkout) | E21.3E |
| `OPERATIONS_AUTOMATION_RETENTION_YEARS` | 1 (calendar year after an automation execution's completion) | E21.3E |
| `EMPLOYEE_ANCILLARY_RETENTION_YEARS` | 2 (calendar years after final separation) | E21.2E |
| `EMPLOYEE_EVIDENCE_RETENTION_YEARS` | 8 (calendar years after final separation; never shorter than ancillary). E21.3F: also the payroll evidence period (`platform:payroll-retention-prune` refuses less than 8) | E21.2E, E21.3F |
| `ERASURE_CASE_RETENTION_YEARS` | 7 (calendar years after a case closed) | E21.2F |
| *(D8 Finance)* | 8 years after the financial year closes: **no setting, no expiry yet** (needs a financial-year close) | E21.2E (blocked). Superseded: `FINANCE_RETENTION_ENABLED` + `FINANCE_RETENTION_YEARS` (8), E21.3A2 |

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
