# ADR 0065: HRX Leave and Staff Attendance Contract

- Status: **Accepted as a contract (HRX.0, documentation only, closed).**
  **Amended by HRX.1** (§22): the owner recorded final decisions on the
  leave year (§4.3), mid-year joiners (§4.5), the self-service role (§13) and
  the day-portion contract (§4.4); HRX.1 — Leave Foundation is built. A
  later HRX.1 correction clarifies the leave year as prospectively
  configurable (§22.1a) and records the HRX.2 approved-leave invariant
  (§22.6). **HRX.2 — Leave Requests & Approval is built** (§23, decisions
  recorded before coding; as-built notes in §23.15). **HRX.3 — Staff
  Attendance is built** (§24: the per-half storage refinement of §7 and the
  Leave ↔ Attendance mechanism, recorded before coding; as-built notes in
  §24.16). **HRX.4 — Staff Self-Service is built** (§25, decisions
  recorded before coding; as-built notes in §25.13). **HRX.5 — the HRX →
  Payroll evidence mechanism is built; legal activation is blocked by
  HRX-L4** (§26, recorded before coding; as-built notes in §26.14).
  **HRX.6 — Retention, Readiness & Closure is built** (§27): Leave and
  Staff Attendance evidence join the existing E21-D9 employee retention run;
  the purge boundary is hardened (§27.10: no runtime EXECUTE, retention
  identity and School hold enforced in the database);
  the HRX implementation programme is **closed**, with HRX.5's legal
  activation still gated (HRX-L1–L4 open). Closure record:
  `docs/modules/HRX-READINESS-AND-CLOSURE.md`.
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

## 24. HRX.3 — Staff Attendance: decisions recorded before coding (2026-10-03)

Baseline `52ce8c4`. This refines §7, §8, §16 and §17 for HRX.3. It adds no
product scope: attendance stays **daily** and **administrative**, never
clock-in/clock-out. Where it narrows a default above, this section governs.

### 24.1 Why §7's single status is refined
- §7 proposed one daily status (`present`, `absent`, `half_day_absent` +
  which half). HRX.2 now approves **exact half-day leave**
  (`first_half` / `second_half`, §22.4, §23.1).
- One daily enum cannot reconcile safely with that. "Half-day absent, first
  half" beside an approved second-half leave says nothing about the second
  half, and "present" beside a first-half leave is ambiguous.
- So the record stores **each half separately**.

### 24.2 Storage: one row per EmploymentRecord × date, two halves
- `staff_attendance_records`: exactly one row per School × EmploymentRecord
  × `attendance_date` (database unique key).
- `first_half_status` and `second_half_status`, each `present`, `absent`
  or `NULL`. `NULL` means **Staff Attendance holds no evidence for that
  half**. It never means leave, holiday or off-day.
- **Never persisted as attendance:** leave, holiday, weekly off, sick,
  medical, late, clocked-in/out, or any other value. The value list is
  closed by a database CHECK, with no extensibility in v1.
- A new record carries evidence for at least one half. A row with both
  halves `NULL` can exist only after a correction (§24.8).
- The record attaches to the **EmploymentRecord** (composite same-School
  FK, RESTRICT). `employee_id` is derived from it by trigger, the
  `leave_requests` precedent, for D9 retention queries. Never a User,
  assignment, role or Teacher.
- `version` starts at 1 and rises by exactly one per correction.

### 24.3 The derived view (never stored)
- Leave and the staff calendar keep owning leave, holidays and weekly
  offs. The read model composes, **per half**:
  - `leave`: an approved request's day evidence (`leave_request_days`)
    covers the half;
  - else `present` / `absent`: the recorded evidence;
  - else `holiday`: a staff holiday covers the half today;
  - else `off_day`: the weekly pattern makes it non-working today;
  - else `unrecorded`: working time with no evidence.
- Approved leave takes precedence in the **effective** state, but the
  underlying evidence is always returned beside it (`recorded`). A later
  cancellation makes it effective again. Nothing is rewritten.
- A recorded half stays effective even when today's calendar calls that
  half a holiday or off-day (a retrospective calendar change never hides
  evidence). The current calendar classification is returned too.
- The optional **daily summary**: `present` (both relevant halves present),
  `absent`, `half_day_absent` (one present, one absent), `on_leave`,
  `holiday`, `off_day`, `unrecorded`, `partially_recorded`, and `mixed`
  (leave beside present/absent). Holiday and off-day halves are neutral, so
  a Saturday morning present with an off afternoon is `present`. Mixed
  combinations are never forced into a misleading single status; the two
  halves are always returned.

### 24.4 Ownership and one-way dependencies
- `App\Domain\StaffAttendance` (Application / Infrastructure / Http, HR
  bounded context).
- `StaffAttendance → HR` through HR Application services only: a new
  `EmploymentCoverage::holdRecordAttendable()` and a new
  `EmploymentRoster` (directory-tier labels of a School's employments on a
  date).
- `StaffAttendance → Leave` through Leave Application contracts only:
  - `LeaveCoverageReader` (new): approved-leave coverage per half;
  - `StaffCalendarService::calculator()` with a new
    `WorkingDayCalculator::halves()` classification;
  - `LeaveLocks::staffDays()` (§24.6).
- **Never** `Leave → StaffAttendance`, `HR → StaffAttendance`,
  `StaffAttendance → Payroll` or `Payroll → StaffAttendance`. Neither
  domain writes the other's tables, and StaffAttendance never reads a
  Leave table directly.

### 24.5 Leave's approval gate: a dependency-inverted port
- §8 requires approval to refuse leave over recorded presence, but Leave
  must not depend on StaffAttendance.
- **Leave owns the port** `App\Domain\Leave\Application\AttendancePresenceConflictReader`:
  `presentHalves(school, employment, dates)` returns, per date, the halves
  with recorded `present` evidence.
- **StaffAttendance implements it** (`StaffAttendancePresenceReader`). The
  binding is registered in `AppServiceProvider`, the composition root (the
  `FinancialPeriodCloseParticipant` precedent), so Leave names no
  StaffAttendance class.
- **Approval refuses** (`LEAVE_ATTENDANCE_PRESENT`, 409) when a
  **chargeable** half of the request is recorded `present`.
