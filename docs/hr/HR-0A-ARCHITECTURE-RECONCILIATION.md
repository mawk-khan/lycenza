# HR.0A — HR ERP architecture & repository reconciliation

Status: **architecture and reconciliation contract, documentation only**
(2026-11-03, baseline `main = 7a4068fe`). No migration, code, capability,
role, route, UI, demo or configuration was changed.

This is the authoritative entry point of the HR ERP programme. It extends
the HR architecture that already exists: HR, Leave, StaffAttendance,
Payroll and TeachingAssignments. **It never introduces a second HR
architecture beside it.**

| Subject | Single authoritative record |
|---|---|
| Current-state audit (repository) | `HR-EXP-0-CURRENT-STATE-AUDIT.md`, re-verified unchanged here (§B) |
| WMSCB reference capability matrix + gap matrix | `HR-REFERENCE-CAPABILITY-MAP.md` |
| Domain ownership + cross-ERP map | `HR-DOMAIN-OWNERSHIP-MAP.md` |
| Legal, privacy and security gates; findings | `HR-LEGAL-SECURITY-GATE-REGISTER.md` |
| Payroll checker visibility analysis (OD-HR-1) | `HR-EXP-0R-REFERENCE-RECONCILIATION.md` §6 |
| HR architecture decisions (proposed) | this record, §R |
| Implementation roadmap HR.1–HR.16 | `HR-EXPANSION-ROADMAP.md` |
| Test strategy | `HR-TEST-STRATEGY.md` |
| HR.R1 closure contract | `HR-R1-CLOSURE-CONTRACT.md` |
| Deferred scope | `HR-REFERENCE-CAPABILITY-MAP.md` §4 (DEF and GATE rows), §7; this record §S |
| Demo | `HR-DEMO-PLAN.md` |

## A. Baseline

| Item | Value |
|---|---|
| `git fetch --prune` | done 2026-11-03, about 06:57 UTC |
| HEAD / local `main` / `origin/main` | `7a4068fe` / `7a4068fe` / `7a4068fe`: **agree** (HEAD detached at `main`) |
| Working tree | clean except the untracked HR.EXP-0R draft (`docs/hr/HR-EXP-0R-REFERENCE-RECONCILIATION.md`), which this checkpoint adopts and supersedes in part (§T) |
| Worktrees | one (`/home/wajidkhan/sites/lycenza`) |
| Stash | `stash@{0}` (2026-08-27, "phase-5d3-preflight") **untouched** |
| Other branches | `origin/integration/e16-r2` (`fb29aff5`, 2026-10-16, nginx pin) is **not** an ancestor of `main`. It is unrelated, not touched and reported for the owner |
| CI baseline | `CI gate` green on `7a4068fe` (runs 41347404926 and 41347648370, docs lane) and on `562ee2c8` (run 41342231140: the last remote full regression, SIS 11.13; 10,317 green + 1 ESI-12 skip); counter 0/5 |
| Canonical commands | `apps/platform/bin/safe-test` (`--reset-db`, `--group repository-documents`, `--shard N/4`); publication `infrastructure/ci/lycenza-ci publication-check --candidate <sha> --push` (rule 96) |
| E16 | `OWNER-0O-E16-2026-11-01`, valid through **2026-11-28** |
| HR code since HR.EXP-0 | **unchanged**: `git diff --stat c5e3dd23..7a4068fe -- app/Domain/{HR,Leave,StaffAttendance,Payroll}` is empty |
| Migrations | 363 |

## B. Existing HR implementation (runtime truth)

`HR-EXP-0-CURRENT-STATE-AUDIT.md` §1–§20 remains accurate. Code size at
the time of that audit:

| Domain | Files | Tests |
|---|---|---|
| HR | 155 | 86 |
| Leave | 44 | 13 |
| StaffAttendance | 14 | 8 |
| Payroll | 180 | 41 |
| TeachingAssignments | 24 | — |

HR.0A verified these points directly in code and schema:

