# HR.EXP-0 — HR ERP current-state audit (repository side)

Status: **audit record, documentation only** (2026-10-30, baseline
`c5e3dd23`; latest code regression `d44cca48`, 10,144 / 10,143 + 1 ESI-12
skip / 0 failed). Nothing was built: no migration, code, capability, role,
Campus, route, UI or demo change.

Companion records in `docs/hr/`:
- `HR-LEGAL-SECURITY-GATE-REGISTER.md`;
- `HR-DOMAIN-OWNERSHIP-MAP.md`;
- `HR-EXPANSION-ROADMAP.md`;
- `HR-DEMO-PLAN.md`;
- `HR-REFERENCE-CAPABILITY-MAP.md` (**pending the reference pack**, §0).

## 0. Reference pack

- **Designated location:** the owner's pack directory has an `HR` folder.
  On 2026-10-30 it was **empty**, confirmed both from WSL and from Windows.
- **In progress:** 25 HR-module captures from the same reference ERP, taken
  that evening, sit in a separate downloads folder. They are an
  **in-progress, undeclared capture**, not the pack.
- **Owner decision (2026-10-30):** wait for the complete pack. The
  repository audit proceeds now, and the reference map, coverage and final
  wave order follow from the complete pack.
- **No screenshot finding is claimed in this audit.**

## 1. Architecture

**Domains.**

| Domain | Files / tests | Owns |
|---|---|---|
| `app/Domain/HR` | 155 / 86 | Employee identity, employment, assignments, Departments, Positions, Categories, reporting line, profile sections, HR documents, credentials, import, onboarding, lifecycle, ActingEmployee |
| `app/Domain/Leave` | 44 / 13 | leave types, years, policies, ledger, requests, decisions, staff calendar |
| `app/Domain/StaffAttendance` | 14 / 8 | daily staff attendance and its corrections |
| `app/Domain/Payroll` | 180 / 41 | components, structures, compensation, periods, runs, results, adjustments, posting, payslips, statutory engine |

**Modules** (`ModuleCatalogue`):
- `staff_records` is **core**;
- `leave` is optional;
- `staff_attendance` is optional and depends on `leave`;
- `payroll` is optional and depends on `finance`.

**Teacher identity.** `ActingEmployee` (an Employee linked to a User with
exactly one current employment) plus `TeachingAssignment` ownership
(ADR 0063). There is **no Teacher table**, and the `teacher` role holds no
`hr.*` key.

## 2. Staff identity

- **Separate records.** `Employee` (HR identity) and `User` (login) are
  separate. Guardian and Student identities are siblings, and there is no
  Person table.
- **The link** is `employees.user_id`: nullable, unique per School, restrict
  on delete. Creating an Employee **never creates a User**.
- **Staff invitation** (rule 92) never creates an Employee. Linking needs an
  active membership and an enabled User, is never to oneself, and is audited.
- **Staff-parent:** `HrSelfAdministrationGuard` and
  `GuardianSelfAdministrationGuard`, plus the beneficiary refusals (rule 102).
  M-1 (an unlinked staff parent) is a recorded production prerequisite.

## 3. Staff master

**`employees`:**
- `employee_number`: `EMP-` plus 6 digits, a forward-only per-School counter
  under a row lock, unique per School. Immutable only in Eloquent. A rehire
  keeps the number.
- `full_name` (one string), `work_email` (unique per School), `work_phone`,
  `record_status` (`active`/`archived`).

**`employee_personal_details`:** DOB, nationality, marital status, preferred
language, personal email/phone/alternate.

**Other HR profile tables:**
- `employee_addresses`: current, permanent, mailing, other.
- `employee_emergency_contacts`.
- `employee_notes`.
- `employee_qualifications`, `employee_certifications` (verification;
  certifications also carry credential number and expiry).
- `employee_experience_records`.

