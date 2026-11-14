# HR.R1-C35-RF1: residual findings and closure prerequisites (2026-11-14)

Status: **audit record, decision-first.** RF1 inspects and proposes; it
changes no runtime code, migration, test, database or demo data. Every
disposition below marked *proposed* is a recommendation for the owner named
in its row. Nothing here is an owner, Legal, DPO or Security approval.
HR.R1 stays **OPEN**; the five closure baselines stay **unselected**.

**Authority.** D-C35-10 (`HR-R1-C35-P3-DISPOSITIONS.md` §9, HR owner):
"Other open P3/LOW findings — Disposition each individually before baseline
selection". RF1 prepares those dispositions; the owner decides them.

**Baseline inspected.** `main` = `origin/main` = `00f194af` (WO-22
evidence), runtime ancestor `5b78154c` (WO-22), working tree clean before this record, one
worktree, the historical stash (1 entry) and `integration/e16-r2` kept. No
newer publication from any workstream. E16 last valid day 2026-11-28.

## 1. Method

1. Every finding identifier in `docs/hr`, `docs/architecture/adr`,
   `docs/modules` and `CLAUDE.md` was enumerated mechanically
   (`C\d+-[NAE]\d+`, `WO\d+-[NE]\d+`): 104 identifiers (75 C-series, 29
   WO-series). Their definition and latest status were retrieved verbatim,
   with file and line (two read-only inventories, cross-checked here).
   **Widened after the independent review (§11):** the residual rows of
   `HR-LEGAL-SECURITY-GATE-REGISTER.md` (`S-n`, `X-n`, `M-n`, `R-HRn-n`) and
   the review-local labels of every checkpoint record (`R-n`, `L-n`, `M-n`,
   `R1-01-n`) not marked fixed or closed were added (§4.4).
2. The 61 original HR.R1-0 identifiers were read from
   `HR-R1-0-AUDIT-REGISTER.md` "Every finding" (lines 178-238): exactly 61
   (HRC 12, LVA 9, XRP 7, PAY 12, TRR 10, GOV 11).
3. For each finding whose disposition depends on current behaviour, the
   current source was read at `00f194af` (§3).
4. CI history was read from GitHub (runs, attempts, job annotations, JUnit
   artifacts), not from the earlier reports (§5).
5. The development database (DDEV `db`) was inspected **read-only** (§6).
6. Two executions, both on the isolated test database (`school_os_test`),
   never the development database: one timing-sensitive test run 10 times
   (§3.2), and one throwaway probe test reproducing WO22-N1, deleted after
   the run and never committed (§3.1).
7. Severity follows **C27-C** literally
   (`HR-R1-C27-CLOSURE-INTERPRETATIONS.md:27`): "P1 and HIGH are BLOCKER;
   P2 and MEDIUM are MAJOR; P3 and LOW are recorded findings." Legal,
   staging, production and owner-DEFERRED items "do not fail the verdict if
   each is recorded, unchanged and refused". C27 §3 adds that each P3 "still
   needs its own decision or unit before the final record can call itself
   PASS rather than PASS WITH CORRECTION". C27 has no explicit rule for
   *accepted* items; D-C35-6 records "P3 closed (accepted)" and D-C35-8
   "P3 closed (accepted alternative)".

**Disposition categories** (one per finding): VERIFIED CLOSED (existing
evidence), ACCEPT (accepted limitation), ALTERNATIVE (approved alternative),
DEFER (approved deferral), CORRECT (corrective implementation),
OPERATIONAL (operational acceptance evidence), OTHER-DOMAIN (another owner),
GATE (Legal/Security release gate), DECIDE (owner decision required), and
INSUFFICIENT (insufficient evidence). "Proposed" means not yet approved.

## 2. Result in one paragraph

No HIGH was found. **WO22-N1 was reproduced** as the runtime role, and its
recorded LOW rating conflicts with the closest written precedent: ADR 0076
§64 rated the same kind of gap (a raw-SQL state with no evidence row,
enforceable without knowing who acted) a **MEDIUM integrity gap**, which
under C27-C is MAJOR. RF1 does not rate it for the owner; it recommends the
correction under either rating (§3.1). No other development MAJOR is
indicated. **Eighty-one open items** still lack an individually approved
disposition, which D-C35-10 requires before baseline selection; RF1 proposes
one for each (§4). Three owner decisions pending since C12 (D-7, D-9, D-10)
are among them. WO20-N1 lets an ordinary application path (an unlink) clear
the access block that R-HR12-3's owner decision keeps, and needs an owner
decision (§3.3). WO20-N6 is not reachable (§3.5). Operational evidence
(browser, axe, screen reader) is missing for every C35 work order and for
WO-9, and the development database lacks the two newest migrations.
**RF1 verdict: CORRECTIVE WORK REQUIRED** (WO22-N1, with owner decisions
pending for the rest).

## 3. Material-risk triage (brief Part C)

### 3.1 WO22-N1: a task returned without its event (recorded LOW; MEDIUM by precedent; reproduced)

**Exact finding** (`HR-R1-C35-WO22-TASK-REVIEW-GOVERNANCE.md:104`): "The
runtime role can set a task `returned` without its `returned` event; the event
is the only record of who returned it, so a raw return escapes the identity
guard. The service always writes both in one transaction. Inside rule 102's
boundary (who acted); a deferred trigger or a `returned_by_user_id` column
would close it."

**Current code** (`2027_01_31_090000_create_workforce_tasks.php`, as patched
by `2027_02_25`):
- `workforce_tasks` has `completed_by_user_id`/`completed_at` and
  `cancelled_by_user_id`/`cancelled_at`, required by
  `workforce_tasks_outcome_check`. It has **no returned-by or submitted-by
  column.**
- `hr_workforce_tasks_guard` allows `submitted -> returned` (lines 228-231),
  and requires `return_count = OLD + 1` (242). Its assignee clause
  (239-240, which WO-22 extended to known-same accounts) compares
  `completed_by_user_id`, which is NULL on a
  return, so it does nothing for a return: the return's only identity check
  is the event guard. It never checks that a `returned` (or `submitted`)
  event exists.
- The only application writer, `TaskWorkflowService::review()`, saves the row
  and inserts the event in one transaction (lines 93-94).
- The runtime role holds no DELETE on `workforce_tasks` or
  `workforce_task_events` (verified read-only on DDEV: `has_table_privilege`
  false).

**Reproduction** (throwaway probe on `school_os_test`, deleted afterwards):
connected as `school_os_app` under the School's context, a reviewed task
submitted through the service (events `assigned`, `submitted`) was UPDATEd
to `returned` (`return_count` 1, version + 1): **accepted**, events still
`assigned`, `submitted`. An assigned task UPDATEd to `submitted`:
**accepted**, events still `assigned`.

**Analysis.**
1. *Can the runtime role return a task without reviewer evidence?* Yes
   (reproduced above).
2. *Is a supported runtime path affected?* No. Every application path writes
   both rows.
3. *Downstream effect.* A return is not terminal: the task goes back to the
   assignee, who must complete or cancel it (`return_count <= 1`). No money,
   access, leave, payroll or separation decision reads the return. The only
   loss is the timeline's record of who returned it.
