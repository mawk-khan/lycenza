# ADR 0065: HRX Leave and Staff Attendance Contract

- Status: **Accepted as a contract (HRX.0, documentation only, closed).**
  **Amended by HRX.1** (§22): the owner recorded final decisions on the
  leave year (§4.3), mid-year joiners (§4.5), the self-service role (§13) and
  the day-portion contract (§4.4); HRX.1 — Leave Foundation is built. A
  later HRX.1 correction clarifies the leave year as prospectively
  configurable (§22.1a) and records the HRX.2 approved-leave invariant
  (§22.6). **HRX.2 — Leave Requests & Approval is built** (§23, decisions
  recorded before coding; as-built notes in §23.15). The
  remaining **[OWNER DECISION]** defaults still apply to HRX.2–HRX.6 unless
  the owner records a different choice before the checkpoint that needs it.
- Date: 2026-10-03
- Programme: **HRX — Leave & staff attendance**
  (`docs/roadmap/MASTER-ROADMAP.md`, "Post-foundation product programmes",
  order 3).
- Baseline: `origin/main` `ab9885e` (E21.4; formal regression checkpoint
  `ab9885e`, 7,420 tests, 0 failures, 1 skip).
- Builds on, and does not rewrite:
  - ADR 0028 (HR resequencing) and `docs/modules/HR.md` (Employee,
    EmploymentRecord, EmployeeAssignment, reporting hierarchy);
  - ADR 0034 and ADR 0036 (Payroll and statutory payroll);
  - ADR 0059 (User versus Employee; the closed School role catalog);
  - ADR 0063 (ActingEmployee; §14 assigns own leave, own payslip,
    direct-report approval and manager relationships to HRX);
  - ADR 0058 (production gates; E21 and E33 stay as recorded);
  - `docs/security/E21-RETENTION-DETERMINATION.md` (D9).
- Not a reopening: ADR 0061 defers nothing HRX covers. HRX is a new
  post-foundation programme, not a Phase 0H/0M item.
- Related: CLAUDE.md rules 4–7, 9, 11, 13, 17–24, 28–33, 60, 70, 86, 92.

Evidence convention: paths under `app/`, `database/` and `routes/` are
relative to `apps/platform/`. **[FACT]** is as built at `ab9885e`; everything
else is this ADR's contract.

## 1. Scope and non-scope

**In scope (v1):** School-configured leave types and policies, a working
calendar for staff, leave allocation, an auditable leave ledger, leave
requests with single-level manager approval, daily staff attendance with
auditable corrections, staff self-service (own leave, own attendance, own
payslip) and, as its own checkpoint, an explicit Leave → Payroll
loss-of-pay contract.

**Out of scope (v1), each needing its own reopening or gate:**
- anything health-related: diagnosis, medical reason, medical certificate,
  medical attachment, health condition, free-text health note (§10);
- biometric or device attendance: fingerprint, face recognition, any
  biometric device or kiosk integration, and GPS/geofenced clock-in (§10);
- automated state- or establishment-specific statutory leave entitlements
  (Shops & Establishments, Factories Act, maternity benefit computation,
  etc.) not yet legally validated (§19);
- clock-in/clock-out time tracking, shifts, overtime, and late-arrival
  minutes (§8);
- multi-level or HR-final approval chains, delegation and proxy approvers
  (§6);
- partial cancellation or modification of an approved request (§5);
- leave encashment and payroll payouts of leave balances;
- Recruitment (unassigned to any programme; not HRX);
- every Phase 10 / Phase 0K School Operations module (Library, Transport,
  Visitor, Hostel, Inventory, Canteen, Health, Safety), OPF, RES, POR,
  Lesson Planning and the cancelled LMS Submission.

HRX touches no TCH/E33 surface (§13).

## 2. Repository facts the contract builds on

- **[FACT] No Leave or Staff Attendance code exists.** At `ab9885e` there is
  no leave or staff-attendance migration, model, service, route, page,
  capability or test anywhere (search for leave_type, leave_request,
  leave_balance, staff_attendance, employee_attendance, LeaveType,
  LeaveRequest across `app`, `database`, `routes`, `resources/js`, `tests`:
  no hits). The only attendance is Student class attendance
  (`attendance_records` keyed on `student_enrollment_id`; capabilities
  `attendance.view`, `.manage`, `.teacher`).
- **[FACT] Payroll has no attendance input.** The ECR export states NCP
  days are always `0` (no attendance / loss-of-pay integration,
  `StatutoryEcrExportService`). A partial pay period is entered by staff as
  a manual override (`PayrollRunService::recordManualOverride`).
- **[FACT] HR structure.** `employees` → `employment_records` (statuses
  `draft`, `pre_joining`, `active`, `notice_period`, `separated`,
  `terminated`, `retired`, `deceased`; a rehire is a new record) →
  `employee_assignments` (`is_primary`, `starts_on`/`ends_on`, and
  `manager_assignment_id`, a same-School self-reference written only by
  `ReportingHierarchyService::setManager()`, audited, cycle-checked).
  Compensation attaches to `employment_record_id` with effective dates
  (`employee_compensation_assignments`).
- **[FACT] ActingEmployee** (`App\Domain\HR\Application\ActingEmployeeResolver`)
  resolves User → active membership → linked active Employee → exactly one
  eligible current EmploymentRecord (`active` or `notice_period`);
  `resolve()` reads fresh, `hold()` locks inside a consumer's transaction.
  It authorizes nothing by itself.
- **[FACT] No School holiday or working calendar exists.** Academic Years
  and Terms define academic dates only; there are no holiday, weekly-off or
  working-day tables.
- **[FACT] Roles.** Six system roles: `platform_super_admin`,
  `platform_auditor`, `group_admin`, `school_admin`, `principal`, `teacher`.
  The School role catalog is closed (`StaffRoleCatalog`, ADR 0059). The
  `teacher` role carries exactly four owned-scope capabilities, and no
  production `teacher` grant is allowed while E33 / TCH-L1 is open (ADR
  0063 §40).
- **[FACT] Payslips** are rendered on demand (`PayslipReadService`) for an
  approved-or-later run under `payroll.compensation.sensitive.view`; no
  employee self-service exception exists (`PAYROLL.md`).

## 3. Domain ownership and dependencies

- **Decision:** HRX lives in **two new Domain namespaces owned by the HR
  bounded context**: `App\Domain\Leave` (types, policies, calendar, ledger,
  requests, approvals) and `App\Domain\StaffAttendance` (daily records,
  corrections). Each has its own Application / Infrastructure / Http
  layers, like every other module. They are separate namespaces, not more
  files inside `App\Domain\HR`, so HR's existing guards (e.g. HR's own
  architecture tests) stay meaningful and each module's boundary is
  testable.