- **`absent` does not block.** The absence evidence stays untouched
  underneath, and the effective view shows `leave`.
- Submission, rejection and withdrawal do not consult attendance.

### 24.6 One cross-domain day lock
- Key `hrx.staff_day:{school}:{employment}:{date}`, an advisory
  transaction lock, owned by `LeaveLocks::staffDays()`. It always takes
  dates in **ascending** order, and a multi-employment writer takes them by
  employment id, then date.
- Taken by:
  - leave approval and cancellation, for every date of the request;
  - every attendance write (record, bulk register, correction).
- Leave lock order (amends §23.10): School → request row → HR rows →
  **staff days** → schedule → calendar → assignment → years → balances.
- Attendance lock order: School → HR rows → **staff days** → calendar
  (shared) → the record row.
- Neither path takes a lock the other takes earlier in its own order, so
  there is no deadlock. On a conflicting pair exactly one commits first;
  the other then sees it.

### 24.7 Writes
- **Who:** `hr.staff_attendance.manage` only (§7): administrators, for any
  Employee of the School. No manager recording, no self-marking, no
  `hr.staff_attendance.self` (HRX.4).
- **Eligibility:**
  - initial recording needs an `active` / `notice_period` EmploymentRecord
    whose dates contain the date, of an active Employee (§7);
  - a correction needs only that the record's employment dates still
    contain the date. Correcting history after separation is legitimate.
- **No future date:** the date is on or before today in the School's
  timezone (`SchoolTimezone`).
- **Working time:** a half recorded `present` or `absent` must be working
  time in today's staff calendar (`STAFF_ATTENDANCE_NOT_WORKING_TIME`).
  There is no override in v1.
- **Approved leave:** a half covered by approved leave cannot be recorded
  `present` or `absent` (`STAFF_ATTENDANCE_ON_LEAVE`). The opposite half
  stays independently recordable.
- **Single record:** refused if a record already exists
  (`STAFF_ATTENDANCE_ALREADY_RECORDED`). The unique key is the backstop.
  An existing row changes only by correction.
- **Bulk daily register:** one date, many EmploymentRecords. It is
  **all-or-nothing** in one transaction. Every item is validated after the
  locks (sorted by employment id); a duplicate item, an existing record or
  any invalid item refuses the whole register. It is never an implicit
  correction.

### 24.8 Corrections: compare-and-swap + append-only history
- **Command:** record id, `expected_version`, the new first and second
  halves, and a closed `reason_code`. No free text.
- **Closed, neutral reason codes:** `entered_in_error`, `late_information`,
  `administrative_review`, `other`. None of them is health-related.
- **Re-checked:**
  - approved leave: a half changed to `present`/`absent` while approved
    leave covers it is refused; cancel the leave first;
  - working time: only for a half that gains evidence (it was `NULL`).
    Changing existing evidence is allowed whatever today's calendar says;
  - clearing a half to `NULL` is always allowed;
  - clearing **both** halves needs `entered_in_error`.
- **On success, in one transaction:**
  - an append-only `staff_attendance_corrections` row (from/to version,
    before/after of both halves, reason, actor);
  - the conditional UPDATE `WHERE version = expected`;
  - audit and the `staff_attendance.corrected.v1` event.
- A stale version is `STAFF_ATTENDANCE_VERSION_STALE` (409). An unchanged
  correction is refused (422).

### 24.9 What the database enforces
- one record per School × EmploymentRecord × date;
- the closed half values;
- `version >= 1`;
- a new record has evidence;
- same-School composite foreign keys, RESTRICT, no cascade from an
  Employee or EmploymentRecord;
- no DELETE for the runtime role on either table; corrections are
  append-only (no UPDATE either);
- a correction's from-version and before-values equal the record's current
  ones, and the chain is unique per (record, from-version), with
  to = from + 1;
- **a record row changes only with its correction evidence.** An UPDATE
  must raise the version by exactly one, change only the halves, and match
  a correction row with the same versions and before/after values. A
  deferred constraint trigger refuses, at commit, a correction that was
  never applied. Raw SQL with the runtime role therefore cannot rewrite
  history without evidence.

### 24.10 Idempotency, audit, events
- **Idempotency:** record, bulk register and correction are `idempotent`,
  each completing its record inside the business transaction (rule 33).
- **Audit:** structured ids, date, halves, version, reason and counts only:
  - `staff_attendance.recorded`, per record, with its source `single` or
    `bulk`;
  - `staff_attendance.bulk_recorded` (date, count);
  - `staff_attendance.corrected`.
- **Outbox:** `staff_attendance.corrected.v1`, carrying the record,
  employment, Employee, date, from/to version, before/after halves and the
  reason code. It carries no leave detail, is not webhook-publishable, and
  is consumed by nothing before HRX.5. No Payroll event.

### 24.11 Reads and Leave privacy
- `hr.staff_attendance.view`: the daily register, an employment's history
  and one record with its corrections.
- A leave-covered half shows `leave`. The leave request id and leave-type
  name are added **only** when the reader also holds `hr.leave.view`.
- Never shown: the request reason, decisions, approver, policy terms or
  balance.
- Mutation responses are built from the written record and need only
  `hr.staff_attendance.manage`. The two capabilities are independent; no
  capability implies another.

### 24.12 Capabilities
- `hr.staff_attendance.view` and `hr.staff_attendance.manage`, granted to
  `school_admin` and `principal`.
- Never added to `teacher` (E33). `hr.staff_attendance.self` is HRX.4.

### 24.13 Retention (E21-D9)
- Both tables are D9 employment evidence: 8 years after the Employee's
  final separation.
- They form a new catalog category, `staff_attendance_evidence`, with
  status `MECHANISM_PENDING` until HRX.6. Until then they keep the Employee
  (`dependency_blocked`; the longest period wins).
- Both reference users as retained actor references.

### 24.14 Boundaries kept
- No clock-in/out, shift, overtime, late arrival, break, device, GPS,
  photo, biometric, medical, note or attachment.
- No hours inferred from halves.
- No Payroll read, NCP or loss-of-pay: HRX.5 adds the §9 contract.
- Staff Attendance is not Student `attendance_records`, and shares no
  code, capability or table with it.