| Area | Verified fact |
|---|---|
| Employment | `employment_records`: `employment_type` (free text), `starts_on`, `ends_on`, `probation_ends_on`, `status` (8-value CHECK). `EmploymentService::update()` changes type and category **in place** (X-3) |
| Assignments | `employee_assignments`: Campus, department (nullable), position, `is_primary`, `starts_on` / `ends_on`; composite same-School FKs; **only open primaries are unique** (`employee_assignments_one_primary_open_per_employment`). Changes are `create` / `end` / `setPrimary`, never an in-place department or position update |
| Reporting line | `manager_assignment_id` is a live pointer with a self-report CHECK. `ReportingLine::managerOf(date)` resolves it through both assignments' dates; indirect cycles are refused in the application after ordered locks. **No manager history** (X-2) |
| Leave | types (paid, tracks balance, half day); policies versioned by `supersedes_policy_id`; per-employment `leave_policy_assignments` (effective from/to); ledger kinds `allocation`, `adjustment`, `consumption`, `reversal`, `carry_forward_in` / `out`, `expiry`; never negative (trigger); allocation runs unique per (School, year, type); request days frozen. `WorkingDayCalculator` counts working half-days only, so there is no sandwich rule |
| Calendar | `staff_working_weekdays` (one School pattern) and `staff_holidays` (one per date), both School-wide and Leave-owned |
| Staff attendance | one record per employment × date, two halves `present` / `absent`, `version`; `staff_attendance_corrections` stores before and after values, append-only. No department or Campus filter on the register |
| Payroll handoff | `payroll_run_hrx_inputs` freezes Leave and attendance evidence per result; after approval a source change surfaces as `source_changed_after_approval`, never a rewrite |
| Audit | `school_audit_events` (actor, event type, subject, `request_id`, metadata JSON). HR events carry ids, closed codes and **changed field names, never values** |
| Exports | none in HR. The only Payroll exports are the statutory ECR / ESI / TDS paths (legal-gated, no production role) |

## C. Contracts that must be preserved

| Contract | Source | What HR work must never break |
|---|---|---|
| Identity ≠ HR ≠ Teacher | ADR 0063, rule 92 | creating an Employee never creates a User; job titles and designations are never roles; ActingEmployee identifies and never authorizes |
| TCH-L1 conditions | E33, ADR 0058 | the `teacher` role's key set, ActingEmployee resolution and TeachingAssignment ownership change only with a TCH-L1 §11 re-review |
| HRX (Leave and Staff Attendance) | ADR 0065 | daily presence status; no clock, device, biometric, health, free text or attachments (§7, §8, §10); decisions whole-School (OD-8.8 Option A); self-decision refused in three places; append-only evidence |
| Campus adoption | rule 101, `HrEmployeeAccess` | exactly four adopted HR keys; Leave, Staff Attendance and Payroll keys are whole-School; `CurrentEmployeeAssignments` is the one currency reader |
| Self-administration | rule 95, `HrSelfAdministrationGuard` | nobody administers their own HR identity, placement or pay |
| Approval foundation | rule 102 | request/decide workflows are registered, decide under the item lock with fresh authority and `MakerCheckerPolicy` |
| Payroll maker/checker | ORG.8B | creators, calculators and adjustment authors never approve; stale results refused |
| Staff identity for other domains | rule 108 | `CurrentStaffMembers` is the only "current staff" answer |
| Retention | E21, ADR 0066 | HR evidence 8 years after separation; runtime DELETE revoked on evidence |
| Role catalogue | rules 93–94 | 17 pinned staff roles; any new role or key is a reviewed release |

## D. Organization and SIS dependencies HR reuses

**Organization:**
- `School`, `Campus`, `ActiveCampus`;
- composite `(campus_id, school_id)` keys;
- `SchoolTimezone::today()`;
- module entitlements: `staff_records` core; `leave`, `staff_attendance`
  (depends on leave) and `payroll` (depends on finance) optional;
- `ModuleExecutionGuard` for async work;
- the Setup & Readiness registry (a new area per configurable module,
  rule 111).
- **No HR Network authority:** Network roles never read tenant data (rules
  113–114).

**Identity:**
- memberships, Staff accounts and role grants;
- `CapabilityResolver`;
- `CampusScopeResolver`;
- `FreshMfaRequirement`;
- `SensitiveReadAssurance`.

**SIS:**
- nothing is shared today.
- A staff ↔ student relationship would be a new explicit contract (D20-29),
  never inferred from contact data.

**Academic:**
- TeachingAssignments for teacher ownership;
- Academic Structure readers for Section and Subject references (D19).

**Platform:**
- `AuditRecorder` and `SchoolAuditEventReader`;
- Documents (`employee` owner type);
- the approval foundation;
- the idempotency guard;
- `TenantLock`;
- `TenantScoped` jobs;
- the D-11 export contract (SIS today).

## E. WMSCB reconciliation totals

From `HR-REFERENCE-CAPABILITY-MAP.md` §4–§5: **361 capabilities**, each
with exactly one status.

| Status | Count |
|---|---|
| IMPLEMENTED | 96 |
| PARTIALLY_IMPLEMENTED | 40 |
| MISSING | 90 |
| IMPLEMENTED_DIFFERENTLY_AND_ACCEPTABLE | 18 |
| CROSS_DOMAIN | 8 |
| DEFERRED | 16 |
| BLOCKED_BY_GATE | 87 (65 of them HRX-L2) |
| NOT_REQUIRED | 6 |