**Payroll-owned, per employment:**
- `employee_statutory_identifiers`: PAN, UAN, PF member id, ESIC IP;
  encrypted with an HMAC lookup.
- `employee_pf_status`, `employee_esi_coverage`, `employee_tax_profile`.
- `employee_compensation_assignments`.

**Not modelled:** gender, photograph, blood group, religion, **bank details**,
skills, dependants. There is no teacher field in HR; teaching data is
`teaching_assignments`.

## 4. Lifecycle and temporal model

- **Statuses.**
  - Employee: `active`/`archived`.
  - Employment: `draft`, `pre_joining`, `active`, `notice_period`,
    `separated`, `terminated`, `retired`, `deceased` (DB CHECK).
- **Transitions.**
  - The status is set at create time. The web form writes `active`; the API
    accepts any status (S-8).
  - The only later change is `end()` to a terminal status. It closes
    assignments, clears manager pointers and ends teaching ownership.
  - `separate()` refuses a future date, but `/end` accepts one (S-8).
  - A rehire is a new employment.
  - Archive and restore are whole-School.
- **Temporal fields.**
  - Employment: `starts_on`, `ends_on`, `probation_ends_on`.
  - Assignments: date ranges plus one open primary (partial unique).
  - The reporting line is a live pointer, valid through the two assignments'
    dates. There is no manager-history table; changes are audited.
  - Compensation is effective-dated.
- **Currency.** `CurrentEmployeeAssignments` and `CurrentStaffMembers` use the
  School-local date.
- **ORG10-D01** (OPEN UAT-MINOR, accepted for staging): the directory's
  "Current" lists future hires, and "Pre-joining" is always empty.
- **Hard delete:** never at runtime (DELETE is revoked). Only
  `platform:employee-retention-prune`, as the retention identity, after D9.

## 5. Directory and profile

- **Directory.**
  - Rows: number, name, position, department, Campus, manager. No contacts.
  - Search: name, or number prefix.
  - Filters: Campus, department, position, archived, employment state,
    multi-Campus, joined in a period, reports to.
  - Sort: name/number. 25 a page (max 100). Campus-scoped through
    `HrEmployeeAccess`.
- **Profile.**
  - A whole-School workspace, tiered by section: summary, personal, contact,
    addresses, emergency contacts, employment history, assignments,
    qualifications, experience, certifications, documents, notes.
  - Also an activity timeline and a minimized Campus-safe overview.
- **No Staff 360.** Leave, attendance, payroll and teaching assignments are
  not projected; teaching assignments appear only as a link.

## 6. Structure

- **Departments** (`hr_departments`): School-owned, optional same-School
  Campus, parent hierarchy (no self-parent CHECK), code unique per School,
  active/inactive. **No department head.** Reparent has a cycle race (S-9).
- **Positions:** name, code, description, status. No grade or band, and no
  link to any authorization table. **Designation ≠ authorization role**
  (confirmed).
- **Employee categories:** School-configured (e.g. teaching / non-teaching).
  No structural teaching flag.
- **Employment type:** free text on web and API; closed only in import
  (S-12).

## 7. Campus and manager

- **Campus-adopted:** exactly `hr.employees.view`/`.manage` and
  `hr.employees.assignments.view`/`.manage` (`HrEmployeeAccess`, rule 101).
- **Declared whole-School:** 24 HR operations, including create, archive,
  account link, import, employment and rehire.
- **Not adopted:** Leave, Staff Attendance and Payroll keys (whole-School
  only; a Campus HR grant gives no Leave or attendance authority).
- **Reporting line.**
  - The lock order is followed (`EmployeePlacementLock`).
  - The no-self-report CHECK is in the database; indirect cycles are refused
    in the application after ordered locks (S-10).
  - Same-School composite key.
  - G-9 deadlock is LOW and recorded.

## 8. Documents and credentials

