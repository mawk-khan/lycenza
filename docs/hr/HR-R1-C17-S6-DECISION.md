# HR.R1-C17: S-6 determination and the WO-6 period-lock contract (2026-11-08)

**Implemented 2026-11-08 (HR.R1-C18, `HR-R1-C18-WO6-MONTH-LOCK.md`):** the requester rule (`hr.staff_attendance.manage`) and the one-holder rule (fail closed) were then decided by the HR owner; the writer is `StaffAttendanceService` itself, as one owner; the database applies an approval.

**Completion state: S-6 APPROVED — WO-6 IMPLEMENTATION READY.** S-6 is decided and recorded, and WO-6 is specified below. **No WO-6 runtime work has started, and starting it needs its own authorization.** D8-10 and D8-13 stay ACTIVE until it ships. HR.R1 stays OPEN. Baseline `289865c3`.

Four milestones, kept apart:

| Milestone | State |
|---|---|
| Decision package ready | Done |
| S-6 determination approved | **Done, 2026-11-08** |
| WO-6 implementation authorized | **Not yet** |
| Runtime control implemented | **No** |

## 1. The finding

`HR-LEGAL-SECURITY-GATE-REGISTER.md`, S-6, LOW: "Staff attendance correction: no window, MFA or second person; cached check". HR.EXP-0 §12 called it a "recorded decision". HR.R1-C10 traced that to `APPROVAL-AUTHORITY-MATRIX.md:48`, an audit classification about approval only (NO_APPROVAL; keep), and found no owner determination. The C12 docket assigned it to the HR owner, with Security for its assurance, and tied it to D8-13. Until now no determination existed.

**What the code does today.**

| Aspect | Today |
|---|---|
| Record | One per employment × date, two halves, each `present` or `absent`. No clock time, shift, device or biometric |
| Writers | `StaffAttendanceService::record`, `recordRegister`, `correct`, reached from web and `/api/v1` (`store`, `storeRegister`, `correct`), capability `hr.staff_attendance.manage` (school_admin, principal, hr_officer). Each takes the School FOR SHARE (rule 86) |
| Correction | Compare-and-swap on `version`, an append-only `staff_attendance_corrections` row with a closed reason (`entered_in_error`, `late_information`, `administrative_review`, `other`), self refused. **No time window, no MFA, no second person** |
| "Cached check" | Authority is `authorizeCapabilityFor`, before the transaction, from the cached resolver. Never re-read under the lock |
| Database | `trg_staff_attendance_records_guard`: only the halves change, by exactly one version. `trg_staff_attendance_corrections_guard` and the deferred `_applied` trigger: a correction starts from the current values and is applied in the same transaction. Corrections append-only; record DELETE revoked from the runtime role |
| Period | **None.** No month or period record exists. The month summary and Payroll periods are calendar months |
| Payroll | `PayrollAbsenceEvidenceReader::captureForPayroll` reads evidence inside Payroll's transaction under `hrx.staff_employment` (exclusive) and the calendar lock (shared), and stores a fingerprint. `PayrollHrxInputReadService::checkDifferences` shows a later change. No money effect (HRX-L4) |

## 2. The decision

Given in this session on 2026-11-08, by the repository owner answering four questions, each framed with its role. The record names the role, not a person (docket §1).

| Id | Decided | Role | Option chosen |
|---|---|---|---|
| S6-1 | What a lock means | HR owner | **Locked, never reopened; assured corrections only.** Recording, registers and ordinary corrections are refused in a locked month. A change is possible only as a high-assurance locked-month correction: a new append-only correction row, with the original kept. No reopen state, so no open window |
| S6-2 | The unit and who locks | HR owner | **The School-local calendar month, locked by a new `hr.staff_attendance.lock` key**, held by school_admin and principal only, whole-School, never Campus-adopted. hr_officer keeps ordinary recording |
| S6-3 | Assurance on locking and locked-month correction | Security, with HR | **Fresh MFA for both.** A locked-month correction is requested by one person and approved by another lock-key holder, never the requester or the Employee concerned, with the approver's fresh MFA. Authority is re-read under the lock at lock and at decision |
| S6-4 | Open-month corrections | Security, with HR | **Kept as ordinary operations**, with closed reason, compare-and-swap and self refused, **but authority re-read fresh inside the transaction**, closing the "cached check". The lock is the window |

**Rejected:** a strict lock with no route for a later error; controlled reopening (an open window to govern); a single person correcting a locked month; no added assurance; fresh MFA on every correction; recording today's controls as deliberate.

**Not decided here, so still open:**

