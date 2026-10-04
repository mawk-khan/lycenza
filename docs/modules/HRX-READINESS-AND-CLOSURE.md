# HRX — Leave & Staff Attendance: Readiness and Closure Record

This is a closure record, not a module design. It states, in one place,
what the HRX programme delivered, what stays gated, and the evidence for
each claim. The design lives in ADR 0065 (§1–§27) and `docs/modules/HR.md`.

## 1. Closure state

- **HRX — LEAVE & STAFF ATTENDANCE IMPLEMENTATION PUBLISHED / CLOSED**
  (HRX.6, 2026-10-04).
- **HRX.5 MECHANISM CLOSED / LEGAL ACTIVATION GATED.** The HRX → Payroll
  evidence contract is built. Monetary loss-of-pay, any payroll
  "non-payable" policy and EPFO NCP conversion are **not** activated
  (HRX-L4).
- **Not production clearance.** HRX-L1–L4 are open (§9). The E21 retention
  policy is project-adopted; qualified legal ratification and the
  production retention configuration are pending. Do not quote this record
  as "HRX production-ready" or "legally cleared".

| Checkpoint | Commit | Record |
|---|---|---|
| HRX.0 contract (docs) | `ce4c06a` | ADR 0065 §1–§21 |
| HRX.1 Leave foundation | `b5d1a4b`, correction `f1001ec` | §22 |
| HRX.2 Leave requests & approval | `52ce8c4` | §23 |
| HRX.3 Staff attendance | `0689569` | §24 |
| HRX.4 Staff self-service | `2b0b1f5` | §25 |
| HRX.5 Payroll absence evidence (mechanism) | `48a2f5f` | §26, `docs/security/HRX-L4-PAYROLL-LOSS-OF-PAY-DETERMINATION.md` |
| HRX.6 Retention, readiness & closure | this checkpoint | §27, this record |

## 2. Table inventory

| Class | Tables | Retention |
|---|---|---|
| Per-Employee Leave evidence (D9) | `leave_policy_assignments`, `leave_ledger_entries`, `leave_requests`, `leave_request_days`, `leave_decisions`, `leave_year_close_items`, `leave_year_close_reconciliations` | `leave_evidence`: 8 y after final separation, Leave's participant (HRX.6) |
| Per-Employee Staff Attendance evidence (D9) | `staff_attendance_records`, `staff_attendance_corrections` | `staff_attendance_evidence`: 8 y after final separation, Staff Attendance's participant (HRX.6) |
| School configuration | `leave_settings`, `leave_years`, `leave_year_start_changes`, `leave_types`, `leave_policies`, `staff_working_weekdays`, `staff_holidays`, `leave_allocation_runs`, `leave_year_closes` | `leave_configuration`: tenant lifetime (never age-pruned) |
| Payroll evidence | `payroll_run_hrx_inputs` | `payroll_ledger`: leaves only with its payroll result (E21.3F) |

Every one of these 19 tables is tenant-owned with **forced RLS**.

## 3. Retention (E21-D9) — summary of §27

- **One engine.** `platform:employee-retention-prune` (evidence phase) runs
  Payroll's per-employment rows → **Leave** → **Staff Attendance** → HR's
  Employee root. The reviewed erasure case uses the same participants.
  One D9 setting (`EMPLOYEE_EVIDENCE_RETENTION_YEARS`); no HRX setting.
- **Privileged path** (§3a). `retention_expire_leave_employee_evidence` and
  `retention_expire_staff_attendance_employee_evidence` (SECURITY DEFINER,
  pinned `search_path`, never PUBLIC, **not executable by the runtime
  role**). Each re-proves:
  - the authorized retention identity (session user);
  - no retention hold on the School;
  - the tenant;
  - the 8-year floor against real `now()`;
  - the final separation, with the Employee and EmploymentRecords locked
    FOR UPDATE;
  - the HRX lock;
  - that every row is older than the cutoff.
- **Order.**
  - Leave: reconciliation entries → reconciliations → ledger, leaves
    first → days → decisions → requests → close items → assignments.
  - Staff Attendance: corrections → records.
- **Properties (tested).**
  - Idempotent: a second run deletes nothing and does not fail.
  - Transactional: one Employee per transaction.
  - Concurrency-safe: two real OS processes.
  - Tenant-safe: wrong tenant refused; a held School only counts.
  - Counts-only metrics and logs; a dry run deletes nothing.
- **Kept.**
  - School configuration, audit and outbox.
  - `payroll_run_hrx_inputs`: unchanged by the HRX purge, removed with its
    result by Payroll retention.
  - A manager's decision on another Employee's request.
- **Employee deletion.**
  - Unexpired HRX evidence blocks it.
  - Expired-but-unpurged HRX evidence blocks it.
  - After the HRX purge, HRX no longer blocks.
  - Other domains (for example a timetable entry) still block
    independently.
  - Never a cascade.