**The pack gate is COMPLETE:**
- 602 PNG;
- fingerprint `40ed6a83…1ac3`;
- `HR.zip` companion;
- all six menus captured expanded.

## F. Critical architecture gaps

| Rank | Id | Gap | Why | Destination |
|---|---|---|---|---|
| **P0** | S-1 / OD-HR-1 | The Payroll checker approves results it cannot see (no figure at all) | integrity of pay | owner decision → Payroll integrity slice (HR.15; may be pulled forward) |
| **P0 (decision)** | OD-HR-14 | HRX-L2 bundles timing evidence with biometrics, so **65 capabilities** (shifts, clock evaluation, devices) cannot even be designed for build | without a decision, HR.4–HR.8 cannot start | owner + legal (gate register §3) |
| P1 | X-2 | Reporting relationship is not effective-dated: manager history is lost | historical reports, delegation, audit | HR.3 |
| P1 | X-3 | Employment category and type are updated in place | historical headcount by category, leave eligibility by category | HR.1 |
| P1 | S-10 | "One current employment", primary-assignment overlap and indirect cycles are application-only | integrity under concurrency | HR.1 / HR.3 |
| P1 | D1-11 | No closed staff-type classification (Teaching / Non-Teaching / Outsource) | tasks, eligibility and reports need a rule-safe classification | HR.1 |
| P1 | D5-02/03 | One School-wide calendar, owned by Leave | Campus calendars, shifts and evaluation all need a work-schedule owner | HR.4 (HR-AD-05) |
| P1 | D10-03…19 | Leave policy has no eligibility, window, sandwich, notice or proration rules | most leave gaps | HR.9–HR.10 |
| P1 | OD-HR-5 | Line managers cannot approve leave in practice | manager workflows (leave, comp-off, permission, regularization) | owner catalogue decision with HR.11 |
| P1 | D8-10 | No attendance period lock or confirmation before payroll | payroll-consumed attendance can still change (it is detected, not prevented) | HR.7 |
| P2 | X-1 | Timetable, Transport and Visitor read HR's `Employee` model directly, without employment currency | separated staff selectable; rule 4 drift | HR.15 |
| P2 | — | No HR report registry or D-11 export | reports and exports would be ad hoc | HR.14 |
| P2 | S-16 | Ancillary profile rows and holidays are hard-deleted | content lost before retention | HR.2 / HR.4 |
| P2 | — | Payroll run page N+1 (HR.EXP-0 §18) | scale | HR.15 / HR.16 |
| P2 | Payroll → `EmploymentRecord` | 25 direct model uses | rule 4 drift (historical) | HR.15 decision |

**HIGH security findings: none.** **No duplicate organization master:**
`hr_departments` ≠ `academic_departments` by documented design.

## G. Domain ownership (summary)

Full map: `HR-DOMAIN-OWNERSHIP-MAP.md`. Sources of truth:

| Data | Source of truth |
|---|---|
| Staff identity, employment, assignments, departments, designations, categories, reporting hierarchy, lifecycle | HR |
| Attendance | StaffAttendance |
| Leave, comp-off, permissions, windows | Leave |
| Work calendar and shifts | proposed WorkSchedule (Leave today) |
| Tasks | proposed WorkforceTasks (HR sub-domain) |
| Organization, School, Campus | Organization |
| Student | SIS |
| Staff-to-student relationship | an explicit integration contract (not yet defined) |
| Student fee balance | Fees / Payments |
| Staff assets | Inventory (a custody contract is needed) |
| Timetable, substitution | Timetable |
| Transport | Transport |
| Users, roles, capabilities | Identity |
| Payroll calculation **and compensation fields** | Payroll (HR stores no pay) |
| Audit | the platform ledger |
| Headcount analytics | Analytics |

## H. Security, privacy and legal gates (summary)

Full register: `HR-LEGAL-SECURITY-GATE-REGISTER.md`.

| Gate | Effect |
|---|---|
| HRX-L1 | health; **also all free text and attachments in Leave and attendance corrections** |
| HRX-L2 | biometric, device, GPS, FR; ADR 0065 §8 also excludes clock, shifts and late minutes |
| HRX-L3 | statutory leave |
| HRX-L4 | LOP money effect |
| ADR 0036 | statutory payroll |
| E45 | ESI disability |
| E33 / TCH-L1 | **preserve** |
| E47 | personas |
| E21 | retention ratification |
| ADR 0040 | person-count analytics |
| D-11 | exports |
| Rule 105 | no person on an Inventory movement |
| Rules 92 / 93 | no access level on the HR form |
| ADR 0065 §6 | delegation and multi-level approval |
| OD-HR-1 | Payroll checker visibility |
| E16 | release exceptions, through 2026-11-28 |

