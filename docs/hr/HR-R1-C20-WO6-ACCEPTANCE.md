# HR.R1-C20: WO-6 acceptance evidence (2026-11-08)

**HR.R1 stays OPEN, and this is not the closure audit.** It works through the WO-6 acceptance debt C18-A1 to C18-A14 (`HR-R1-C19-RETENTION-BATCH-BOUND.md` §7), against the C17 acceptance list (`HR-R1-C17-S6-DECISION.md` §3.8). Baseline `6e4b4a88` (WO-6 `26ace7b3`, C19 `53b32960`). No month-lock policy, reopening, Attendance state, requester authority, independence rule, MFA policy, Payroll admission or retention period changed.

## 1. Acceptance matrix

| Id | Requirement (C19 §7 wording) | Class | Evidence | Status |
|---|---|---|---|---|
| C18-A1 | Race: a register save against a month lock | Test evidence | `StaffAttendanceMonthLockRaceTest`: two OS processes, both orders, the contender observed waiting on a lock (the harness checks a lock wait, not which lock; by the code it is the month key). A register in flight commits before the lock; a register waiting on a lock is refused (`STAFF_ATTENDANCE_MONTH_LOCKED`) and writes nothing | VERIFIED CLOSED |
| C18-A2 | Race: a decision against a later ordinary write | Test evidence | Same class: an approval held mid-transaction; a raw runtime-role version bump of the same record waits on the row the approval is applying, then is refused, by the evidence guard (`staff_attendance_correction_missing`, which fires before the month guard); a service correction during the approval is refused at once. Right after, a well-formed raw correction from the new version (insert and update in one transaction) is refused by the month guard itself (`staff_attendance_month_locked`); the same raw correction in an open month is accepted (positive control). Exactly one correction (the request's), version 2, month still locked. The reverse order adds nothing: a write in a locked month is refused within its own statement, holding at most the record row until it rolls back | VERIFIED CLOSED |
| C18-A3 | Race: Payroll capture against a locked-month correction | Test evidence | `PayrollHrxConcurrencyTest`, both orders: the capture takes the employment's HRX lock exclusively and the approval takes it shared, so they serialize. Approval first: the capture waits and records the corrected fingerprint, no difference detected. Capture first: the approval waits; the captured snapshot is unchanged, one calculation only, and `checkDifferences` reports the change | VERIFIED CLOSED |
| C18-A4 | A stale fresh-MFA proof refused | Test evidence | `StaffAttendanceMonthLockAcceptanceTest`, through the real routes: with the factor enrolled, lock and decide refuse a missing code, a stale TOTP (four steps back, outside the window), a reused (already accepted) TOTP step and another user's valid recovery code; nothing is locked or decided. The service refuses a proof minted for another user with `LogicException` (approve, refuse, lock) | VERIFIED CLOSED |
| C18-A5 | REPEATABLE READ refused on `staff_attendance_locked_corrections` | Test evidence | Race-test class, real runtime role in a child process: a request and a decision at REPEATABLE READ are refused (`staff_attendance_isolation`) with nothing written; the same request and decision at READ COMMITTED succeed (positive control) | VERIFIED CLOSED |
| C18-A6 | The database-clock branch of `lockMonth`'s translation | Test evidence | The month is the database's own School-local month (rule 141; a UTC month would be wrong in the last hours of a month east of UTC), with the application clock a month ahead, so only the trigger can refuse: the service answers `STAFF_ATTENDANCE_MONTH_NOT_ENDED`, nothing locked. A raw insert pins SQLSTATE 23514 and the exact text the translation matches, so a reword fails this test before it can turn the refusal into a 500. No permissive fallback; the match stays fail-closed | VERIFIED CLOSED |
| C18-A7 | `platform:verify-database` pins for the new triggers | Verifier | New check `staff_attendance_month_lock_invariants`: the five triggers present, enabled and defined exactly as shipped (timing, events, table, function, the apply trigger's WHEN); each guard, comments stripped, calls the isolation check and takes the month key in its own mode (exclusive to lock, shared to write); the three helpers' bodies; the apply function a pinned definer the runtime role cannot call; forced RLS on both tables; the two unique indexes with their predicates. Both tables join the no-runtime-DELETE list, the lock table the no-runtime-UPDATE list. `StaffAttendanceMonthLockVerifierTest` commits 18 real regressions on the isolated test database, each detected and restored: 5 disabled triggers, a trigger rebound to another function, a trigger narrowed to INSERT, a guard's isolation call commented out, a guard taking the key exclusively, a no-op isolation helper, the apply function as SECURITY INVOKER, the apply-once index with another predicate, 2 forced-RLS removals, an EXECUTE grant and 3 privilege grants | CORRECTED AND VERIFIED |
| C18-A8 | A malformed `{lockedCorrection}` under elevation is a 404 before the 403 | Informational | Elevated requests: every School route answers an elevation with 403 for a well-formed id (`ElevatedSchoolRouteDenialTest`); a malformed id is not tried there. The governing contract is `WebRouteParameters`: a malformed UUID matches no route, "the normal 404, before any controller or query runs, the same for every School". Pinned: malformed id = 404 for a user with and without the key; a well-formed id without the key = 403. No status change was authorized or made | VERIFIED CLOSED (approved policy) |
| C18-A9 | A direct `/api/v1` refusal test of a locked-month write | Test evidence | Record, register and correction into a locked month: 409 `STAFF_ATTENDANCE_MONTH_LOCKED`, no row, no `staff_attendance.*` audit event. An open month still records; missing capability 403; forged record 404; another School and a suspended School the tenant-safe 404 | VERIFIED CLOSED |
| C18-A10 | A real logged-in browser walkthrough | Operational | §3: done in local DDEV on synthetic demo data, owner-authorized | VERIFIED CLOSED |
| C18-A11 | Automated accessibility checks (axe) on the two pages | Operational | §4: 13 states scanned; one serious violation on the month page fixed and re-scanned clean | CORRECTED AND VERIFIED |
| C18-A12 | A manual screen-reader pass | Operational | Not performed: no assistive-technology tester or tool in this environment. Owner: HR with an accessibility reviewer. Acceptance impact: WO-6 is not certified for screen-reader use | PENDING OPERATIONAL EVIDENCE |
| C18-A13 | Representative performance measurements | Operational | §5. No approved target exists; none is proposed as an SLA | VERIFIED CLOSED (measured, no approved target) |
| C18-A14 | School-month advisory-lock contention measurements | Operational | §5 | VERIFIED CLOSED (measured, no approved target) |

**Totals:** 0 verified defects in the S-6/WO-6 invariants. 8 were missing tests (A1-A6, A8, A9), 1 a verifier gap that needed code (A7), and 5 operational (A10-A14). None needed an owner decision. 13 of 14 closed: 11 VERIFIED CLOSED and 2 CORRECTED AND VERIFIED (A7, A11). A12 PENDING.

**Advisory-key identity (brief §12).** A test reads `pg_locks`: `StaffAttendanceMonthLocks::take()` and the record trigger hold exactly `staff_attendance_month_key(school, month)`, shared for writers and exclusive for locking, and the key is gone at commit and at rollback.

**Record correction (C18).** C18's report said "five guards plus the apply trigger". The catalogue has four guard triggers plus the apply trigger (`DatabaseRoleVerifier::STAFF_ATTENDANCE_MONTH_LOCK_GUARDS`).

## 2. Lock order and deadlocks

Read from the code, not the documents:

- **Writers:** School `FOR SHARE`, month key shared, employment, Leave locks, calendar, rows.
- **Lock:** School, month key exclusive, insert.
- **Approval:** School, month key shared, employment, `hrx.staff_employment` shared, days, calendar, then the request `FOR UPDATE`.
- **Payroll capture:** `hrx.staff_employment` exclusive, calendar shared; no month key.

No new race deadlocked. Every contender was observed waiting on a lock and then resolved. The capture and the approval meet only on `hrx.staff_employment`, so neither waits for the other while holding something the other needs.

## 3. Browser walkthrough (C18-A10)

**Setup.** Local DDEV at `https://lycenza.ddev.site`, code `6e4b4a88` plus this checkpoint's changes, headless Chromium (Playwright 1.56.1), ephemeral in the session scratchpad and never committed (HR.16 practice). The owner authorized it on 2026-11-08 and chose to keep the result: October 2026 is permanently locked in Lycenza Demo School's local DDEV database. DDEV also received the two pending forward migrations and the catalogue seeder. Its staff working week was unset ("Attendance cannot be recorded until it is"), so Monday to Friday were set to full days through Leave configuration. No reset, no deletion.

**Personas (synthetic demo).**

- `principal@example.test` and `multi.school@example.test`: both lock-key holders, neither linked to an Employee, each enrolling a TOTP factor through Account security.
- `hr.payroll@example.test`: can view, holds no key.
- `teacher@example.test`: no Staff Attendance access.

**What happened, in order.**

| Step | Observed |
|---|---|
| Month summary for October | "Month lock 2026-10 is open", Lock offered with confirmation and code |
| Register for 5 October | Arvind Pillai (EMP-000004) recorded present/present through Save register |
| Lock October, keyboard only | Space on the confirmation, code typed, Enter: "locked since 8 Nov 2026" |
| Register in the locked month | Save register hidden, Correct hidden, Request correction offered |
| A forced ordinary correction (POST the page never offers) | Refused: "This month is locked. A change needs a locked-month correction, approved by someone else. (STAFF_ATTENDANCE_MONTH_LOCKED)"; record unchanged |
| Request correction (first half absent, reason late information) | "Correction waiting for approval"; record unchanged |
| The requester's month page | Decide not offered on their own request |
| Second lock-key holder | Decide, confirmation, fresh TOTP, Approve: "Approved and applied", month still locked; record absent/present, version 2 |
| Viewer without the key | Lock state shown; no Lock, no Decide |
| Teacher | 403 |
| Audit log | `recorded`/`bulk_recorded`, `month_locked`, `locked_correction_requested`, `locked_correction_approved`, `corrected` |
| Database after | One correction row, the request's; the forced correction left nothing |

**Not exercised in the browser.** A self-approval by POST, own-attendance approval (no persona is linked to an Employee), and the refuse path. Each is covered by the service, route and raw-role tests. Screenshots and the step log are in the session scratchpad only; they hold no real personal data.

## 4. Accessibility (C18-A11, A12)

**axe-core 4.10.2** (`@axe-core/playwright`, WCAG 2.0/2.1 A and AA) on 13 states, plus one re-scan after the fix (14 scans):

- the month page open, locked, as requester, as decider, after approval, as a viewer without the key, and at mobile width (390 px);
- the register open, locked, and locked at mobile width;
- the request form, the decide view and the audit log.

**Result:** 0 violations everywhere except two, both `scrollable-region-focusable` (serious):

- **The month page at mobile width:** the summary table's scroll container could not take keyboard focus. Fixed with the existing pattern (`Payroll/Reconciliations/Index.vue`): `tabindex="0"`, `role="region"`, an accessible name. Re-scanned: 0 violations, region focusable.
- **The School audit log** (`/app/compliance/audit-log`): the same pattern, outside WO-6. Recorded as C20-N1, not changed here.

The mobile register has no horizontal overflow. **The manual screen-reader pass (A12) was not performed,** and automated checks are not a substitute.

## 5. Performance and contention (C18-A13, A14)

**Environment.** The isolated test database (PostgreSQL 16.15), 16 vCPU / 31 GB WSL2 host, real service paths, warm-up samples discarded. One School with 1,000 employments and 44,000 attendance rows (September and October), analysed. Single host, so these are indicative, not production figures. The benchmark script is in the session scratchpad and is not committed.

| Operation | Samples | p50 | p95 |
|---|---|---|---|
| Record one day | 50 | 17.4 ms | 19.6 ms |
| Register, 50 items | 5 | 390 ms | 400 ms |
| Register, 200 items | 5 | 1,516 ms | 1,561 ms |
| Lock a populated month | 1 | 23.8 ms | — |
| Refusal in a locked month | 35 | 2.9 ms | 3.4 ms |
| Request a locked-month correction | 25 | 17.9 ms | 21.1 ms |
| Approve it | 25 | 29.2 ms | 31.0 ms |
| Read the month's requests | 10 | 8.9 ms | 9.7 ms |
| Month summary page (1,000 employments) | 10 | 1,401 ms | 1,548 ms |
| Payroll capture, 200 employments | 3 | 2,382 ms | — |
| Payroll capture, 1,000 employments | 3 | 9,333 ms | — |

**Contention.** A lock waits only for writers already in flight. In two OS processes, a lock started 1.2 s into a 200-item register (process total 1.7 s) finished in 505 ms against 265 ms alone, about 240 ms of waiting. A writer behind a committed lock is refused in about 3 ms.

**Interpretation.** Measured; there is no approved target, and no pre-WO-6 baseline was measured, so this does not prove the absence of a regression. WO-6's own operations are small: a lock of about 24 ms, refusals of about 3 ms, approvals of about 29 ms. Two existing costs are worth an owner's attention (C20-N2):

- the month summary at 1,000 employments;
- a Payroll capture of 1,000 employments, which an approval for one of them may wait behind.

Neither is a WO-6 change, and neither was altered.

## 6. Current-month locking

Unchanged. The confirmation package is `HR-R1-C19-RETENTION-BATCH-BOUND.md` §8. The recommendation remains Option A, ended months only, because a lock never reopens and a correction only changes an existing record. **CURRENT-MONTH LOCK POLICY: OWNER CONFIRMATION PENDING.** The implementation matches Option A.

## 7. New findings

| Id | Severity | Finding | Owner, disposition |
|---|---|---|---|
| C20-N1 | LOW (axe serious) | The School audit log's table scroll region cannot take keyboard focus (`scrollable-region-focusable`) | Compliance UI. Open; the same three-attribute fix as the month page |
| C20-N2 | Observation | The month summary takes about 1.4 s at 1,000 employments, and a Payroll capture of 1,000 employments about 9.3 s, so an approval can wait that long. Neither is a WO-6 change | HR (Staff Attendance reads), Payroll. Open; no target exists |

C19-N1 (Communications) and C19-N2 (Platform) are unchanged. C19-N2 did not recur in this checkpoint's local work; CI is recorded below.

## 8. Independent review

A reviewer read the final candidate: **no HIGH, four MEDIUM, six LOW.**

| Finding | Fix |
|---|---|
| M1: A6 took the month from UTC, so it failed in the last hours of a month east of UTC | Takes the database's School-local month (rule 141) |
| M2: the verifier ignored trigger timing and events, read comments, checked helpers by name only and ignored index predicates | Exact trigger definitions; bodies with comments stripped and lock mode checked; helper bodies; predicates |
| M3: the record said the negative test broke every property; it broke 11 | 18 regressions now, listed in §1 |
| M4: A2's raw contender is refused by the evidence guard, so the month guard was not shown | Pinned to that guard, plus a well-formed raw correction refused by the month guard |
| LOWs | Missing code now tested with the factor enrolled; the borrowed proof asserts `LogicException`; the record's wording corrected |
| Kept as limits | The race harness checks a lock wait, not which lock (shared harness, out of scope); a trigger set to ENABLE ALWAYS would read as disabled; a killed verifier test could leave the shared test database broken until reset (the same as the existing retention verifier tests) |

The fixes were re-run (acceptance 7, races 11, verifier 2) and **reviewed again: all four MEDIUMs closed, no HIGH or MEDIUM.** Of its four LOWs, the raw-correction op now runs as one transaction with an open-month positive control and the scan count is corrected. Two remain as limits: a text check cannot tell live code from dead code (a deliberate change could defeat it), and the trigger check assumes `public` on the verifying connection's search_path (it fails closed otherwise).

## 9. Changed

- **Verifier:** `DatabaseRoleVerifier`, the A7 pins.
- **Month page:** `Monthly.vue`, the accessible scroll region.
- **Tests:**
  - new: `StaffAttendanceMonthLockAcceptanceTest` (7) and `StaffAttendanceMonthLockVerifierTest` (2);
  - extended: `StaffAttendanceMonthLockRaceTest` (+4) and `PayrollHrxConcurrencyTest` (+2);
  - three test-only ops in `staff-attendance-op.php`.
- **Not changed:** no migration, no catalogue change, no Payroll, Leave or Retention code.