## 3a. Retention privilege model (hardened, ADR 0065 §27.10)

The security controls are database privileges and in-function checks,
not the code path that happens to call the functions.

| Layer | Identity | Control |
|---|---|---|
| Runtime application role | `school_os_app` | No DELETE on HRX evidence; no EXECUTE on the purge functions or their prologue; no privilege on `retention_school_holds`. It cannot purge HRX evidence, or place or release a hold. |
| Authorized retention execution identity | the existing migration/owner connection `pgsql_admin` | `platform:employee-retention-prune` (and the reviewed erasure case) run the HRX participants on it, through `RetentionExpiry::privileged()`. |
| SECURITY DEFINER function owner | the migration role (table owner) | Executes the deletes; pinned `search_path`, qualified tables, never owned by the runtime role. |
| Legal-hold enforcement | `retention_lock_hrx_employee`, inside both functions | Refuses unless the session user holds the owner's privileges, and refuses a School recorded in `retention_school_holds` -- the database mirror of `RETENTION_HOLD_SCHOOL_IDS`, synchronized before every HRX run. |

- **Verifier.** `DatabaseRoleVerifier` check
  `privileged_retention_functions_closed`: both functions are definers with
  a pinned `search_path`, not owned by, not executable by and not PUBLIC to
  the runtime role, and the runtime role has no privilege on the hold
  mirror.
- **Operational requirement.** The process running the employee retention
  command needs the migration/owner credentials (`DB_ADMIN_*`). This joins
  the pending production retention configuration.
- **Residual, outside HRX.** The other E21 retention functions keep the
  E21.2B model: runtime EXECUTE, with the hold checked in PHP. Changing them
  is a separate E21 decision.
- **Amended 2026-10-04 (E21-RH.1, ADR 0066).** Using `pgsql_admin` as the
  retention identity is temporary: it contradicts ADR 0021 for the
  scheduled command, and the scheduler needs migration credentials.
  E21-RH.2 moves HRX to the dedicated retention identity. The
  `retention_school_holds` mirror becomes the authoritative hold table in
  E21-RH.3.

## 4. Capabilities and roles

| Capability | Purpose |
|---|---|
| `hr.leave.configure` | types, policies, years, start-month changes, calendar, settings |
| `hr.leave.view` | entitlements, balances, requests, ledger |
| `hr.leave.manage` | allocation, adjustment, assignment, administrative request lifecycle, year close |
| `hr.leave.approve` | manager-path approval/rejection (with a verified reporting line) |
| `hr.leave.self` | own leave view / submit / withdraw / cancel |
| `hr.staff_attendance.view` | register and history |
| `hr.staff_attendance.manage` | record, bulk register, correction |
| `hr.staff_attendance.self` | own attendance (read only) |
| `payroll.payslips.self` | own posted payslips |

- **`teacher` role:** unchanged at 4 capabilities, none `hr.*` or
  `payroll.*` (seeded test database, audited 2026-10-04). E33 / TCH-L1 and
  the owner decision of ADR 0063 §40 are untouched.
- **`staff_self_service` role:** exactly 3 capabilities —
  `hr.leave.self`, `hr.staff_attendance.self`, `payroll.payslips.self`.
- No role-name check anywhere (Leave/Staff Attendance guards).

## 5. Identity

`ActingEmployeeResolver` (HR) is the only User → Employee resolver. HRX
uses it in `LeaveRequestService`, `LeaveRequestReadService`,
`LeaveReadService`, `StaffAttendanceReadService` and `PayslipReadService`;
the guards ban `employees.user_id` / `'user_id'` in Leave and Staff
Attendance code.

## 6. Tenancy, RLS and database privileges

- **Forced RLS:** 191 tables repository-wide (pinned by
  `ElevationRlsIsolationTest`), including all 19 HRX tables.
  `UserReferenceCatalog`: 103 references, 93 retained (unchanged by HRX.6).
- **Runtime role** (`school_os_app`): not superuser, no BYPASSRLS. Grants
  on HRX tables:

| Grants | Tables |
|---|---|
| INSERT, SELECT (append-only) | `leave_ledger_entries`, `leave_decisions`, `leave_request_days`, `leave_year_close_items`, `leave_year_close_reconciliations`, `staff_attendance_corrections`, `leave_allocation_runs`, `leave_year_closes`, `leave_year_start_changes`, `leave_years`, `payroll_run_hrx_inputs` |
| INSERT, SELECT, UPDATE (guarded by triggers) | `leave_requests`, `leave_policy_assignments`, `staff_attendance_records`, `leave_policies`, `leave_types`, `leave_settings` |
| DELETE allowed (configuration) | `staff_holidays`, `staff_working_weekdays` only |