**Not yet raised** (proposed ids): HR-L5 bank, HR-L6 family/nominees,
HR-L7 government ids on the profile, HR-L8 gender/marital eligibility,
HR-L9 recruitment, HR-L10 disciplinary, HR-L11 appraisal, HR-L12
background checks, HR-L13 photographs.

**Proposed:** OD-HR-14, the HRX-L2a / L2b split.

## I. Proposed logical model

Names follow repository conventions:
- School-owned `snake_case` plural tables;
- `BelongsToSchool` + `TenantRls`;
- composite `(id, school_id)` keys;
- append-only evidence.

**Reuse first.** Nothing below is created by HR.0A.

| Brief entity | Repository entity | State | Phase |
|---|---|---|---|
| Staff | `employees` (`Employee`) | exists | — |
| Employment | `employment_records` | exists | — |
| (employment terms over time) | **`employment_terms`**: employment, category, closed `employment_type`, effective from/to, change reason | proposed (X-3) | HR.1 |
| StaffIdentifier | `employee_number` (exists) + **`employee_reference_codes`** (closed kind, value, unique per School); statutory ids stay in Payroll; biometric codes go in StaffAttendance | partly | HR.1 / HR.8 |
| StaffAssignment | `employee_assignments` | exists | — |
| (named placement change) | **`employee_placement_changes`**: closed kind (`campus_transfer`, `department_transfer`, `designation_change`, `promotion`, `reassignment`), reason code, from / to assignment, effective date, actor; append-only | proposed | HR.1 |
| (staff type) | closed `staff_type` on `employee_categories` (teaching / non_teaching / outsourced) | proposed | HR.1 |
| StaffStatusHistory | **`employment_lifecycle_events`** (append-only; joined, confirmed, notice_started, resignation_requested, separated, rehired; closed reason) | proposed | HR.12 |
| StaffContact / StaffAddress / EmergencyContact | `employees` work contact, `employee_personal_details`, `employee_addresses`, `employee_emergency_contacts` | exists | HR.2 (archive instead of delete) |
| Family / Dependent | — | gated (HR-L6) | — |
| Qualification / Experience | `employee_qualifications`, `employee_certifications`, `employee_experience_records` | exists | — |
| StaffDocument | `employee_documents` + Documents | exists | HR.2 (category catalogue) |
| Bank / Payment Profile | — (Payroll-owned when allowed) | gated (HR-L5) | — |
| ReportingRelationship | **`reporting_relationships`**: subordinate assignment, manager assignment, `kind` (primary / dotted), starts / ends, reason; replaces the live pointer | proposed (X-2) | HR.3 |
| ReportingDelegation | **`reporting_delegations`** (delegator, delegate, decision family, window) | after the ADR 0065 §6 amendment | HR.3 / HR.11 |
| WorkCalendar / Holiday | `staff_working_weekdays`, `staff_holidays` → **`work_calendars`** (School or Campus, versioned), **`work_calendar_rules`** (weekday, nth week), **`work_calendar_holidays`** (portion, optional, applicability) | proposed move | HR.4 |
| Shift / ShiftRule / ShiftAssignment | **`shifts`**, **`shift_versions`** (sessions, local times, grace, early allowance, crosses midnight, work-hours type), **`shift_assignments`** (employment, shift, base / temporary, starts / ends) | gated (L2a) | HR.4 |
| AttendanceSource / Device / Identity | **`attendance_sources`** (closed kind), **`attendance_devices`**, **`attendance_identities`** (source, external code → Employee, effective-dated) | gated (L2b) | HR.5 / HR.8 |
| AttendanceEvent | **`attendance_events`**: append-only, UTC `occurred_at`, `received_at`, source, device, Campus, `provider_event_id` unique per source, raw hash | gated (L2a/b) | HR.5 |
| AttendanceDay | `staff_attendance_records` (manual result) + **`attendance_day_evaluations`** (versioned evaluation from events, shift, calendar, leave, permission) | partly / gated | HR.6 |
| AttendanceAdjustment | `staff_attendance_corrections` | exists | — |
| AttendanceRegularization | **`attendance_regularization_requests`** (request/decide) | proposed | HR.7 |
| (period lock) | **`staff_attendance_period_locks`** (month, locked, reopened with reason) | proposed | HR.7 |
| LeavePolicy / LeavePolicyVersion | `leave_types`, `leave_policies` (versioned rows) + new **rule columns on the version** (notice, backdate window, max per request, sandwich / prefix / suffix modes, proration) | partly | HR.9 |
| LeaveEligibility | **`leave_eligibility_rules`** (policy × staff type / category / department) → resolved into `leave_policy_assignments` by an idempotent assignment run | proposed | HR.9 |
| LeaveAccount | implicit (employment × type × year) | exists | — |
| LeaveLedgerEntry | `leave_ledger_entries` + kinds `opening`, `accrual`, `comp_off_credit`, `comp_off_expiry` | partly | HR.10 / HR.11 |
| LeaveRequest / LeaveDecision | `leave_requests`, `leave_request_days`, `leave_decisions` | exists | — |
| CompOffCredit | **`compensatory_off_requests`** → ledger | proposed | HR.11 |
| PermissionRequest | **`staff_permission_requests`** (time window gated by L2a) | proposed | HR.11 |
| SpecialLeaveWindow | **`leave_special_windows`** + **`leave_special_window_periods`** | proposed | HR.11 |
| WorkforceTask* | **`workforce_task_categories`**, **`workforce_task_templates`**, **`workforce_task_assignments`**, **`workforce_task_submissions`** | proposed | HR.13 |
| (separation workflow) | **`separation_requests`**, **`separation_clearance_items`** (each owner answers) | proposed | HR.12 |