- **Dependency direction (one-way):**
  - `Leave` → `HR` (read Employee/EmploymentRecord/assignment facts and
    ActingEmployee through HR's Application services; never HR tables
    directly from Leave code that is not HR's own public read service);
  - `StaffAttendance` → `HR`, and `StaffAttendance` → `Leave` (it asks
    Leave's Application read service whether a date is covered by approved
    leave);
  - `Payroll` → `Leave` / `StaffAttendance` only through one explicit
    Application read contract (§9), added in HRX.5;
  - **never** `HR` → `Leave`/`StaffAttendance`, **never** `Leave` →
    `Payroll` or `StaffAttendance` → `Payroll`, **never** `Leave` →
    `StaffAttendance`. No circular dependency.
- **User → Employee resolution** stays HR's: HRX uses `ActingEmployeeResolver`
  and never resolves an Employee from a User on its own
  (`ActingEmployeeArchitectureGuardTest` keeps applying; HRX code must not
  name `employees.user_id`).
- **Cross-module effects** go through Application services or outbox
  events, never another module's tables.

## 4. Leave model

### 4.1 Leave types (School-configured)
- **Decision:** leave types are **School-configured**, with no
  system-defined statutory catalog in v1 (no state-specific legal
  assumption, §19). A School may seed common names (Casual, Earned, Sick,
  Unpaid) from a non-binding UI template in HRX.1.
- **Fields (v1):** `code` (normalized, unique per School, rule 74), `name`,
  `is_paid` (unpaid leave feeds loss-of-pay in HRX.5), `tracks_balance`
  (false: no allocation and no balance check, e.g. unpaid leave),
  `allows_half_day`, `status` (`active`/`inactive`; never deleted once used,
  rule 73).
- A type named "Sick" is a **category label only**: it collects no health
  detail (§10).

### 4.2 Leave policies and assignment
- **Decision:** a `LeavePolicy` (School configuration) holds, per leave
  type, the annual allocation (in units, §4.4), whether carry-forward is
  allowed, the carry-forward cap and the carried units' expiry (days after
  the new leave year starts, or none).
- **Assignment attaches to the EmploymentRecord**, with
  `effective_from`/`effective_to` and no overlap per EmploymentRecord (the
  `employee_compensation_assignments` precedent), so a rehire starts fresh
  and D9 follows the employment.
- Policy versions are immutable once used: a change is a new policy (or a
  new effective-dated assignment), never an in-place edit of a used one.

### 4.3 Leave year
- **Superseded by the final owner decision in §22.1** (School-configured
  start month, default April, independent of Finance). The original
  recommendation is kept below for the record.
- **[OWNER DECISION] Recommended default (superseded):** one **School-configured leave
  year** with a configurable start month, defaulting to the School's
  financial-year start month (`fee_settings`, default April). Alternatives:
  calendar year; academic year; employment anniversary. Not chosen by
  default because no single model is universally mandated, and a
  state/establishment-specific rule is a legal gate (§19), not a guess.
- The start month locks once the first allocation exists (the FEE
  receipt-numbering precedent).

### 4.4 Units (exact)
- **Final (§22.4):** the shared day-portion contract is `full` = 2,
  `first_half` = 1, `second_half` = 1 integer units, used by Leave and, from
  HRX.3, Staff Attendance.
- **Decision:** quantities are **integer half-day units** (1 = half a day,
  2 = a full day). No float, no decimal fraction anywhere. Half-days are
  allowed only when the leave type `allows_half_day`; a half-day is
  `first_half` or `second_half`.

### 4.5 Balances: ledger-derived
- **Decision:** an **append-only leave ledger**
  (`leave_ledger_entries`, signed integer units) per EmploymentRecord ×
  leave type × leave year. The balance is the **sum of its entries**,
  never a stored mutable balance. Entry kinds (closed list):
  - `allocation` (annual grant), `adjustment` (manual, reason code, by an
    authorized administrator);
  - `consumption` (written when a request is approved), `reversal`
    (written when approved leave is cancelled; references the consumption
    it reverses);
  - `carry_forward_in` / `carry_forward_out` (year close), `expiry`
    (unused carried units lapse).
- **Accrual (v1):** front-loaded annual allocation through an explicit,
  staff-triggered allocation run per leave year (preview → execute, the FEE
  assessment-run pattern). Monthly accrual is **out of v1**.
- **Superseded by §22.2:** a mid-year joiner gets an explicit `allocation`
  of the exact units an administrator specifies — not a full allocation
  corrected by a negative `adjustment`. The original text follows.
- **[OWNER DECISION] Mid-year joiners — recommended default (superseded):** no automatic
  proration; the allocation run grants the policy's full annual allocation,
  and an administrator records an explicit `adjustment` where a lower grant
  is intended (mirrors FEE decision D1: no proration).
- **No overdraft:** a balance-tracked type can never go negative; approval
  is refused (409) if the consumption would exceed the balance.
- Optionally a stored per-key summary may cache the sum for speed, but the
  ledger is authoritative and a verifier must be able to recompute it
  (the Finance dual-read precedent). Not required in v1.

### 4.6 Carry-forward and expiry
- Per policy line: allowed or not, cap in units, expiry of carried units.
- A staff-triggered **year-close run** (preview → execute, idempotent per
  School × leave year) writes `carry_forward_out`/`_in` and `expiry`
  entries. Nothing is ever recalculated in place.

### 4.7 Working calendar (holidays, weekly offs)
- **[FACT]** no authoritative School holiday calendar exists, so there is
  nothing to reuse.
- **Decision:** Leave owns a **staff working calendar** (School
  configuration): a weekly working pattern (which weekdays are working,
  half or off) and dated holidays per leave year. HRX.1 builds it.
  Requested leave counts only working days (a holiday or weekly off inside
  a request consumes nothing); a half-working day counts as one unit.
- If a School-wide calendar is later built elsewhere (e.g. an academic
  calendar), HRX adopts it through an explicit read contract rather than
  duplicating it; this ADR records that as the preferred future
  consolidation, not v1 scope.

### 4.8 Corrections
- Approved history is never edited in place. A wrong allocation is fixed
  by an `adjustment`; a wrongly approved request is cancelled (a
  `reversal`), and a replacement is a new request. Every correction is a
  new ledger entry with actor, time and a closed reason code.

## 5. Leave request lifecycle