- **`employee_documents`** holds metadata only. It records a category
  (free text, S-12) and a tier (`restricted`/`highly_sensitive`), plus issue
  and expiry dates and active/archived status. There is no delete route.
- **Bytes** go through the Documents `employee` owner type. Sensitive reads
  need MFA; writes need a step-up.
- **Not built:** expiry processing, document self-service.
- **Verification:** qualifications and certifications only.
- **Cross-product content security** (EXIF, PDF sanitisation, malware) stays
  with Documents / platform security (`DOCUMENTS.md`).

## 9. Recruitment

- **Absent.** There is no vacancy, candidate, application, public form,
  interview, offer or candidate→Employee conversion anywhere (code, schema
  and routes). `HR.md` lists recruitment as out of scope.
- **Onboarding today:** `EmployeeOnboardingService` (Employee + Employment +
  first Assignment, atomic, Campus-aware, start date today or earlier) and
  the JSON import (≤1,000 rows, a transaction per row, create-only).

## 10. Self-service and managers

- **Self-service** (`staff_self_service` role, `ActingEmployeeResolver`):
  own leave (submit, withdraw, cancel before start), own staff attendance
  (read only), own payslips (posted runs only).
- **Absent:** own profile, contact-update request, own documents, team
  views.
- **Managers:** a Leave approvals queue for current direct reports, needing
  `hr.leave.approve`. That key is held only by `school_admin` and
  `principal`, so ordinary line managers cannot approve in practice.

## 11. Leave

**Leave years.**
- Configurable start month (default April). It is prospective-only once a
  year exists, through `scheduleStartChange()`, and is never back-dated.
- A year is opened once and is immutable.

**Balances and entitlement.**
- Ledger-computed balances: there is no balance column.
- Ledger entry kinds: allocation, adjustment, consumption, reversal,
  carry-forward in/out, expiry.
- **No accrual or proration.** Allocation is annual or explicit.
- **Never negative** (DB trigger).
- Carry-forward is capped at the explicit year close. The mid-year expiry of
  carried units is recorded but not executed.
- Policies are versioned.

**Adjustments.** `hr.leave.manage`, a direction and a closed reason, audited
and refused on the actor's own record. **No MFA** (S-7).

**Requests and decisions.**
- Self-service or on behalf of someone. The reason is optional and closed;
  there is no free text and no attachment (guarded).
- States: `submitted → approved | rejected | withdrawn`, then
  `approved → cancelled` (DB trigger).
- **Decision authority is whole-School only** (`requireCurrent` without a
  Campus; the manager path also needs the current reporting line). There is
  no Campus code in Leave (OD-8.8 Option A).
- Self-decision is refused in three places (the service,
  `leave_decisions_no_self_decision` and the participant trigger).

**Calendar.** The staff calendar (weekday pattern plus dated holidays, half
days) lives in Leave. It is School-wide; there is **no Campus calendar and no
shifts**. It fails closed until all 7 weekdays are set.

**Idempotency.** API: 25 of 31 mutations are `idempotent`. Web routes rely on
natural guards.

## 12. Staff attendance

- **The record:** one per employment × date, two halves, each `present` or
  `absent`.
- **Not stored:** check-in/out, late or early, clock time, shift, device,
  biometric (guarded columns).
- **Recording:** by `hr.staff_attendance.manage` only, never in the future.
  Leave and holidays are cross-checked both ways.
- **Corrections:** compare-and-swap, an append-only correction row, closed
  reasons, self refused. **No time window, no MFA, no second person**
  (S-6, recorded decision).
- **Month report:** half-day counts only. Never late, no-punch, threshold or
  loss-of-pay figures.
- **Self-service:** read-only.

## 13. Payroll

- **Periods:** `draft → open → closed`.
- **Runs:**
  - `draft → calculated → approved → posted`; regular or correction.
  - Reversal is a new posting row.
  - There is no paid, export or bank state.