**Relationships (all same-School composite keys):**
- An Employee has many employments.
- An employment has many terms, assignments, policy assignments, shift
  assignments, attendance days and ledger entries.
- An assignment (subordinate) has many reporting relationships to an
  assignment (manager).
- An attendance identity → Employee. An attendance event → identity →
  employment (resolved at evaluation for the date).
- Leave types → policy versions → policy assignments → ledger. Requests
  are frozen into days.
- Task templates → position and staff type. Task assignments → assignee
  Employee and assigner Employee; optionally a Section or SubjectOffering
  through Academic Structure composite keys.

## J. Temporal model

**Effective-dated contracts.** None of these may use `updated_at`.

| Concept | Today | Target | Overlap / exclusivity rule |
|---|---|---|---|
| Employment | dated | dated | per Employee, no two overlapping non-draft employments (S-10 database backstop) |
| Employment category / type / staff type | in place | `employment_terms` | contiguous, non-overlapping, covering the employment |
| Campus / department / designation | dated assignments | + `employee_placement_changes` | per employment, **at most one primary assignment on any date** (extends today's open-only unique index) |
| Manager | live pointer | `reporting_relationships` | per subordinate assignment, one primary relationship per date; no cycle on any date |
| Shift | — | `shift_assignments` | one base shift per employment per date; temporaries do not overlap each other and override the base |
| Work calendar | School-wide, unversioned | `work_calendars` versions | one version per (School, Campus-or-NULL) per date; Campus wins over School |
| Leave policy | versioned rows | + rule columns on the version | a used version is immutable (supersede, never edit) |
| Leave policy assignment | dated | dated | per employment × type, no overlap |
| Leave entitlement | year ledger | + accrual period key | one accrual per (account, period): unique |
| Delegation | — | `reporting_delegations` | delegate ≠ delegator; windows per (delegator, family) do not overlap |
| Attendance identity | — | dated | per (source, code) one Employee at a time; per Employee per source one active code |
| Compensation | dated (Payroll) | unchanged | Payroll's existing rules |

**Mechanism.** The repository deliberately does **not** use
`btree_gist` / `EXCLUDE USING gist`; see `create_employment_records_table`
and `create_employee_compensation_assignments_table`. Overlap rules
therefore use:
- a BEFORE INSERT/UPDATE trigger under the owner's lock (the
  `student_group_memberships` no-overlap pattern, rule 128);
- `EmployeePlacementLock` taken first;
- two-process tests.

Dates are School-local (`SchoolTimezone::today()`). An end "today" closes
yesterday (ADR 0078 empty-interval convention).

## K. Authorization model

**Four concerns, never merged:**

| Concern | Mechanism | Rule |
|---|---|---|
| 1. Application authorization | capabilities via `CapabilityResolver`, Campus scope via `CampusScopeResolver` | rules 6, 24, 98, 101 |
| 2. Reporting relationship | `ReportingLine` (fact, as of a date) | never a capability |
| 3. Approval authority | the approval foundation: `requireCurrent()` + `MakerCheckerPolicy` + the reporting line as an **additional** condition | rule 102 |
| 4. Data visibility | section, column and dataset capabilities + MFA assurance | rules 95, 114, 125 |

**A reporting manager is not entitled to the report's HR data.** A
manager sees team operational status only through a proposed scoped read
(`hr.team.view`, C4 scoped authority), with the minimal columns below.

### E. Sensitive field authorization (data classes)