- **States (closed):** `submitted`, `approved`, `rejected`, `withdrawn`,
  `cancelled`. **No `draft`**: a request is created at submission (an
  unsent form is the browser's state).
- **Transitions:**
  - `submitted → approved | rejected` (approver decision, §6);
  - `submitted → withdrawn` (the requester, or an administrator on their
    behalf);
  - `approved → cancelled` (the requester before the leave starts, or an
    administrator at any time; writes a `reversal` entry);
  - `rejected`, `withdrawn`, `cancelled` are terminal.
- **Content:** EmploymentRecord, leave type, start date + session, end
  date + session, computed units (from the calendar at submission,
  recomputed and fixed at approval), and an optional **closed** reason code
  — **no free-text reason field in v1** (it would invite health detail,
  §10). The approver's decision carries a closed reason code only.
- Overlapping live requests (submitted or approved) for the same
  EmploymentRecord on any shared date/session are refused.
- **Partial cancellation / editing** an approved request is out of v1:
  cancel and resubmit.
- A request for dates already inside a **posted** payroll period is allowed;
  its payroll effect is handled only by HRX.5's correction-safe contract
  (§9), never by rewriting posted payroll.

## 6. Approval model

- **Decision:** **single-level manager approval**, with an administrative
  path.
- **Manager:** the approver is the Employee holding the assignment named by
  the requester's assignment's `manager_assignment_id`, resolved
  **fresh at decision time** (the reporting line may have changed since
  submission). The requester's assignment is the one open on the decision
  date; with several open assignments, the `is_primary` one; with none
  primary and several open, the request has no manager approver.
- **Authorization = capability AND ownership:** the approver holds
  `hr.leave.approve` AND their verified ActingEmployee is that manager
  Employee (in this School, eligible today). No role name is ever checked.
- **Administrative path:** `hr.leave.manage` may decide any request in the
  School (no manager, manager absent, escalation). Recorded as an
  administrative decision.
- **No self-approval:** the decider's Employee can never be the requester's
  Employee (database CHECK on the decision row plus service check), even
  with `hr.leave.manage`.
- Multi-level, HR-final, delegation and proxy approval are out of v1.

## 7. Staff Attendance model

- **Decision:** **daily presence status**, not clock-in/clock-out events.
- **Record:** one live record per EmploymentRecord × date (database unique
  constraint), status from a closed list:
  - `present`, `absent`, `half_day_absent` (with `first_half`/`second_half`).
- **Not stored as attendance:** leave, holidays and weekly offs. The
  attendance **read model** derives `on_leave` from approved leave (via
  Leave's read service) and `holiday`/`weekly_off` from the working
  calendar, so Leave stays the single source of truth for leave.
- **Who records:** administrators with `hr.staff_attendance.manage`, for
  any Employee of the School, singly or in a bulk daily register. Manager
  recording and employee self-marking are out of v1 (self-marking without
  device or biometric verification is weak evidence, and device
  verification is gated, §10).
- **Recording on a leave day:** recording `present`/`absent` for a date and
  session covered by approved leave is refused (409); the leave must be
  cancelled first.
- **Corrections:** append-only. The record's status changes only through a
  correction command with expected-status compare-and-swap (the Student
  Attendance precedent), and every correction also writes an immutable
  `staff_attendance_corrections` row (previous status, new status, closed
  reason code, actor, time). No free text. History is never rewritten or
  deleted.
- Only an `active` or `notice_period` employment can be recorded, on dates
  inside its employment dates.

## 8. Leave ↔ Attendance relationship
- Leave never writes attendance rows; attendance never writes leave rows.
- `StaffAttendance` asks `Leave`'s Application read service
  (`coverageFor(employment, date)`) when recording and when building its
  read model.
- Approval of leave that covers an already-recorded `present` date is
  refused until that attendance is corrected (one consistent truth per
  date); the order of operations is the administrator's to resolve, never
  silently overridden.
- Unpaid days for payroll come from approved unpaid leave plus `absent`
  days, assembled by the HRX.5 contract (§9), not by either module writing
  into Payroll.

## 9. Payroll boundary (loss of pay / NCP days)

- **Current state [FACT]:** NCP / loss-of-pay days are always 0; partial
  periods are manual overrides.
- **Future contract (HRX.5, its own checkpoint):**
  - one HRX Application read contract, e.g.
    `UnpaidDaysProvider::forPeriod(school, employmentRecord, payrollPeriod)`,
    returning integer half-day units of approved unpaid leave and `absent`
    attendance inside the period, plus a deterministic **fingerprint** of
    the inputs;
  - Payroll **calls** it during calculation and **snapshots** the result
    into Payroll's own evidence (a payroll-owned input row or result line
    referencing the fingerprint); Payroll never queries Leave or
    StaffAttendance tables;
  - **posted payroll is never rewritten**: a later leave cancellation or
    attendance correction affecting a posted period is surfaced as a
    pending difference and flows only through Payroll's existing
    correction-run mechanism (ADR 0034), staff-triggered;
  - the calculation stays deterministic and exact (integer units; money by
    exact decimal, CLAUDE.md rule 9), and the NCP figure in the ECR export
    comes from the snapshot.
- Until HRX.5, HRX changes no Payroll behaviour.

## 10. Health-data and biometric boundary (v1)

- **Structurally absent in v1:** diagnosis, medical reason, medical
  certificate, medical attachment, health condition, any free-text health
  note, and any free-text reason at all on leave requests, decisions and
  attendance corrections. No Documents owner for leave in v1. An
  architecture guard test (like `AttendanceArchitectureGuardTest`) must
  fail if a reason/note/remark/certificate/attachment column is added.
- **"Sick leave"** may exist as a leave type name; it collects nothing
  else.
- **Gates:** any feature collecting medical documents, diagnosis, medical
  justification or a health condition is **[LEGAL REVIEW REQUIRED]** under
  the Health tier of `DATA-CLASSIFICATION.md` and stays outside HRX v1
  until specifically reopened. Biometric attendance (fingerprint, face,
  device integration) is a separate **[LEGAL / SECURITY REVIEW REQUIRED]**
  gate (biometrics are Highly Sensitive).

## 11. Self-service (ActingEmployee)

- **Identity:** every self-service action resolves the actor through
  `ActingEmployeeResolver` (`hold()` inside the write transaction). No
  second User → Employee resolver.
- **Own leave** (`hr.leave.self`): view own balances and requests, submit
  a request for the acting EmploymentRecord, withdraw own `submitted`
  request, cancel own `approved` request before it starts.
- **Own attendance** (`hr.staff_attendance.self`): read-only view of own
  daily records (and derived leave/holiday days).
- **Own payslip** (`payroll.payslips.self`): read own payslips for posted
  runs of the acting Employee's EmploymentRecords, through the existing
  `PayslipReadService` with an ownership path (the payslip's
  `employment_record_id` belongs to the acting Employee). No redesign of
  Payroll evidence. Statutory identifiers stay masked as they are today; a
  payslip whose result was expired by retention is an ordinary 404.
- **Manager** (`hr.leave.approve`): see and decide requests of direct
  reports only (§6). No broader team views in v1.
- Self-service routes follow the existing `/my/` surface convention and
  answer an identical 404 for anything the actor does not own (the TCH.6
  rule).

## 12. Capability catalog (proposed; implemented per checkpoint)

| Capability | Meaning | Default grant |
|---|---|---|
| `hr.leave.configure` | leave types, policies, policy assignments, working calendar, leave-year settings | `school_admin`, `principal` |
| `hr.leave.view` | read all leave records of the School | `school_admin`, `principal` |
| `hr.leave.manage` | allocation and year-close runs, adjustments, administrative decisions, act on behalf | `school_admin`, `principal` |
| `hr.leave.approve` | decide direct reports' requests (with the §6 ownership check) | `staff` role (§13) |
| `hr.leave.self` | own balances and requests | `staff` role (§13) |
| `hr.staff_attendance.view` | read the School's staff attendance | `school_admin`, `principal` |
| `hr.staff_attendance.manage` | record and correct staff attendance | `school_admin`, `principal` |
| `hr.staff_attendance.self` | own attendance (read) | `staff` role (§13) |
| `payroll.payslips.self` | own payslips (read) | `staff` role (§13) |

Names follow the existing dotted `module.resource.action` convention; the
`hr.staff_attendance.*` prefix keeps them distinct from Student
`attendance.*`. **No role-name authorization anywhere:** code such as
`$user->role === 'manager'` is prohibited (rule 24); checks are
`capability` (+ ownership where stated) through the existing Gate,
`capability:` middleware or `AuthorizesCapability`. Capabilities are
seeded in the checkpoint that first uses them, never earlier.

## 13. Teacher role freeze (E33)

**Final (§22.3):** the self-service role is named **`staff_self_service`**
(not `staff`); "`staff` role" below and in §12 reads as
`staff_self_service`. It is created in HRX.4, not earlier.

- The `teacher` role and its four capabilities are **not changed**. No HRX
  capability is added to it while E33 / TCH-L1 is open.
- **[OWNER DECISION] Recommended default:** a **new system School role
  `staff`** ("Staff self-service") carrying `hr.leave.self`,
  `hr.leave.approve`, `hr.staff_attendance.self` and
  `payroll.payslips.self`, added to the closed School role catalog
  (`StaffRoleCatalog`, ADR 0059) in HRX.4. Any employee, teaching or not,
  may hold `staff` alongside other roles. `staff` carries no Student data
  capability, so its production grants are not gated by E33.
  Alternative: grant self-service only to `school_admin`/`principal`
  (rejected as default: most staff could not use it).
- ActingEmployee, TeachingAssignment, Attendance-teacher and LMS-teacher
  authorization are untouched.

## 14. Tenancy and RLS

- Every HRX table carries `school_id`, uses `BelongsToSchool` (rule 17) and
  `TenantRls::enable()` (rule 18), and every child references its parent by
  composite `(id, school_id)` foreign keys (rule 70), including references
  to `employment_records` and `employee_assignments`.
- The School comes only from trusted context (route binding + membership,
  or `TenantContext::requireSchool()`), never from request payload (rules
  19, 68). Cross-School operations are impossible by construction.
- Queued work (allocation and year-close runs) uses `TenantScoped` (rule
  21) and declares `$tries`/`$timeout` (rule 58).
- Cross-tenant tests at the Eloquent and raw-SQL/RLS layer for every table
  (rule 28).

## 15. Data classification

| Record | Tier | Notes |
|---|---|---|
| LeaveType, LeavePolicy (+ lines), working calendar, holidays, leave-year settings | Confidential | School configuration, no personal data |
| LeavePolicyAssignment | Sensitive | names an Employee's employment |
| LeaveLedgerEntry (allocation, consumption, …) | Sensitive | Employee personal data (entitlement history) |
| LeaveRequest, LeaveDecision | Sensitive | Employee absence by date; no health detail (§10) |
| StaffAttendanceRecord, StaffAttendanceCorrection | Sensitive | Employee presence by date; no reason text |
| Unpaid-days snapshot in Payroll (HRX.5) | as Payroll results | Highly Sensitive with the result it feeds |

No new tier. Projections are minimized (Employee id, number and name;
never work contact, HR profile, compensation or account data), matching
the Timetable/TeachingAssignment rows. `DATA-CLASSIFICATION.md` gains its
rows in the checkpoint that creates each table.

## 16. Audit, domain events, idempotency

- **Audit (rule 11),** ids, dates, units and closed codes only, never free
  text: `leave.type.created/updated/deactivated`, `leave.policy.created`,
  `leave.policy.assigned/ended`, `leave.calendar.changed`,
  `leave.allocation_run.executed`, `leave.adjustment.recorded`,
  `leave.year_close.executed`, `leave.request.submitted/approved/rejected/
  withdrawn/cancelled`, `staff_attendance.recorded`,
  `staff_attendance.corrected`, `payroll.payslip.self_viewed`.
- **Domain events (outbox, ADR 0025; not webhook-publishable by default,
  rule 45/77):** `leave.request.approved.v1`, `leave.request.cancelled.v1`,
  `staff_attendance.corrected.v1` — minimized payloads (ids, dates, units,
  paid/unpaid) for HRX.5's pending-difference signal. No synchronous
  cross-module writes.
- **Idempotency (rules 29–33):** reviewed per endpoint. Required (client
  retry is plausible and duplication is costly): leave submission,
  approval/rejection, cancellation, adjustment, allocation/year-close run
  execution, bulk attendance recording, attendance correction. Allocation
  and year-close runs additionally carry their own database uniqueness per
  School × leave year × run (the FEE run precedent). Consumption entries
  are unique per approved request (database constraint), so a replayed
  approval never consumes twice (`completeWithin()` inside the business
  transaction, rule 33).

## 17. Concurrency

- **Balance:** approval locks the (EmploymentRecord, leave type, leave
  year) key — a `TenantLock`/advisory transaction lock keyed with the
  School (rule 60) or the EmploymentRecord row FOR UPDATE — then recomputes
  the ledger sum and inserts the consumption in the same transaction. Two
  concurrent approvals for one employee can never both consume the last
  units.
- **Approval:** the decision is a conditional UPDATE `WHERE status =
  'submitted'` (the Payroll approve precedent); exactly one decider wins,
  the other gets 409.
- **Approval vs withdrawal/cancellation:** the same conditional-UPDATE
  serialization; withdrawal only from `submitted`, cancellation only from
  `approved`; whichever commits first decides, the other is refused.
- **Attendance:** database uniqueness on (school, employment record, date)
  plus transactional validation; corrections use expected-status
  compare-and-swap.
- **Leave vs attendance:** approval and attendance recording both lock the
  EmploymentRecord row (one lock order: EmploymentRecord, then the leave
  key) so the §8 refusal rules hold under concurrency.
- Real two-process race tests for each, in the checkpoint that builds it.

## 18. Retention (E21-D9)

| Record | Category | Trigger / period |
|---|---|---|
| LeaveType, LeavePolicy (+ lines), working calendar, holidays, leave-year settings | tenant lifetime (School configuration) | none |
| LeavePolicyAssignment, LeaveLedgerEntry, LeaveRequest, LeaveDecision, StaffAttendanceRecord, StaffAttendanceCorrection | D9 employment evidence | 8 calendar years after the Employee's final separation (`EmployeeRetentionEligibility`), with the Employee's evidence unit |
| Allocation / year-close run headers | tenant lifetime (operational configuration, no per-employee amount) | none |
| Payroll unpaid-days snapshot (HRX.5) | Payroll evidence | with its payroll result (E21.3F) |

- Every new table is added to `TenantRetentionCatalog` and, where it
  references an Employee or EmploymentRecord, to
  `EmployeeRetentionClassificationTest` **in the checkpoint that creates
  it** (both fail closed otherwise). Until HRX.6 adds the D9 purge
  participant, those rows keep the Employee (`dependency_blocked`): safe,
  the longest period wins.
- No raw Employee cascade: Employee-referencing FKs are RESTRICT.
- Retention ratification stays E21's (pending pre-production).

## 19. Legal gates (recorded, not guessed)

- **HRX-L1 Health data** — any medical detail, certificate, diagnosis or
  health condition: [LEGAL REVIEW REQUIRED] (Health tier); out of v1.
- **HRX-L2 Biometrics / device attendance:** [LEGAL / SECURITY REVIEW
  REQUIRED]; out of v1.
- **HRX-L3 Statutory leave entitlements** — state Shops & Establishments
  Acts, Factories Act, maternity benefit and similar, which vary by state
  and establishment type: [LEGAL REVIEW REQUIRED] before any automated
  statutory entitlement; v1 entitlements are School-configured policy only
  and the product makes no statutory claim. India-first baseline with
  board/state deployment overlays; nothing CBSE- or state-specific is
  hard-coded.
- **HRX-L4 Loss-of-pay rules** — how unpaid days reduce wages under
  applicable wage law: confirm before HRX.5 enables any automatic
  deduction; until then HRX.5 may compute and display, with the deduction
  itself an explicit staff decision if the owner so chooses.
- These do not block HRX.1–HRX.4 development; they bound it.

## 20. Checkpoint plan

| Checkpoint | Scope |
|---|---|
| **HRX.0 — Contract** | this ADR (docs only). Closed |
| **HRX.1 — Leave Foundation** | `Leave` namespace; leave types, policies, policy assignments, leave-year settings, working calendar; the append-only ledger; allocation runs and manual adjustments; balance read; admin UI/API; capabilities `hr.leave.configure/.view/.manage`; tenancy, RLS, audit, classification and catalog rows |
| **HRX.2 — Leave Requests & Approval** | request lifecycle, unit computation from the calendar, manager approval (fresh reporting-line ownership) and administrative decisions, cancellation/reversal, year-close run with carry-forward and expiry; `hr.leave.approve`; balance/approval races |
| **HRX.3 — Staff Attendance Foundation** | `StaffAttendance` namespace; daily records, bulk register, CAS corrections with history, leave-coverage refusal, read model; `hr.staff_attendance.view/.manage`; races |
| **HRX.4 — Staff Self-Service** | the `staff` role (owner decision §13) in the closed catalog; own leave, own attendance, own payslip (`payroll.payslips.self` through `PayslipReadService`); `/my/` surfaces; identical-404 rule |
| **HRX.5 — Payroll Loss-of-Pay Integration** | the §9 read contract, Payroll snapshot, NCP days in ECR, pending-difference signal into correction runs; gated by HRX-L4 for automatic deduction |
| **HRX.6 — Retention, Readiness & Closure Audit** | D9 purge participant for HRX evidence, readiness, architecture audit, full regression, programme closure |

Each checkpoint is mechanism-bounded, ships focused, authorization (allow
and deny), tenancy/RLS, concurrency and architecture tests, a DDEV review,
and follows the rule 82 full-regression cadence.

## 21. What this ADR does not do

- No code, migration, capability, route or UI; no test changes.
- No change to Phase 10 / Phase 0K modules, OPF, RES, POR, Lesson
  Planning, Health/Safety or the cancelled LMS Submission.
- No change to the `teacher` role, ActingEmployee, TeachingAssignment, or
  Attendance/LMS teacher authorization; E33 / TCH-L1 unchanged.
- No E21 reopening; retention classification only.
- No statutory leave claim and no production legal clearance.

## 22. Amendment — HRX.1 final owner decisions and as-built notes (2026-10-03)

Recorded at the start of HRX.1 (baseline `ce4c06a`). These are **final
owner decisions**; they supersede the matching recommended defaults above.

### 22.1 Leave year (supersedes §4.3)
- The HRX leave year is **School-configured**:
  `leave_settings.leave_year_start_month`, 1..12, **default 4 (April)**.
- April is a **product default, not a statutory rule**; it implies no
  jurisdiction's law (HRX-L3 stays open).
- **No Leave → Finance dependency.** Leave never reads `fee_settings` or any
  Finance period, and a Finance change can never alter a Leave period
  (`LeaveArchitectureGuardTest`).
- **Historical boundaries never move silently.** A leave year is
  materialized as a `leave_years` row with frozen `starts_on`/`ends_on`/
  `start_month` (UPDATE and DELETE revoked from the runtime role; a trigger
  refuses overlap).
- **Corrected (HRX.1 leave-year configuration correction, 2026-10-03):** as
  first built, HRX.1 froze the start month permanently once any leave year
  existed. That was stronger than this decision and is replaced by §22.1a.

### 22.1a Prospective leave-year configuration (clarifies §22.1)
- **Leave-year configuration is prospectively configurable.**
- **Materialized `leave_years` rows are immutable.** No UPDATE or DELETE
  for the runtime role; the trigger refuses any update.
- **Changing the future School setting never mutates, reinterprets or
  relocates historical Leave evidence.** Ledger entries and policy
  assignments stay on the years and employments they were recorded
  against.
- **Mechanism:**
  - `leave_settings.leave_year_start_month` is the **base** start month. It
    changes directly only while the School has no leave year and no
    scheduled change (`LEAVE_YEAR_LOCKED`, database trigger on INSERT and
    UPDATE).
  - After that, a change is an append-only **`leave_year_start_changes`**
    row: `previous_start_month`, `start_month` and `effective_from`
    (`POST …/leave/year-start-changes`, `hr.leave.configure`,
    idempotent).
- **`effective_from` must be all of:**
  - the **first day of the new start month**;
  - strictly in the **School-local future**;
  - strictly **after the end of every materialized leave year**;
  - strictly **after every earlier change**.

  Otherwise it is refused (`LEAVE_YEAR_EFFECTIVE_FROM_INVALID`,
  `LEAVE_YEAR_BOUNDARY_INVALID`; a no-op change is
  `LEAVE_YEAR_START_UNCHANGED`). The database enforces the boundary rules
  again under the School's `leave.years` advisory lock.
- **Transition year:**
  - The year that would cross a change is cut short the day before it takes
    effect. It is materialized as an explicit **transition year**
    (`is_transition`, shorter than a full year, label `YYYY-MM/YYYY-MM`).
  - From `effective_from` on, years follow the new start month.
  - Consecutive years therefore never overlap and never leave a gap. No year
    has zero length, and every date belongs to exactly one year.
  - Example: April schedule, change to January effective 2028-01-01. The
    years are 2026-27 (unchanged), then 2027-04-01..2027-12-31
    (transition), then 2028.
- **Database consistency.** The leave-year trigger refuses a year that
  disagrees with the schedule in force:
  - a start month other than the one governing its start date;
  - a year crossing a change;
  - a transition year that does not end the day before a recorded change.
- **Unchanged:** default April (a product default), month 1..12, and no
  Finance dependency.

### 22.2 Mid-year joiners (supersedes the §4.5 default)
- **No automatic proration**, anywhere.
- A mid-year joiner receives an **explicit `allocation` entry of the exact
  units an administrator specifies** (`POST …/leave/allocations`). It is not a full
  allocation followed by a negative adjustment.
- At most one `allocation` per EmploymentRecord × leave type × leave year
  (partial unique index). The annual run grants the policy's full annual
  units only to employments that have no allocation yet, and skips any
  explicit grant.
- `adjustment` is reserved for **later corrections**, with a closed reason
  code (`entitlement_change`, `allocation_correction`,
  `administrative_correction`) and an explicit `credit`/`debit` direction.

### 22.3 Self-service role (supersedes the §13 default)
- HRX.4 creates a **separate `staff_self_service` role / capability bundle**
  in the closed School role catalog.
- The `teacher` role is **unchanged while E33 is open**: no HRX capability is
  added to it.
- Authorization **never checks a role name**; only capabilities (+
  ownership where stated).
- HRX.1 creates no `staff_self_service` role and no `.self`/`.approve`
  capability (guarded by `LeaveArchitectureGuardTest`).

### 22.4 Shared day-portion contract (refines §4.4)
- `App\Domain\Leave\Application\DayPortion`: `full` = 2, `first_half` = 1,
  `second_half` = 1 integer half-day units.
- Never `0.5`, floating point or decimal. Every unit column is `integer`
  with a `> 0` check (ledger) or `>= 0` (policy annual allocation).
- A half-day always identifies **which** half. A weekly working pattern day
  is `full`, `first_half` or `off`; a holiday is `full`, `first_half` or
  `second_half`. `WorkingDayCalculator` counts the intersection of the
  requested halves with the working halves.
- HRX.3 Staff Attendance reuses this contract; it does not define its own.

### 22.5 HRX.1 as built
- **Namespace:** `app/Domain/Leave` (Application, Infrastructure, Http).
  Leave depends on HR only through `EmploymentCoverage::holdRecord()`, a
  new HR-owned Application method (FOR SHARE on the Employee and the
  EmploymentRecord). HR and Payroll never depend on Leave.
- **Tables (10):** `leave_settings`, `leave_year_start_changes` (added by
  the §22.1a correction), `leave_years`, `leave_types`, `leave_policies`,
  `leave_policy_assignments`, `staff_working_weekdays`, `staff_holidays`,
  `leave_allocation_runs`, `leave_ledger_entries`. All of them use forced
  RLS, composite `(id, school_id)` foreign keys and RESTRICT.
  No Employee/EmploymentRecord cascade.
- **Ledger shape:** each entry has a `kind` (closed list, §4.5) and
  **positive** `units`; the sign follows from the kind (credit kinds:
  `allocation`, `reversal`, `carry_forward_in`; an `adjustment` carries an
  explicit `direction`). This replaces §4.5's "signed integer units": the
  balance is still the sum. The ledger is append-only (UPDATE/DELETE
  revoked).
- **No negative balance, enforced by the database.** A trigger locks the
  balance key (advisory transaction lock) and refuses any entry that would
  take credits − debits below zero (`LEAVE_BALANCE_INSUFFICIENT`). It also
  refuses a reversal that does not match exactly one consumption.
- **Untracked types never reach the ledger.** A composite foreign key on
  `(leave_type_id, school_id, tracks_balance = true)` makes this structural.
- **Immutability triggers:**
  - policies: only `active → retired`; a change is a new superseding
    version;
  - leave types: identity and rules freeze once used;
  - assignments: only end or shorten; overlap is refused under an advisory
    lock;
  - leave years: frozen.
- **Allocation run:**
  - preview → execute, once per School × leave year × leave type
    (`leave_allocation_runs_once`, `LEAVE_RUN_ALREADY_EXECUTED`);
  - it is executed synchronously in one transaction, not as a queued job.
    The School's staff count makes a queue unnecessary, and §14's
    `TenantScoped` requirement applies when a queued run is introduced;
  - the header is append-only and records the final count.
- **Carry-forward/expiry:** the schema is in place (policy cap/expiry
  terms; `carry_forward_in`/`carry_forward_out`/`expiry` kinds), and the pure
  `CarryForwardCalculator` computes it. No code writes those kinds in HRX.1;
  the year-close run is HRX.2.
- **Working calendar:**
  - a weekly pattern of all 7 ISO weekdays plus dated holidays;
  - it fails closed (`LEAVE_CALENDAR_NOT_CONFIGURED`) until all seven days
    are set.
- **Capabilities:** only `hr.leave.configure`, `hr.leave.view` and
  `hr.leave.manage`, granted to `school_admin` and `principal`.
- **API:** 25 `/api/v1/schools/{school}/leave/…` operations (24 + the
  §22.1a start-month change), all
  `private-no-store`. Every entitlement and configuration create is
  `idempotent`; allocation, run and adjustment complete inside their
  transaction (`completeWithin`, rule 33).
- **Audit:** `leave.settings.changed`, `leave.year_start.change_scheduled`
  (old and new month, `effectiveFrom`; actor, School and time are on the
  event), `leave.year.opened`, `leave.type.*`,
  `leave.policy.created/retired`, `leave.calendar.*`, `leave.policy.assigned`/`assignment_ended`,
  `leave.allocation.granted`, `leave.allocation_run.executed`,
  `leave.adjustment.recorded`. Metadata is ids and units only.
- **Domain events:** none in HRX.1. Nothing consumes leave configuration yet,
  so no outbox event is emitted; HRX.2 adds request/decision events
  (not webhook-publishable, rule 77).
- **Admin UI deferred to HRX.2.** HRX.1 ships the API and services only. The
  administrative screens arrive with the request/approval UI in HRX.2, so
  configuration and decisions share one surface.
- **Retention:**
  - `leave_configuration` (settings, years, types, policies, calendar, run
    headers) is tenant lifetime;
  - `leave_evidence` (assignments, ledger) is D9 employment evidence. Its
    status is `MECHANISM_PENDING` until HRX.6 adds the purge participant;
  - until then the Employee's evidence unit is `dependency_blocked`
    (retained, the longest period wins).
- **Health/biometric exclusion is structural:** no Leave column can hold a
  diagnosis, medical reason, certificate, attachment, note, free text or
  biometric. `reason_code` is a closed list, and there is no Document owner
  for leave. This is guarded by `LeaveArchitectureGuardTest`.
- **Legal gates unchanged:** HRX-L1, L2, L3 and L4 remain open. HRX-L3's
  applicability matrix is
  `docs/security/HRX-L3-STATUTORY-LEAVE-APPLICABILITY-MATRIX.md`, and no
  jurisdiction is cleared.

### 22.6 HRX.2 forward invariant (recorded; not implemented in HRX.1)
- **Approved leave must retain the exact chargeable dates, day portions and
  integer units determined at approval/consumption time.**
- A later change to any of these must never silently alter approved
  historical leave evidence:
  - the weekly working pattern;
  - staff holidays;
  - a leave policy;
  - the leave-year configuration.
- HRX.2 therefore **persists the chargeable-day calculation** as immutable
  evidence at approval: per date, the portion and the units charged, with the
  leave year. The `consumption` ledger entry carries exactly that total.
  `WorkingDayCalculator` is never re-run to reinterpret an approved request.
- A configuration change affects only requests not yet approved. Approved
  leave is changed only by cancellation (`reversal`) and a new request
  (§4.8).

## 23. HRX.2 — Leave Requests & Approval: decisions recorded before coding (2026-10-03)

Baseline `f1001ec`. These close the open details of §5, §6, §16 and §17 for
HRX.2. Where they narrow a default above, this section governs.

### 23.1 Request shape
- A request names one EmploymentRecord and one leave type. It has
  `starts_on` + `start_portion` and `ends_on` + `end_portion`.
- **One day** (`starts_on = ends_on`): both portions are equal, and they are
  `full`, `first_half` or `second_half`.
- **Several days:**
  - the first day is `full` or `second_half` (the leave starts at the half
    break);
  - the last day is `full` or `first_half`;
  - every day in between is `full`.
- Every other combination is refused by a database CHECK. A non-`full`
  portion needs a leave type that `allows_half_day`.
- **Expansion is deterministic.** Each date contributes the halves above.
  A request is one contiguous run of half-days, stored as generated
  integer half-day indices (`first_half_index`, `last_half_index`).
- Dates, portions, type and employment never change after submission
  (database trigger). A changed request is a new request.

### 23.2 Overlap
- A `submitted` or `approved` request reserves its half-days.
- A trigger under the per-employment advisory lock `leave.requests:{school}:{employment}`
  refuses any live request whose half-day run intersects another live
  request of the same EmploymentRecord (`leave_request_overlap`), whatever
  the leave type.
- Opposite halves of one day never intersect. `rejected`, `withdrawn` and
  `cancelled` requests reserve nothing.
- The repository's no-`btree_gist` precedent is kept.

### 23.3 Closed reason codes (no free text, nothing health-related)
- **Request (optional):** `personal`, `family`, `official_duty`, `other`.
- **Rejection (required):** `staffing_need`, `policy_not_met`,
  `duplicate_request`, `entered_in_error`, `other`.
- **Withdrawal / cancellation (required):** `plans_changed`,
  `entered_in_error`, `administrative_correction`, `other`.

### 23.4 Chargeable-day evidence (immutable, §22.6)
- **Calculation at approval.** A working half is a requested half that is
  working in the weekly pattern and not covered by a staff holiday. The
  calendar must be fully configured (`LEAVE_CALENDAR_NOT_CONFIGURED`).
- **Evidence rows.** Approval writes one `leave_request_days` row per date
  with at least one chargeable half: date, portion, units (1 or 2), the
  materialized leave year, and — for a balance-tracked type — the policy
  assignment and policy version effective on that date. The table is
  append-only for the runtime role.
- **Never recomputed.** Display, cancellation and year close read these
  rows; `WorkingDayCalculator` is never re-run for an approved request.
- **No chargeable half.** Submission is refused
  (`LEAVE_REQUEST_NO_WORKING_DAYS`), and so is an approval whose fresh
  calculation finds none.
- **Policy and years.**
  - Every chargeable date of a tracked type needs an effective policy
    assignment (`LEAVE_NO_POLICY_ASSIGNMENT`).
  - Every chargeable date needs a materialized leave year
    (`LEAVE_YEAR_NOT_OPEN`).
  - A request crossing a leave-year or policy boundary is **split by
    date**. Each day carries its own year and policy, and consumption is
    one `consumption` ledger entry per (request, leave year). Each entry
    passes its own balance invariant. A unique index allows one consumption
    per request × year.

### 23.5 Decisions and self-decision
- **Evidence.** `leave_decisions` is append-only. Each row has the request,
  the decision (`approved`, `rejected`, `withdrawn`, `cancelled`), the path
  (`manager` or `administrative`), the deciding User, the Employee linked
  to that User (set by a database trigger, never by the caller), the
  requester's Employee, a closed reason code, and the time.
- **One decision of each kind.** At most one `approved`/`rejected`/
  `withdrawn` decision and one `cancelled` decision per request (partial
  unique indexes). The request's status changes only by a conditional
  transition in the same transaction.
- **No person approves or rejects their own request, on either path.** A
  database CHECK requires the decider's Employee to differ from the
  requester's Employee for `approved` and `rejected` decisions. Because the
  trigger derives both Employees, raw SQL cannot bypass it. The service
  refuses first (`LEAVE_SELF_DECISION`). An administrative override for
  one's own request is **not** permitted. Withdrawal and cancellation by
  the requester are allowed (HRX.4 uses them).

### 23.6 Manager path (capability AND fresh ownership)
- **The rule.** The manager path needs `hr.leave.approve` and that the
  acting Employee (`ActingEmployeeResolver::hold()`) is the requester's
  current manager.
- **Who the manager is.** HR answers that through a new HR Application
  contract, `ReportingLine`, read FOR SHARE at decision time:
  - take the requester's assignment open today (with several, the
    `is_primary` one; otherwise none);
  - take its `manager_assignment_id`, which must also be open today;
  - the manager is that assignment's Employee.

  The answer is never snapshotted at submission.