- **Maker-checker (ORG.8B) is intact.** Creators, calculators and adjustment
  authors never approve. Stale results are refused. There are DB backstops
  and two-process tests.
- **S-1 (MEDIUM, P0):** the default approver cannot see the results it
  approves.
- **Structures and compensation:**
  - Structures are immutable revisions.
  - Compensation is effective-dated, values are immutable, and arithmetic
    uses `Money`. Currency is INR (hardcoded).
  - Assignment needs fresh MFA and refuses oneself. **There is no
    compensation approval** (S-2).
- **Finance boundary.** Posting goes through `LedgerService` only: salary
  expense, salary payable and per-deduction liabilities. Accounts are
  resolved at posting time. Payroll journals cannot be reversed through the
  generic ledger. **No disbursement and no bank file.**
- **Statutory payroll:** PF, ESI, PT, LWF and TDS engines, plus ECR, ESI
  worksheet and TDS draft exports, exist. **Not wired, no production role**;
  ESI-12 is deferred.
- **Payslips:** rendered on demand (Inertia print view, or JSON), never
  stored as PDF.
  - Admin path: `compensation.sensitive.view` plus MFA.
  - Self path: `payroll.payslips.self`, own and posted only, without MFA.
- **Bank details: none exist.**

## 14. Not present

The following are absent from code, schema and routes:
- performance and appraisal;
- training;
- complaints and grievances;
- disciplinary records;
- reimbursements and expenses;
- staff assets (Inventory issues to a Department, never to a person);
- skills;
- exports;
- partner HR scopes.

## 15. Authorization (system roles)

| Area | school_admin | principal | hr_officer | hr_sensitive_records | payroll_officer | staff_self_service |
|---|---|---|---|---|---|---|
| Employees: view, manage, personal view | ✓ | ✓ | ✓ | | | |
| Personal manage, assignments, qualifications, documents, notes, Departments, Positions, Categories | | | ✓ | | | |
| Highly sensitive HR records | | | | ✓ (add-on) | | |
| Leave configure, view, manage | ✓ | ✓ | ✓ | | | |
| Leave approve | ✓ | ✓ | | | | |
| Staff attendance view, manage | ✓ | ✓ | ✓ | | | |
| Own leave, attendance, payslips | ✓ (grantable) | | | | | ✓ |
| Payroll structures, compensation view, periods, runs view/prepare | ✓ | | | | ✓ | |
| Payroll approve, post, reverse, accounting | ✓ | | | | | |
| Compensation sensitive view/manage | | | | | ✓ | |
| Statutory payroll | — no production role — | | | | | |

- **Other roles:** `teacher`, `accountant`, `cashier` and the other
  operational roles hold no HR or Payroll key.
- **Platform and Group roles** (`platform_*`, `group_admin`, `network_*`)
  hold none; the scope trigger forbids it.
- **Visibility:**
  - Principal sees no payroll.
  - School Admin sees no per-employee payroll result, payslip or
    compensation value.
  - Nobody sees statutory identifiers in production.
- **Grant rights:**
  - `school.roles.grant.hr` covers the hr_officer-only keys;
  - `.hr_sensitive` covers the highly sensitive HR records;
  - `.payroll_sensitive` covers compensation sensitive view/manage.

## 16. Database

- **Tenancy.** Every HR, Leave, Staff Attendance and Payroll tenant table has
  `school_id` and forced RLS (`TenantRls::enable`). Same-School composite
  keys are used throughout, and SET NULL keys are column-scoped (rule 97).
- **Runtime DELETE.**
  - Revoked on employees, employment, assignments, personal details,
    documents, compensation, statutory data, Leave evidence, attendance
    records and Payroll ledgers.
  - Kept on the six ancillary profile tables and `staff_holidays` (S-16).
- **Append-only:** decisions, ledgers, corrections, calculations, postings
  and adjustments.