| Data class | Example fields | Existing authority | Rule for reports and exports |
|---|---|---|---|
| Directory (internal) | name, number, position, department, Campus | `hr.employees.view` (Campus-adopted) | OPERATIONAL column |
| Work contact | work email / phone | `hr.employees.view` | OPERATIONAL |
| Personal contact, address, emergency contacts | personal email / phone, addresses | `hr.employees.personal.view` | RESTRICTED (`requires`) |
| Employment | dates, status, category, type, assignments | `hr.employees.assignments.view` | RESTRICTED for history |
| Manager-operational | today's attendance status, approved leave dates (no type or reason) | proposed `hr.team.view` (reporting-line scoped) | never in a School-wide export |
| HR confidential | notes, highly sensitive documents | `hr.employees.notes.view`, `hr.employees.sensitive.view` (`hr_sensitive_records`), MFA | never a column |
| Payroll confidential | compensation, results, payslips | `payroll.compensation.sensitive.view` (`payroll_sensitive` grant right), MFA | never an HR column |
| Bank / payment | — | none (HR-L5) | not stored |
| Statutory / government ids | UAN, PAN, PF, ESIC (Payroll) | `payroll.statutory.identifiers.view` (no production role), S-4 | never an HR column |
| Family / dependants | — | none (HR-L6) | not stored |
| Sensitive demographic | DOB, marital status, nationality | `hr.employees.personal.view`; gender, religion and caste not stored | DOB never on a Campus or manager surface |
| Health | — | none (HRX-L1) | not stored |
| Security / audit | activity timeline, audit log | `school.audit.view`, activity on the profile | never exported with HR data |

**Column rule.** HR reports reuse the `StudentReportColumn` OPERATIONAL /
RESTRICTED pattern:
- a column's **availability** is decided on the server from the viewer's
  capabilities, so an absent column is absent, never blanked;
- a forged column is a 422;
- the export uses the identical column authority plus a dataset
  capability, and is audited (`hr_report.exported`, ids only).

The central `SensitiveDataCatalogue` stays explanation-only. Any future
deny-only policy follows rule 114 (central projection on every path).

### Persona matrix (existing roles; changes are proposals, rule 93)

| Persona | Role today | Holds | Never holds |
|---|---|---|---|
| Employee self-service | `staff_self_service` | own leave, attendance (read), payslips | others' data; own placement or pay administration |
| Reporting manager | any role + reporting line | today: the Leave approvals queue only with `hr.leave.approve` (`school_admin` / `principal`) | personal, payroll, health or notes data. Proposed: `hr.team.view` + manager decision keys (OD-HR-5) |
| School HR officer | `hr_officer` (+ `hr_sensitive_records` add-on) | employees, personal, assignments, documents, masters, leave configure / manage, staff attendance | payroll values, leave approval |
| School HR manager | `school_admin` / `principal` | employees, leave configure / manage / **approve**, staff attendance | per-employee pay (`school_admin`); all payroll (`principal`) |
| Central / corporate HR | **none** | — | Network roles never read tenant HR data (rules 84, 113–114). Multi-School HR = per-School grants on each membership |
| Attendance operator | `hr_officer` | staff attendance manage | a dedicated role would be a catalogue release |
| Payroll operator | `payroll_officer` | structures, compensation-sensitive, runs prepare | approve, post (maker/checker) |
| Auditor (read-only) | **none School-side** (`platform_auditor` reads the platform ledger only) | — | an HR auditor role is an owner decision |
| SysAdmin / platform | platform roles, elevation | **no School authority** (rule 83) | confidential HR data; elevation grants context, never authority |

## L. Reporting architecture

| Report class | Mode | Notes |
|---|---|---|
| Directory, joiners, leavers, reporting tree, Leave requests, monthly leave, registers, ledger statement, modification logs, task lists | **transactional query**: paged on the server (≤ 50 rows, max 100), scope first, filters only narrow, closed sorts, `as_of` parameter where temporal | HR report registry mirroring `StudentReportRegistry` (HR.14) |
| Attendance day results | **materialized by design**: `attendance_day_evaluations` *is* the read model, versioned | HR.6 |
| Monthly attendance per employment (payroll input) | materialized monthly summary, frozen at lock | HR.7 |
| Leave balance as of a date | ledger sum (bounded per account); year-close snapshots exist | HR.10 / HR.14 |
| Headcount, trends, gender / age, leave analytics | **Analytics read models**: blocked (ADR 0040) | never in HR |
| Exports | D-11 extension: per-dataset capability, declared columns, `CsvCell::safe()`, ≤ 10,000 rows, synchronous, audited; **no background export job** (rule 125) | HR.14 |

**UX.** Replace the reference's very wide horizontal tables with:
- pinned identity columns;
- a permission-aware column selector;
- a detail drawer or profile page instead of 40 columns.