4. *Known-same-person.* A raw-SQL actor can equally insert a `returned`
   event naming an unrelated actor; who acted is the application boundary in
   rule 102. The gap removes evidence rather than enabling a decision.
5. *Contract.* Rule 102 (ORG.8F, §64): "If PostgreSQL can enforce a
   structural rule without knowing which human acted (a status domain, **an
   evidence row's existence**, a terminal state's end date), enforce it in the
   database." The existence of a `returned` event when the status becomes
   `returned` is such a rule, and is not enforced. It is not a violation of
   a protected financial, access or tenancy invariant.
6. *Precedent.* ADR 0076 §64.12 (`0076-*.md`, G-6) reproduced the same shape
   on Students and placements ("raw SQL reaches states no canonical service
   can create … PostgreSQL can require the evidence row … without knowing who
   acted") and classified it **"MEDIUM integrity gap"**, adding that "ORG.8
   is not cleanly technically closed until the narrow follow-up (ORG.8G)
   lands". Its reasons were (a) structural invariants enforceable without
   an actor, (b) rule 11's Student-status audit could be skipped, and (c)
   no canonical path affected. WO22-N1 matches (a) and (c), and the review's
   `hr.task.returned` audit event is skipped too. It differs on (b): a task
   return is not one of rule 11's named categories, it is not terminal, and
   nothing downstream reads it. PAY-14 (rated LOW) concerned a frozen
   column, not an evidence row's existence.
7. **New related observation (RF1-N1, INFO):** the same holds for
   `submitted`: a raw transition to `submitted` with no `submitted` event is
   accepted. WO-22's submitter exclusion (`isKnownToBeSubmitter`) reads the
   `submitted` events, so such a task has no recorded submitter. The
   assignee check still applies.

**Determination.** The severity is the owner's (Architecture) to set; RF1
does not settle it. The recorded LOW rests on the distinctions above; the
§64 precedent and rule 102's written text point to MEDIUM, which C27-C
makes MAJOR. **RF1 recommends treating it as MEDIUM for consistency with
§64**, unless the owner records those distinctions as a deviation (rule 15).
Under either rating the recommended disposition is **CORRECT**: a small unit
adding a deferred constraint trigger that requires a same-transaction
`returned` event (and a `submitted` event, RF1-N1), with raw-SQL tests, a
verifier pin and a rollback proof. ACCEPT is not offered without that
deviation note, because rule 102 says such a rule is enforced in the
database.

### 3.2 WO22-N5: the C30 two-process test timeout (LOW, test stability)

**Exact finding** (WO-22 record :108):
`IdempotencyServerErrorRealRouteTest::two_real_processes_racing_a_released_failure_never_write_twice`
"failed once on the first candidate run: two real HTTP processes did not
answer within its 25 s barrier under CI load."

**CI evidence** (run 43581045054 attempt 1, job 132405678034, log fetched):
"neither request returned while the other was held" at
`IdempotencyServerErrorRealRouteTest.php:253` (the deadline in
`raceUnderBarrier()`), called from :198; shard 3/4 "Tests: 2986, Assertions:
92608, Failures: 1", time 10:20. Attempt 2 (failed jobs only, same SHA):
shard 3 green, 2,986 tests, 92,641 assertions.

**Test mechanics** (lines 187-258): the test holds advisory lock 3030; an
injected trigger makes the first request to reach the endpoint wait on it;
the second request should find the claim in flight and return 409 at once.
The loop fails if **both** requests are still running after 25 s (chosen
under the 30 s in-flight timeout). The server is `php -S` with
`PHP_CLI_SERVER_WORKERS=4`.

**Reproducibility.**
- Locally, 10 consecutive runs on the isolated test database: **10/10
  passed**, 6.6-8.4 s each.
- CI since 2026-11-11 (70 CI runs; every failed job's annotations read): the
  test failed **once**, on WO-22 attempt 1. The other first-attempt failures
  in that window were WO20-E1 (fixed), C26-N2 (`BacklogSignalsTest`), two
  C29 catalogue tests (fixed then) and one C34 sharding fault.

**Possible causes, not yet distinguished.**
1. The second request did not reach PHP within 25 s: a cold `php -S` worker
   (Laravel boot) on a loaded runner, or a request queued behind the held one.
2. The second request blocked in PostgreSQL on the first's uncommitted claim
   (a unique-index wait). That would mean the claim was not committed before
   the endpoint ran, which the passing runs contradict.
3. Something else in the runner.

The test failed before any business assertion, and it holds the barrier in a
`finally`, so the run shows no duplicate write and no wrong replay. A timeout
in test coordination is not evidence that product idempotency is broken.

**Relationship to C30.**
- **C30-N1** (VOLATILE read helpers make a 5xx remembered) is unrelated.
- **C30-N2** (a request slower than the 30 s in-flight timeout and its
  reclaimer can both execute) is related only through the chosen 25 s
  deadline. The failure happened inside the window, before any reclaim was
  possible, so it is not an instance of C30-N2.
- The C30 contract (rule 32) is unchanged.

**Determination.** LOW, test-stability, owner Platform. **CORRECT
(proposed, test-only):** a bounded Platform unit that first instruments the
barrier (record which request reached the trigger, the server's accept time,
`pg_stat_activity` wait events at the deadline), then fixes the coordination
(e.g. warm the server before the race and wait for its readiness). The
timeout is not raised without a root cause. Until then a recurrence must be
reported as WO22-N5, never silently rerun. The same treatment fits C26-N2
(an exact-age assertion across a second boundary).

### 3.3 WO20-N1: an access answer from the current link only (LOW, decide)

**Exact finding** (WO-20 record :108): "An unrecorded case, or a legacy
record, still reads only the Employee's CURRENT link: unlinking the Employee
turns OPEN into CLEAR, so completion can certify while the old account keeps
its roles. Older than WO-20 (HR.15) and never a false RETAINED; closing it
person-level (uncorrected links, rule 152) for every state is an HR and
Identity owner decision."

**Current code** (`SeparationObligations::schoolAccess()`, lines 139-168):
`$live` is the current link's grants. Only a retained case with a bound set
also reads the holders among `uncorrectedLinkedAccounts()`. For a case that
is not retained, or a legacy retained record, an unlink makes `$live` empty
and the obligation CLEAR. CLEAR does not block completion
(`SeparationObligation.php:10-19`).

**Consequence.** A separation can be certified complete while an account
that was linked to the Employee, and is not corrected as someone else,
still holds School roles. No access is granted: the roles exist
independently and stay visible under Settings → Staff accounts. But the
access block is itself an owner decision: **R-HR12-3** (gate register :434)
"a separated person keeps School staff access until someone off-boards them;
completion of the case is blocked meanwhile". An ordinary application path
gets around it: one holder of `hr.employees.manage` unlinks the Employee
(`HrEmployeeAccountLinkController`, `EmployeeService::unlinkUser()`, no fresh
MFA), and a holder of separation manage completes the case.

**RF1-N2 (new, LOW): the unlink self-check is current-link only.**
`unlinkUser()` refuses only `refuseSelfLink($actor, $locked->user_id)`
(`EmployeeService.php:340`, `HrSelfAdministrationGuard.php:37-42`), which
compares the actor with the CURRENTLY linked account. An older, uncorrected
account of the same person (rule 152's "known same person") can unlink them,
the same current-link shape as WO18-N1.

Rule 138 ("Unknown is never clear") is not literally broken: the current-link
reading is HR.15's approved design, and rule 152 names only the listed
decisions. The closest precedent, HRC-4 (same harm class), was P3, and
D-C35-2 kept it there.

**Determination.** LOW (defensible on the HRC-4 precedent; no access is
granted). **DECIDE, recommended CORRECT:** read the holders among all
uncorrected links for every state, as retained cases already do
(`uncorrectedLinkedAccounts()` exists), and make the unlink self-check
person-level (RF1-N2, WO18-N1, WO20-N7), in one small unit. ACCEPT is
possible only as a recorded **amendment of R-HR12-3**, since it lets an
unlink end the block. Owner: HR and Identity.

### 3.4 WO20-N5: OPEN on a completed case after a rehire (LOW, accept)

Display only: the completed case shows OPEN with "Off-board it" when the kept
account gains a role for the new job. Nothing is blocked or written.
Following the advice would off-board a current employee, so the risk is
operator confusion. **ACCEPT (proposed)**, optionally with wording that names
a later employment. Owner: HR.

### 3.5 WO20-N6: completion versus an account-link change (not reachable)

**Exact finding** (:113): "Completion does not take the lock that link
changes take (the compensation lock and the Employee row), so a relink
committing during completion can be missed…"

**Lock analysis (corrected after the independent review; the source finding
states the facts wrongly).**
- `complete()` → `lockCase()` → `lockEmployment()`
  (`SeparationCaseService.php:384, :463, :435-447`) →
  `EmployeePlacementLock::forPlacementChange()`, which reads the **Employee
  row FOR SHARE** (`EmployeePlacementLock.php:45-47, :62`) before `forCase()`
  reads the link, and holds it to commit.
- `linkUser()` and `unlinkUser()` take the same row **FOR UPDATE**
  (`EmployeeService.php:333, :400`).
- So completion and a link change on the same Employee strictly serialize:
  one waits for the other to commit, and the race the finding describes
  cannot happen. A link committed before completion is seen; one after it
  shows OPEN on the completed case, as rule 137 describes for later grants.

**Determination.** **VERIFIED NOT REACHABLE (proposed close).** Owner: HR,
Identity.

### 3.6 WO19-N1: self-service cancellation credit (LOW, accept)

The self-service cancellation reverses its own debit behind the cached
`hr.leave.self` (up to 60 s) plus a freshly locked ActingEmployee. D-C35-3
scoped fresh authority to "every ledger and accrual write entry point", and
`HR-R1-C35-P3-DISPOSITIONS.md:43` lists those entry points ("allocate,
allocation run, adjust, opening, accrual run start and continue") and adds
that "consumption and reversal written inside a leave decision keep that
decision's authority". Cancellation is not among the listed entry points,
which supports WO-19's reading. The residual is codified in
rule 134 and ADR 0065 §34, but no explicit owner acceptance is recorded.
Effect: within the cache window, someone whose self-service right was revoked
can still cancel their own request, which re-credits what that request
debited: the ordinary outcome of a cancellation. **ACCEPT (proposed),
owner confirmation needed.** Owner: HR, Security. Extending D-4 to
self-service needs that owner's word; RF1 does not do it.

### 3.7 WO19-N3: the MFA code in the idempotency fingerprint (LOW, Platform)

`RequestFingerprint` hashes the logical body with unsalted SHA-256 and
stores only the hash (`api_idempotency_keys.request_fingerprint`); the raw
code is never stored or logged (rule 37). With the other fields known, a
six-digit code is searchable from the hash. A **spent** code is worthless; a
code that failed validation before verification may still be inside its
time step, so a database reader could in theory recover a live code, but
would still need the account's session or token. A retry with a new code
changes the fingerprint and fails as a key conflict rather than replaying,
which is the documented key contract. Only JSON bodies are fingerprinted
(`RequestFingerprint::jsonBody()`, lines 57-64), so it affects the JSON API
routes with both `idempotent` and an MFA field, not web form posts.
**OTHER-DOMAIN (proposed): Platform**, before staging: exclude `mfa_code`
from the fingerprint centrally. RF1 does not change global idempotency.

### 3.8 WO21-N1: a requirement retired during approval (LOW, small fix)

The window fails closed: the clearance items guard refuses the insert, the
transaction rolls back and nothing commits. The operator sees the guard's raw
database error (a 500) rather than `SEPARATION_REQUIREMENTS_CHANGED`. This is
an operator-experience defect, not an integrity failure.
**CORRECT (proposed, small):** map that SQLSTATE and marker to the existing
refusal, with a two-process test. Alternatively ACCEPT. Owner: HR.

### 3.9 WO21-N3: a missing requirement row (INFO, verified)

`school_os_app` has no DELETE on `separation_clearance_requirements`
(verified read-only on DDEV), requirements are retired rather than deleted,
and `HrWriterBoundaryTest` / `HR_FAMILY_RUNTIME_PRIVILEGES` pin the family
privileges. The silent drop in `requirementsNamed()` is reachable only by an
admin-role DELETE or a future retention design. **ACCEPT (proposed)**, with a
re-review trigger: any retention or deletion design for requirements.

### 3.10 WO17-N2: an extra trigger on the Payroll guard tables (LOW)

Verified read-only on DDEV: `school_os_app` has no TRIGGER privilege on
`payroll_runs`, `payroll_posting_attestations` or
`payroll_reversal_reconciliation_reviews`; the tables are owned by the admin
(migration) role; `school_os_app` is neither superuser nor BYPASSRLS. Adding
a trigger needs DDL as the owner, i.e. a migration. The HR family pins every
trigger (`HR_FAMILY_TRIGGERS`); these three Payroll tables pin their guards'
bodies but not the full trigger set. **DECIDE (proposed: ACCEPT for
development, CORRECT before staging):** extend the verifier's full trigger
set to the Payroll guard tables in a Platform/Payroll unit before staging.
No new verifier policy is added by RF1.

## 4. Individual dispositions (Deliverable A)

Severity is as recorded unless §3 says otherwise. "Dev" is the development
consequence under C27-C: none of these is MAJOR except possibly WO22-N1, whose rating is the owner's (§3.1). Staging and production
consequences are named only where one exists. Every disposition is
**proposed** unless the "Approved" column names a recorded decision.

### 4.1 C35 work-order findings (29 identifiers + RF1-N1 and RF1-N2; 3 closed, 28 open)

| Id | Sev | Finding (short; verbatim in the source) | Owner | Proposed | Approved | Next |
|---|---|---|---|---|---|---|
| WO17-N1 | MEDIUM | Post-decision independence by own account only | Payroll, Identity, Security | VERIFIED CLOSED | D-WO18-1; closed f58b2eeb | none |
| WO17-N2 | LOW | Extra trigger on 3 Payroll guard tables undetected | Platform, Payroll | DECIDE (accept dev; correct before staging) | — | §3.10 |
| WO18-N1 | INFO | Deceased direct end compares the current link only | HR, Identity | DECIDE (recommend CORRECT with WO20-N1: person-level, like every case write) | — | owner |
| WO18-N2 | INFO | Administrative leave submission compares the current link only; the decision is person-level | HR | ACCEPT (submission is not a decision) | — | owner |
| WO19-E1 | test | Weekend-sensitive UI test | — | VERIFIED CLOSED (fixed in WO-19) | — | none |
| WO19-N1 | LOW | Self-service cancellation behind cached `hr.leave.self` | HR, Security | ACCEPT (§3.6) | Codified in rule 134; no explicit acceptance | owner |
| WO19-N2 | LOW | A revoked operator marks the accrual run `failed` | HR | ACCEPT (truthful, resumable, audited) | — | owner |
| WO19-N3 | LOW | MFA code inside the idempotency fingerprint hash | Platform | OTHER-DOMAIN, before staging (§3.7) | — | Platform |
| WO19-N4 | LOW | A long run holds the operator's grants FOR SHARE | HR, Identity | ACCEPT (not a deadlock; same order as `StaffAccessService`) | — | owner |
| WO19-N5 | INFO | Access Center shows `hr.leave.manage` as MFA-assured | Identity UI | DECIDE (small wording correction, or accept) | — | owner |
| WO19-N6 | INFO | Batch, run, adjustment, close proven sequentially only | HR | ACCEPT (shared read; races cover the entry points) | — | owner |
| WO20-E1 | test | Filesystem-order comparison | — | VERIFIED CLOSED (fixed in WO-20) | — | none |
| WO20-N1 | LOW | Current-link-only access answer; unlink turns OPEN into CLEAR | HR, Identity | DECIDE, recommend CORRECT (§3.3) | — | owner |
| WO20-N2 | INFO | School filter is defence in depth behind forced RLS | Identity | ACCEPT | — | owner |
| WO20-N3 | INFO | A retention-purged bound grant is omitted from the role names | Identity | ACCEPT | — | owner |
| WO20-N4 | INFO | `activeSchoolGrantCount()` has no caller | Identity | ACCEPT (public answer kept) | — | owner |
| WO20-N5 | LOW | OPEN on a completed case after a rehire | HR | ACCEPT (§3.4) | — | owner |
| WO20-N6 | LOW | Claimed: completion does not take the link lock (incorrect, §3.5) | HR, Identity | VERIFIED NOT REACHABLE (§3.5: Employee row FOR SHARE vs FOR UPDATE) | — | owner confirms close |
| WO20-N7 | INFO | An older uncorrected account holding no kept grant is not checked | HR, Identity | With WO20-N1 | — | owner |
| WO21-N1 | LOW | Retirement during approval surfaces as a raw error | HR | CORRECT (small), or accept (§3.8) | — | owner |
| WO21-N2 | INFO | View holders see other staff types' requirement labels | HR | ACCEPT (configuration labels) | — | owner |
| WO21-N3 | INFO | `requirementsNamed()` would drop an unknown id | HR | ACCEPT (§3.9) | — | owner |
| WO21-N4 | INFO | Requirement reader's School filter is defence in depth | HR | ACCEPT | — | owner |
| WO21-N5 | INFO | No browser, axe or screen-reader evidence | HR | OPERATIONAL (§7) | — | UAT |
| WO22-N1 | LOW recorded; MEDIUM by ADR 0076 §64 precedent | Raw return without its event (reproduced) | Architecture, HR | CORRECT (§3.1); severity for the owner | — | owner, then WO-23 |
| WO22-N2 | INFO | Registry labels (`ownCaseGuards`, `participants`) loosely fit | Architecture | ACCEPT (documentation precision) | — | owner |
| WO22-N3 | INFO | Cancel/reassign by an account known to be the assignee | HR, Architecture | ACCEPT (rule 152 names decisions only) | — | owner |
| WO22-N4 | INFO | Fresh process: 404 instead of 403 | HR | ACCEPT (both refuse) | — | owner |
| WO22-N5 | LOW | C30 two-process test timed out once | Platform | CORRECT (test-only, §3.2) | — | Platform |
| RF1-N1 | INFO | Raw `submitted` without a `submitted` event (reproduced) | Architecture, HR | With WO22-N1 | — | owner |
| RF1-N2 | LOW | The unlink self-check compares the current link only (§3.3) | HR, Identity | With WO20-N1 (person-level) | — | owner |

### 4.2 C-series findings (75 identifiers: 37 closed, 3 decisions, 35 below)

Closed by recorded evidence (not re-litigated): C10-N1, C11-N1..N3,
C12-N1..N3, C12-N5, C14-N2, C14-N5, C14-N6, C15-N1, C18-A1..A11, C18-A13,
C18-A14, C22-N1, C25-N1, C26-N1, C27-N1..N3, C28-N2, C34-N1..N5. C26-A1..A3
are decisions, not findings.

| Id | Sev | Finding (short) | Owner | Proposed | Approved | Next |
|---|---|---|---|---|---|---|
| C10-N2 | P3 | `end()`+`create()`+`setPrimary()` composes a move outside rule 130's named path (still present by reading) | HR | DECIDE (route through `PlacementChangeService`, or accept as a correction path) | — | owner |
| C10-N3 | P3 | `LeavePageSupport` option list `limit(1000)` with no SQL order (still present, line 50) | Leave, HR | DEFER with XRP-3's trigger (staging or > 1,000 staff) | — | owner |
| C10-N4 | P3 | Coverage guard proves a contract, never assurance | Architecture | ACCEPT (rule 132 requires a step-up test per protected write route) | — | owner |
| C10-N5 | docs | ADR 0034 / PAYROLL.md said Payroll reads HR through Application | Architecture | VERIFIED CLOSED (C23 docs, C24 moves; rule 150) | D-2 | close |
| C10-N6 | docs | E21 determination says links have no history table | E21 record owner | OTHER-DOMAIN (correct the determination record) | — | E21 owner |
| C10-N7 | P3 | ECR `#~#` file: a name can shift fields | Payroll | GATE (with S-4: unreachable until a role holds the statutory key) | — | before any statutory grant |
| C10-N8 | P3 | PAN format not validated | Payroll | GATE (with S-4) | — | before any statutory grant |
| C10-N9 | P3 | Failed fresh-MFA code: no route-specific throttle | Security, Platform | OTHER-DOMAIN, before staging | — | Platform |
| C10-N10 | LOW | Archived-through-bypass Document leaves an active record | Documents, HR | ACCEPT (development data only; no production data exists) | — | owner |
| C11-N4 | note | Rejected/cancelled requests also freeze a leave type | HR | DECIDE (pending since C12 docket :343) | — | owner |
| C12-N4 | docs | "25 files" did not reproduce (24) | Architecture | VERIFIED CLOSED (map §3 corrected by C23) | D-2 | close |
| C14-N1 | P3 | 17 web Payroll actions look up before the capability check | Security, Architecture (C23 names WO-10/Payroll) | DECIDE (owner conflict to resolve) | — | owner |
| C14-N3 | LOW | `ddev test` survives its client; shared test DB | Platform tooling | ACCEPT (operator practice: never run two `ddev test` at once) | — | owner |
| C14-N4 | LOW | A School refusal inside an import is a row result in a remembered 201 | Platform, HR | DECIDE (accept current behaviour) | — | owner |
| C18-A12 | acc. | No manual screen-reader pass (WO-6) | HR + accessibility reviewer | OPERATIONAL | — | UAT |
| C19-N1 | LOW | Communications retention loop stops on a locked row | Communications, Retention | OTHER-DOMAIN | — | Communications |
| C19-N2 | LOW | Supply-chain step hides network vs resolution failure | Platform CI | OTHER-DOMAIN | — | Platform |
| C20-N1 | LOW (axe serious) | Audit-log scroll region not focusable | Compliance UI | OTHER-DOMAIN (three-attribute fix) | — | Compliance |
| C20-N2 | obs. | Month summary 1.4 s, capture 9.3 s at 1,000 | HR, Payroll | GATE (staging performance; D-7 target pending) | — | D-7 |
| C22-N2 | LOW | Body hash strips `--` comments | Platform | OTHER-DOMAIN, before staging | — | Platform |
| C22-N3 | LOW | Legacy holiday withdrawable without history start | HR | ACCEPT (no answer changes) | — | owner |
| C22-N4 | LOW | v1-only run shows "not compared" | HR | ACCEPT | Recorded accepted (C23:325, C27:134) | none |
| C24-N1 | LOW | Malformed id on a statutory API route is a 500 | Platform | OTHER-DOMAIN (WO-14 specified) | — | Platform |
| C26-N2 | obs. | `BacklogSignalsTest` exact-age flake | Platform | CORRECT (test-only, with WO22-N5) | — | Platform |
| C28-N1 | LOW | 115 non-HR INVOKER trigger functions without `search_path` | Platform, domains | OTHER-DOMAIN, before staging | — | Platform |
| C28-N3 | LOW | A weakened body keeping `SET search_path` passes | Platform, HR | OTHER-DOMAIN, before staging | — | Platform |
| C28-N4 | LOW | `retention_role_narrow` false failure on managed PG 16 | Platform | OTHER-DOMAIN, before staging | — | Platform |
| C29-N1 | INFO | Two verifiers may decide one revision differently | HR | ACCEPT (no maker/checker in scope; C33:72) | — | owner |
| C29-N2 | INFO | Verification history unbounded per credential | HR | ACCEPT | — | owner |
| C30-N1 | LOW | VOLATILE read helpers make a 5xx remembered | Platform | ACCEPT (conservative direction) | — | Platform |
| C30-N2 | INFO | Default path: > 30 s request and reclaimer both execute | Platform | ACCEPT (documented contract; rule 33) | — | Platform |
| C32-N1 | INFO | WO-9 browser and axe not run | HR | OPERATIONAL | — | UAT |
| C32-N2 | INFO | Whole-year check measured at 300, not 1,000 | HR | GATE (staging measurement) | — | staging |
| C32-N3 | INFO | Untested unreadable-evidence paths | HR | ACCEPT, or a test-only unit | — | owner |
| C32-N4 | INFO | Report cost grows with never-closed years | HR | ACCEPT (operational: close years) | — | owner |

### 4.3 Original HR.R1-0 identifiers not closed (61 kept: 45 closed, 2 accepted by decision, 1 deferred, 13 below)

| Id | Sev | Status recorded | Proposed | Owner |
|---|---|---|---|---|
| HRC-7 | P3 | Documented residual (C10) | ACCEPT (record the residual as accepted) | HR |
| LVA-7 | P3 | Open, D-7 | DECIDE D-7 | HR, Leave |
| PAY-12 | P2 (staging) | Measured, no target, D-7 | GATE (staging; C27: recorded) | Payroll |
| TRR-5 | P3 | Open, D-9 | DECIDE D-9 | HR |
| TRR-10 | P3 | Open, operational (staging UAT) | OPERATIONAL | HR |
| GOV-1 | P2 | Umbrella | Closes when the rest do (C27:44) | HR |
| GOV-2 | P3 | Partial: S-4 open | GATE (S-4, before any statutory grant) | Security |
| GOV-6 | P3 | Partial: 18 decision-gated, 6 Payroll models | DECIDE (owners of those models) | HR, Payroll |
| GOV-7 | P3 | Open, D-10 | DECIDE D-10 | HR |
| GOV-8 | P2 | E16 valid through 2026-11-28 | GATE (release; review ~2026-11-20) | Security |
| GOV-9 | P2 | Staging infrastructure, restore, encryption | GATE (staging) | Platform |
| GOV-10 | P3 | Accepted by the audit's disposition | ACCEPT (formal acceptance not recorded) | HR |
| GOV-11 | P3 | Open, operational | OPERATIONAL | HR |

The other 48 are closed, accepted or deferred (XRP-3) by recorded decisions; the seven C35 P3s
are reconciled in §8. Register drift noted, not corrected here: REG:51's
"17 named findings" totals, and backlog rows R0-07 ("D-3 open"), R0-17 ("the
rest open") and R0-21 ("XRP-3 open") predate C32 and C35.

### 4.4 Residual rows outside the `C`/`WO` identifiers (added after the review)

From `HR-LEGAL-SECURITY-GATE-REGISTER.md` (residual table, lines 400-462) and
the C24/C25 records. `S-1`…`S-16` and `X-2`/`X-3` are closed or carried
elsewhere (S-4 under GOV-2, S-7 by WO-19, X-1 under R1-02).

| Id | Sev | Finding (short) | Owner | Proposed | Approved | Next |
|---|---|---|---|---|---|---|
| R-HR10-1 | LOW, PARTIAL | A backdated end may leave monthly credits; a closed year refuses the correction, so the credit stays flagged with no ledger path | Leave | DECIDE (accept, or a closed-year correction path) | — | owner |
| R-HR12-2 | MEDIUM | Library, Inventory, Finance clearance not built | Library, Inventory, Finance | DEFER | **DEFERRED by owner (HR.15, 2026-11-04)**; C27-C: recorded, not failing | none |
| R-HR12-3 | LOW | Access kept until off-boarded; completion blocked meanwhile | Identity | — | **Owner decision** (flag, never automatic); see WO20-N1 | WO20-N1 decision |
| R-HR13-1..3 | LOW | Task attachments/ratings; recurrence ignores holidays; missed days never back-generated | HR | — | **Owner decisions** (OD-HR-7, OD-HR-14, R-HR13-3) | none |
| R-HR14-3 | LOW | HR report export (D-11) | HR | — | **Owner decision** 2026-11-05 | none |
| R-HR14-4 | LOW | First report pages slow before ANALYZE (measured 75 s → 49 ms) | Operations | ACCEPT (runbook §6) | — | owner |
| R-HR14-5 | INFO | Rates, headcount and MIS totals stay GATE | Analytics | — | **Owner decision** (ADR 0040); recorded block, not progress | none |
| R-HR16-1 | LOW | Month view ~4.3 s at 5,000 staff | Staff Attendance | GATE (staging performance, no target; with D-7) | — | staging |
| R-HR16-2 | LOW | Separation decisions (fresh MFA), and leave approval, attendance correction and task review (no fresh MFA), not exercised in a browser | HR UI | OPERATIONAL (§7) | — | UAT |
| R-HR16-3 | LOW | No screen-reader pass; native date input segment without focus ring | HR UI | OPERATIONAL (§7) | — | UAT |
| R-HR16-4 | INFO | Legacy path-only HR documents: staging/production count unknown | HR, Documents | GATE (pre-staging inventory) | — | staging |
| M-1 | prerequisite | An unlinked staff parent is not detected by beneficiary refusals | Owner policy | GATE (production policy prerequisite) | — | production |
| C24 R-2 (WO-15) | LOW/INFO | Export names via `employment_records.employee_id`; nothing ties it to `payroll_run_results.employee_id` | Payroll, HR | DECIDE (C25 proposed WO-15: freeze or tie, "needing a Payroll/HR integrity decision") | — | owner |
| C24 R-5 | LOW | The register guard's variable-write heuristic is name-based | Architecture | ACCEPT (stated in the docblock) | — | owner |

WO-14 is C24-N1 (§4.2). A scan of every checkpoint record's review tables
for labels not marked fixed or closed found only C24 R-2, R-5, R-7 (= C24-N1),
C25's R1-01-3 (a decision) and C26's L3 (done).

### 4.5 Inventory gaps found

- `HR-R1-C35-P3-DISPOSITIONS.md` §5 called itself complete but omitted nine
  items whose status is not a closure: C10-N4, C10-N5, C10-N6, C11-N4,
  C12-N4, C14-N3, C14-N4, C22-N2, C22-N3. They are dispositioned above.
- The audit register has no row for C18-A*, C22-N2..N4 or C34-N1..N5; their
  status lives only in the checkpoint records.
- Severity labels disagree in places (C10-N2..N10 "LOW" in C11 vs P3 in the
  register; C24-N1 LOW vs review "R-7 INFO"; HRC-1, HRC-2 and PAY-2 "P1" in
  the closure contract vs P2 rows). No re-rating follows from RF1; all three
  are closed.

- The first RF1 draft searched only `C`/`WO` identifiers and so missed the
  rows now in §4.4; the independent review found them (§11).

### 4.6 Totals

**Totals.** 81 open items need the owner's individual disposition before
baseline selection: 28 in §4.1 (all but the three closed), 32 in §4.2 (all but
C10-N5, C12-N4 and C22-N4, which already rest on recorded decisions), 12 in
§4.3 (all but the GOV-1 umbrella) and 9 in §4.4 (all but the seven items, in
five rows, that rest on owner decisions: R-HR12-2, R-HR12-3, R-HR13-1..3,
R-HR14-3, R-HR14-5). Of these, 30 are routed (OPERATIONAL, GATE,
OTHER-DOMAIN or DEFER) and need only the owner's acknowledgement; the rest are
an acceptance, a choice or a correction.
**Recommended corrections:** WO22-N1 with RF1-N1 (required, §3.1);
WO20-N1 with WO20-N7, WO18-N1 and RF1-N2 (recommended, §3.3); WO21-N1
(small); and the test-only WO22-N5 and C26-N2. Only WO22-N1 is a candidate
MAJOR, depending on the owner's rating.

## 5. CI and test-evidence reconciliation (Deliverable D)

| Unit | Candidate run | Attempts | Outcome | `main` run (code) | Evidence commit CI |
|---|---|---|---|---|---|
| WO-19 `3786968e` | 43512976352 | 1 | Green, first attempt | 43516528492 green | `31a14420`: 43516576887, main 43516962135 green |
| WO-20 `338785ba` | 43531875656 | 1 | **Failed** (WO20-E1); replaced, not rerun | — | — |
| WO-20 `2c66460c` | 43534600597 | 1 | Green, first attempt | 43537113592 **cancelled** (superseded by the evidence push; 7 jobs cancelled, `CI gate` failure as a consequence) | `0a867e21`: 43537173432, main 43537394916 green |
| WO-21 `f03aa6aa` | 43569068523 | 2 | Attempt 1: production-image build failed downloading an upstream archive (`curl: (35) TLS connect error … unexpected eof`); all test shards green. Attempt 2 (failed jobs, same SHA): green. An infrastructure retry | 43571824668 green | `cb57c399`: 43572070979, main 43572267174 green |
| WO-22 `5b78154c` | 43581045054 | 2 | Attempt 1: shard 3 failed one test (WO22-N5). Attempt 2 (failed jobs, same SHA): green. A rerun of a timing-sensitive test | 43583794330 green | `00f194af`: 43583964359, main 43584123640 green; nightly 43591479413 green |

- **Publication contract.** Rule 96 and `publication-check` require a green
  `CI gate` on the exact candidate SHA; each of the four was published on
  one. WO-20's `main` run was cancelled, not green: the exact-SHA candidate
  run is the canonical regression (ADR 0072 §28.8), so publication was valid,
  but no completed `main` run exists for `2c66460c`.
- **Rerun policy.** CLAUDE.md rule 82 says, for a failing full regression,
  "investigate every failure, fix repository defects, re-run the affected
  tests and then the full suite again". Taking the four shards together on
  one SHA arguably satisfies that, but no written rule in ADR 0072 or
  `RELIABILITY.md` says whether a failed-jobs rerun satisfies a *closure*
  regression. The closure contract says only "A green full regression exists
  on the HR.R1 baseline SHA" (§2). **Proposed for C36 (owner decision):**
  the selected baseline SHA needs a complete, first-attempt-green run of the
  whole CI, or, after any failure, a complete rerun of all jobs, with every
  failure dispositioned individually.
- **Assertion drift**, from the CI JUnit artifacts per test:

| Step | Tests | Assertions | Tests removed | Tests added (assertions) | Changed tests (net) | Largest movers |
|---|---|---|---|---|---|---|
| WO-19 → WO-20 | 11,887 → 11,906 | 469,732 → 468,676 | **0** | 19 (88) | 25 (−1,144) | `CrossHostHandoffConcurrencyTest` −837, `ServiceAssertionReplayConcurrencyTest` −288 |
| WO-20 → WO-21 | 11,906 → 11,923 | 468,676 → 469,017 | **0** | 17 (62) | 26 (+279) | `FinanceRetentionConcurrencyTest` −210, `ReportingLineEmploymentEndConcurrencyTest` −79 |
| WO-21 → WO-22 | 11,923 → 11,943 | 469,017 → 466,988 | **0** | 20 (81) | 30 (−2,110) | `CrossHostHandoffConcurrencyTest` −1,485, `ServiceAssertionReplayConcurrencyTest` −873, `StaffNavigationCatalogueTest` +215 |

  The movers assert inside polling loops (`while (… < CONTENDERS) {
  assertLessThan($deadline, …) }`, every 10 ms), so their count follows
  timing; `StaffNavigationCatalogueTest::navigation_never_names_a_role`
  iterates the `roles` table, which other tests' committed fixtures grow, so
  its count follows shard composition. **No test was removed and no test
  was skipped beyond ESI-12 in any step**; the coverage union proved each
  inventory equal to the canonical one. The drift is explained, not a
  coverage loss. (Method: `class::name` keyed comparison of
  `phpunit-junit.xml` from all four shards of each run.)
- **Flaky-test owners:** WO22-N5 and C26-N2, Platform.

## 6. Development database (Deliverable E)

Read-only inspection of DDEV database `db` (admin connection, no write):

- **Ledger:** 398 rows, last batch 9, last applied
  `2027_02_23_090000_bind_separation_retained_access_to_grant_set`.
- **Repository:** 400 files. `comm` of ledger and files: exactly two pending,
  none extra:
  1. `2027_02_24_090000_record_separation_clearance_staff_type_basis` (WO-21):
     two nullable columns on `employment_separation_cases`
     (`clearance_staff_type`, `clearance_skipped_requirement_ids`), a shape
     CHECK satisfied by NULLs, two guard functions and triggers
     (`trg_employment_separation_cases_staff_type_basis`,
     `trg_separation_clearance_items_staff_type_basis`). No data
     transformation; `down()` drops them in reverse.
  2. `2027_02_25_090000_refuse_known_same_person_task_review` (WO-22):
     patches `hr_workforce_tasks_guard` and `hr_workforce_task_events_guard`
     through exactly-once `pg_get_functiondef` anchors. No table or data
     change; `down()` reverses the patches.
  Ordering: 24 before 25; no dependency between them. 24 builds on the
  separation tables (HR.12) and 2027_02_23; 25 patches functions created by
  2027_01_31 (`search_path` pinned by 2027_02_17) and calls `employee_account_link_same_person_accounts`
  (2027_02_19). All of those are applied on DDEV.
- **Data present (counts only):** 10 Schools, 0 separation cases, 0
  clearance items, 0 staff tasks, 0 task events, 1 account link, 2 payroll
  runs, 0 leave ledger entries; one Staff Attendance month lock
  (2026-10-01, locked 2026-11-08). Neither migration can fail on this data.
- **Roles:** `school_os_app` and `school_os_retention` are neither superuser
  nor BYPASSRLS.
- **Authorization:** D-WO20-3 authorized `2027_02_22` and `2027_02_23` only.
  **No authorization exists for 24 and 25; RF1 left the database unchanged.**

**Operator plan (for a separate owner authorization):**
1. Confirm the target: `current_database()` = `db` on the DDEV project;
   record `select count(*), max(batch) from migrations` (398, 9).
2. Record the source SHA (`git rev-parse HEAD` = the published `main`) and
   that `comm` still shows exactly these two files.
3. Before-evidence: counts of Schools, employees, account links, separation
   cases, clearance items, tasks, task events, payroll runs, leave ledger,
   School audit events; md5 of the October 2026 month-lock row;
   `platform:verify-database`.
4. `php artisan migrate --database=pgsql_admin` (rule 54), never `fresh`,
   `rollback` or `db:seed`.
5. After-evidence: ledger 400, the same counts, the same month-lock md5,
   `platform:verify-database` failed=0 (connection-encryption operator row
   expected outside production).
6. Contingency: a forward correction only. Rollback was proven on the
   isolated test database in WO-21 and WO-22; never roll back the
   development database.
7. Record the result in the HR.R1 evidence.

## 7. Operational acceptance (Deliverable C)

| Workflow | API/feature evidence | Browser | axe | Manual a11y | Fixtures in DDEV | Needed |
|---|---|---|---|---|---|---|
| WO-6 Staff Attendance month lock | C18/C20 suites | C20 walkthrough done | C20: 0 violations on the month page | C18-A12 not done | Locked month exists | Screen-reader pass (accessibility reviewer) |
| WO-5 staff calendar | C22 suites | Not done | Not done | Not done | None | Walkthrough + axe |
| WO-9 accrual status | C32 suites | Not done (C32-N1) | Not done | Not done | No Leave configuration (0 ledger rows) | Synthetic Leave data, then walkthrough + axe |
| WO-19 allocation and runs (fresh MFA, refusals) | 26 tests | Not done | Not done | Not done | None | Synthetic Leave + MFA identity |
| WO-20 kept access bound to roles | 19 tests | Not done | Not done | Not done | 0 separation cases | Synthetic separation cases |
| WO-21 unclassified staff type | 17 tests | Not done (WO21-N5) | Not done | Not done | 0 cases | Synthetic cases |
| WO-22 task review | 20 tests | Not done | Not done | Not done | 0 tasks | Synthetic tasks, two staff, linked accounts |
| School audit log | — | — | C20-N1 (serious) | — | — | Compliance fix |

Earlier HR evidence stands: HR.16's 53-step walkthrough and axe on 22 pages
(0 violations) at its own SHA, not re-run since.

**Fixtures.** The development database holds no separation case, task or
leave ledger entry, so none of the C35 workflows can be walked without new
synthetic data. RF1 created none. `ddev demo-reset` replaces business data
and must not be run without the owner's word. **Owner authorization needed**
for either a separate synthetic School (preferred: no change to the existing
Schools, removable afterwards) or a demo reset, naming the records to
create, an MFA test identity, and the cleanup.

**Walkthrough plan** (per workflow, to execute only once authorized; none
has been run): prerequisites (School, two staff with linked accounts, a
reviewer with the capability, enrolled MFA); the operation; the expected page
and refusal text (e.g. `TASK_SAME_PERSON_REVIEW`, the fresh-MFA prompt, the
"Unclassified" label and named skipped requirements, OPEN with the role
names); the persisted state (status, event row, audit event); evidence
(screenshot, axe JSON, keyboard path); cleanup (archive the synthetic School;
nothing is deleted from audit history).

**Runbook gaps** (`docs/operations/HR-OPERATIONS.md`): §5 does not describe
WO-20's bound role set (OPEN when a role outside the set appears; legacy
records are resolved by off-boarding, never by recording again, D-WO20-1),
WO-21's "Unclassified" case and skipped requirements, WO-19's enrolled-factor
prerequisite and the `failed` run after an operator's revocation (WO19-N2),
or WO-22's review refusals. Proposed: a documentation unit, not RF1.

## 8. The seven original P3s and the four work orders

| Finding | Recorded disposition | Evidence verified in RF1 |
|---|---|---|
| HRC-4 | Closed for development, WO-20 (`2c66460c`) | REG:181; D-C35-2, D-WO20-1/2; residual WO20-N1 |
| HRC-8 | Closed for development, WO-21 (`f03aa6aa`) | REG:185; D-C35-4, D-WO21-1 |
| LVA-4 | Closed for development, WO-19 (`3786968e`); S-7 closed | REG:193; D-C35-3; gate register :409; residual WO19-N1 |
| TRR-6 | Closed for development, WO-22 (`5b78154c`) | REG:223; D-C35-5; residual WO22-N1 |
| XRP-3 | Deferred to a TeachingAssignments checkpoint | D-C35-7; trigger "a School above 1,000 staff, or staging" (P3D:44, :196). Not a staging approval |
| XRP-4 | Accepted known limit | D-C35-8 |
| TRR-8 | Accepted, documented behaviour | D-C35-6 |

Each work order's record lists its tests, planted probes, rollback proof
(where a migration shipped), independent review and exact-SHA CI; RF1 found
no contradiction between those records, the code at `00f194af` and GitHub's
run history (§5). The outstanding browser, axe and screen-reader evidence of
each is §7.

## 9. Boundaries carried unchanged

- **R1-01:** the HR-family one-writer contract holds (`HrWriterBoundaryTest`,
  `hr_family_runtime_privileges`), green in every candidate run above. No new
  family writer since C26.
- **R1-02: PARTIAL.** Payroll's part done (C24); X-1's three controllers and
  the 24 non-Payroll files remain (C24 :153). C10-N3 is one of them.
- **R1-09:** NOT_INTEGRATED is shown, never CLEAR, never blocking (C27-A);
  Library, Inventory and Finance stay deferred (2026-11-04).
- **C33-H1..H5** (Fees, Payments, Communications, Examinations, Erasure):
  handoffs outside HR.R1's development scope; each blocks only its own
  domain's known-same-person extension.
- **RG-ID-1:** open. The development-level known-same-person enforcement does
  not satisfy it; a synthetic declaration is never release evidence.
- **Legal/DPO:** E21 (incl. D9, "pending final legal/compliance
  ratification"), E45/ESI-12 ("not sent, not answered"), E47, HRX-L1..L4,
  S-4 (latent, before any statutory grant), X-1 (remaining parts), ADR 0036,
  ADR 0040, D-11: unchanged. S-7 is closed for development by WO-19; its
  production use needs an enrolled factor (D-C35-3).
- **Platform findings** (C28-N1/N3/N4, C22-N2, C24-N1, C30, WO19-N3,
  C10-N9) stay Platform-owned.
- **Capability map** (recounted mechanically from the §4 status column,
  361 rows): IMP 163, GATE 87, DEF 68, ALT 22, PART 8, XDOM 7, NR 6,
  MISSING 0, as recorded. No row changes in RF1.
- **Reference pack:** fingerprint
  `40ed6a836f5a99c65694118c0481bc468b1fb6ebb379a0275cc33839fa7a1ac3`
  (C16) unchanged in the map; not re-captured.
- **61 audit identifiers:** all present.

## 10. Five-part baseline readiness (Deliverable F)

| Dimension | Candidate | Ready? | Blocking prerequisites |
|---|---|---|---|
| Source | `00f194af` (`main`), runtime `5b78154c` | **No** | The WO22-N1 correction (or a recorded owner rating and deviation); owner dispositions of the 81 items (D-C35-10); other approved corrections published; D-7, D-9, D-10 |
| Database / migrations | Repository 400 migrations; DDEV 398 | **No** | Owner-authorized forward migration of 24 and 25 (§6), then verifier evidence |
| Reference pack | C16 fingerprint | Yes, for selection | None (unchanged) |
| Legal/Security evidence | Gate register at `00f194af` | Yes as an evidence snapshot | Selecting it authorizes nothing; every gate stays shut |
| Local environment | DDEV (Docker Desktop), `school_os_test`, Mailpit, object storage per environment | **No** | Migrations; synthetic fixtures for UAT (§7); real-object-storage tests run in CI only |

Prerequisites before the owner may select: (1) individual dispositions of
§4 approved; (2) any chosen corrections published with exact-SHA CI;
(3) D-7, D-9, D-10 decided or explicitly deferred; (4) the development
database forward-migrated and verified; (5) the final regression rule (§5)
decided; (6) whether browser/axe/screen-reader evidence is required before
selection or before UAT sign-off (C35-P3:169 says "needed before UAT
sign-off", which RF1 reads as not a baseline prerequisite).

## 11. Independent review

An independent reviewer (read-only, at `00f194af`) examined this record
against the source records and the code. It is a review, not an owner,
Security or Legal approval. Its findings and their treatment:

| # | Reviewer's rating | Finding | Treatment |
|---|---|---|---|
| 1 | MEDIUM | The inventory searched only `C`/`WO` identifiers and missed C24 R-2 (WO-15), R-5, R-HR10-1, R-HR16-1..4, R-HR14-4, M-1 and R-HR12-2 | **Corrected:** search widened (§1), rows added (§4.4), "every outstanding finding" now holds for the widened search |
| 2 | MEDIUM | WO22-N1's LOW ignored ADR 0076 §64's "MEDIUM integrity gap" precedent; the task-row clause does nothing for a return | **Corrected:** reproduced (§3.1), precedent and distinctions recorded, ACCEPT withdrawn without a deviation note, verdict changed |
| 3 | MEDIUM | WO20-N1's "not an escalation" omitted R-HR12-3, which an unlink defeats; the claim that the unlink refuses "one's own record" was false (current link only) | **Corrected:** R-HR12-3 cited, ACCEPT framed as its amendment, RF1-N2 added |
| 4 | LOW | WO20-N6's lock facts are wrong: completion holds the Employee row FOR SHARE and link changes take it FOR UPDATE | **Corrected:** verified in the code; VERIFIED NOT REACHABLE (§3.5) |
| 5 | LOW | Section counts contradicted each other; "113 identifiers" did not reproduce | **Corrected:** 104 identifiers; every header recounted |
| 6 | INFO | 2027_02_25's dependencies; rule 82 on reruns; fingerprint covers JSON bodies only; P3D:43 supports WO19-N1's reading | **Corrected** in §6, §5, §3.7, §3.6. Its suggestion that WO22-N1 sits at :103 was checked and not taken (:104 is right) |

**Confirmed by the reviewer:** the quotations and severities of 28 spot-checked
identifiers; every "Approved" entry has a recorded basis (no owner decision
invented); WO19-N1, WO19-N3, WO21-N1 and WO17-N2 stand as LOW (the runtime
role has no TRIGGER privilege by default, `01-roles.sql:42-43`); the
assertion arithmetic; 400 migrations. **No development MAJOR demonstrated;**
WO22-N1 becomes one if the owner applies §64 literally. The reviewer's
verdict on the first draft was "CORRECTIVE WORK REQUIRED (documentation)",
to return to OWNER DECISIONS PENDING once corrected. After the corrections,
this record's own verdict (§12) is CORRECTIVE WORK REQUIRED because of
WO22-N1's runtime correction, not the documentation.

**Final-state recheck** (a second independent read-only pass over the
corrected record): every §11 correction is reflected and its facts are
true; arithmetic 104 identifiers and 28 + 32 + 12 + 9 = 81 open, 30 routed
(after its correction of R-HR14-5); no invented approval. Its one MEDIUM (an
unqualified "none of these is MAJOR" in §4's introduction) and seven LOW
(register line numbers, D-C35-8's exact wording, the WO20-N6 row text, the
R-HR14-5 owner and decision, the R-HR16-2 wording, §4.3's "deferred", the
§4.4 count wording) and three INFO (2027_02_17 only pins `search_path`;
section order; "working tree clean") were all corrected.

## 12. Verdict and next checkpoint

**RF1 COMPLETE — CORRECTIVE WORK REQUIRED.**
- **The correction:** WO22-N1 (with RF1-N1) is reproduced. Rule 102 says such
  an evidence-row rule is enforced in the database, and the §64 precedent
  rates the same gap MEDIUM. A bounded correction is required before baseline
  selection, unless the owner records a LOW rating with a rule 15 deviation.
- **No HIGH; no MAJOR established.** Whether WO22-N1 is MAJOR is the owner's
  rating.
- **Also pending:**
  - the owner's individual dispositions of 81 open items (§4), including
    D-7, D-9, D-10 and WO20-N1 against R-HR12-3;
  - the development-database migration (§6);
  - the fixture authorization and the operational evidence (§7).
- **HR.R1 stays OPEN;** the baselines stay unselected.

**Recommended next:** **HR.R1-C35-RF2 — owner dispositions and the WO22-N1
severity decision**, one decision session, followed by **WO-23**, one bounded
correction unit: WO22-N1 and RF1-N1, plus whichever of WO20-N1 (with
WO20-N7, WO18-N1, RF1-N2), WO21-N1 and the test-only WO22-N5 and C26-N2 the
owner approves. HR.R1-C36 (baseline selection) is not ready. Nothing is
started.