- HRX-L1 to HRX-L4 stay open.

### 24.15 Admin UI
- Session pages under `/app/staff-attendance`:
  - the daily register (date selector, per-half state with
    leave/holiday/off-day overlays, single record, bulk save, correction);
  - an employment's history with correction evidence.
- Each page is checked server-side again. There is no self-service,
  Teacher or clock page.

### 24.16 HRX.3 as built
- **Namespace:** `app/Domain/StaffAttendance` (Application, Infrastructure,
  Events, Http).
- **Tables (2), forced RLS, composite same-School RESTRICT FKs:**
  - `staff_attendance_records`:
    - unique `staff_attendance_records_one_per_day`;
    - shape CHECK: the closed half values, `version >= 1`, and evidence
      unless corrected;
    - the guard trigger: version 1 on insert, `employee_id` derived, only
      the halves change, version + 1, matching correction required;
    - DELETE revoked.
  - `staff_attendance_corrections`:
    - unique chain `(record, from_version)`, CHECK `to = from + 1`;
    - a real change only, the closed reason codes, and clearing both
      halves only with `entered_in_error`;
    - the guard trigger locks the record and requires its current version
      and values;
    - a deferred `trg_staff_attendance_corrections_applied`;
    - UPDATE and DELETE revoked.
- **Contracts added:**
  - HR: `EmploymentCoverage::holdRecordAttendable()`, `EmploymentRoster`.
  - Leave:
    - `AttendancePresenceConflictReader` (port);
    - `LeaveCoverageReader` (approved coverage per half, from
      `leave_request_days`);
    - `WorkingDayCalculator::halves()`;
    - `LeaveLocks::staffDays()`.
  - StaffAttendance implements the port (`StaffAttendancePresenceReader`),
    bound in `AppServiceProvider`.
- **Leave changes:**
  - `LeaveRequestService` takes the staff-day locks in approval (after HR
    rows, before the schedule lock) and in cancellation;
  - approval refuses `LEAVE_ATTENDANCE_PRESENT` for a chargeable half
    recorded `present`;
  - nothing else in Leave changed.
- **Error codes:**
  - `STAFF_ATTENDANCE_DATE_INVALID` / `_DATE_IN_FUTURE` / `_EMPTY` /
    `_STATUS_INVALID` / `_REGISTER_SIZE` / `_DUPLICATE_ITEM` /
    `_EMPLOYMENT_UNKNOWN` / `_REASON_INVALID` / `_CLEAR_REASON` /
    `_CORRECTION_UNCHANGED` / `_RANGE_INVALID` (422);
  - `STAFF_ATTENDANCE_ALREADY_RECORDED` / `_ON_LEAVE` /
    `_NOT_WORKING_TIME` / `_EMPLOYMENT_NOT_ELIGIBLE` / `_VERSION_STALE`
    (409);
  - an unconfigured staff week surfaces Leave's
    `LEAVE_CALENDAR_NOT_CONFIGURED` (409).
- **API:** 6 operations under `/api/v1/schools/{school}/staff-attendance`,
  all `private-no-store`:
  - `GET register`, `POST register` (bulk), `GET history` (at most 93
    days);
  - `POST records`, `GET records/{id}`, `POST records/{id}/corrections`.
  - The three mutations are `idempotent` with `completeWithin()` (rule 33).
- **UI:**
  - `/app/staff-attendance` (daily register: per-row save, bulk save of
    the marked rows, correction form);
  - `/app/staff-attendance/history` (an employment's days with correction
    history);
  - a Dashboard link gated by `hr.staff_attendance.view`.
- **Guards:**
  - `StaffAttendanceArchitectureGuardTest`;
  - `LeaveArchitectureGuardTest` (Leave never names StaffAttendance or its
    tables);
  - Student `AttendanceArchitectureGuardTest`, scoped to Student
    attendance so the Staff module's names and closed reason code are not
    mistaken for Student writes.
- **Pins:** ElevationRls forced-RLS count 190; `UserReferenceCatalog` 102
  (92 retained); `EmployeeRetentionClassificationTest` gains
  `staff_attendance_records` under `employees` and `employment_records`.
- **Recorded, not built:** own attendance (HRX.4), the HRX.5 unpaid-days
  contract, and the D9 purge (HRX.6).

## 25. HRX.4 — Staff Self-Service: decisions recorded before coding (2026-10-04)

Baseline `0689569`. This closes the open details of §11 and §13 for HRX.4.
Where it narrows a default above, this section governs.

### 25.1 Identity: ActingEmployee only
- **Every self-service read and write starts from the authenticated User
  and the trusted School context**, resolved through
  `ActingEmployeeResolver` only: `resolve()` for reads, `hold()` inside a
  write's transaction. No second resolver, and never an email, role,
  Teacher record, or client-supplied Employee, EmploymentRecord or School
  id.
- **The resolver yields exactly one current EmploymentRecord**
  (`active`/`notice_period`, dates containing today). It fails closed on
  zero or several. Self-service submission therefore always uses
  `ActingEmployee::employmentRecordId`; the client never names an
  EmploymentRecord, and there is nothing to guess.
- **The capability gives permission; ActingEmployee gives ownership.** Both
  are always required.

### 25.2 Capabilities and the `staff_self_service` role
- **Capabilities** (§12): `hr.leave.self`, `hr.staff_attendance.self`,
  `payroll.payslips.self`. None implies, or is implied by, any
  administrative, manager or Payroll capability.
- **`staff_self_service`** (§22.3) is a system School role carrying exactly
  those three. It is a capability bundle only; no code checks the role key.
- **Grants.** It joins the closed School role catalog (ADR 0059) and is
  granted and revoked through Settings → Staff accounts like any School
  role. It is never provisioned automatically because an Employee exists,
  and a direct capability grant works the same way.
- **`school_admin`** also holds the three capabilities. This is solely so
  it can grant the role (`StaffRoleCatalog` lets an actor grant only a role
  whose every capability they hold — the TCH.3 precedent). On its own, a
  capability still reaches only the holder's own ActingEmployee data.
- **Unchanged:** `principal` and `teacher` (E33). A Teacher who needs
  self-service is granted `staff_self_service` separately.
  `hr.leave.approve` stays separate (§23.6).

