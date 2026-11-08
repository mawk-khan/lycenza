# HR.R1-C18: WO-6, the Staff Attendance month lock (2026-11-08)

**HR.R1 stays OPEN, and this is not the closure audit.** It implements S-6 as decided in HR.R1-C17 (`HR-R1-C17-S6-DECISION.md`), on the HR owner's authorization given in this checkpoint. Baseline `4fbdd053`.

## 1. Authority

| Item | Recorded (2026-11-08, in session) |
|---|---|
| Implementation | Authorized by the owner: "implement WO-6 now", exactly as specified in the C17 record §3 |
| Who requests a locked-month correction | HR owner: holders of `hr.staff_attendance.manage` (confirms C17's assumption) |
| A School with one lock-key holder | HR owner: fail closed. Locking still works; a request waits for a second lock-key holder; no self-approval, no override |
| Still not decided | Whether Payroll calculates only locked months; any retention period (E21-D9). Neither is touched |

## 2. What was built

**Lock.** A School-local calendar month is locked once and never reopened.

- **Who:** a holder of the new `hr.staff_attendance.lock` (school_admin, principal; whole-School; never Campus-adopted; never a grant right).
- **How:** an explicit confirmation and a fresh MFA code (rule 95), with authority re-read under the lock.
- **Where:** one append-only row per School and month (`staff_attendance_month_locks`).
- **Only an ended month** can be locked, in the service, the page and the database. The current month is refused: a locked month takes no new record and is never reopened, so locking it would leave the rest of it unrecordable for good. The owner did not decide current-month locking; this is the conservative boundary, which a later owner decision may relax.

**Writers.** `record`, `recordRegister` and an ordinary `correct` are refused in a locked month. An open-month `correct` now re-reads its authority inside the transaction (S6-4, the "cached check").

**Locked-month correction.** It is a request (`staff_attendance_locked_corrections`) that changes nothing until approved.

- **Approver:** another holder of the lock key, never the requester, a known same person, or anyone ever linked to the Employee.
- **Assurance:** the approver's fresh MFA, and authority re-read under the lock.
- **Application:** the database applies the approval from the request's own values: a correction row naming the request, then the record's new version, in the same statement. The decision is final. A month stays locked after any number of corrections.

**One writer.** Everything is in `StaffAttendanceService`. The C17 spec allowed a sibling class; one class keeps the domain's single writer, which two architecture tests pin, and reuses the correction's leave and working-time checks (`refuseNewEvidenceOutsideWorkingTime`, extracted, not copied).

**Pages.**

- **Register:** shows the lock, hides recording in a locked month, and offers Request correction instead of Correct.
- **Month summary:** shows the lock and offers Lock to lock-key holders. It lists the month's requests and offers a decision only where the server says this actor may decide.
- **No new API endpoint:** `/api/v1` refuses the same writes through the service.

## 3. Database

| Mechanism | What it guarantees |
|---|---|
| `staff_attendance_month_key(school, month)` | One advisory key per School-month, the same string `StaffAttendanceMonthLocks` derives |
| `trg_staff_attendance_month_locks_guard` | Takes the key EXCLUSIVELY; records the database's clock; refuses a month that has not ended and a locker without a whole-School lock key |
| `trg_staff_attendance_records_month_guard`, `trg_staff_attendance_corrections_month_guard` | Take the key SHARED, so a raw write serializes with locking; in a locked month refuse an insert, and any update or correction except the application of an approved request with exactly its values |
| `staff_attendance_require_read_committed()` | Called first by every month guard. The check sees a lock committed while it waited only with a fresh snapshot per statement, which READ COMMITTED gives; a REPEATABLE READ or SERIALIZABLE transaction could miss it, so any level but READ COMMITTED is refused (review M1) |
| `trg_staff_attendance_locked_corrections_guard` | Born `requested`; the record, version and values current; the requester holds the manage key and is not the Employee. A decision is final; terms frozen; the decider holds the lock key and is independent; an approval must not be stale |
| `trg_staff_attendance_locked_corrections_apply` | The only thing that changes a locked month: applies the approved values once (`staff_attendance_corrections_locked_correction_once`) |
| RLS, keys | Both tables FORCE RLS; same-School composite keys to the record; RESTRICT to users; lock rows append-only; requests have no runtime DELETE. `school_id` cascades, as on the other Staff Attendance tables, where the C17 spec said RESTRICT: the runtime role cannot delete a School, and committed test Schools are purged through it. A recorded deviation |

**Lock order.** The guarantee holds at READ COMMITTED, the application's level; other levels are refused rather than trusted. Writers: School (FOR SHARE), month (SHARED), employment, leave locks, calendar, rows. Locking: School, month (EXCLUSIVE), insert. Payroll's capture is unchanged and takes no month lock. Nothing that takes the month key exclusively waits for anything afterwards, so no cycle exists.

**Who acted stays an application boundary** (rule 102). The database checks that the recorded locker, requester and decider qualify and are independent; it cannot prove who pressed the button or that a code was given. Two accounts are still not proof of two people: the same-person Identity MEDIUM stays open.

**Retention.**

- **Requests** are Employee evidence in the existing `staff_attendance_evidence` unit (D9). They carry the retention anchor and delete guard, and `retention_expire_staff_attendance_employee_evidence` was patched in place against its live body (rule 146): requests leave after the correction rows that name them, and their anchor counts as recent evidence.
- **Lock rows** are `hr_configuration`: no person data, kept with the School.
- **No new period was invented.**

## 4. Evidence

| Test | Proves |
|---|---|
| `StaffAttendanceMonthLockTest` (13) | Every writer refused in a locked month, other months open; the lock key, own fresh code, ended month only, once; a known same person neither offered nor allowed to decide; an open-month correction refused after revocation while the cache still says yes (S6-4); Campus grant refused; request → independent approval → applied, month still locked, decision final; refusal changes nothing; an ended link still disqualifies; replay and form-key reuse; own attendance refused; suspended School; revoked key refused under the lock; Payroll's evidence fingerprint changes only on approval |
| `StaffAttendanceMonthLockRawRoleTest` (5) | As the runtime role: no raw record or correction lands; a pending request authorizes nothing; requester, non-holder and term edits refused; an approval applies once and is final; lock rows append-only and holder-only; another School sees nothing |
| `StaffAttendanceMonthLockRaceTest` (7) | Two OS processes, contender observed blocked: a write in flight finishes before the lock; a write after it, service or raw SQL, is refused; two locks, two approvals and a suspension race each resolve to one outcome. A raw write at REPEATABLE READ is refused |
| `StaffAttendanceMonthLockUiTest` (3) | The real routes: page props; confirmation, invalid and spent codes refused; register request with form key, replay safe; requester never offered their own request and refused on posting |
| `HrxEvidenceRetentionTest` (9, extended) | Requests expire with their Employee in the existing unit; dry run counts them; recent evidence keeps the whole unit |

**Mutations, each restored afterwards.**

| Mutation | Result |
|---|---|
| The service's month check removed from `record` | Failed (the database refused with a raw error) |
| The independence check removed from approval | Failed |
| The same-person check removed | Failed |
| The fresh authority read removed from decisions | Failed |
| The proof-ownership check removed from locking | Failed |
| The fresh MFA step replaced by a forged proof in the lock controller | Failed |
| The database month guards disabled | Failed: two raw-SQL tests |

**Other checks.** The rollback proof shows the schema byte-identical after rolling both migrations back and re-applying them, and `platform:verify-database` reports `failed=0 operator_evidence_required=1`. Pint, Larastan, vue-tsc, ESLint (3 pre-existing warnings elsewhere), Prettier, the build and the design-token test all pass.

**Independent review.** It read the final delta: no HIGH, four MEDIUM, seven LOW.

- **Isolation level (M1):** every month guard now refuses anything but READ COMMITTED.
- **Same-person rule (M2):** the service and the page now apply the database's known-same-person rule, through the shared HR helper.
- **Current month (M3):** only an ended month locks.
- **Fresh re-read (M4):** an open-month correction now has its fresh re-read test.
- **LOWs:** a requester is refused through link history; stale names and drift are fixed; pending requests list first.
- **Cascade (L2):** recorded as a deviation above.

A focused re-check of those fixes found no HIGH or MEDIUM. Two of its LOWs were then fixed and **not reviewed again**: the request guard now checks the isolation level for a decision too, and a lock the database refuses in the clock-skew seconds around midnight on the 1st becomes the domain refusal, not a raw error.

**Delta review (after publication).** A separate reviewer read every code change after the re-checked state (`66a03b2c..26ace7b3`: those two fixes, the route-parameter classification and the test changes): **no HIGH, no MEDIUM.** Three LOWs, all open:

- **Untested isolation path:** the REPEATABLE READ refusal on `staff_attendance_locked_corrections` (decision and insert) has no test; the existing one covers `staff_attendance_records`.
- **Untested translation branch:** the database-clock branch of `lockMonth`'s translation is unreached by tests, and it matches the message text, not SQLSTATE 23514. If the message changes it fails safe (a 500), and masks nothing.
- **Informational:** a malformed `{lockedCorrection}` under elevation is a 404 before the 403, like every other UUID parameter.

**Publication.** Three candidates.

| Candidate | CI gate | What failed, and the fix |
|---|---|---|
| `1d1f6c06` | red | Shard 4: `WebRouteParametersGuardTest`, the new `{month}` and `{lockedCorrection}` parameters unclassified. Integrity: a trailing blank line in `CLAUDE.md`. Both fixed in `ce0821a9` |
| `ce0821a9` | red | Shard 3: `ElevatedSchoolRouteDenialTest`, a route-level `{month}` constraint answered an elevation with 404, not 403. `{month}` now 404s in the controller, after school-context, the `check`/`report` precedent. Shard 4: C15-N1, second occurrence; the whole shard ran green locally |
| `26ace7b3` | green | Shard 4 failed C15-N1 a third time, now with the real output (`Deleted 4`, held 0); its one job re-run passed. Published |

Run 42480517599, four shards, **11,520 cases, 0 failures, 0 errors, 1 skip (ESI-12), 464,002 assertions**, coverage union proven; shard 4 counted from its re-run. `publication-check` publishable, E16 valid through 2026-11-28; `main` = `26ace7b3`. 32 more cases than C15.

Both defects CI caught were missed locally because `tests/Feature/Routing` and `tests/Feature/Platform` were not in the focused set: a new School route needs both. The local publish gate's whitespace check compares the index with `HEAD`, so after a commit it checks nothing; `git diff --check origin/main HEAD` is the CI's check.

## 5. Limits

- **The same-person Identity risk.** A second person is a second account (open MEDIUM).
- **Payroll.** Its calculation admission is unchanged: it may still calculate an unlocked month. Whether it should not is an open Payroll/HR decision.
- **Timestamps.** The database stamps the decision and the applied correction with its own clock, the established pattern.
- **Calendar history** (D14-08, D14-09) is not part of this: WO-5, after D-6.
- **C17 acceptance items not built here:**
  - races: a register versus a lock, a decision versus a later ordinary write, and Payroll capture versus a locked-month correction (Payroll's own ordering is unchanged);
  - the stale-MFA-proof case;
  - the performance measurements;
  - `platform:verify-database` pins for the new triggers.

  The migration's own checks and the rollback proof stand in for the last; the rest are open.
- **Isolation.** The database guarantee holds at READ COMMITTED. Other levels are refused, not supported.
- **No browser walkthrough** was performed. The pages are exercised through their routes and props.
- **C15-N1** (Retention/Platform), raised to MEDIUM: the audit prune stopped one row short in CI three times. Not caused by this change; recorded in the register with its output. Closed by HR.R1-C19.
- **Acceptance debt** is tracked as C18-A1 to C18-A14 in `HR-R1-C19-RETENTION-BATCH-BOUND.md` §7, and the ended-month-only rule awaits the HR owner (§8 there).
