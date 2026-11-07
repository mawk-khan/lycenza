# HR test strategy (HR.0A)

Status: **authoritative test strategy for HR.1–HR.16 and HR.R1** (HR.0A,
2026-11-03). Each phase brief may tighten it, never loosen it.

It applies CLAUDE.md rules 13, 14, 28, 78, 82 and 96 to HR. Nothing here
is new infrastructure. Every suite runs on the existing harness:
- real PostgreSQL (ADR 0024);
- `apps/platform/bin/safe-test`;
- `TestCase::purgeSchoolsAsAdmin()`;
- two-process concurrency tests (the
  `AcademicYearActivationConcurrencyTest` pattern);
- the remote exact-SHA `CI gate` in shards (ADR 0072 §31).

## 1. Layers required per phase

| Layer | When required | Pattern to copy |
|---|---|---|
| Unit (pure) | every evaluator, calculator or traversal (shift evaluation, leave unit calculation, accrual, hierarchy depth) | `WorkingDayCalculator`, `CarryForwardCalculator` |
| Domain / service | every Application service method | existing HR / Leave service tests |
| Feature (web + API) | every route, **allow AND deny** (rule 13) | `tests/Feature/HR/*` |
| Authorization coverage | every School route has a contract (rule 98) | `SchoolRouteCoverage`, `CoverageGuard` |
| Campus scope | every Campus-adopted key: in-scope, out-of-scope 404, NULL-Campus whole-School only | `HrEmployeeAccess` tests, rule 101 |
| Tenancy | School A cannot read or write School B: Eloquent **and** raw SQL/RLS; missing context fails closed (rule 28) | `tests/Feature/Postgres/RawIsolationTest.php` |
| Database invariants | every CHECK, trigger, append-only guard and composite FK is tested by a raw write that must fail | Leave ledger guard tests |
| Concurrency | every new lock or overlap rule: two real processes | `AcademicYearActivationConcurrencyTest`, placement-lock tests |
| Idempotency | every retryable consequential mutation (rule 29); every allocation or import run | `leave_allocation_runs_once` tests, `EnsureIdempotent` |
| Architecture guards | ownership, no-dependency, no-forbidden-column, catalogue pins | `AttendanceArchitectureGuardTest`, `ModuleCatalogueArchitectureTest`, `ApprovalWorkflowRegistryTest`, `StaffRoleCatalogueSnapshotTest` |
| Retention | every new table declares its unit; purge and hold tests | E21 / ADR 0066 tests |
| Query budget | every list, report and Staff 360 page: constant queries as data grows | `StudentProfile360Test` |
| Demo | `ddev demo-reset` seeds through canonical services; pins updated (`CROSS-CUTTING-CHANGE-CHECKLIST.md`) | `DemoSisDataTest` |
| Documentation guards | `--group repository-documents` | CI docs lane |

## 2. Deterministic scenario catalogue

Every scenario uses fixed dates (never "now" unless a clock is frozen),
the School-local date (`SchoolTimezone::today()`), and fictional data.

### 2.1 Workforce (HR.1, HR.2, HR.12)
- An employee joins mid-year: `CurrentEmployeeAssignments` excludes them
  before `starts_on`, and the directory "Current" never lists a future
  hire (fixes ORG10-D01).
- **Campus transfer** as one named operation:
  - the old assignment ends yesterday and the new one starts today;
  - a Campus actor needs both Campuses in scope;
  - the reason and actor are recorded.
- **Department transfer, designation change and promotion:** the same
  mechanism, with a closed change kind and reason.
- **Category or employment-type change** (X-3 fix): a new effective-dated
  row. The as-of-date read returns the old value before the change.
- **Inactive / separated:** assignments close and teaching ownership ends
  (ADR 0063 §47). History is intact and nothing is deleted.
- **Historical report as of date D** returns the assignment, category and
  manager effective on D, never the current one.
- **Rehire:**
  - a new employment, same Employee and same number;
  - the previous employment's history is untouched;
  - two overlapping employments are refused by the database (S-10
    backstop) and in two-process tests.

### 2.2 Reporting hierarchy (HR.3)
- Traversal at depths 1, 2, 5 and 12 (arbitrary depth, no fixed level
  columns).