### 25.3 Lifecycle (narrowest safe decision)
- **No post-employment portal.** Every self-service read and write
  requires a resolvable ActingEmployee **today**. A separated, retired or
  terminated Employee, an archived Employee, a suspended membership or a
  disabled User has no self-service access. Their historical leave,
  attendance and payslips stay available to administrators.
- **Scope of each read:**
  - leave requests: the Employee's own (all their EmploymentRecords in this
    School);
  - leave balances: the acting EmploymentRecord in the leave year
    containing today;
  - attendance: the acting EmploymentRecord, inside its employment dates;
  - payslips: posted runs of any of the Employee's EmploymentRecords in
    this School.
- **School switching** re-resolves everything. Nothing is cached, and
  identity never crosses Schools.

### 25.4 Own leave
- **Read:** balances (the same ledger-derived calculation as
  administration, in integer units), active leave types (id, code, name,
  paid, tracked, half-day), own requests, and a request's chargeable days
  and decisions (decision, path, reason code, time). Never the ledger
  entries, other Employees, policies, allocations, year close or approval
  queues.
- **Submit:** `LeaveRequestService::submit()`, the same code as the
  administrative path, for the acting EmploymentRecord. Input is the leave
  type, dates, portions and an optional closed reason code only.
- **Withdraw:** own `submitted` request.
- **Cancel:** own `approved` request **before it starts** (School-local
  today < `starts_on`, §5; otherwise `LEAVE_SELF_CANCEL_STARTED`, 409).
  This is the same cancellation code: reversals, reconciliation, the close
  chain refusal, locks and the event.
- **Never:** approving or rejecting anything on this path. Self-decision
  stays refused by the database.
- **Decision path `self`.** A requester's own withdrawal or cancellation is
  recorded with path `self`, a new value amending §23.5. The database
  allows `self` only for `withdrawn`/`cancelled` and only when the decider's
  Employee equals the requester's. Both Employees are trigger-derived, so
  raw SQL cannot forge it.
- **Audit and events:** the same events, with `source`/`path` metadata.
  There are no new or duplicate outbox events.

### 25.5 Private 404
- An unknown id, another Employee's request or payslip, another School's
  id, or an actor without an ActingEmployee all answer **one identical
  404 body** (the TCH.6 rule).
- Lists need no identifier at all (`/my/...`), so there is nothing to
  enumerate.

### 25.6 Own attendance (read-only)
- The HRX.3 composed per-half view (`StaffAttendanceReadService`) for the
  acting EmploymentRecord, over at most 93 days inside its employment
  dates.
- It shows the effective state, the recorded value underneath, today's
  calendar classification, and the employee's own leave-type name.
- It omits internal record ids/versions and the correction history.
- There is no own-attendance mutation of any kind.

### 25.7 Own payslips
- **Through `PayslipReadService` only**, with a new ownership path
  `renderOwn()` sharing the existing assembly code. Nothing is copied into
  HRX, and nothing is recalculated.
- **Posted runs only** (`status = 'posted'`). Approved-not-posted, draft
  and calculated runs are excluded. Results expired by retention are
  simply absent. A reversed run shows its flag.
- **Statutory section.** The employee's own statutory deductions are
  included, with identifiers masked as today.
- **Audit.** Each payslip view records `payroll.payslip.self_viewed`
  (§16). The list carries period, run and status only, with no amounts,
  and is not audited.

### 25.8 Routes
- **API:** `/api/v1/schools/{school}/my/leave` (overview),
  `/my/leave/requests` (list, submit), `/my/leave/requests/{id}` (show),
  `.../withdraw`, `.../cancel`, `/my/staff-attendance`, `/my/payslips`, and
  `/my/payslips/{payrollRun}/{employmentRecord}`.
- **Pages:** `/app/my-leave`, `/app/my-staff-attendance`, `/app/my-payslips`
  (the payslip view reuses the printable payslip page).
- **Middleware:** every route has `private-no-store`, its `.self`
  capability middleware and School membership, and every service checks
  again.

### 25.9 Idempotency and concurrency
- Submit and withdraw are `idempotent` (the generic completion, as on the
  administrative path). Cancel completes inside its transaction (rule 33).
- **No new race semantics:** the self path calls the same locked
  operations, in the §24.6 lock order, with ActingEmployee held as the HR
  rows.

### 25.10 Storage and legal boundaries
- **No new table.** There is one CHECK amendment on `leave_decisions` (the
  `self` path) and no retention change.
- No health, free-text, biometric, device, clock, statutory-entitlement or
  loss-of-pay behaviour. HRX-L1 to HRX-L4 stay open.

### 25.13 HRX.4 as built
- **No new module and no new table.** The ownership paths live where the
  data lives:
  - Leave: `LeaveRequestService::submitOwn()` / `withdrawOwn()` /
    `cancelOwn()`; `LeaveRequestReadService::ownOverview()` / `own()` /
    `ownShow()`; `LeaveReadService::ownOverview()`, which shares
    `balanceRows()` with the administrative balance read.
  - StaffAttendance: `StaffAttendanceReadService::own()`, sharing the HRX.3
    composition.
  - Payroll: `PayslipReadService::ownPayslips()` / `renderOwn()`, sharing
    `assemble()` with `render()`.
- **Migration** `2026_11_21_090000_allow_self_leave_decisions`: the
  `leave_decisions_shape_check` CHECK now allows path `self` only for
  `withdrawn`/`cancelled` with `decider_employee_id = requester_employee_id`
  (both trigger-derived). `down()` refuses once a `self` decision exists.
- **Identity.**
  - Writes hold ActingEmployee inside the transaction (after the request
    row, as the HR rows, §23.10 order).
  - The owned paths (manager and self) now answer an unknown request id
    exactly like an unowned one (`LeaveRequestService::privateNotFound()`).
  - Self submission refuses `employee_id`, `employment_record_id`,
    `school_id`, `requester_user_id` and `manager_id` (422).
- **Seeded:**
  - the three `.self` capabilities;
  - the `staff_self_service` system School role;
  - the three also on `school_admin` (grant reason only);
  - the demo Teacher account gains `staff_self_service` as a separate
    grant, with the `teacher` role unchanged.