## M. Tenancy

Every HR table is School-owned:
- `BelongsToSchool`;
- `TenantRls::enable`;
- composite keys;
- queue jobs `TenantScoped`;
- cache keys through `TenantCache`;
- locks through `TenantLock::forSchool()`.

**Isolation tests are mandatory** for:
- cross-School id guessing;
- a manager relationship across Schools (composite FK);
- attendance, leave, reports and exports;
- biometric identity: `(school_id, source, code)` unique **per School**,
  never global;
- staff ↔ SIS links.

Each runs at the Eloquent **and** raw-SQL / RLS layers. No second tenancy
mechanism.

## N. Audit model

- Reuse `AuditRecorder` → `school_audit_events`:
  - actor, event type, subject, `request_id`;
  - metadata = ids, closed codes and changed field names.
- **Before and after state** lives in the owning evidence tables. These
  are all append-only:
  - `staff_attendance_corrections` (before / after values);
  - `employment_terms`;
  - `employee_placement_changes`;
  - `reporting_relationships`;
  - policy versions;
  - the ledger.
- Never values in audit metadata: the privacy rule used across ORG and
  SIS.
- **Significant changes that must be audited** (each phase adds its event
  types to the HR catalogue in `docs/modules/HR.md`):
  - employment status;
  - department, designation, Campus;
  - manager;
  - shift;
  - attendance corrections;
  - leave policy, allocation, decisions;
  - bank / compensation (Payroll);
  - biometric mapping;
  - sensitive profile changes;
  - exports.
- No separate HR audit framework.

## O. Performance

| High-volume data | Estimate (400 staff) | Recommendation |
|---|---|---|
| `attendance_events` | ~4 punches × 300 days ≈ 480k / year / School | unique `(school_id, source_id, provider_event_id)`; index `(school_id, attendance_identity_id, occurred_at)`; partition only when measured (rule 2) |
| `attendance_day_evaluations` | ~146k / year | unique `(school_id, employment_record_id, date)` for the current version; index `(school_id, date)` |
| Leave ledger | small | existing indexes |
| Audit | grows with all writes | existing `(school_id, event_type)`, subject index |
| Workforce task submissions | template × frequency × staff (daily tasks ≈ staff × 250 × n) | generate occurrences lazily (on read for "due today"), never pre-materialize a year |
| Reports | — | constant-query budgets (`StudentProfile360Test` pattern); never load every Employee into the browser; server paging |

The known **Payroll run N+1** (~15 queries per line) is fixed before any
payroll scale work.

## P. UI / UX contract

Use the Lycenza design system: Inertia + Vue, the existing HR pages and
navigation groups. Target navigation under the HR area:

| Area | Contents |
|---|---|
| Staff | directory, profile / Staff 360, masters, transfers, lifecycle |
| Attendance | register, month, corrections, regularization, locks |
| Attendance Sources | gated; appears only when HR.8 is authorized |
| Leave | types, policies, eligibility, allocation, requests, approvals, comp-off, permissions, windows, calendar |
| Workforce Tasks | — |
| HR Reports | — |

**Prefer:**
- detail screens;
- responsive tables with pinned identity columns;
- server paging;
- permission-aware column selectors;
- explicit lifecycle status;
- accessible forms;
- confirmation for material actions (MFA where rule 95 requires it);
- meaningful empty states;
- links from each setup area into the Setup & Readiness Center.

**Never:**
- extreme horizontal scrolling;
- inline master creation from a profile form;
- an "access level" field on HR forms.

## Q. Migration and backfill implications

No production data exists (development and demo only), but each backfill
is written as a reversible migration (rule 10).

| Change | Backfill |
|---|---|
| `employment_terms` (HR.1) | one row per employment from current `employee_category_id` / `employment_type`, `effective_from = starts_on`. Free-text `employment_type` is mapped to the closed catalogue; unmapped values are listed for the owner, never guessed |
| Staff type on categories (HR.1) | the owner classifies each School's existing categories (demo: teaching / non-teaching) |
| Primary-assignment overlap trigger (HR.1) | pre-check existing rows; refuse to migrate if overlaps exist (none expected) |
| `reporting_relationships` (HR.3) | one row per non-null `manager_assignment_id`, starting at the later of the two assignment starts and marked `backfilled`. **Earlier manager history is not reconstructible** (audit holds field names only); recorded as a known limit |
| WorkSchedule move (HR.4) | copy `staff_working_weekdays` / `staff_holidays` into a School-level calendar version; Leave and StaffAttendance switch readers in the same release |
| Document category catalogue (HR.2) | map free text; unmapped → `uncategorised` for review |
| Leave opening balances | the `opening` kind is used only at go-live import; nothing to backfill |
| Staff attendance | existing manual records remain the manual-source truth; **no fabricated events** |