- **Retention:**
  - `hr_evidence`: D9, 8 years after separation.
  - `hr_ancillary`: 2 years.
  - `leave_evidence` and `staff_attendance_evidence`: 8 years.
  - `payroll_ledger`: D9 × D8.
  - Configuration tables: tenant lifetime.
  - User references are RETAIN, except `employees.user_id`
    (ACTIVE_PURPOSE_BLOCKER).
- **Platform reference tables:** the statutory rule-version tables have no
  RLS, like `education_boards`.
- **Application-only invariants:** one current employment and indirect
  reporting cycles (S-10).

## 17. Audit and concurrency

- **Audit.** Every write in the four domains is audited with ids and codes
  only (catalogue in `HR.md` and the HRX closure). Payroll events never carry
  amounts.
- **Two-process tests exist for:**
  - Leave and Leave requests;
  - Payroll approval, posting, run lifecycle and compensation;
  - placement locks.
- **Stale-form protection** exists for the attendance correction
  (compare-and-swap) but not for the Employee profile edit. That is a
  candidate for HR.EXP-1, like SIS.EXP-1 L-2.

## 18. Performance

Demo School, 12 Employees, measured with a temporary harness (not
committed):

| Page | Queries (hr.payroll / school.admin) | ms |
|---|---|---|
| Staff directory `/app/hr/employees` | 21 / 19 | 24–30 |
| Staff profile | 30 / 22 | 26–27 |
| Departments | 11 / 403 | 11 |
| Leave requests | 22 / 22 | 15–20 |
| Leave approvals | 403 / 21 | 14 |
| Staff attendance register | 22 / 22 | 16 |
| Staff attendance month | 25 / 25 | 21–24 |
| Payroll hub | 9 / 9 | 10 |
| **Payroll run detail (8 lines)** | **137 / 125** | 71–76 |

**N+1 on the payroll run page.** About 15 queries repeat for every run line:
the employment record, the same `staff_working_weekdays` read, Leave,
attendance, and a tenant-context switch. A 400-person run would need about
6,000 queries. This is a **P2 performance finding** (not security) for the
Payroll wave. No other page grows with data at this size.

## 19. Demo

- **Coverage.** Only Lycenza Demo School has HR data (`DemoDataBuilder::buildHr`,
  `DemoModuleData::payroll`):
  - 12 Employees (8 teaching, 4 non-teaching);
  - 2 categories, 3 Departments, 6 Positions, one Campus each, one primary
    assignment;
  - payroll: BASIC, HRA, PF; the August 2026 run posted, the September run
    calculated.
- **Personas:**
  - `hr.payroll@example.test` (hr_officer, hr_sensitive_records,
    payroll_officer, demo statutory);
  - `school.admin@` (approves and posts);
  - `principal@` (HR, Leave, attendance; Payroll 403);
  - `teacher@` (self-service, EMP-000003).
- **Missing:**
  - reporting lines;
  - Leave data;
  - staff attendance data;
  - recruitment;
  - HR in any Organization-world School (Northstar);
  - any HR walkthrough document.

  The demo also posts the August run as a user without the post key (S-13).

## 20. Assessment

**The HR foundation is sound.** Identity separation, Campus scope,
whole-School Leave decisions, payroll maker-checker, append-only evidence,
forced RLS and retention are all in place. **Nothing needs replacement**, and
the expansion stays **additive**.

**Prerequisites before feature waves:**
1. **Payroll checker visibility (S-1):** owner design decision, then a fix.
2. **HR integrity hardening (HR.HARDEN-0):**
   - S-3 (after the owner decision);
   - S-5, S-8, S-9, S-10, S-11, S-12, S-13;
   - ORG10-D01.

**Gaps relative to a full HR ERP:**
- recruitment;
- Staff 360;
- profile self-service;
- shifts and Campus calendars;
- accrual;
- bank and disbursement;
- performance, training, discipline.

Most are owner- or legal-gated (`HR-EXPANSION-ROADMAP.md`).