- **Payroll and locking.** Whether Payroll should calculate only locked months; HR.7's roadmap item 8 suggests it, but nobody has decided it. WO-6 changes no Payroll behaviour.
- **Who may request.** Who may *request* a locked-month correction beyond `hr.staff_attendance.manage`; the spec below uses that key.
- **Small Schools.** The rule for a School with one lock-key holder (the request waits, as other shared-foundation workflows do).
- **Retention.** Any retention period (E21-D9 unratified).

**What it does not resolve.** The same-person Identity MEDIUM stays open: two accounts do not prove two people, so the second person is a second account under today's Identity contract. C14-N5 stays open. No Legal or DPO determination is made or implied. Teacher attendance (ADR 0063, Student attendance) and Student Attendance are untouched.

## 3. WO-6: the implementation contract

### 3.1 Scope

D8-10 (period lock) and D8-13 (S-6). Inside: the lock, the locked-month correction workflow, the fresh re-read on open-month corrections, the database backstop, the tests. Outside: Payroll behaviour, calendar history (WO-5), any timing, shift, biometric or device processing, reopening, Campus scope.

### 3.2 Schema

| Table | Columns | Rules |
|---|---|---|
| `staff_attendance_month_locks` | `id`, `school_id`, `month` (first day), `locked_by_user_id`, `locked_at`, `created_at` | Unique `(school_id, month)`; forced RLS (`TenantRls::enable`); append-only (`TenantRls::makeAppendOnly`); `school_id` and `locked_by_user_id` ON DELETE RESTRICT (rule 97); `month` CHECK first of month |
| `staff_attendance_locked_corrections` | `id`, `school_id`, `staff_attendance_record_id`, `employment_record_id`, `from_version`, `before_*`/`after_*` halves, `reason_code` (the closed set), `form_key`, `requested_by_user_id`, `requested_at`, `status` (`pending`, `approved`, `refused`), `decided_by_user_id`, `decided_at` | Forced RLS; composite same-School keys to the record and the employment (rule 70); unique `(school_id, form_key)`; terms immutable; a decision is final; `decided_by_user_id <> requested_by_user_id` (CHECK); no runtime DELETE |
| `staff_attendance_corrections` | add nullable `locked_correction_id` | Composite same-School key to the request; set only by an approved request |

Every migration runs through the admin connection with a working `down()` that refuses once evidence exists, as the existing table does (rules 10, 143). `platform:verify-database` gains the new guards.

### 3.3 Writers

One owner: `StaffAttendanceService` plus a new `StaffAttendanceLockService` in the same module. No other domain writes a lock. R1-01 is not adopted by this.

| Operation | Capability | Assurance | Effect |
|---|---|---|---|
| `lock(school, month)` | `hr.staff_attendance.lock`, re-read with `ApprovalDecisionAuthority::requireCurrent` under the lock | `FreshMfaRequirement::requireForAction`, after the capability check and outside the transaction (rule 95); explicit confirmation | Inserts the lock row; audit `staff_attendance.month_locked` (month only) |
| `record`, `recordRegister` | `hr.staff_attendance.manage` | Unchanged | Refused if the date's month is locked |
| `correct` (open month) | `hr.staff_attendance.manage`, **now re-read fresh in the transaction** | Unchanged | Refused if locked |
| `requestLockedCorrection` | `hr.staff_attendance.manage`, re-read fresh | None (a request changes nothing) | Inserts a pending request on a server-issued form key; self refused (`refuseOwnEmployment`) |
| `decideLockedCorrection` | `hr.staff_attendance.lock`, re-read with `requireCurrent` | The approver's own `FreshMfaProof` | Under the request row lock: `MakerCheckerPolicy` with the requester as participant; approver refused own employment; stale refused (the record's version must still equal `from_version`); on approval, the correction row with `locked_correction_id` and the record update in the same transaction; audit with ids and closed codes |

It is registered in `ApprovalWorkflowRegistry` on the shared foundation (rule 102), with `ApprovalProvenance` on its audit event. `hr.staff_attendance.lock` is a catalogue release: `CapabilityAndRoleSeeder`, `ModuleCatalogue` (staff_attendance), `StaffRoleCatalogueSnapshotTest` and the route coverage contracts (rules 93, 98, 100).

### 3.4 Lock order and the database backstop

Each School-month has one advisory lock, `hr.staff_month:{school}:{month}`.