- **API:** 9 operations under `/api/v1/schools/{school}/my/...`.
  - Leave writes are `idempotent`; cancel uses `completeWithin()`.
  - Own attendance and payslips are GET only.
- **Pages:** `/app/my-leave` (+ `/requests/{id}`), `/app/my-staff-attendance`
  and `/app/my-payslips` (+ `/{run}/{employmentRecord}`, reusing the
  printable payslip page with a `back` link). Dashboard links follow each
  `.self` capability separately. An actor without an ActingEmployee sees an
  "unavailable" state.
- **Payroll presentation:** `PayslipPresenter` replaces the two duplicated
  presenters in the administrative payslip controllers. The output is
  unchanged.
- **Guards:**
  - `StaffSelfServiceArchitectureGuardTest`: no code names the role;
    routes are capability-gated, private and identifier-free; ownership
    only through ActingEmployee; no HRX.5 concept; no table.
  - `StaffSelfServiceOpenApiCoverageTest`.
  - Leave and Staff Attendance guards updated to the HRX.4 shape.

## 26. HRX.5 — Payroll absence evidence: decisions recorded before coding (2026-10-04)

Baseline `2b0b1f5`. This implements §9 as an **evidence** boundary. It does
not clear HRX-L4. The qualified-review record is
`docs/security/HRX-L4-PAYROLL-LOSS-OF-PAY-DETERMINATION.md`. Where this
narrows §9, this section governs.

### 26.1 Audit of Payroll before HRX.5 [FACT at `2b0b1f5`]
- **ECR NCP.** `StatutoryEcrExportService::buildRow()` writes the literal
  `'0'` as NCP Days for every member. Nothing stores an NCP value; the
  export emits it at generation time. Its docblock already disclosed this
  as a gap.
- **Part-month pay.**
  - `PayrollRunService::resolveEmploymentRecord()` sends an employment to
    a **manual override** when it starts or ends inside the period, or when
    its compensation assignment changes inside the period.
  - Without an override row (`payroll_adjustments.mode = manual_override`,
    append-only, latest row per component wins), the employment stays
    unresolved and the run stays `draft`.
- **Immutability.**
  - `calculate()` replaces the whole result set while the run is `draft`
    or `calculated`; it locks the run row FOR UPDATE.
  - `approve()` (`calculated -> approved`) is the immutability boundary.
    Results, their lines and statutory results are frozen by trigger from
    `approved` on.
  - Posting (`approved -> posted`) writes `payroll_run_postings` and the
    journal.
- **Corrections.** A posted regular run is corrected by a separate
  `correction` run whose per-employment `correction_delta` adjustments
  are entered by a payroll administrator. Posted history is never edited.
- **Periods.** Payroll periods are calendar months (`starts_on` /
  `ends_on` derived from `period_month`). The HRX contract nevertheless
  takes the period's explicit dates.
- **No HRX input** of any kind existed.

### 26.2 Four concepts, never one field
1. **HRX absence evidence.** Facts in integer half-day units: approved
   paid leave, approved unpaid leave, recorded absence, recorded presence,
   unresolved working time. Owned by HRX and read through §26.3.
2. **Payroll non-payable input.** What Payroll would treat as not payable.
   This needs a validated, versioned payroll policy (HRX-L4). **None
   exists in v1.** Payroll stores the evidence (1) with policy status
   `pending_hrx_l4`, and no "non-payable" quantity is derived anywhere.
3. **Monetary loss-of-pay deduction.** **Disabled.** No code path takes
   HRX evidence into gross, basic, net, EPF or EPS wages, or into any
   statutory base (guarded, §26.11).
4. **EPFO NCP days.** **Unchanged.** The ECR export keeps its legacy
   default `0`, **pending a validated HRX-L4/EPFO mapping**. It is not
   claimed to be legally correct. There is no half-day → day conversion
   anywhere (guarded, §26.11).

### 26.3 The HRX read contract (one-way)
- **`App\Domain\StaffAttendance\Application\Payroll\PayrollAbsenceEvidenceReader`.**
  StaffAttendance already composes approved leave (through Leave's
  `LeaveCoverageReader`) with attendance and the staff calendar, so the one
  contract lives there.
- **Dependencies:**
  - Payroll → this contract, its DTO and the HR span contract only;
  - never HRX → Payroll;
  - never Payroll → any `leave_*` or `staff_attendance_*` table or model.
- **Input:** School, EmploymentRecord, period `starts_on`, `ends_on`. Never
  a User.
- **Two entry points:**
  - `read()`: fresh, no locks, for comparisons and screens;
  - `captureForPayroll()`: inside Payroll's transaction, under the §26.9
    locks, for snapshots.
- **Contract version:** `hrx_payroll_input.v1`.

### 26.4 Classification: one effective class per half (HRX.3 precedence)
- **Within coverage**, each half is exactly one of:
  - `leave_paid` / `leave_unpaid`: approved leave day evidence, by the
    leave type's `is_paid`. That flag is frozen once the type is used
    (§22.5);
  - `present` / `absent`: recorded evidence, not covered by leave;
  - `holiday` / `off_day`: today's staff calendar, no record, no leave;
  - `unrecorded`: working time with neither;
  - `calendar_unknown`: the staff week is unconfigured.
- **No double counting.** A recorded `absent` half under approved leave
  counts as leave only. The underlying record id, version and value stay
  in the half's source facts.
- **Never counted as absence:** holidays, off-days and unrecorded halves.
  Unrecorded is never present or absent.
- **Paid leave never becomes non-payable.**
- **Unpaid leave and recorded absence are facts only.** Neither is labelled
  loss-of-pay, deductible or NCP.

### 26.5 Coverage and the period
- The half-days counted are those of the period **intersected with the
  EmploymentRecord's own dates** (HR `EmploymentRoster::span()`, the one
  employment-date source). Dates before joining or after separation are
  never absence. A period with no overlap is empty and complete.
- Leave years play no part. The leave year only funds units; it never
  splits a payroll period.

### 26.6 Completeness
- **`complete`** only when the calendar is configured and no working half
  in coverage is `unrecorded`.
- Otherwise **`input_incomplete`**, with reasons `calendar_not_configured`
  and/or `unrecorded_working_time`.