- **No relationship.** A request outside the actor's current direct
  reports is the private 404 (`/leave/approvals/...`). The manager list is
  built server-side from `ReportingLine`.

### 23.7 Administrative path
- `hr.leave.manage` decides any request of the School (approve, reject,
  withdraw on behalf, cancel at any time), recorded with path
  `administrative`.
- HRX.2 submits on behalf through `hr.leave.manage`. The same
  `LeaveRequestService::submit()` is what HRX.4 will call for
  self-service.

### 23.8 Year close
- **Scope.** One run per School × leave year (`leave_year_closes`, unique)
  covers every balance-tracked type. Preview, then execute
  (`hr.leave.manage`, idempotent, audited).
- **Refused unless all of these hold:**
  - the year has ended in the School's timezone (`LEAVE_YEAR_NOT_ENDED`);
  - the adjacent previous year, if materialized, is closed
    (`LEAVE_YEAR_CLOSE_ORDER`);
  - the adjacent next year (starting the day after it ends; a transition
    year counts) is materialized (`LEAVE_NEXT_YEAR_NOT_OPEN`);
  - no `submitted` request overlaps the year's dates
    (`LEAVE_YEAR_CLOSE_PENDING_REQUESTS`).
- **Items.** For every (employment, type) with ledger entries in the year,
  the run writes an immutable `leave_year_close_items` row. It holds the
  closing balance (ledger-derived) and the policy used: the assignment
  effective on the year's last day, else the latest assignment overlapping
  the year. It also holds the carried units, the lapsed units and the
  carried units' expiry date (`CarryForwardCalculator`).