- **No DELETE on any HRX evidence table, and no EXECUTE on the HRX purge
  functions.** Evidence leaves only through the two retention functions,
  called by the retention identity (§3a, `HrxRetentionGuardTest`).
- **FK / cascade:**
  - every FK out of an HRX evidence table is RESTRICT; only
    `school_id → schools` cascades;
  - `payroll_run_hrx_inputs → payroll_run_results` cascades (the snapshot
    goes with its result) and references no HRX row;
  - same-School composite keys throughout.

## 7. Boundaries

- **Leave ↔ Staff Attendance.**
  - Staff Attendance reads Leave only through `LeaveCoverageReader`,
    `StaffCalendarService` and `LeaveLocks`.
  - Leave reads recorded presence only through its own port,
    `AttendancePresenceConflictReader` (bound in `AppServiceProvider`).
  - No cycle; neither writes the other's tables.
- **Payroll.**
  - Payroll reads HRX only through `PayrollAbsenceEvidenceReader`
    (`hrx_payroll_input.v1`), never a `leave_*` / `staff_attendance_*`
    table.
  - HRX never depends on Payroll.
  - No evidence reaches an amount, a statutory computation or the ECR
    (`PayrollHrxArchitectureGuardTest`).
  - ECR NCP stays the legacy `'0'`; no half-day rounding exists.
- **HR** depends on no HRX module.

## 8. Immutable evidence

- The ledger, decisions, request days, close items, reconciliations and
  attendance corrections are append-only (no runtime UPDATE/DELETE,
  trigger-guarded).
- An approved request carries its frozen day snapshot. A change is a new
  reversal, cancellation or correction row, never an edit.
- Attendance records change only by compare-and-swap together with a
  correction row; the database requires one.
- Payroll snapshots freeze at approval. Posted payroll is never rewritten:
  later HRX changes surface as fingerprint differences.

## 9. Legal gates — all OPEN

| Gate | Subject | State |
|---|---|---|
| HRX-L1 | health data (diagnosis, certificate, medical reason) | **OPEN** — structurally absent (guards); no medical upload |
| HRX-L2 | biometric / device attendance | **OPEN** — no clock time, device, location, photo or biometric column (guards) |
| HRX-L3 | statutory leave entitlements by State/establishment | **OPEN** — no jurisdiction cleared (`docs/security/HRX-L3-STATUTORY-LEAVE-APPLICABILITY-MATRIX.md`); entitlements are School-configured policy only |
| HRX-L4 | loss of pay and EPFO NCP | **OPEN** — mechanism implemented, legal activation blocked (`docs/security/HRX-L4-PAYROLL-LOSS-OF-PAY-DETERMINATION.md`) |

- **Primary-source blockers.** From this environment the official Gazette
  copy of S.O. 5322(E), the India Code text of the Code on Wages and the
  EPFO ECR material returned HTTP 403.
- Secondary summaries are recorded as such, never as qualified clearance.
- Owner authorization to build mechanisms is not legal validation.

## 10. Deferred limitations (recorded, not executed)

- **In-year carried-leave expiry is not executed.** `carried_expires_on`
  is recorded on the close item (§23.8); nothing lapses the carried units
  mid-year.
- **`LEAVE_CANCELLATION_CLOSE_CHAIN`.** Cancelling approved leave across
  two closed leave years is refused deterministically (§23.9); a
  correction then needs an administrative adjustment.
- No statutory leave formulas, shifts, overtime, clock-in/out, biometrics,
  medical upload, former-employee portal, or automatic NCP or salary
  deduction.

## 11. Self-service

- Own data only, through `ActingEmployeeResolver`:
  - own leave: view, submit, withdraw, cancel (decision path `self`, which
    the database derives);
  - own attendance: read only;
  - own posted payslips.
- A request for another Employee's resource answers the same private 404
  as an unknown id.
- A former Employee has no portal: self-service needs a current
  employment.

## 12. API, frontend and events

- **OpenAPI:** 57 HRX operations in `packages/contracts/openapi/school-os-api.yaml`.
  Coverage is bidirectional, route ↔ contract, in
  `LeaveOpenApiCoverageTest`, `StaffAttendanceOpenApiCoverageTest`,
  `StaffSelfServiceOpenApiCoverageTest` and
  `PayrollHrxOpenApiCoverageTest`. HRX.6 adds no route.
- **Frontend (12 pages + 1 panel):**
  - Leave: Configuration, Entitlements, Requests, RequestShow, Approvals,
    YearClose;
  - Staff Attendance: Register, History;
  - My: Leave, LeaveRequest, StaffAttendance, Payslips;
  - the HRX evidence panel on `Payroll/Runs/Show`.