- Nothing is guessed, and there is no override of source evidence. With
  monetary use disabled, incompleteness is shown and audited, and it
  blocks nothing in v1. Any future monetary policy must refuse incomplete
  input.

### 26.7 Fingerprint
- **SHA-256 of canonical JSON** over:
  - the contract version, EmploymentRecord, period and coverage;
  - whether the calendar is configured;
  - per half in coverage: date, half, class, calendar working flag, and the
    source facts (approved leave request id + `is_paid`; attendance record
    id + version + recorded value).
- **Equal source state gives an equal fingerprint.** Any change in
  approval, cancellation, recorded value, correction version, or calendar
  working status of a half changes it.
- **Excluded:** names, codes, timestamps and other presentation.

### 26.8 The Payroll snapshot: `payroll_run_hrx_inputs`
- **One row per payroll run result** (regular runs only). It is captured
  when `calculate()` persists each result, and replaced with the result on
  recalculation (results are delete-and-reinsert while the run is
  `draft`/`calculated`).
- **It holds:** contract version, fingerprint, completeness + reasons,
  period and coverage dates, the integer unit counts, the per-half evidence
  (jsonb), the capturing actor and `captured_at`.
- **FK:** a composite foreign key to its result ON DELETE CASCADE. It
  follows its result, including E21.3F payroll retention (which deletes
  results inside its privileged function).
- **Frozen like results:**
  - no runtime UPDATE or DELETE (append-only by privilege);
  - a trigger refuses insert, update or cascade delete once the run is
    `approved`/`posted`, except inside the E21.3F retention function.
- **Never cascades from** an Employee, Leave or StaffAttendance; it has no
  foreign key to them.

### 26.9 Locks (one consistent HRX read)
- **New HRX lock:** `hrx.staff_employment:{school}:{employment}`
  (`LeaveLocks::staffEmployment()`). Every HRX writer takes it **shared**,
  after its HR rows and before the staff days:
  - leave approval and cancellation;
  - attendance record, bulk register and correction.
- **`captureForPayroll()`** takes it **exclusively** for every employment
  of the run (sorted by id), then the calendar lock shared, then reads.
- **Result:** the calculation sees each employment's HRX state either
  wholly before or wholly after any concurrent HRX write, never a mix.
- **No deadlock:**
  - Payroll holds the run row and these locks only;
  - HRX writers never wait on a Payroll lock;
  - Payroll takes the calendar lock only after all employment locks.

### 26.10 Pending differences and corrections
- **Comparison:** the captured fingerprint against a fresh `read()` of the
  same period and employment. Equal means no difference.
- **Run states:**
  - for an `approved`/`posted` run, a difference is **"HRX source changed
    after approval"**. The snapshot and results stay frozen;
  - for a `draft`/`calculated` run, the remedy is recalculation.
- **Read** (`GET …/payroll-runs/{run}/hrx-inputs`): the snapshot and live
  comparison.
- **Check** (`POST …/hrx-inputs/difference-checks`, `idempotent`): records
  `payroll.hrx_input.difference_detected` per changed employment.
- **Both** need `payroll.runs.prepare` (attendance-derived personal data,
  not "non-sensitive" run detail).
- **Correction** of money remains the existing correction run with
  administrator-entered deltas. HRX.5 adds no new financial mechanism and
  never reposts or rewrites a run.

### 26.11 Legal-activation guards
- **Code:** no Payroll calculation, statutory calculation or ECR export
  code reads the snapshot or the reader. Only the capture step in
  `PayrollRunService` and the HRX-input read service do.
- **Tests:**
  - unpaid leave and recorded absence leave gross, net and statutory
    figures unchanged;
  - the ECR NCP stays `0`;
  - no expression rounds half-day units into days (`intdiv`, `ceil`,
    `floor`, `round`, `/ 2` on half-day quantities) in Payroll, Leave or
    StaffAttendance.

### 26.12 Authorization, classification, retention
- **Authorization:** payroll administration only (`payroll.runs.prepare`
  for capture, which is `calculate()`, and for reads and checks). Never
  `staff_self_service` or `teacher`; own payslips are unchanged and never
  show HRX inputs.
- **Classification:** the snapshot is Payroll evidence, Highly Sensitive
  with the result it supports (§15). No new tier.
- **Retention:** E21-D9 payroll evidence in the `payroll_ledger` category
  with its result (8 y after final separation, E21.3F). It is deleted with
  its result by the existing privileged expiry. The E21 policy is
  unchanged.

### 26.13 Part-month composition
- **Coverage first, then classification.** The HRX evidence counts only
  halves inside the employment's dates.
- **The manual override stays authoritative for money** in v1, and HRX
  evidence never changes an amount, so nothing is reduced twice.
- **For a future validated policy:** coverage (joining/separation) and
  absence (within coverage) are separate inputs and must stay separate;
  the denominator is the required working time of the covered period,
  never a fixed 30/31 or calendar days, unless a validated rule says so.

### 26.14 HRX.5 as built
- **Contract:** `StaffAttendance\Application\Payroll\PayrollAbsenceEvidenceReader`
  (`read()`, `captureForPayroll()`) and the `PayrollAbsenceEvidence` DTO.
  Leave's `LeaveCoverageReader` now also returns the frozen `isPaid`.
- **Lock:** `LeaveLocks::staffEmployment()`. HRX writers take it shared
  after their HR rows and before the staff days:
  - leave approval and cancellation;
  - attendance record, register and correction.

  Capture takes it exclusively for every employment of the run, sorted,
  then the calendar lock shared.
- **Payroll:**
  - `PayrollRunService::captureHrxInputs()` runs after every result is
    persisted, for regular runs only, and records one
    `payroll.hrx_input.captured` audit per calculation;
  - `PayrollHrxInputReadService` (`forRun()`, `checkDifferences()`);
  - `PayrollHrxInputController`;
  - an HRX evidence panel on the run page (`payroll.runs.prepare`).
- **Migration** `2026_11_22_090000_create_payroll_run_hrx_inputs`:
  - adds the unique `payroll_run_results_identity_key` on
    `(id, school_id, payroll_run_id, employment_record_id)` for the
    composite cascade key;
  - creates `payroll_run_hrx_inputs`: shape CHECK; one row per result;
    `trg_payroll_run_hrx_inputs_freeze` (regular runs only, never UPDATE,
    nothing after approval except inside the E21.3F function); forced RLS;
    append-only.
  - `down()` refuses while rows exist.