- **Ledger entries.**
  - `carry_forward_out` (closing year) and `carry_forward_in` (next year)
    of the carried units;
  - `expiry` (closing year) of the lapsed units.

  The closing year's balance is then zero.
- **Carried-unit in-year expiry is recorded, not executed, in HRX.2.**
  `carry_forward_expiry_days` produces the item's `carried_expires_on`. The
  scheduled lapse of unused carried units inside the next year needs a
  consumption-ordering rule (which units are used first) and its own
  reconciliation. That is deferred to a later checkpoint and is shown in
  the UI as recorded, not enforced. Nothing is silently lapsed.
- **A closed year is sealed.** The ledger trigger takes a shared
  `leave.year:{school}:{year}` advisory lock for every entry (the close
  takes it exclusively). In a closed year it allows only `reversal`
  entries and the close's or a reconciliation's own `carry_forward_out`/
  `expiry` entries (`LEAVE_YEAR_CLOSED`). Allocation, adjustment,
  consumption and `carry_forward_in` into a closed year are refused.

### 23.9 Cancellation after close: reconciliation
- **When.** Cancelling an approved request whose consumption sits in a
  closed year reconciles that year in the **same transaction**. This is
  automatic, deterministic and never a rerun of the close.
- **Steps.** With `u` the reversed units:
  - read the year's close item, its recorded policy cap, and the prior
    reconciliations;
  - the previous closing balance `B` is the item's closing balance plus the
    units of earlier reconciliations;
  - the previous carried figure `C` is the item's carried units plus their
    `Δc`;
  - the new carried figure is `C' = min(B + u, cap)` (0 when carry-forward
    is not allowed);
  - so `Δc = C' − C` and `Δl = u − Δc`. Both are ≥ 0.