- **Cycles:**
  - A→B→C→A is refused at the service, by the database trigger, and
    under a two-process race (each transaction sets one edge);
  - A→A is refused by CHECK.
- **Manager changes mid-period:**
  - `managerOf(D)` returns the old manager before the change and the new
    one after;
  - a Leave decision uses the manager effective at decision time.
- **Temporary delegation** (only after the ADR 0065 §6 amendment):
  - it applies inside its window only;
  - the delegate cannot decide their own request.
- **Manager becomes inactive:** the reports surface as "no current
  manager", never silently re-parented.
- **Cross-School manager:** refused by the composite FK and by RLS (a raw
  insert must fail).
- **Reporting manager ≠ data access:** a manager without
  `hr.employees.personal.view` sees no personal section of a report.

### 2.3 Shifts and work calendar (HR.4; gated by HRX-L2a)
- Normal day shift; overnight shift (22:00–06:00), attributed to the
  shift's start date.
- A temporary shift overrides the base shift inside its window only.
- Week-off and holiday, at Campus level and at School level; Campus wins
  where both exist.
- A retroactive shift correction creates a new version, and affected days
  are re-evaluated explicitly (never silently).
- Overlapping shift assignments for one employment are refused (trigger
  plus lock, the student-group no-overlap pattern; this project does not
  use `btree_gist`).

### 2.4 Attendance (HR.5–HR.7; clock evidence gated)
- One in / one out; multiple punches (first-in / last-out); missing out;
  missing in.
- **Duplicate provider event:** the unique `(school_id, source_id,
  provider_event_id)` key makes the second insert a no-op replay.
- **Late-arriving import:** an event for an already evaluated day
  triggers deterministic re-evaluation with a new evaluation version.
- **Overnight attendance:** a 23:50 in and a 06:10 out belong to the
  previous day's shift.
- **Timezone boundary:** events are stored in UTC and evaluated in the
  School timezone; a 23:30 local punch never moves to the next date.
- **Classification:** late arrival and early leaving (threshold and
  grace), permission-covered lateness, leave, holiday and week-off.
- **Manual correction:** the source event is untouched; an append-only
  correction row is written; the evaluation is recomputed.
- **Locked period:** a correction inside a payroll-consumed period is
  refused, or goes through the controlled reopening path.
- **Recalculation idempotency:** evaluating the same inputs twice gives
  identical results.

### 2.5 Leave (HR.9–HR.11)
- Balance:
  - sufficient balance; insufficient balance (refused);
  - unpaid leave (no balance tracked).
- Accrual:
  - monthly accrual;
  - joining-date proration;
  - **rerunning an accrual or allocation is idempotent** (unique run key,
    no double credit).
- Carry-forward: cap; in-year expiry (now executed).
- Request timing:
  - backdated request inside the window, and outside it (refused);
  - a future request that violates notice (refused);
  - maximum duration per request.
- Attachments: a mandatory-attachment rule stays **BLOCKED_BY_GATE**
  (HRX-L1). Its test asserts refusal, not acceptance.
- Eligibility:
  - department and category eligibility;
  - gender and marital eligibility are tested **only if** HR-L8 is
    decided; until then a guard test asserts no such field exists.
- Holiday treatment: sandwich, prefix and suffix rules; holiday
  inclusion.
- Workflow and ledger:
  - cancellation reverses the ledger;
  - a rejection never debits;
  - an approval debits once;
  - a policy change after the request leaves the request's frozen units
    unchanged;
  - annual rollover;
  - the identity `opening + credits − debits ± adjustments = closing`
    holds per account and is reproduced from the ledger.

### 2.6 Compensatory off (HR.11)
- A credit requested for a worked holiday or week-off; the date must be a
  non-working day for that employment.
- Approval posts a `comp_off_credit` ledger entry; a rejection posts
  nothing.
- Expiry posts `comp_off_expiry`.
- Use consumes like any leave.
- Cancelling an unused credit is a reversal entry, never an edit.

### 2.7 Security (every phase)
- An employee cannot open another employee's confidential record: private
  404, identical to an unknown id.