| Path | Order |
|---|---|
| `record`, `recordRegister`, `correct`, `decideLockedCorrection` | School FOR SHARE (guard) → **month advisory lock SHARED** → employment FOR SHARE → `LeaveLocks` staff employment (shared) and days → calendar (shared) → record FOR UPDATE |
| `lock` | School FOR SHARE → **month advisory lock EXCLUSIVE** → insert the lock row |
| Payroll `captureForPayroll` | Unchanged: `hrx.staff_employment` (exclusive) → calendar (shared). It takes no month lock |

The lock takes nothing after its advisory lock, so no cycle exists. In-flight writes of the month finish before the lock commits, and writes after it see it.

**Backstop.** A BEFORE INSERT OR UPDATE trigger on `staff_attendance_records` and a BEFORE INSERT trigger on `staff_attendance_corrections` take the same month advisory lock SHARED, then refuse when a lock row exists, except for an UPDATE whose correction row (same record, `to_version` = the new version) carries a `locked_correction_id` of an approved, unapplied request with exactly its values. So a raw runtime-role write serializes with locking and cannot defeat it. The application check gives a clean domain refusal; the trigger is the invariant. Retention only deletes, through its own identity, and is unaffected.

### 3.5 Payroll, Leave, history, retention

- **Payroll:** unchanged. A locked-month correction changes the evidence after a capture, so `checkDifferences` reports `sourceChanged`. Nothing recalculates, reposts or reverses, and no money effect exists (HRX-L4). Whether Payroll requires locked months is open (§2).
- **Leave:** unchanged; a correction never writes the ledger. The existing leave-coverage refusal still applies to a locked-month correction.
- **History:** the original record version, every correction row (ordinary and locked-month), the lock row and the request with its decision all stay. Nothing makes a locked month look as if it had been open.
- **Retention:** lock rows hold no personal data and stay with the School. Requests are Employee evidence in the existing `hrx_evidence` unit, expired with the Employee by the existing function (extended). No new period is invented.

### 3.6 Idempotency and suspension

- **Locking:** a second lock of the same month is refused by the unique key, never written twice.
- **Requests:** claimed on a server-issued form key.
- **Decisions:** final under the request lock.
- **Suspension:** every operation asks `SchoolOperationalGuard` in its transaction (rule 86), and a suspended School can neither lock, request nor decide. C14's release of a suspended-School refusal and C14-N2 are unchanged.

### 3.7 UI and API

The month summary gains the lock status, and for lock-key holders a Lock action with confirmation and a fresh code. A locked record offers Request correction instead of Correct, and approvers get a decision view. Refusals use the existing `refusal` contract and the design system (rule 136), with an accessible name on every control. No control suggests a reopen. `/api/v1` refuses the same writes through the service.

### 3.8 Tests (acceptance)

| Kind | Cases |
|---|---|
| Functional | Open-month write; lock; second lock refused; record, register and correct refused when locked; locked-month request, approve, refuse; stale request refused; history shows original, correction, lock and decision |
| Authorization | Missing lock key; Campus-only grant refused; wrong School; forged month, record, request; requester approving; approver as the Employee; requester as the Employee; open-month correction after the capability is revoked mid-request (fresh re-read) |
| Assurance | Lock and decide without, with stale, and with reused MFA proof; another user's proof |
| Database (runtime role) | Raw insert and update in a locked month refused; raw correction without an approved request refused; lock row update and delete refused; cross-School and no-context reads empty |
| Concurrency (two processes, barriers) | Write versus lock (both orders); register versus lock; two locks; decision versus a later ordinary write; School suspension versus lock; Payroll capture versus locked-month correction |
| Nonregression | Payroll HRX snapshot and `checkDifferences`; Leave coverage; C14 idempotency release; retention expiry still deletes |
| Performance | Lock beside a large register; register refusal cost; history read. Proposed targets only, no SLA |

The four rows change status only on evidence: D8-10 IMP when the lock and its backstop ship; D8-13 closed when S6-3 and S6-4 are implemented and pass.

### 3.9 Review

There's a final-state independent security review of the actual candidate, focused on lock bypass, reopening paths, MFA, maker/checker, raw SQL, isolation, concurrency, Payroll evidence, audit and retention. Then a full canonical regression on the exact SHA.

## 4. Effects

- **D8-10, D8-13:** ACTIVE, decided, not built.
- **D14-08, D14-09:** untouched; WO-5 after D-6.
- **§2:** the first precondition is still not met (four ACTIVE rows), the second met, the third not (no baseline).
- **Gates:** every legal and security gate unchanged except S-6, which is now decided and awaiting its control.
- **Recommended next:** the WO-6 implementation checkpoint, exactly this contract. It is not started.