- **Entries.** `carry_forward_out Δc` and `expiry Δl` in the closed year,
  and `carry_forward_in Δc` in the next year. They are linked to a
  `leave_year_close_reconciliations` row (close item, reversal entry,
  request, units, Δc, Δl). The original close, items, consumption and
  reversal stay untouched. The closed year returns to zero.
- **Policy.** The original item's policy governs. Today's policy is never
  used.
- **Two consecutive closed years.** If the next year is also closed, the
  cancellation is refused (`LEAVE_CANCELLATION_CLOSE_CHAIN`). Correcting
  across two closed years needs a chained reconciliation design that v1
  does not build; the administrator corrects in the open year with an
  adjustment.
- **Uniqueness.** One reconciliation per reversal entry.

### 23.10 Lock order (deadlock-free)
1. School `FOR SHARE` (`SchoolOperationalGuard`), then the request row
   `FOR UPDATE`.
2. HR rows: ActingEmployee `hold()`, employment coverage, then the
   reporting line `FOR SHARE`.
3. `leave.years:{school}`, then `leave.calendar:{school}` (shared for
   readers; exclusive for every calendar writer, holidays included), then
   policy-assignment keys.
4. `leave.year:{school}:{year}`, shared, in leave-year order.
5. The balance keys, in sorted order.