- A manager sees only reports in scope, and only the columns their own
  capabilities allow.
- **A manager relationship never grants payroll, bank, family or health
  data.**
- HR permissions are Campus-scoped exactly as declared.
- Exports and custom columns:
  - an export needs its dataset capability;
  - **column availability is decided on the server**, so a forged column
    is a 422;
  - an export never exceeds the screen.
- Cross-tenant: id guessing across Schools, manager across Schools,
  attendance, leave, reports, exports, biometric identity and staff ↔ SIS
  links.
- Direct-object id attacks on every `{id}` route.

### 2.8 Audit (every phase)
- Every significant change writes exactly one audit event:
  - actor, event type, subject, request id;
  - closed reason codes;
  - **changed field names, never values**.
- Before and after values live in the owning evidence table (corrections,
  versions, ledger), never in audit metadata.
- Sensitive changes (bank, compensation, biometric mapping, identity) are
  audited with field names only.
- Evidence tables refuse runtime UPDATE and DELETE.

## 2a. Implemented suites (HR.1A)

| Suite | Proves |
|---|---|
| `tests/Feature/HR/Workforce/WorkforceDatabaseInvariantsTest` | raw runtime-role writes: inclusive adjacency vs one shared day; open + later employment refused; primary overlap refused while secondaries may overlap; the first terms created by the database; projection never written directly; terms append only; runtime has no UPDATE or DELETE on history; a placement change bound to its employment and dates; Employee number immutable; a Department cycle refused; history tenant-isolated (RLS + composite key) |
| `tests/Feature/HR/Workforce/WorkforceConcurrencyTest` | two real processes, raw writes, contender proven blocked: overlapping employments commit once (23P01); overlapping primaries commit once (23P01); opposite reparents never cycle (23514) |
| `tests/Feature/HR/Workforce/PlacementChangeServiceTest` | promotion + transfer keeps the old placement and history answers both dates for ever; kind must match what changes; department and designation changes; retroactive transfer; source must be the primary covering the date; manager kept and reports re-pointed; future change with reports refused; ended employment refused; self refused; unauthorized refused; Campus-scoped: in scope, from today only |
| `tests/Feature/HR/Workforce/EmploymentTermsAndHistoryTest` | terms appended and answered on any date; projection follows; audit metadata; no rewrite of history or the future; ended employment keeps its terms; whole-School only and never one's own; staff type closed and never derived; starting statuses (S-8); a separated / future / draft / current employment answered correctly; batch query count constant; S-11 |
| `tests/Feature/HR/Workforce/WorkforceWebTest` | terms and placement routes allow/deny/422/404; Profile history and catalogues only for the employment tier; every web path that starts an employment needs a staff type; API PATCH refuses terms and no future end over HTTP; account kind (S-15); future hire is pre-joining, never current (ORG10-D01); verifier `hr_workforce_invariants` |

## 2b. Implemented suites (HR.3A)

| Suite | Proves |
|---|---|
| `tests/Feature/HR/Reporting/ReportingRelationshipServiceTest` | a dated line answered from its date only; a mid-period change keeps both answers and is audited (ids and codes); future-dated change; removal; a change never rewrites a later line; both employments bound it; cross-School, self and same-Employee refused; A→B→A, A→B→C→A, five-node and later-starting loops refused, a reversal over disjoint dates allowed; depth 12 chain, depth, tree; constant-query batch lookup; transfer and assignment end leave lines alone; employment end closes both sides and cancels later lines (no re-parenting); correction voids and replaces over the same dates; unauthorized refused; no authorization table touched |
| `tests/Feature/HR/Reporting/ReportingDatabaseInvariantsTest` | raw runtime writes: one line per day, adjacency, empty interval; self (CHECK) and same Employee (trigger); within both employments; composite same-School key; date-aware loops; immutable parties and start, narrowing end, final void, never created voided; shape CHECKs; employment dates never move past a live line; no runtime DELETE; RLS and fail-closed; pointer gone; verifier `hr_reporting_invariants` |
| `tests/Feature/HR/Reporting/ReportingConcurrencyTest` | two real processes, contender proven blocked: two raw lines for one subordinate commit once (23P01); opposite raw lines never loop (23514); two service assignments serialize into one line per day; an assignment waiting on an employment end is refused; a manager employment end waiting on an assignment closes the new line; a reporting change waiting on a transfer lands on the unchanged employment |
| `tests/Feature/HR/Reporting/ReportingMigrationTest` | cut-over backfill from the current pointer only (no fabricated history); conflicting pointers refuse and change nothing; rollback keeps current lines as pointers |
| `tests/Feature/HR/Reporting/ReportingWebTest` | web set-manager needs a closed reason, takes an effective date, removes; API answers the line; correction is whole-School only (Campus and viewer refused, foreign line 404); Profile trail and reasons; Campus officer from today only |