- **ECR:** unchanged (`'0'`). The docblock now says "legacy default pending
  validated HRX-L4/EPFO mapping".
- **Catalogs:** `payroll_ledger` gains the table; `UserReferenceCatalog`
  is 103 (93 retained); the forced-RLS count is 191.
- **Guards:**
  - `PayrollHrxArchitectureGuardTest`: one contract; no HRX table in
    Payroll; HRX never depends on Payroll; evidence never reaches money,
    statutory or ECR code; no half-day rounding; NCP default kept;
  - `PayrollHrxOpenApiCoverageTest`;
  - Staff Attendance's guard now allows Payroll only that contract's
    namespace and bans Payroll *tables*, not the word.
- **HRX-L4 stays OPEN.** Monetary use, the payroll non-payable policy and
  the EPFO NCP adapter are not built.

## 27. HRX.6 — Retention, readiness & closure (2026-10-04)

Recorded before coding; as-built notes in §27.9. Baseline `48a2f5f`.
Nothing here activates a legal gate: HRX-L1–L4 stay **OPEN**.

### 27.1 One D9 mechanism, two participants
- Leave and Staff Attendance evidence is D9 employment evidence (§18): kept
  **8 calendar years after the Employee's final separation**
  (`EmployeeRetentionEligibility`, the one canonical answer;
  `EMPLOYEE_EVIDENCE_RETENTION_YEARS`, the one D9 setting). There is no HRX
  retention setting and no second engine.
- Each module owns a participant of the existing run
  `platform:employee-retention-prune` (evidence phase), which now runs:
  1. Payroll's per-employment rows (unchanged);
  2. **Leave** (`LeaveEvidenceRetentionService`);
  3. **Staff Attendance** (`StaffAttendanceEvidenceRetentionService`);
  4. HR's own evidence purge (the Employee root), unchanged.
- The reviewed Employee erasure case (E21.2F) calls the same participants
  in the same order, never earlier than D9.
- Payroll's posted evidence keeps its own run (`platform:payroll-retention-prune`,
  E21.3F); nothing there changed.

### 27.2 The privileged path
- The runtime role gains **no** privilege. It has no DELETE on any HRX
  evidence table; the ledger, decisions, request days, close items,
  reconciliations and attendance corrections stay append-only.
- Two fixed-purpose functions (SECURITY DEFINER, pinned `search_path`,
  qualified tables, never PUBLIC):
  - `retention_expire_leave_employee_evidence`;
  - `retention_expire_staff_attendance_employee_evidence`.

  *As first built they followed the E21.2B grant (runtime EXECUTE). That is
  **superseded by §27.10**: the runtime role has no EXECUTE, and the
  functions themselves enforce the retention identity and the School hold.*
- Each re-proves the unit in the database:
  - the School is the current tenant (`retention_assert_tenant`);
  - the shared D9 Employee floor (`retention_assert_payroll_employee_floor`,
    E21.3F): the cutoff is at least 8 calendar years back (real `now()`);
    the Employee and its EmploymentRecords are locked FOR UPDATE; every
    EmploymentRecord is terminal with an `ends_on` before the cutoff;
  - each employment's `hrx.staff_employment` lock, EXCLUSIVELY, sorted;
  - **every row of the unit was written before the cutoff.** A late write
    (a cancellation, a correction, a close reconciliation) is new evidence
    with its own clock: the unit is kept (`retention_hrx_dependency`,
    counted `dependency_blocked`) until it is itself old enough.
- A non-definer helper (`retention_lock_hrx_employee`) holds the shared
  prologue and is not executable by the runtime role.
- No cascade is added anywhere. Every FK out of an HRX evidence table stays
  RESTRICT (only `school_id → schools` cascades, as before).

### 27.3 Deterministic, causally complete purge order
- **Leave**, for the Employee's EmploymentRecords and requests:
  1. ledger entries a close reconciliation produced;
  2. the reconciliations (of its close items or requests);
  3. the remaining ledger entries, leaves first (an entry that no remaining
     entry reverses, repeated), so a reversal always goes before what it
     reverses;
  4. request days, decisions, requests;
  5. close items, policy assignments.
- **Staff Attendance:** the corrections, then the records -- a record and
  its whole correction history together, never one without the other.
- Each function verifies nothing of the unit remains
  (`retention_hrx_unit`), returns the rows removed, and with dry run only
  proves and counts.

### 27.4 What is never touched
- School configuration (tenant lifetime): `leave_settings`, `leave_years`,
  `leave_year_start_changes`, `leave_types`, `leave_policies`,
  `staff_working_weekdays`, `staff_holidays`, `leave_allocation_runs`, the
  `leave_year_closes` header.
- Audit events (D1) and outbox rows (their own lifecycle).
- `payroll_run_hrx_inputs`: **Payroll evidence**. It references no HRX row,
  survives the raw HRX purge unchanged, and leaves only with its payroll
  result through E21.3F. After the purge the run's HRX panel truthfully
  reports the source as changed; nothing is rewritten.
- A decision this Employee made as another Employee's **manager** belongs
  to that Employee's request. It stays, and keeps the manager's Employee
  root until that request itself expires (longest period wins).

### 27.5 Employee deletion
- HR's purge is still kept by the live FK catalog (`ReferencingRows`) while
  any HRX row references the Employee: unexpired HRX evidence blocks it,
  expired-but-unpurged HRX evidence blocks it, and once the participants
  have run HRX no longer blocks. Every other domain still blocks
  independently. Employee deletion never cascades through HRX.
- The dry run counts Leave/Staff Attendance tables as cleared first
  (`$clearedFirst`), except `leave_decisions` (a manager's decision may
  stay), so it is conservative.

### 27.6 Observability
- Counts only: `lycenza_retention_rows_total{operation="employee", outcome}`
  with two new categories, `leave_evidence` and `staff_attendance_evidence`
  (no new family). The command prints one HRX line; logs carry categories,
  never identifiers.