The year close takes 1, then 4 exclusively, then 5. A submission takes the
request lock (2.2) and the year locks for the years it touches.

### 23.11 Events (outbox; not webhook-publishable)
- `leave.request.approved.v1` carries: the request id, employment record
  id, employee id, leave type id, `isPaid`, `tracksBalance`, and the days
  as `[{date, portion, units, leaveYearId}]` plus the total units.
- `leave.request.cancelled.v1` carries the same identifiers and the
  reversed units per leave year.
- There is no free text and no health data. Nothing consumes them before
  HRX.5.

### 23.12 Capability
- `hr.leave.approve` is registered in HRX.2 and granted to `school_admin`
  and `principal`. It is **not** added to `teacher` (E33).
- In development and tests, a manager receives it through the ordinary
  grant mechanism (`createUserWithCapabilities`, or a School role
  assignment).
- `hr.leave.self` and `staff_self_service` stay HRX.4.

### 23.13 Retention
- `leave_requests`, `leave_request_days`, `leave_decisions`,
  `leave_year_close_items` and `leave_year_close_reconciliations` are D9
  employment evidence (`leave_evidence`, mechanism pending HRX.6). They are
  RESTRICT to the EmploymentRecord.
- `leave_year_closes` (the header) is tenant lifetime.

### 23.14 Admin UI
- **Session-authenticated Inertia pages** under `/app/leave/*`:
  - configuration (settings, years, types, policies, calendar);
  - entitlements (assignments, allocations, adjustments, runs, balances);
  - requests (submit on behalf, decide, withdraw, cancel, evidence);
  - year close;
  - the manager's approvals.