## R. Proposed architecture decisions

**None is decided by HR.0A.** Each becomes an ADR or an amendment in its
phase.

| Id | Decision proposed | Amends |
|---|---|---|
| HR-AD-01 | Employment terms are effective-dated (`employment_terms`); in-place category and type updates end | ADR 0028 / `HR.md` |
| HR-AD-02 | A named placement-change operation (closed kind + reason) over the existing end-and-create assignment mechanism | ADR 0076 §43 deferral (HR-083) |
| HR-AD-03 | A closed staff-type classification on employee categories | `HR.md` |
| HR-AD-04 | Effective-dated `reporting_relationships` replace the live pointer; L0–L3 is computed depth; database cycle backstop | `HR.md`, ADR 0065 §6 for delegation |
| HR-AD-05 | A WorkSchedule owner for calendars and shifts (moved out of Leave), consumed by Leave, StaffAttendance and Payroll | ADR 0065 §4.7, §8 |
| HR-AD-06 | Split HRX-L2 into L2a (timing) and L2b (biometric, device, GPS, FR) | ADR 0065 §19 (OD-HR-14) |
| HR-AD-07 | Attendance = source events → versioned day evaluation; manual records remain a source; corrections never touch events | ADR 0065 §7 |
| HR-AD-08 | Attendance period lock with controlled reopening before payroll consumption | ADR 0065, ADR 0076 §60 |
| HR-AD-09 | Leave rule columns live on the policy version; eligibility resolves into policy assignments by an idempotent run | ADR 0065 §4 |
| HR-AD-10 | New ledger kinds (`opening`, `accrual`, `comp_off_credit`, `comp_off_expiry`) under the existing ledger guard | ADR 0065 §4 |
| HR-AD-11 | WorkforceTasks is an HR sub-domain with no dependency on Attendance or Leave | new ADR |
| HR-AD-12 | An HR report registry and a D-11 export extension for HR datasets | rule 125 / D-11 |

## S. Findings and deferred scope

**Findings:**
- **X-1** (cross-domain Employee reads, P2) is new. X-2 and X-3 are
  architecture gaps.
- S-1…S-16 stand as recorded (gate register §5).
- **No critical defect requires immediate remediation**, so HR.0A stays
  documentation-only.

**Minimal correction proposal for X-1 (HR.15):**
- route Timetable's teacher search and entry validation, Transport's driver
  assignment and Visitor's host selection through `EmployeePickerReadService`
  and `EmploymentCoverage`;
- this means current employment, a Campus-scoped list where the consumer is
  Campus-scoped, and a generic refusal otherwise;
- each consumer gets a deny test.

**Deferred** (each keeps its row in the capability map):
- recruitment;
- training / PD;
- feedback and complaints;
- performance;
- outsourcing vendors;
- the handbook library;
- profile photographs;
- profile cards;
- family / dependants;
- bank / disbursement;
- government ids on the profile;
- gender / marital eligibility;
- superannuation;
- WFH;
- manager correction;
- delegation / escalation / multi-level (until ADR 0065 §6 is amended);
- Analytics (headcount, leave and attendance analytics).

## T. Decisions of this checkpoint

1. **HR reference pack gate: COMPLETE.**
   - 602 PNG, fingerprint `40ed6a836f5a…a1ac3`, frozen as the HR baseline.
   - HR.EXP-0R's provisional 335-file inventory and gate verdict are
     superseded.
   - Its §6 (OD-HR-1 analysis) and §7–§8 remain the authoritative
     formulation.
2. **The HR.EXP draft waves are superseded** by the HR.1–HR.16 + HR.R1
   roadmap (`HR-EXPANSION-ROADMAP.md`, with the mapping from old to new).
3. **HR.R1 is reserved** (`HR-R1-CLOSURE-CONTRACT.md`).
4. **Owner decisions requested:**
   - OD-HR-14 (HRX-L2 split);
   - OD-HR-1 / OD-HR-2 (payroll checker);
   - OD-HR-5 (manager approval);
   - OD-HR-3, OD-HR-9, OD-HR-12, OD-HR-13 as their phases arrive.
5. **Next checkpoint (exactly one): HR.1A — Workforce Foundation**:
   - employment terms;
   - named placement change;
   - staff type;
   - primary-assignment and employment overlap backstops;
   - S-8, S-9, S-11, S-12 (type) and S-15;
   - ORG10-D01.

   It is ungated, and it is the base on which reporting history (HR.3),
   leave eligibility (HR.9) and tasks (HR.13) depend.