## 2c. Implemented suites (HR.2A)

| Suite | Proves |
|---|---|
| `tests/Feature/HR/Profile/StaffProfileDatabaseInvariantsTest` | raw runtime writes: an archived record is frozen, never created archived, closed reason, all-or-none shape; no runtime DELETE on any profile table; an archived address frees its kind, an archived contact is never primary; reference codes closed, normalized, School-unique per kind, never reused, one live per kind, immutable, retired final; HR document records: no new raw path, closed category, identity always Highly Sensitive, same-School Document, file never changes, archived final; reference codes RLS-isolated and fail closed; verifier `hr_profile_invariants` |
| `tests/Feature/HR/Profile/StaffProfileFoundationTest` | reference codes: normalized, never reused, exact search, retired finds nobody, audit without value, whole-School only, never one's own; archive with a required reason, kept as evidence, frozen; self-administration over identity, personal, address, archive, document; stale/missing version 409/422 (service, API); contact normalization and phone validation; governed upload (closed category, inert formats only), download authorized by tier, foreign record 404, archive archives the Document; derived experience (merge, open-ended), absent parts for a narrower reader; manager, teacher, payroll and platform root get no profile; Employee without a User, rehire keeps one identity; constant queries; Campus officer cannot reach another Campus's Employee |
| `tests/Unit/HR/ExperienceCalculatorTest` | overlap and adjacency merged, open-ended excluded and reported, future cut at today, service adds rehires without the gap |

## 2d. Implemented suites (HR.9)

| Suite | Proves |
|---|---|
| `tests/Feature/Leave/LeavePolicyRulesTest` | a version without rules keeps today's behaviour; notice counted in calendar days from the School-local date (0, 2 refused, 3 accepted); backdating bounded, 0 = never, the future is not backdating; maximum per request counts chargeable half-days only (a weekend is not counted: no sandwich); every version assigned on the requested dates must hold, and the judged ids are audited; self-service held to the same rules; approval does not re-judge, a replacement version never rewrites approved days or repoints the assignment; service and CHECK validation; rule columns immutable for the runtime role |
| `tests/Feature/Leave/LeaveEligibilityTest` | rules read back with their version; overlap with another active version refused, never ranked (disjoint types accepted, replacement takes over, retired rules inert, other leave type independent); duplicate / too many / inactive or other-School Category or Department refused; raw writes: no rule added to a saved version, no UPDATE or DELETE, overlap refused, declared count complete at commit; outcomes from HR.1A facts on the date (staff type, Category, Department, unclassified never guessed, separated, planned, own, draft never listed); an all-any rule matches the unclassified; two matching versions AMBIGUOUS; an effective-dated Category change moves the answer from its date only; a transfer shows drift but never moves an assignment or balance; the run assigns only the eligible with provenance, ends with the employment or before a later assignment, skips own and unmatched, re-runs assign nothing; a rehire inherits nothing; preview writes nothing and reports an overlap; capability allow / deny per operation; School isolation; inactive type and invalid date refused; verifier `leave_policy_invariants` |
| `tests/Feature/Leave/LeaveEligibilityConcurrencyTest` | two real processes, contender observed blocked: two runs assign each employment once; two overlapping versions never both become active; a run waiting on a supersede resolves against the committed replacement; a run waiting on an employment end skips it |
| `tests/Feature/Leave/LeaveEligibilityApiTest`, `tests/Feature/App/LeaveEligibilityUiTest` | typed input (unknown rule keys and a `gender` key refused, 422), each operation's capability allowed and the others denied, `no-store`, the run replays on its Idempotency-Key and conflicts on a different payload, one tenant-safe 404, a timing refusal is a stable code; the editor's dimensions, a refusal as a form error, the JSON preview for configure only, the page for view and the run for manage |
| `tests/Feature/Postgres/LeaveRlsIsolationTest` (extended), `LeaveArchitectureGuardTest` (extended), `LeaveOpenApiCoverageTest` (43 operations) | forced RLS and fail-closed reads on both new tables, cross-School rule / Category / run refused structurally; no HR-L8 column; the contract matches the live routes |