- **Server-side gates.** Every page checks capabilities, and the services
  check them again. The manager page lists only server-resolved direct
  reports. No self-service screen and no Teacher-specific screen.

### 23.15 HRX.2 as built
- **Tables (6), all with forced RLS and composite same-School RESTRICT
  foreign keys:**
  - `leave_requests`;
  - `leave_request_days` (append-only);
  - `leave_decisions` (append-only);
  - `leave_year_closes` (append-only);
  - `leave_year_close_items` (append-only);
  - `leave_year_close_reconciliations` (append-only).

  The ledger gains `leave_request_id`, `year_close_id` and
  `year_close_reconciliation_id`. A links CHECK ties consumption/reversal
  to a request and carry/expiry movements to a close. Unique indexes allow
  one consumption per request × year and one close movement per kind and
  key.
- **What the database enforces itself:**
  - the request shape;
  - live-request overlap (half-day indices, advisory lock);
  - immutable request content;
  - the closed status graph, where every transition needs its decision
    evidence;
  - an approval needs day evidence and, for a tracked type, a consumption
    for every charged year;
  - a cancellation needs a reversal for every consumption;
  - day evidence written only while approving, inside the request dates,
    with the year containing the date and the assignment effective on it;
  - both Employees on a decision derived by trigger, with the no-self
    CHECK;
  - a consumption equal to that year's day evidence;
  - a reversal of exactly one consumption of the same request;
  - the year-close seal.
- **HR contracts used (HR Application, Leave depends one way):**
  - `ReportingLine::holdManagerOf()` / `reportsOf()`, which is new;
  - `EmploymentCoverage::holdRecordCovering()`, new (the employment must
    span the whole request);
  - `ActingEmployeeResolver::hold()/resolve()`, only in
    `LeaveRequestService` and `LeaveRequestReadService` (guarded).
- **Locks.** `LeaveLocks` holds every Leave advisory key. The HRX.1
  allocation path now takes the year (shared) before the balance key, and
  holiday add/remove take the calendar lock.
- **API.** 15 new operations, 40 in all:
  - `/leave/requests` (7: list, submit, show, approve, reject, withdraw,
    cancel);
  - `/leave/approvals` (4);
  - `/leave/year-closes` (4).

  Approve, cancel and year-close execute complete their idempotency
  record inside the business transaction (rule 33). Submit, reject and
  withdraw use the generic `idempotent` completion, because their
  conditional transition is itself duplicate-safe.
- **Manager reads.** `hr.leave.approve` reads leave-type id, code and name
  only (`LeaveReadService::typeNames()`), never the rest of the
  configuration.
- **UI.** `/app/leave/{configuration,entitlements,requests,requests/{id},
  year-close}` (administration) and `/app/leave/approvals` (manager). The
  Dashboard links are gated by `hr.leave.view` / `hr.leave.approve`.
- **Events.** `leave.request.approved.v1` and
  `leave.request.cancelled.v1` go through the outbox. They are not in
  `WebhookEventRegistry`.
- **Audit.** `leave.request.submitted/approved/rejected/withdrawn/cancelled`
  (with path, reason code, days and units),
  `leave.year_close.executed` and `leave.year_close.reconciled`.
- **Recorded, not executed.** The in-year lapse of carried units
  (`carried_expires_on`), see §23.8.