### 27.7 Test isolation (closure fix)
- The HRX.1–HRX.4 race tests left committed `test.capability_grant.*` roles
  and their `role_capabilities` behind. One shared trait,
  `Tests\Concerns\PurgesCommittedHrxFixtures`, now removes every durable
  fixture (School rows, the test's Users, the roles minted since setUp) and
  asserts the durable counts equal the setUp snapshot. Canonical seed rows
  existed before setUp and are never touched.

### 27.8 Closure
- **HRX — LEAVE & STAFF ATTENDANCE IMPLEMENTATION PUBLISHED / CLOSED.**
- **HRX.5 MECHANISM CLOSED / LEGAL ACTIVATION GATED** (HRX-L4).
- HRX-L1 (health data), HRX-L2 (biometric/device attendance), HRX-L3
  (statutory leave) and HRX-L4 (loss of pay / EPFO NCP) remain **OPEN**.
  The E21 policy is project-adopted; qualified legal ratification and the
  production retention configuration remain pending.
- Deferred, not executed: in-year expiry of carried leave
  (`carried_expires_on` is recorded only, §23.8); a cancellation across two
  closed years is refused (`LEAVE_CANCELLATION_CLOSE_CHAIN`, §23.9).

### 27.9 HRX.6 as built
- **Migration** `2026_11_23_090000_add_hrx_evidence_retention`: the two
  functions and the lock helper; grants as above. `down()` drops the
  mechanism only (no column, marker or privilege to restore).
- **Code:** `RetentionExpiry::hrxEmployeeEvidence()` with the two refusal
  constants; the two participants; `PruneEmployeeRecords` and
  `EmployeeErasureAdapter` wiring; HR's dry run accepts cleared tables for
  the Employee root as well; `RetentionMetrics` categories;
  `DatabaseRoleVerifier::RETENTION_FUNCTIONS`; `TenantRetentionCatalog`
  `leave_evidence` and `staff_attendance_evidence` → **adopted** (no
  `mechanism_pending` category remains).
- **Tests:** `HrxEvidenceRetentionTest`, `HrxRetentionGuardTest`,
  `HrxRetentionConcurrencyTest` (two real processes),
  `HrxPayrollRetentionIndependenceTest`; the classification and readiness
  pins updated.

### 27.10 Hardening: the purge privilege boundary (2026-10-04)
*Amended 2026-10-04 (E21-RH.1, ADR 0066):* the "authorized retention
execution identity" below, `pgsql_admin`, is a **temporary** arrangement.
It contradicts ADR 0021 for a scheduled command and is broader than the
task needs. E21-RH.2 replaces it with the dedicated retention identity,
and replaces the session-user owner-membership check with
retention-role membership. HRX itself stays closed at `dc8b50a`.
*Amended 2026-10-04 (E21-RH.2, ADR 0066 §10):* done.
- The HRX units now run on `pgsql_retention` as `school_os_retention`.
- The prologue accepts exactly that login (`session_user`).
- Writing the hold mirror became operator maintenance
  (`platform:retention-holds-sync`). A destructive run refuses while a
  configured hold is unrecorded.

- **Problem.** The runtime role had no DELETE on HRX evidence, but it held
  EXECUTE on the two SECURITY DEFINER functions, which delete with their
  owner's privileges. A direct `SELECT retention_expire_...(...)` on a
  runtime connection could therefore purge eligible evidence, and the E21
  legal hold (`RETENTION_HOLD_SCHOOL_IDS`) was enforced only in PHP.
- **Final privilege model** (migration
  `2026_11_24_090000_harden_hrx_retention_privileges`):

| Layer | Identity | What it can do |
|---|---|---|
| Runtime application role | `school_os_app` (every request/queue connection) | No DELETE on HRX evidence; **no EXECUTE** on either function or on `retention_lock_hrx_employee`; no privilege on `retention_school_holds`. It cannot purge, place or release a hold. |
| Authorized retention execution identity | the existing migration/owner connection `pgsql_admin` (rule 54; no new role) | `RetentionExpiry::privileged()` runs each HRX participant's whole unit (reads, locks, unit transactions, the function call) on it, after mirroring the configured holds. |
| SECURITY DEFINER function owner | the migration role that ran the migration (table owner) | Deletes; the functions are not owned by the runtime role, pin `search_path = pg_catalog, pg_temp` and qualify every table. |
| Database enforcement inside the function | `retention_lock_hrx_employee` (shared prologue, not runtime-executable) | Refuses unless the **session user** is a member of the HR tables' owner (`retention_privilege`; `SET ROLE` cannot change `session_user`), refuses a **held School** (`retention_hold`), then the tenant, floor, separation, locks and row-age checks. |

- **Legal hold at the destructive boundary.** `retention_school_holds`
  (`school_id` PK, `source = 'config'`, `held_since`) is the database side
  of the existing E21 School hold. `RetentionHolds::synchronize()` makes it
  equal to `RETENTION_HOLD_SCHOOL_IDS` on the retention identity's
  connection before every HRX participant runs (the configuration stays the
  operator's source; a release is a configuration change, mirrored on the
  next run). A direct call by an otherwise authorized caller for a held
  School is refused, deleting nothing. Rollback refuses while a hold is
  recorded.
- **Dry run and reporting** keep their semantics: a held School is counted
  `held` and nothing is deleted; a refusal maps to "kept", never an error.
- **Not changed:** the D9 policy, trigger, floor, purge order, the
  Employee-deletion behaviour and every legal gate. The other E21 retention
  functions keep the E21.2B model (runtime EXECUTE, PHP-side hold); moving
  them is a separate E21 decision, not part of HRX.
- **Tests:** `HrxRetentionGuardTest` proves, on raw SQL:
  - runtime direct DELETE and runtime direct function calls (both
    functions, dry and destructive, and the prologue) are denied;
  - a simulated accidental runtime EXECUTE grant is still refused
    (`retention_privilege`);
  - a held School survives a direct authorized call (zero rows removed);
    after the release the authorized run purges it;
  - School A's elevated context cannot touch School B;
  - the runtime role cannot read, place or release a hold.

  The HRX retention tests now commit their fixtures, because the retention
  identity's connection cannot see an open test transaction, and they
  clean up through `PurgesCommittedHrxFixtures`.