## 2e. Implemented suites (HR.10)

| Suite | Proves |
|---|---|
| `tests/Feature/Leave/LeaveAccrualTest` | the method is annual XOR monthly (service, CHECK, frozen); whole-month eligibility with disjoint outcomes (joiner and leaver = part month, never prorated; mid-month assignment; unassigned); preview writes nothing; provenance (month, run, assignment); a re-run credits nothing and reconciles; timing (in advance / arrears), future and invalid months; funding exclusivity (allocate refused, annual run skips, DB `leave_funding_conflict`); the DB checks units, month, year and duplicates; run evidence immutable, completed run final; a closed year receives nothing; dated facts for transfer (drift flagged, not acted on), supersession (assigned version prices), an ended employment and a rehire; policy-driven run includes the operator, opening never; accrued leave consumed, refused when insufficient, cancelled, statement identity; capability allow / deny, School isolation, audit keys; the grid shows posted months and never a fabricated zero |
| `tests/Feature/Leave/LeaveOpeningAdjustmentTest` | an opening once, only first, never one's own, manage only; an adjustment needs a funded account, may name an entry of the SAME account, never edits it, never goes negative; web and API need a fresh MFA code after the capability (missing, wrong, viewer 403); Entitlements statements and the Accruals page render; approver-only denied |
| `tests/Feature/Leave/LeaveAccrualLargeRosterTest` | 2,060 employments (above HR.9's former cap): HR.9 applicability counts and assigns all; an interrupted run (3 batches), a failing batch (rolled back alone, run `failed` with a code), a resume of the SAME run to completion; examined = the disjoint outcomes; every eligible employment credited exactly once; a re-run credits nothing |
| `tests/Feature/Leave/LeaveAccrualConcurrencyTest` | two real processes, contender observed blocked: two runs of a month credit each account once; an employment end inside the month and an assignment end inside the month are never credited; a run waiting on a year close refuses (`LEAVE_YEAR_CLOSED`) and is marked failed |

## 2f. Implemented suites (HR.11, partial)

| Suite | Proves |
|---|---|
| `tests/Feature/Leave/LeaveLineManagerTest` | the `line_manager` role carries exactly `hr.leave.approve`; a line manager decides a current report's request (inbox count, filters narrow, minimal labels, one consumption, recorded decision with name and path) and keeps it after a reporting change; no line, no role, former / future / separated manager refused, the request stays pending for the administrative path; a Campus-only grant never decides; platform root is refused; never one's own request; the inbox pages without skips or repeats |
| `tests/Feature/Leave/LeaveDecisionSeparationConcurrencyTest` | two real processes, contender observed blocked: approval versus the requester's separation (refused, nothing consumed); approval versus the manager's separation (private 404, still pending) |
| catalogue pins (`StaffRoleCatalogueSnapshotTest`, `ProductionStaffRolesTest`, `StaffRoleGrantAuthorityTest`, `StaffRoleCatalogueHardeningTest`) | 18 roles; `line_manager` = add-on; granted by School Admin through the real SR.2 rule without a grant right |

## 2g. Implemented suites (HR.14, HR.16)

- `HrReportsTest`, `HrReportsLargePopulationTest` (2,100), `HrReportTemporalSemanticsTest` (status on a date, as-posted leave, exact School-local boundaries ahead of and behind UTC).
- `SeparationAccessCertificationTest`: two real processes, contender observed blocked (`ForcesConcurrentOverlap`); fails without the lock.
- `StaffAttendanceRegisterPagingTest` (2,100, no cap).
- Browser and axe evidence: ephemeral Playwright + `@axe-core/playwright` outside the repository (owner decision 2026-11-05), recorded in `HR-16-READINESS.md` §4; no browser tooling is a repository dependency.
- Concurrency tests whose child processes use the real clock take dates from `SchoolTimezone::today()`, never the UTC date.

## 2h. Implemented suites (HR.R1-C7)

- `Tests\Feature\HR\EmployeeAccountLinkHistoryTest`: the link, unlink, relink and correction contract on web and API.
- `Tests\Feature\Postgres\EmployeeAccountLinkRawRoleTest`: as the real runtime role, committed, with positive controls.
- `Tests\Feature\Payroll\PayrollHistoricalLinkTest`: the Payroll conflict matrix, asserting database state.
- `Tests\Feature\Payroll\PayrollAccountLinkConcurrencyTest`: two real processes with the contender observed blocked, in both orders, including link against calculation.
- `Tests\Feature\Retention\PayrollAccountLinkRetentionTest`: through `platform:payroll-retention-prune`, `platform:employee-retention-prune` and a real User erasure case.

## 2i. Implemented suites (HR.R1-C8)

- `Tests\Feature\HR\EmployeeAccountLinkCorrectionTest`: the two-person contract on service, web and API.
- `Tests\Feature\Payroll\PayrollCorrectionTargetTest`, `PayrollCorrectionTargetConcurrencyTest`: PAY-17, its ledger effect, and each order of correction against reversal in two processes.
- `EmployeeAccountLinkRawRoleTest` and `PayrollAccountLinkConcurrencyTest` gained forged decisions and correction races. `PayrollAccountLinkRetentionTest` gained dead corrections, holds, and the pinned retention checks.
- `PayrollReconciliationLargePopulationTest` runs discovery at 10,000 entries under a statement timeout that only a quadratic plan reaches. Latency itself is measured, not asserted: `docs/hr/PAY-REVERSAL-PERFORMANCE-EVIDENCE.md`.

## 3. Regression cadence

- **Per phase:**
  - focused suites first (rule 14);
  - then the relevant integration suites (Leave ↔ StaffAttendance ↔
    Payroll for HR.6–HR.11);
  - then the static gates (Pint, Larastan, vue-tsc, ESLint, Prettier,
    build).
- **Full regression:**
  - the code-lane candidate's remote exact-SHA `CI gate` (rule 96);
  - additionally a local `bin/safe-test` full run at every
    cross-domain phase (HR.1, HR.3, HR.6, HR.10, HR.12, HR.15, HR.16).
- **Environment-specific tests** (MinIO, real S3) are reported
  separately, never skipped to make a run green.
- **Baseline failures** are distinguished from new failures with
  evidence: the same test red on `main` at the candidate's merge base.

## Lock-order proofs (HR.R1-C9)

A two-party race cannot force the order that matters when a lock order is the
defect. `ReportingLineEmploymentEndConcurrencyTest` uses a third session to hold
the contended row, so one party queues on it first and the other queues behind.
Releasing it forces the cycle every time. Unfixed, each of the four orders gave
`40P01` on each of 2 runs. Prove a lock-order correction this way, on the unfixed
code first, and race it against every writer of the rows it newly locks: the first
C9 correction passed against `end()` and deadlocked against a placement change.

## Lifecycle models and writers (HR.R1-C11)

- **A dropped key fails.** Eloquent silently drops a non-fillable key. `TestCase` turns
  that into a failure for `Tests\Support\LifecycleModels::HARDENED` only: the wider
  setting hits unrelated fixtures and hides real results.
- **An admin read cannot see this class's fixtures.** In a transacting test class, a read
  through `pgsql_admin` sees nothing, so an `assertNull` there passes for the wrong
  reason. Read under the School's context, or use a committed-data class.
- **A new HR writer** goes through `SchoolOperationalGuard` and belongs in
  `HrExecutionTimeSchoolCheckTest`; a second writing class goes in
  `HrWriterBoundaryTest::NAMED_WRITERS`. Those pins scan source, so they show where the
  code writes, and the privilege assertions beside them are the runtime boundary.