- **Outbox (ADR 0025)** — minimized payloads, **not webhook-publishable**
  (absent from `WebhookEventRegistry`):
  - `leave.request.approved.v1`;
  - `leave.request.cancelled.v1`;
  - `staff_attendance.corrected.v1`.

### Audit event catalog
- `leave.type.created/updated/activated/deactivated`
- `leave.policy.created/retired/assigned/assignment_ended`
- `leave.settings.changed`, `leave.year.opened`,
  `leave.year_start.change_scheduled`
- `leave.calendar.pattern_changed/holiday_added/holiday_removed`
- `leave.allocation.granted`, `leave.allocation_run.executed`,
  `leave.adjustment.recorded`
- `leave.request.submitted/approved/rejected/withdrawn/cancelled`
- `leave.year_close.executed/reconciled`
- `staff_attendance.recorded/bulk_recorded/corrected`
- `payroll.payslip.self_viewed`
- `payroll.hrx_input.captured/difference_detected`

Ids, dates, units and closed codes only, never free text. The HRX.6 purge
writes no audit (retention metrics and logs carry counts only, as for every
E21 participant), and it never deletes audit rows.

## 13. Idempotency

25 of the 31 HRX API mutations carry `idempotent`:
- request submit, approve, reject, withdraw, cancel (admin, manager, self);
- allocation, allocation run, adjustment, assignment, assignment end;
- year open, start change, year close, policy create, type create, holiday
  add;
- attendance record, register, correction;
- the HRX difference check.

- **`completeWithin()` inside the business transaction** (rule 33):
  approval, cancellation, year-close execution, attendance writes.
- **The other 6**, reviewed per rule 29, are configuration writes whose
  repetition sets the same state or is refused by a state check:
  - `PUT` settings;
  - `PUT` weekdays;
  - `PATCH` type;
  - type status;
  - policy retire;
  - `DELETE` holiday.
- Retention is a console run, not an endpoint.

## 14. Final lock order

Every HRX writer acquires locks in this order. A writer skips a lock it
does not need, but never reorders.

| # | Lock | Mode |
|---|---|---|
| 1 | School operational guard | FOR SHARE |
| 2 | the request / record row | FOR UPDATE |
| 3 | HR rows (Employee, EmploymentRecord) | FOR SHARE / KEY SHARE (the retention purge: FOR UPDATE) |
| 4 | `hrx.staff_employment` | shared for HRX writers; exclusive for a Payroll capture and the retention purge, sorted by employment id |
| 5 | `hrx.staff_day` | ascending by employment id, then date |
| 6 | `leave.years` | schedule |
| 7 | `leave.calendar` | |
| 8 | `leave.assignment` | |
| 9 | `leave.year` | in leave-year order |
| 10 | `leave.balance` | sorted |

- **Payroll capture:** the run row FOR UPDATE, then `hrx.staff_employment`
  exclusive (sorted), then the calendar shared.
- **Retention purge:** the Employee FOR UPDATE, then its EmploymentRecords
  FOR UPDATE, then `hrx.staff_employment` exclusive (sorted), then the
  deletes.

## 15. Migrations

| Migration | `down()` |
|---|---|
| `2026_11_17_090000_create_leave_foundation` | refuses while Leave holds Employee evidence |
| `2026_11_18_090000_add_prospective_leave_year_configuration` | refuses while a start change or transition year exists |
| `2026_11_19_090000_create_leave_requests_and_year_close` | refuses while request / close evidence exists |
| `2026_11_20_090000_create_staff_attendance` | refuses while attendance evidence exists |
| `2026_11_21_090000_allow_self_leave_decisions` | refuses while `self` decisions exist |
| `2026_11_22_090000_create_payroll_run_hrx_inputs` | refuses while snapshots exist |
| `2026_11_23_090000_add_hrx_evidence_retention` | drops the mechanism only |

Each refusal names the retention path, which now exists.

**DDEV round trip** (2026-10-04, database `db`, verified `local|db`):
migrate, then rollback (the 3 functions removed), then migrate again
(3 functions restored).

## 16. Test isolation

- The HRX race tests commit their fixtures, so two real OS processes can
  see them:
  - Leave allocation and requests;
  - Staff Attendance;
  - Self-service;
  - Payroll HRX;
  - HRX retention.
- They now share `Tests\Concerns\PurgesCommittedHrxFixtures`, which
  removes:
  - School rows;
  - the test's Users;
  - the `test.capability_grant.*` roles minted since setUp, with their
    `role_capabilities`.
- Each test then asserts the durable counts equal its setUp snapshot.
- **Proof** (row counts of every table diffed): zero residue for each race
  file run alone twice, the HRX focused suite twice, and the Payroll suite
  before and after.
- The non-HRX Payroll race tests keep their documented best-effort
  cleanup. That residue is stable and pre-existing, outside HRX.
