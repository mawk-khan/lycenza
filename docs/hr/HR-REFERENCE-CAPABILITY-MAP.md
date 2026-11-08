# HR reference capability map & gap matrix (HR.0A)

Status: **authoritative WMSCB reference capability matrix and HR gap
matrix** (HR.0A, 2026-11-03, baseline `7a4068fe`; statuses updated by
HR.1A, the same day).
- This record closes the HR.EXP-0R pack gate: the pack is now complete,
  §1.
- It fulfils the `HR-REFERENCE-CAPABILITY-MAP.md` that HR.EXP-0 reserved.

**The reference application is a functional discovery source only.** Its
database shape, fixed L0–L3 hierarchy, "access level" roles, free-text
remarks and data collection are **not** requirements. The Lycenza ADRs,
the tenant model, the Organization and SIS contracts and the authorization
model govern.

**No personal value from the pack is copied into this record.** The
screen-id → file-name index stays outside Git, as with the SIS and
Organisation packs.

## 1. Pack provenance and gate

| Item | Value |
|---|---|
| Location | owner-held: `…/Lycenza/Screenshots Downloaded/HR`, with companion `HR.zip` |
| Files | **602** PNG, no sub-folders. `HR.zip` holds exactly the same 602 names; its presence is the capture-finished signal, as with the SIS and Organisation packs |
| Byte-distinct | 601: one exact duplicate (H431 = H432) |
| Tall captures | 148 screens taller than 1,300 px; the tallest is 30,000 px |
| Pack fingerprint (SIS convention: SHA-256 over each file, in sorted file-name order, of its UTF-8 name followed by its hex SHA-256) | `40ed6a836f5a99c65694118c0481bc468b1fb6ebb379a0275cc33839fa7a1ac3` |
| Fingerprint verified (HR.R1-C16, 2026-11-08) | Recomputed from the owner-held pack: 602 PNG, no sub-folders, exactly the recorded value. The exact convention: file names in sorted order (byte, case-folded and default orders agree for this pack); for each, its UTF-8 name immediately followed by its lowercase hex SHA-256, no separator; SHA-256 over the concatenation. `HR.zip` holds the same 602 names; its own SHA-256 is `9c44d851d8a3aacfd13ab9b1ee5b985d2897a1dd2b8c716bd2fa604753c5f73b` |
| Relation to HR.EXP-0R | The 335-file provisional set (`c21e40f7…d4d4`) was a subset still being captured. **Superseded**: this 602-file pack is the HR reference baseline |
| Screen ids | `H001`–`H602` in sorted file-name order |
| Inspection | every screen on 6-up top-of-page contact sheets, plus full-resolution crops for tall pages, forms, dropdowns and report grids. Five parallel read-only inspections (H001–120, 121–240, 241–360, 361–480, 481–602), one record per screen, 602/602 assigned to exactly one family |
| Provenance | the same vendor as the SIS and ORG.9A packs (school ERP, HR module); one multi-branch tenant; a system-administrator persona |

**Pack gate: COMPLETE.** The HR.EXP-0R missing list is satisfied:
- All six top menus are captured expanded (H597–H602, §2).
- The Leaves menu is covered (H430–H498).
- The Biometric Attendance menu is covered (H373–H425).
- The Reports catalogue is covered (H602 and H518–H596).
- The Staff Documents tab is covered.

Payroll run, approval and payslip screens are **not** in the vendor's HR
module. Only the HR "Payroll Biometric Attendance" confirmation and the
salary log appear. OD-HR-1 therefore stays a Lycenza integrity decision
(`HR-EXP-0R-REFERENCE-RECONCILIATION.md` §6). It is not reference parity.

## 2. Reference menu inventory (verbatim, H597–H602)

| Top menu | Items |
|---|---|
| Staff Details (H597) | Create Staff Details · Staff Groups · Inactive Staff Approvals · Update Staff Details · Continuous Feedback/Praises · Staff Trainings & PD · Manage Outsource Staff · Staff Handbook and Policies |
| Attendance (H598) | Give Daily Attendance · Manage Staff Attendance Shifts And Sessions New · Assign Work From Home to Staff · Daily Attendance Report · Monthly Attendance Report · Staff Attendance Timing Modification · Staff Work From Home Attendance Marking |
| Biometric Attendance (H599) | Staff Biometric Codes · Payroll Biometric Attendance Report · Late Comers - Early Leaving · Daily Biometric Attendance Report · Staff Attendance modification · Modified Attendance Report · Staff Biometric Attendance Between Dates · Approve Staff Attendance Regularizations · Branch Wise Daily Biometric Attendance Report |
| Leaves (H600) | Create Staff Leaves · Staff Holidays · Update Staff Monthly leaves · Approve Leaves · Assign Staff Reporting Managers · Inactive Staff Approvals · Approve Staff Compensatory Off Credit Requests · Staff Leave Special Windows · Approve Staff Permission Requests |
| Staff Tasks (H601) | Staff Task Manager · My Team Tasks · Staff Task Report · Staff Task Detailed Report · Staff Task Monitor |
| Reports (H602) | Staff Student Fee details · Staff FR Attendance Report · Staff Attendance Report · Staff Profile Cards · Staff Kids Report · Roaming Staff Details · Staff Praise/Feedback/Complaints Report · Smart MCB GPS Self Check-in and Check-out · Staff Details Report · MIS · Inactive Staff Details · Staff joining details report · Sessions Attended by Staff · Staff Leaves Details · Reporting Managers · Staff Replacement Report · Staff Inventory Issued Items · Staff Attendance and Salary Log · Staff Leaves Report · Month Wise Staff Leaves Report |

## 3. Screen families

Total = all screens. Distinct = screens that are not a dropdown, scroll or
state variant of another screen.

| Family | Screens | Distinct | Main evidence |
|---|---|---|---|
| D20 reports / MIS / dashboards | 121 | 36 | H001–H059, H307–H321, H354–H360, H518–H596 |
| D1 staff master / profile | 91 | 26 | H060–H117, H130–H182, H221–H248, H322–H350 |
| D3 organisation masters | 42 | 4 | H186–H211, H339 |
| D4 reporting hierarchy / levels | 36 | 6 | H100–H104, H160–H172, H191, H478–H482, H565–H580 |
| D18 lifecycle (exit, NOC, contract, superannuation, transfer, joining) | 26 | 9 | H124–H129, H174–H178, H220, H246, H300, H322–H326, H351–H353, H483–H487 |
| D8 attendance operations | 25 | 13 | H281–H284, H303–H306, H414–H425, H587–H590 |
| D19 staff tasks | 24 | 7 | H061–H069, H499–H517 |
| D7 attendance evaluation | 20 | 5 | H049–H057, H380–H401 |
| D10 leave policy | 20 | 5 | H430–H448, H454–H457 |
| D5 calendar / shifts | 19 | 4 | H285–H302, H412 |
| D9 biometric | 15 | 7 | H106, H233, H373–H379, H407–H413 |
| D12 leave allocation | 9 | 4 | H441–H442, H466–H471 |
| D13 leave workflow | 8 | 2 | H472–H477 |
| D11 leave balances | 6 | 4 | H449–H451, H563–H564 |
| D15 compensatory off | 5 | 1 | H488–H492 |
| D14 holidays | 4 | 2 | H462–H465 |
| D17 special leave windows | 4 | 1 | H493–H496 |
| D6 attendance sources (GPS, FR) | 2 | 2 | H521, H545 |
| D16 permissions | 2 | 1 | H497–H498 |
| D2 effective assignment change | 1 | 0 | H225 (in-place designation change) |
| XC collaboration (wall, groups, meetings, greetings) | 40 | 13 | H012–H026, H063–H074, H121–H123, H212–H219 |
| XT training / PD / achievements / research log | 14 | 2 | H258–H271 |
| XO outsourcing vendors and contracts | 14 | 2 | H272–H278 |
| XX other (menus, inventory issued, staff-kids fee) | 13 | 8 | H581–H602 |
| XTT timetable substitution / workload | 10 | 4 | H042, H315, H357–H359, H411, H529, H582 |
| XF praise / feedback / complaints / internal notes | 10 | 2 | H249–H257, H544 |
| XA access level (login role on the HR form) | 6 | 0 | H092, H142, H153, H173 |
| XD handbook / SOP / resumes | 6 | 3 | H279–H280 |
| XP payroll / tax / statutory (Form 12C, UAN/PF/ESI, pay type) | 5 | 2 | H241, H329–H334 |
| XTR staff transport | 4 | 3 | H183–H186 |
| **Total** | **602** | **192** | |

## 4. Gap matrix (every D-capability, exactly one status)

**Status codes:**

| Code | Status |
|---|---|
| IMP | `IMPLEMENTED` |
| PART | `PARTIALLY_IMPLEMENTED` |
| MISS | `MISSING` |
| ALT | `IMPLEMENTED_DIFFERENTLY_AND_ACCEPTABLE` |
| XDOM | `CROSS_DOMAIN` |
| DEF | `DEFERRED` |
| GATE | `BLOCKED_BY_GATE` |
| NR | `NOT_REQUIRED` |

**Risk:** H = integrity, security or legal exposure if built wrong;
M = correctness or history; L = presentation or convenience.

**Gates:** as in `HR-LEGAL-SECURITY-GATE-REGISTER.md`. "L2a" and "L2b" are
the **proposed** split of HRX-L2 (OD-HR-14). Until that split is decided,
both mean HRX-L2.

**Phases:** as in `HR-EXPANSION-ROADMAP.md`.

### D1 — Workforce master

| ID | Requirement | Existing implementation | Status | Evidence | Gap | Owner | Phase | Risk | Gate |
|---|---|---|---|---|---|---|---|---|---|
| D1-01 | Staff identity | `employees` (Employee ≠ User) | IMP | H134, H144 → `Employee` | — | HR | — | — | — |
| D1-02 | Employee / staff code | `employee_number` `EMP-`+6, forward-only counter; immutable in the database (`trg_employees_identity_guard`, HR.1A S-11) | IMP | H060 → `EmployeeNumberAllocator` | — | HR | — | L | — |
| D1-03 | ERP / reference identifiers | `employee_reference_codes` (closed kinds staff_reference / erp / legacy; School-unique, never reused; exact directory search) (HR.2A) | IMP | H117, H134, H233 (Staff Ref, ERP, Branch codes) | — | HR | HR.2A | L | — |
| D1-04 | Biometric identity | none (guards) | GATE | H106, H233, H373 | an attendance identity in StaffAttendance; never on Employee | StaffAttendance | HR.8 | H | HRX-L2 (L2b) |
| D1-05 | Active / inactive state | `record_status` + employment status | IMP | H124, H127 → `EmployeeLifecycleService` | — | HR | — | — | — |
| D1-06 | Joining date | `employment_records.starts_on` | IMP | H117, H323 | — | HR | — | — | — |
| D1-07 | Employment lifecycle | 8 statuses (DB CHECK); `end()` and, since HR.12, the case-driven `active` ⇄ `notice_period` (`trg_employment_records_separation_notice`) ; HR.R1-C2 (gap closure 2026-11-05: the database bounds placements of an ended employment): an employment begins open -- no creation path accepts `ends_on` (service, API, DB), HRC-1 closed | DEF | H322–H325, H483 | S-8 closed (HR.1A); HR.12 adds the notice transitions; probation confirmation (D18-03/04) remains | HR | HR.12 (probation: later) | M | D-1 (HR owner 2026-11-08, HR.R1-C16), was PART; re-review: an owner answer to HR-090, which stays OWNER_DECISION_REQUIRED |
| D1-08 | Campus / School assignment | `employee_assignments.campus_id` (composite FK) | IMP | H174 → `CurrentEmployeeAssignments` | — | HR | — | — | — |
| D1-09 | Department assignment | `employee_assignments.department_id` | IMP | H134 | — | HR | — | — | — |
| D1-10 | Designation | `positions` via assignment | IMP | H134, H191 | — | HR | — | — | — |
| D1-11 | Staff type (Teaching / Non-Teaching / Outsource) | closed `StaffType` (teaching / non_teaching / outsourced) on the employment terms (HR.1A); never derived | IMP | H060, H124, H209 | legacy rows NULL until classified | HR | — | M | — |
| D1-12 | Employment category | `employment_terms` (effective-dated, append-only); closed `EmploymentType` (HR.1A) | IMP | H137, H166, H344 | — | HR | — | M | — |
| D1-13 | Payroll entity | — | NR | H284, H330 | the School is the employer of record (HR-089 DO_NOT_BUILD) | — | — | — | — |
| D1-14 | Profile photograph | none | DEF | H113 (Change Profilepic) | governed media pipeline (rule 115) + photo privacy | HR + Documents | post-HR.16 | M | HR-L13 (not raised) |
| D1-15 | Personal information | `employee_personal_details` (DOB, nationality, marital status, language) | IMP | H093, H144 | gender not modelled (see D10-07) | HR | — | — | — |
| D1-16 | Contact details | work email/phone + personal contacts | IMP | H096 | — | HR | — | — | — |
| D1-17 | Emergency contacts | `employee_emergency_contacts` (archived, never deleted; HR.2A) | IMP | H144 | — | HR | HR.2A | L | — |
| D1-18 | Family information | none | DEF | H098, H182, H247 | third-party personal data | HR | after HR-L6 | H | HR-L6 / OD-HR-12 |
| D1-19 | Address | `employee_addresses` (4 kinds; archived, never deleted; current value + correction history) (HR.2A) | IMP | H109 | — | HR | HR.2A | L | — |
| D1-20 | Bank / payment information | none | DEF | H114, H329 | Payroll-owned profile, encrypted; disbursement is Payments | Payroll | after HR-L5 | H | HR-L5 |
| D1-21 | Qualifications | `employee_qualifications` (+ verification) | IMP | H327 | — | HR | — | — | — |
| D1-22 | Certifications | `employee_certifications` (number, expiry) | IMP | H336 (CTET) | CTET / "trained as" fit as certifications, not new columns | HR | — | — | — |
| D1-23 | Employment history | `employment_records` (rehire = new row) + assignments | IMP | H124, H351 | — | HR | — | — | — |
| D1-24 | Previous experience | `employee_experience_records`; total experience DERIVED (overlaps merged) (HR.2A) | IMP | H328 | last-drawn salary is not collected (pay data stays Payroll) | HR | HR.2A | — | — |
| D1-25 | Statutory identifiers | Payroll `employee_statutory_identifiers` (encrypted, HMAC lookup), no production role | GATE | H241–H245, H329 | general-profile Aadhaar / passport / licence not modelled | Payroll | HR.15 | H | ADR 0036; HR-L7 |
| D1-26 | Staff documents | `employee_documents` → governed Documents uploads, closed categories, tier-authorized downloads (HR.2A) | IMP | H124 (Staff Documents), H246, H353 | — | HR + Documents | HR.2A | M | — |
| D1-27 | Profile change history | activity timeline + audit (changed field names) + archived records as correction history + stale-form tokens (HR.2A) | ALT | H361–H368 (Log), H587 | values are never in audit (privacy); history is in archived rows and versioned evidence | HR + audit | HR.2A | L | — |
| D1-28 | Duplicate check before create | `EmployeeDuplicateDetector` | IMP | H130–H133 | — | HR | — | — | — |
| D1-29 | Bulk staff import | JSON import (≤ 1,000 rows, create-only) | ALT | H157–H173 (CSV) | CSV adapter is OD-HR-9 | HR | HR.2 (if decided) | L | — |
| D1-30 | Bulk field grid editor (all classes, inline) | per-section edit with per-section capability | NR | H221–H248 | a mass editor across sensitive classes would bypass section authorization | — | — | H | — |
| D1-31 | Health, blood group, identification marks | none (guards) | GATE | H099, H108, H247 | — | — | — | H | HRX-L1 |
| D1-32 | Religion, caste, divorcee flag, marriage anniversary | none | NR | H243, H247 | special-category data with no product purpose (D-15 discipline) | — | — | H | — |
| D1-33 | Joining details (recruitment type, advertisement, appointment / regularisation dates) | `starts_on`, `probation_ends_on` | DEF | H117, H322–H325 | recruitment fields wait for OD-HR-6; appointment and confirmation dates go to HR.12 | HR | HR.12 | L | HR-L9 |
| D1-34 | Staff joining security deposit | none | XDOM | H247 | money record (Finance) | Finance | — | M | — |
| D1-35 | Investment declaration (Form 12C), nominees | none (Payroll statutory) | GATE | H332–H335 | — | Payroll | HR.15 | H | ADR 0036, HR-L6 |

### D2 — Effective-dated workforce assignments

| ID | Requirement | Existing implementation | Status | Evidence | Gap | Owner | Phase | Risk | Gate |
|---|---|---|---|---|---|---|---|---|---|
| D2-01 | School / Campus transfer | `PlacementChangeService` transfer: source closed the day before, new primary opened, named record (HR.1A) ; a transfer planned past an employment end never takes effect (HR.R1-C2) | IMP | H174–H178, H300 | — | HR | — | M | — |
| D2-02 | Department transfer | `PlacementChangeService` department change (HR.1A) | IMP | H225 | — | HR | — | M | — |
| D2-03 | Designation change | `PlacementChangeService` designation change (HR.1A) | IMP | H225 (reference edits in place) | — | HR | — | M | — |
| D2-04 | Promotion | `PlacementChangeService` promotion (HR.1A) | IMP | — | — | HR | — | L | — |
| D2-05 | Reporting-manager change | dated, reasoned change through `ReportingRelationshipService::assign()` (HR.3A) | IMP | H481–H482 | — | HR | HR.3A | M | — |
| D2-06 | Employment-category change | dated, reasoned `employment_terms` change (HR.1A) | IMP | H360 | — | HR | — | M | — |
| D2-07 | Shift change | none | GATE | H286 | — | WorkSchedule | HR.4 | M | ADR 0065 §8, HRX-L2 (L2a) |
| D2-08 | Payroll-entity change | — | NR | H174 | see D1-13 | — | — | — | — |
| D2-09 | Temporary assignment | multiple dated, non-primary assignments | IMP | — | — | HR | — | — | — |
| D2-10 | Start / end dates | `starts_on`/`ends_on` + CHECK; primary overlap refused by the database (HR.1A) | IMP | H286 (effective date) | — | HR | — | M | — |
| D2-11 | Reason | closed `PlacementChangeReason` / `EmploymentTermsChangeReason` (HR.1A) | IMP | H351 | — | HR | — | M | — |
| D2-12 | Approving / acting actor | `recorded_by_user_id` on the change record + audit actor (HR.1A) | IMP | — | no approval step (only if HR.12 requires one) | HR | — | L | — |
| D2-13 | Historical reporting (as of a date) | `WorkforceAsOfReader` (employment, terms, primary placement on any date) (HR.1A); manager on any date: `ReportingAsOfReader` (HR.3A) | IMP | H554 | — | HR | — | M | — |

### D3 — Organization ownership

| ID | Requirement | Existing implementation | Status | Evidence | Gap | Owner | Phase | Risk | Gate |
|---|---|---|---|---|---|---|---|---|---|
| D3-01 | Organization / Network | School Group = Network (ADR 0080 §9) | IMP | H021, H032 (branches by state) | HR never crosses Schools | Organization | — | — | — |
| D3-02 | Tenant | School (RLS, `TenantContext`) | IMP | — | — | Platform | — | — | — |
| D3-03 | School | `schools` | IMP | — | — | Organization | — | — | — |
| D3-04 | Campus | `campuses`, `ActiveCampus` | IMP | "Branch" throughout | — | Organization | — | — | — |
| D3-05 | Location (state grouping of branches) | Network Regions / Zones | ALT | H021, H073 | governance, never HR authority (rule 113) | Organization | — | — | — |
| D3-06 | Department | `hr_departments` (HR-owned, hierarchical, optional Campus); reparent cycle-safe under lock + trigger (HR.1A S-9) | IMP | H209–H211 | no department head (deferred) | HR | — | L | — |
| D3-07 | Organisational units, with **no duplicate organization master** | `hr_departments` ≠ `academic_departments` (Subjects, documented) | IMP | — | none found | HR | — | — | — |
| D3-08 | Designation / qualification masters | `positions`; qualifications per employee | ALT | H186–H191 (uncurated masters, inline "Add New") | no inline master creation from the profile form | HR | — | L | — |

### D4 — Reporting hierarchy

| ID | Requirement | Existing implementation | Status | Evidence | Gap | Owner | Phase | Risk | Gate |
|---|---|---|---|---|---|---|---|---|---|
| D4-01 | Designation hierarchy (L0–L3) | none on positions; the hierarchy is assignment → assignment | ALT | H160–H172, H191, H565–H580 | L0–L3 becomes a computed presentation (depth); never level columns | HR | HR.3 | L | — |
| D4-02 | Manager assignment | `ReportingRelationshipService` (dated; HR.3A); `setManager()` kept as the assignment-addressed adapter | IMP | H100, H481 | — | HR | — | — | — |
| D4-03 | Reporting-manager hierarchy of arbitrary depth | dated employment lines, arbitrary depth (HR.3A) | IMP | H478–H480 | — | HR | — | — | — |
| D4-04 | Reporting-manager views | directory "reports to" filter, Leave approvals queue, `hr.reporting_lines` as-of report (HR.14, `ReportingAsOfReader::depthsOn()`) | IMP | H565–H580 | — | HR | HR.14 | L | — |
| D4-05 | Manager changes over time | `reporting_relationships`, answered for any date (`ReportingAsOfReader`, HR.3A) | IMP | H481 | — | HR | HR.3A | M | — |
| D4-06 | Primary manager | one live line per subordinate employment per day, database-enforced (HR.3A) | IMP | — | — | HR | — | — | — |
| D4-07 | Dotted-line manager | none | DEF | — | relationship `kind` (primary / dotted); dotted never decides | HR | HR.3 | L | D-1 (HR owner 2026-11-08, HR.R1-C16), was MISS; re-review: a dotted-line reporting need, as an HR.3A amendment |
| D4-08 | Temporary delegation | none | DEF | — | ADR 0065 §6 amendment (delegation of a decision, not of data) | HR + Leave | HR.3 / HR.11 | H | ADR 0065 §6 |
| D4-09 | School / Campus scope | same-School composite keys; Campus via `HrEmployeeAccess` | IMP | — | — | HR | — | — | — |
| D4-10 | Department scope | directory filter only, never authority | ALT | H478 | — | HR | — | — | — |
| D4-11 | Reporting-chain traversal | `ReportingAsOfReader::chainOn()` / `depthOn()` / `treeOn()` (recursive, depth-capped, HR.3A) | IMP | — | — | HR | HR.3A | M | — |
| D4-12 | Cycle prevention (A→B→C→A impossible) | service check plus date-aware database trigger, two-process tested (HR.3A) | IMP | — | — | HR | HR.3A | H | — |
| D4-13 | Hierarchy ≠ authorization | the manager path also needs `hr.leave.approve` | IMP | — | keep: a manager is never data access | HR | — | H | — |

### D5 — Work calendar and shifts

| ID | Requirement | Existing implementation | Status | Evidence | Gap | Owner | Phase | Risk | Gate |
|---|---|---|---|---|---|---|---|---|---|
| D5-01 | Payroll / HR year | `leave_years` (prospective start month) + payroll periods | IMP | H321, H461 | — | Leave / Payroll | — | — | — |
| D5-02 | Work calendars | `staff_working_weekdays` (School-wide) | DEF | H285 | one calendar per School only | WorkSchedule (proposed) | HR.4 | M | D-1 (HR owner 2026-11-08, HR.R1-C16), was PART; re-review: calendar applicability, after D-6 |
| D5-03 | Branch / Campus calendars | none | DEF | H462 (branch-wise) | Campus calendar with School fallback | WorkSchedule | HR.4 | M | D-1 (HR owner 2026-11-08, HR.R1-C16), was MISS; re-review: calendar applicability, after D-6 |
| D5-04 | Holidays | `staff_holidays` | IMP | H462 | see D14 | Leave → WorkSchedule | — | — | — |
| D5-05 | Optional holidays | none | DEF | H462 | flag + per-employee choice | WorkSchedule | HR.4 | L | D-1 (HR owner 2026-11-08, HR.R1-C16), was MISS; re-review: calendar applicability, after D-6 |
| D5-06 | Week-offs | weekday pattern (full / first half / off) | DEF | H289–H292 | nth-week-of-month patterns (2nd/4th Saturday) | WorkSchedule | HR.4 | L | D-1 (HR owner 2026-11-08, HR.R1-C16), was PART; re-review: calendar applicability, after D-6 |
| D5-07 | Shift definitions | none | GATE | H285, H293, H296 | — | WorkSchedule | HR.4 | M | ADR 0065 §8, L2a |
| D5-08 | Shift start / end | none | GATE | H287, H301 | — | WorkSchedule | HR.4 | M | L2a |
| D5-09 | Cross-midnight shifts | none | GATE | — | — | WorkSchedule | HR.4 | M | L2a |
| D5-10 | Breaks / sessions | none (half-day halves only) | GATE | H290 | — | WorkSchedule | HR.4 | M | L2a |
| D5-11 | Grace periods | none | GATE | H301 | — | WorkSchedule | HR.4 | M | L2a |
| D5-12 | Late thresholds | none | GATE | H049, H301 | — | WorkSchedule | HR.4 | M | L2a |
| D5-13 | Early-leaving thresholds | none | GATE | H301 | — | WorkSchedule | HR.4 | M | L2a |
| D5-14 | Minimum working duration (work-hours shift) | none | GATE | H294–H295, H302 | — | WorkSchedule | HR.4 | M | L2a |
| D5-15 | Half-day thresholds | none | GATE | H295 | — | WorkSchedule | HR.4 | M | L2a |
| D5-16 | Shift assignment | none | GATE | H297 | — | WorkSchedule | HR.4 | M | L2a |
| D5-17 | Bulk shift assignment | none | GATE | H286 | — | WorkSchedule | HR.4 | L | L2a |
| D5-18 | Temporary shift change | none | GATE | H286 | — | WorkSchedule | HR.4 | M | L2a |
| D5-19 | Effective dates on shifts | none | GATE | H286 | — | WorkSchedule | HR.4 | M | L2a |
| D5-20 | Historical shift lookup | none | GATE | — | — | WorkSchedule | HR.4 | M | L2a |

### D6 — Attendance input and event platform

| ID | Requirement | Existing implementation | Status | Evidence | Gap | Owner | Phase | Risk | Gate |
|---|---|---|---|---|---|---|---|---|---|
| D6-01 | Biometric device source | none (guards) | GATE | H399, H407 | — | StaffAttendance | HR.8 | H | L2b |
| D6-02 | Manual HR attendance | half-day register (result-level, ADR 0065 §7) | ALT | H281–H284 | no event layer, by contract | StaffAttendance | — | — | — |
| D6-03 | Facial recognition | none | GATE | H521 | — | StaffAttendance | HR.8 | H | L2b |
| D6-04 | GPS / mobile self check-in | none | GATE | H545 | — | StaffAttendance | HR.8 | H | L2b |
| D6-05 | Provider API / punch import | none | GATE | H413 (CSV punch upload) | — | StaffAttendance | HR.8 | H | L2b |
| D6-06 | Authorized correction | append-only correction + compare-and-swap | IMP | H414–H421 | — | StaffAttendance | — | — | — |
| D6-07 | Raw evidence preserved (source → normalized → evaluated) | none | GATE | H408 (raw log) | — | StaffAttendance | HR.5 | H | L2a/L2b |
| D6-08 | Provider event id | none | GATE | — | — | StaffAttendance | HR.5 | M | L2b |
| D6-09 | Idempotent ingestion | none | GATE | H407 (re-run job) | — | StaffAttendance | HR.5 | H | L2b |
| D6-10 | Duplicate punches | none | GATE | H408 | — | StaffAttendance | HR.5 | M | L2b |
| D6-11 | Event timestamp | none | GATE | — | — | StaffAttendance | HR.5 | M | L2a |
| D6-12 | Timezone handling | School timezone exists (`SchoolTimezone`) | GATE | — | — | StaffAttendance | HR.5 | M | L2a |
| D6-13 | Campus on event | none | GATE | H408 (Is Roaming) | — | StaffAttendance | HR.5 | M | L2b |
| D6-14 | Device on event | none | GATE | H407 | — | StaffAttendance | HR.5 | M | L2b |
| D6-15 | Source on event | none | GATE | — | — | StaffAttendance | HR.5 | M | L2a |
| D6-16 | First-in | none | GATE | H051 | — | StaffAttendance | HR.5 | M | L2a |
| D6-17 | Last-out | none | GATE | H051 | — | StaffAttendance | HR.5 | M | L2a |
| D6-18 | Multiple punches | none | GATE | H397 (movement register) | — | StaffAttendance | HR.5 | M | L2a |
| D6-19 | Cross-midnight events | none | GATE | — | — | StaffAttendance | HR.5 | M | L2a |
| D6-20 | Late-arriving imports | none | GATE | H407 | — | StaffAttendance | HR.5 | M | L2b |
| D6-21 | Missing punches | none | GATE | H399 (No Punch) | — | StaffAttendance | HR.5 | M | L2a |
| D6-22 | Corrections never mutate source evidence | corrections are separate append-only rows | IMP | — | keep for events | StaffAttendance | — | H | — |

### D7 — Attendance evaluation

| ID | Requirement | Existing implementation | Status | Evidence | Gap | Owner | Phase | Risk | Gate |
|---|---|---|---|---|---|---|---|---|---|
| D7-01 | Present | half status `present` | IMP | H307 | — | StaffAttendance | — | — | — |
| D7-02 | Absent | half status `absent` | IMP | H309 | — | StaffAttendance | — | — | — |
| D7-03 | Leave | derived from Leave (read model) | IMP | H307 | — | StaffAttendance + Leave | — | — | — |
| D7-04 | Week off | derived from the calendar | IMP | H307 | — | StaffAttendance | — | — | — |
| D7-05 | Holiday | derived from the calendar | IMP | — | — | StaffAttendance | — | — | — |
| D7-06 | Half day | two halves | IMP | H050 | — | StaffAttendance | — | — | — |
| D7-07 | Late | none | GATE | H049, H399 | — | StaffAttendance | HR.6 | M | L2a |
| D7-08 | Early leaving | none | GATE | H050, H399 | — | StaffAttendance | HR.6 | M | L2a |
| D7-09 | No punch | none | GATE | H399 | — | StaffAttendance | HR.6 | M | L2b |
| D7-10 | Incomplete punch | none | GATE | H399 | — | StaffAttendance | HR.6 | M | L2a |
| D7-11 | Working duration | none | GATE | H051, H056 | — | StaffAttendance | HR.6 | M | L2a |
| D7-12 | Permitted late arrival | none | GATE | H399 | — | StaffAttendance | HR.6 | M | L2a |
| D7-13 | Permitted early departure | none | GATE | H380 | — | StaffAttendance | HR.6 | M | L2a |
| D7-14 | Attendance exceptions | write-time conflict refusal (leave × presence, both ways) | ALT | H414–H420 (queues) | an exception queue only when evaluation exists | StaffAttendance | HR.7 | L | — |
| D7-15 | LOP candidates | `PayrollAbsenceEvidenceReader` → `payroll_run_hrx_inputs` (evidence only) | PART | H380–H391 | late → LOP and week-off LOP rules | StaffAttendance + Payroll | HR.15 | H | HRX-L4 |
| D7-16 | Deterministic historical re-evaluation | records + corrections; payroll fingerprint difference | ALT | — | an evaluation version once evaluation exists | StaffAttendance | HR.6 | M | — |

### D8 — Attendance operations

| ID | Requirement | Existing implementation | Status | Evidence | Gap | Owner | Phase | Risk | Gate |
|---|---|---|---|---|---|---|---|---|---|
| D8-01 | Manual marking | daily register (`hr.staff_attendance.manage`) | IMP | H281 | register is whole-School (no Campus or department filter) | StaffAttendance | HR.7 | L | — |
| D8-02 | Attendance correction | `StaffAttendanceService` correct (CAS + row) | IMP | H414 | — | StaffAttendance | — | — | — |
| D8-03 | Regularization request | none | DEF | H425 | self-service request (HR-011 owner decision) | StaffAttendance | HR.7 | M | D-1 (HR owner 2026-11-08, HR.R1-C16), was MISS; re-review: an owner answer to HR-011, which stays OWNER_DECISION_REQUIRED |
| D8-04 | Regularization approval | none | DEF | H425 | decision on the shared approval foundation (rule 102) | StaffAttendance | HR.7 | M | D-1 (HR owner 2026-11-08, HR.R1-C16), was MISS; re-review: an owner answer to HR-011, which stays OWNER_DECISION_REQUIRED |
| D8-05 | HR correction | as D8-02 | IMP | H414–H421 | — | StaffAttendance | — | — | — |
| D8-06 | Manager correction | none (manager recording is out of v1, ADR 0065 §7) | DEF | — | ADR 0065 reopening | StaffAttendance | HR.7 | M | ADR 0065 §7 |
| D8-07 | Reason | closed reason codes | IMP | H421 | — | StaffAttendance | — | — | — |
| D8-08 | Evidence / attachment | none | GATE | — | — | — | — | H | HRX-L1 (ADR 0065 §10) |
| D8-09 | Audit history | correction rows + audit | IMP | H422–H423 | — | StaffAttendance | — | — | — |
| D8-10 | Locking / finalization | HR.R1-C18: a School-local month is locked by `hr.staff_attendance.lock` with fresh MFA and never reopened; a locked month changes only by a two-person, fresh-MFA correction the database applies (S-6) | ALT | H384–H391 (Confirm) | no reopening, by owner decision S6-1 (HR.R1-C17); Payroll may still calculate an unlocked month (open decision) | StaffAttendance + Payroll | HR.7a | H | — |
| D8-11 | Payroll handoff | `payroll_run_hrx_inputs` (frozen at approval) | IMP | H380 | — | Payroll | — | — | — |
| D8-12 | Recalculation after correction | recalculation refreshes; after approval it surfaces as `source_changed_after_approval` | ALT | — | — | Payroll | — | — | — |
| D8-13 | Prevention of unauthorized historical edits | HR.R1-C18: future refused; the month lock is the window; a locked month changes only with another lock-key holder's fresh-MFA approval; open-month corrections re-read authority in the transaction (S6-3, S6-4) | IMP | H589 | — | StaffAttendance | HR.7a | H | — |
| D8-14 | Work-from-home assignment and marking | none | DEF | H303–H306, H371 | owner decision HR-004 | StaffAttendance | HR.7 | L | — |
| D8-15 | Modified-attendance report | `hr.attendance_corrections` (HR.14, D20-33) | IMP | H422–H423 | — | StaffAttendance | HR.14 | L | — |
| D8-16 | Free-text attendance remarks / staff reason | none (closed codes) | GATE | H419–H421 | — | — | — | H | HRX-L1 |

### D9 — Biometric attendance

| ID | Requirement | Existing implementation | Status | Evidence | Gap | Owner | Phase | Risk | Gate |
|---|---|---|---|---|---|---|---|---|---|
| D9-01 | Biometric code mapping | none | GATE | H373–H379 | — | StaffAttendance | HR.8 | H | L2b |
| D9-02 | Biometric identity uniqueness | none | GATE | H379 (errors) | — | StaffAttendance | HR.8 | H | L2b |
| D9-03 | Staff / device relationship | none | GATE | H174 (DeviceID) | — | StaffAttendance | HR.8 | M | L2b |
| D9-04 | Device / provider registry | none; Integration Hub lists biometric as unavailable | GATE | H407 | — | StaffAttendance | HR.8 | H | L2b |
| D9-05 | Imports | none | GATE | H413 | — | StaffAttendance | HR.8 | H | L2b |
| D9-06 | Import audit | none | GATE | H407 | — | StaffAttendance | HR.8 | M | L2b |
| D9-07 | First-in | none | GATE | H401 | — | StaffAttendance | HR.8 | M | L2b |
| D9-08 | Last-out | none | GATE | H401 | — | StaffAttendance | HR.8 | M | L2b |
| D9-09 | Working hours | none | GATE | H401 | — | StaffAttendance | HR.8 | M | L2b |
| D9-10 | Late classification | none | GATE | H392–H397 | — | StaffAttendance | HR.8 | M | L2b |
| D9-11 | Early leaving | none | GATE | H392–H397 | — | StaffAttendance | HR.8 | M | L2b |
| D9-12 | Permitted late / early flags | none | GATE | H369, H399 | — | StaffAttendance | HR.8 | M | L2b |
| D9-13 | Branch-wise daily report | none | GATE | H599 (menu) | — | StaffAttendance | HR.8 | L | L2b |
| D9-14 | Department filtering (biometric views) | none (the register has no department filter) | GATE | H281 | — | StaffAttendance | HR.8 | L | L2b |
| D9-15 | Absentee reports | `hr.attendance_absentees` (HR.14): recorded absent halves not covered by approved leave, by date range and historical Department, paged in SQL (no cap) | IMP | H406 | — | StaffAttendance | HR.14 | L | — |
| D9-16 | Leave reports (attendance view) | Leave requests and monthly reports | ALT | H402–H404 | — | Leave | — | — | — |
| D9-17 | Export | none | GATE | H308 (Export xls) | — | — | HR.14 | H | D-11 |

### D10 — Leave policy foundation

| ID | Requirement | Existing implementation | Status | Evidence | Gap | Owner | Phase | Risk | Gate |
|---|---|---|---|---|---|---|---|---|---|
| D10-01 | Leave type | `leave_types` (code, paid, tracks balance, half day, status) | IMP | H430–H433 | — | Leave | — | — | — |
| D10-02 | Leave category (grouping + quota) | policy per type, assigned per employment | ALT | H439–H442 | — | Leave | — | — | — |
| D10-03 | Category / department eligibility | eligibility rules on the policy version (staff type, Employee Category, primary Department; NULL = any), never ambiguous between active versions (DB); resolved from HR.1A facts on a date; additive idempotent run → policy assignments with provenance (HR.9) | IMP | H443–H448 | — | Leave | HR.9 | M | — |
| D10-04 | Paid / unpaid | `is_paid` | IMP | H430 | — | Leave | — | — | — |
| D10-05 | Month-on-month carry-forward | monthly accrual (HR.10): each month's credit stays in the year's balance (no monthly lapse) until consumed or the year close | IMP | H430 | — | Leave | HR.10 | M | — |
| D10-06 | Year-on-year carry-forward | year close with cap | IMP | H430 | — | Leave | — | — | — |
| D10-07 | Gender eligibility | none; gender not modelled | DEF | H434 | sensitive-demographic decision first | Leave | after HR-L8 | H | HR-L8, HRX-L3 |
| D10-08 | Marital-status eligibility | none | DEF | H434 | as D10-07 | Leave | after HR-L8 | H | HR-L8 |
| D10-09 | Advance-planning (notice) requirement | `min_notice_days` (calendar days) on the policy version, enforced at submission on every path (HR.9) | IMP | H435 | — | Leave | HR.9 | L | — |
| D10-10 | Backdated application window | `max_backdate_days` (calendar days; 0 = never) on the policy version; never relaxes a closed year, recorded presence or the Payroll capture (HR.9) | IMP | H435 | — | Leave | HR.9 | M | — |
| D10-11 | Maximum duration per request | `max_units_per_request` (chargeable half-day units) on the policy version (HR.9); the 366-day span CHECK stays | IMP | H435 | — | Leave | HR.9 | L | — |
| D10-12 | Mandatory attachment | none (no attachment exists) | GATE | H436 | — | — | — | H | HRX-L1 (ADR 0065 §10) |
| D10-13 | Sandwich policy | none (holidays never count; ADR 0065 §4.7) | DEF | H437 | changes the §4.7 counting contract (frozen days, balances, Payroll HRX evidence): owner decision first, then an ADR 0065 amendment; no dormant toggle | Leave | after OD-HR-15 | M | OD-HR-15 |
| D10-14 | Prefix holiday / week-off policy | none | DEF | H437 | as D10-13 | Leave | after OD-HR-15 | M | OD-HR-15 |
| D10-15 | Suffix holiday / week-off policy | none | DEF | H437 | as D10-13 | Leave | after OD-HR-15 | M | OD-HR-15 |
| D10-16 | Holiday / week-off inclusion | fixed exclusion (working time only) | PART | H437 | configurable inclusion is part of OD-HR-15 | Leave | after OD-HR-15 | M | OD-HR-15 |
| D10-17 | Academic / payroll year | leave years | IMP | H461 | — | Leave | — | — | — |
| D10-18 | Start month | prospective start month | IMP | H441 | — | Leave | — | — | — |
| D10-19 | Joining-date behaviour | monthly accrual credits from the first WHOLE month of employment; a joining month is listed (`partial_period`), never prorated; annual: explicit allocation of exact units (ADR 0065 §22.2) (HR.10) | PART | H466–H471 | prorated first period needs a §22.2 amendment | Leave | after OD-HR-17 | M | OD-HR-17 |
| D10-20 | Staff-category eligibility | eligibility rule on `employee_category_id` (HR.1A terms effective on the date) and the closed staff type (HR.9) | IMP | H443 | — | Leave | HR.9 | M | — |
| D10-21 | Policies versioned / effective-dated | `supersedes_policy_id` + `leave_policy_assignments` (effective from/to) + frozen request days; HR.9 rule columns and eligibility rules live on the (immutable) version | IMP | — | — | Leave | — | H | — |
| D10-22 | Statutory leave types (maternity, …) | School policy only | GATE | H433 | — | Leave | — | H | HRX-L3 |
| D10-23 | Predefined leave remarks | closed reason codes | ALT | H455 | free text stays refused | Leave | — | — | HRX-L1 |
| D10-24 | Absence → LOP adjustment from paid balance | none | GATE | H430 | — | Leave + Payroll | — | H | HRX-L4 |

### D11 — Leave ledger and balance

| ID | Requirement | Existing implementation | Status | Evidence | Gap | Owner | Phase | Risk | Gate |
|---|---|---|---|---|---|---|---|---|---|
| D11-01 | Opening migration entry | ledger kind `opening`: exact units, once per account, only as its first entry (DB), fresh MFA, never one's own (HR.10) | IMP | — | — | Leave | HR.10 | M | — |
| D11-02 | Entitlement | `allocation` | IMP | H449 | — | Leave | — | — | — |
| D11-03 | Monthly accrual | ledger kind `accrual` with `accrual_period` (unique per account x month); DB-checked against the whole-month monthly assignment (HR.10) | IMP | H466 | — | Leave | HR.10 | M | — |
| D11-04 | Annual allocation | allocation run (once per year × type) | IMP | — | — | Leave | — | — | — |
| D11-05 | Carry-forward | `carry_forward_in` / `out` | IMP | — | — | Leave | — | — | — |
| D11-06 | Carry-forward expiry | `carried_expires_on` recorded, not executed | PART | — | executing it needs a consumption order (carried-first / current-first / pro rata) | Leave | after OD-HR-16 | M | OD-HR-16 |
| D11-07 | Approved leave debit | `consumption` (unique per request × year) | IMP | H449 | — | Leave | — | — | — |
| D11-08 | Cancellation reversal | `reversal` | IMP | — | — | Leave | — | — | — |
| D11-09 | Comp-off credit | none | DEF | H449 (Compensatory row) | no authoritative worked-day evidence (Staff Attendance refuses non-working halves); expiry also OD-HR-16 | Leave | after a Staff Attendance amendment | M | OD-HR-18 (comp-off blocked, owner 2026-11-04) |
| D11-10 | Comp-off expiry | none | DEF | — | no authoritative worked-day evidence (Staff Attendance refuses non-working halves); expiry also OD-HR-16 | Leave | after a Staff Attendance amendment | M | OD-HR-18 (comp-off blocked, owner 2026-11-04) |
| D11-11 | Attendance deduction (late / early → leave) | none | GATE | H449, H563 | — | Leave + StaffAttendance | — | H | L2a + HRX-L4 |
| D11-12 | LOP | none in the ledger | GATE | H449 | — | Payroll | — | H | HRX-L4 |
| D11-13 | Manual adjustment | `adjustment` (closed reasons) + fresh MFA on web and API (S-7) + optional link to the corrected entry of the same account (HR.10) | IMP | — | — | Leave | HR.10 | M | — |
| D11-14 | Correction / reversal | reversal + adjustments; ledger append-only | IMP | — | — | Leave | — | — | — |
| D11-15 | Balance derived from entries, never a column | computed; never negative (trigger) | IMP | — | — | Leave | — | — | — |
| D11-16 | Reproducible statement (opening + credits − debits ± adjustments = closing) | `LeaveStatementReader::statement()`: every entry, running balance, provenance, the identity checked (HR.10) | IMP | H449–H451 | — | Leave | HR.10 | M | — |

### D12 — Leave allocation

| ID | Requirement | Existing implementation | Status | Evidence | Gap | Owner | Phase | Risk | Gate |
|---|---|---|---|---|---|---|---|---|---|
| D12-01 | Monthly allocation | monthly accrual runs: preview, recoverable batches, complete roster, reconciled disjoint outcomes; monthly grid (HR.10) | IMP | H466–H471 | — | Leave | HR.10 | M | — |
| D12-02 | Annual allocation | `executeRun()` | IMP | — | — | Leave | — | — | — |
| D12-03 | Entitlement from joining date | monthly accrual from the first whole month (D10-19); no proration | PART | H466 | prorated first month (D12-08) | Leave | after OD-HR-17 | M | OD-HR-17 |
| D12-04 | Department / category eligibility | eligibility rules + the additive run (D10-03) (HR.9) | IMP | H443–H448 | — | Leave | HR.9 | M | — |
| D12-05 | Assigned / unassigned staff | Leave eligibility page / API: every employment's closed outcome on a date (assigned with drift, eligible, ambiguous, no matching rule, not eligible) (HR.9) | IMP | H467 | — | Leave | HR.9 | L | — |
| D12-06 | Bulk allocation | allocation run | IMP | — | — | Leave | — | — | — |
| D12-07 | Carry-forward | year close | IMP | — | — | Leave | — | — | — |
| D12-08 | Prorating | none: ADR 0065 §22.2 (final owner decision, no automatic proration); a part month is reported `partial_period` | DEF | H466 | a §22.2 amendment with a half-day rounding rule | Leave | after OD-HR-17 | M | OD-HR-17 |
| D12-09 | Leave year | leave years | IMP | H461 | — | Leave | — | — | — |
| D12-10 | Effective policy version | the run uses the assignment's policy | IMP | — | — | Leave | — | — | — |
| D12-11 | Idempotent runs (never double-credit) | `leave_allocation_runs_once` (School, year, type); accrual: one per account x month (unique) + one open run per month + resumable cursor (HR.10) | IMP | — | — | Leave | — | H | — |

### D13 — Leave workflow

| ID | Requirement | Existing implementation | Status | Evidence | Gap | Owner | Phase | Risk | Gate |
|---|---|---|---|---|---|---|---|---|---|
| D13-01 | Application | self-service + on behalf | IMP | H472 | — | Leave | — | — | — |
| D13-02 | Draft | none (submit directly) | NR | — | no product need shown | — | — | — | — |
| D13-03 | Pending | `submitted` | IMP | H472 | — | Leave | — | — | — |
| D13-04 | Approved | `approved` | IMP | H476 | — | Leave | — | — | — |
| D13-05 | Rejected | `rejected` (closed reasons) | IMP | H476 | — | Leave | — | — | — |
| D13-06 | Cancelled / withdrawn | `withdrawn` | IMP | H591 | — | Leave | — | — | — |
| D13-07 | Cancellation after approval | `approved → cancelled` (trigger) with reversal | IMP | H591 | — | Leave | — | — | — |
| D13-08 | Reason | closed `reason_code` | ALT | H591 (free text) | free text refused | Leave | — | — | HRX-L1 |
| D13-09 | Employee remarks | none | GATE | H591 | — | — | — | H | HRX-L1 |
| D13-10 | Manager remarks | none | GATE | H476 | — | — | — | H | HRX-L1 |
| D13-11 | HR remarks | none | GATE | — | — | — | — | H | HRX-L1 |
| D13-12 | Attachments | none | GATE | — | — | — | — | H | HRX-L1 |
| D13-13 | Approval timestamp | `leave_decisions` row time | IMP | H476 | — | Leave | — | — | — |
| D13-14 | Approving / rejecting actor | `decider_employee_id` + audit | IMP | H476 | — | Leave | — | — | — |
| D13-15 | Delegated approval | none | DEF | — | — | Leave | after a §6 amendment | H | ADR 0065 §6 (kept, owner 2026-11-04) |
| D13-16 | Escalation | none | DEF | — | — | Leave | after a §6 amendment | M | ADR 0065 §6 (kept, owner 2026-11-04) |
| D13-17 | Multi-level recommend → decide | none | DEF | H473–H475 | — | Leave | after a §6 amendment | M | ADR 0065 §6 (kept, owner 2026-11-04) |
| D13-18 | Audit | every transition | IMP | — | — | Leave | — | — | — |
| D13-19 | Approval authority explicitly resolved | `hr.leave.approve` + current reporting line, or `hr.leave.manage`; self refused three ways | IMP | — | — | Leave | — | H | — |
| D13-20 | Line managers can approve in practice | the `line_manager` add-on role holds exactly `hr.leave.approve`; still needs the current HR.3A line on the decision date, never one's own, whole-School grant only; paged inbox with filters and decision history (HR.11, OD-HR-5 = Option 2) | IMP | H472 | — | Leave + IAM | HR.11 | M | — |

### D14 — Holidays

| ID | Requirement | Existing implementation | Status | Evidence | Gap | Owner | Phase | Risk | Gate |
|---|---|---|---|---|---|---|---|---|---|
| D14-01 | Year / calendar | dated holidays (independent of year) | IMP | H462 | — | Leave | — | — | — |
| D14-02 | School / Campus | School only | DEF | H463 | Campus applicability | WorkSchedule | HR.4 | M | D-1 (HR owner 2026-11-08, HR.R1-C16), was PART; re-review: calendar applicability, after D-6 |
| D14-03 | Leave-category applicability | none | DEF | H462 | applicability by staff group | WorkSchedule | HR.4 | L | D-1 (HR owner 2026-11-08, HR.R1-C16), was MISS; re-review: calendar applicability, after D-6 |
| D14-04 | Department applicability | none | DEF | H462 | as D14-03 | WorkSchedule | HR.4 | L | D-1 (HR owner 2026-11-08, HR.R1-C16), was MISS; re-review: calendar applicability, after D-6 |
| D14-05 | From / to date | one row per date (a range = several rows) | ALT | H462 | range entry in the UI | WorkSchedule | HR.4 | L | — |
| D14-06 | Description | `name` | IMP | H462 | — | Leave | — | — | — |
| D14-07 | Optional holiday | none | DEF | H462 | D5-05 | WorkSchedule | HR.4 | L | D-1 (HR owner 2026-11-08, HR.R1-C16), was MISS; re-review: calendar applicability, after D-6 |
| D14-08 | Effective publication | immediate | MISS | — | draft → published, with recalculation notice | WorkSchedule | HR.4 | M | — |
| D14-09 | Modifications | remove + add (hard delete, S-16) | PART | — | versioned change, never delete | WorkSchedule | HR.4 | M | — |
| D14-10 | Attendance impact | derived at read time; conflicting writes refused | IMP | — | — | StaffAttendance | — | — | — |
| D14-11 | Leave sandwich impact | none | DEF | — | D10-13 (OD-HR-15) | Leave | after OD-HR-15 | M | OD-HR-15 |
| D14-12 | No silent retroactive change | request days frozen; payroll fingerprint differences | ALT | — | controlled recalculation once evaluation exists | Leave | HR.4 | M | — |

### D15 — Compensatory off

| ID | Requirement | Existing implementation | Status | Evidence | Gap | Owner | Phase | Risk | Gate |
|---|---|---|---|---|---|---|---|---|---|
| D15-01 | Worked holiday / week-off date | none | DEF | H488 | request with a non-working-day check | Leave | after a Staff Attendance amendment | M | OD-HR-18 (comp-off blocked, owner 2026-11-04) |
| D15-02 | Employee | — | DEF | H488 | ActingEmployee / on behalf | Leave | after a Staff Attendance amendment | L | OD-HR-18 (comp-off blocked, owner 2026-11-04) |
| D15-03 | Evidence | none | GATE | — | no attachment | — | — | H | HRX-L1 |
| D15-04 | Remarks | none | GATE | H488 | closed reason only | — | — | M | HRX-L1 (ADR 0065 §10 principle) |
| D15-05 | Requested by | — | DEF | H489 | — | Leave | after a Staff Attendance amendment | L | OD-HR-18 (comp-off blocked, owner 2026-11-04) |
| D15-06 | Approved / rejected / cancelled lifecycle | none | DEF | H489–H492 | shared approval foundation | Leave | after a Staff Attendance amendment | M | OD-HR-18 (comp-off blocked, owner 2026-11-04) |
| D15-07 | Manual HR credit | ledger adjustment can credit | ALT | H488 (bulk admin credit) | becomes a typed `comp_off_credit` | Leave | HR.11 | M | — |
| D15-08 | Credit amount | — | DEF | — | units (half-days) | Leave | after a Staff Attendance amendment | L | OD-HR-18 (comp-off blocked, owner 2026-11-04) |
| D15-09 | Expiry | none | DEF | — | expiry entry | Leave | after a Staff Attendance amendment | M | OD-HR-18 (comp-off blocked, owner 2026-11-04) |
| D15-10 | Leave-ledger posting | — | DEF | — | D11-09 | Leave | after a Staff Attendance amendment | H | OD-HR-18 (comp-off blocked, owner 2026-11-04) |
| D15-11 | Reversal | — | DEF | — | reversal entry | Leave | after a Staff Attendance amendment | M | OD-HR-18 (comp-off blocked, owner 2026-11-04) |

### D16 — Staff permissions (short duration)

| ID | Requirement | Existing implementation | Status | Evidence | Gap | Owner | Phase | Risk | Gate |
|---|---|---|---|---|---|---|---|---|---|
| D16-01 | Employee | none | DEF | H497 | request entity | Leave | after OD-HR-14 / a permissions contract | L | OD-HR-19 (permissions blocked, owner 2026-11-04) |
| D16-02 | Date | none | DEF | H497 | — | Leave | after OD-HR-14 / a permissions contract | L | OD-HR-19 (permissions blocked, owner 2026-11-04) |
| D16-03 | Out time | none | GATE | H497 | — | Leave | HR.11 | M | ADR 0065 §8, L2a |
| D16-04 | Expected return | none | GATE | H497 | — | Leave | HR.11 | M | L2a |
| D16-05 | Purpose | none | DEF | H497 | closed purpose code | Leave | after OD-HR-14 / a permissions contract | L | OD-HR-19 (permissions blocked, owner 2026-11-04) |
| D16-06 | Reason | none | GATE | H497 | free text refused | — | — | M | HRX-L1 |
| D16-07 | Request timestamp | — | DEF | H497 | — | Leave | after OD-HR-14 / a permissions contract | L | OD-HR-19 (permissions blocked, owner 2026-11-04) |
| D16-08 | Pending / approved / rejected | none | DEF | H498 | shared approval foundation | Leave | after OD-HR-14 / a permissions contract | M | OD-HR-19 (permissions blocked, owner 2026-11-04) |
| D16-09 | Manager | — | DEF | H498 | reporting line at decision | Leave | after OD-HR-14 / a permissions contract | M | OD-HR-19 (permissions blocked, owner 2026-11-04) |
| D16-10 | Audit | — | DEF | H498 | — | Leave | after OD-HR-14 / a permissions contract | L | OD-HR-19 (permissions blocked, owner 2026-11-04) |
| D16-11 | Attendance impact | none | GATE | — | needs evaluation (D7) | StaffAttendance | HR.11 | M | L2a |

### D17 — Special leave windows

| ID | Requirement | Existing implementation | Status | Evidence | Gap | Owner | Phase | Risk | Gate |
|---|---|---|---|---|---|---|---|---|---|
| D17-01 | Window name | none | DEF | H493 | — | Leave | after a precedence + deduction decision | L | OD-HR-20 (special windows blocked, owner 2026-11-04) |
| D17-02 | School / Campus | none | DEF | H493 | — | Leave | after a precedence + deduction decision | L | OD-HR-20 (special windows blocked, owner 2026-11-04) |
| D17-03 | Date range | none | DEF | H494 | — | Leave | after a precedence + deduction decision | L | OD-HR-20 (special windows blocked, owner 2026-11-04) |
| D17-04 | Applicable departments | none | DEF | H494 | — | Leave | after a precedence + deduction decision | L | OD-HR-20 (special windows blocked, owner 2026-11-04) |
| D17-05 | Leave types | none | DEF | H493 | — | Leave | after a precedence + deduction decision | L | OD-HR-20 (special windows blocked, owner 2026-11-04) |
| D17-06 | Deduction rules (0.5 / 1 per day) | none | DEF | H493 | rule on the window version | Leave | after a precedence + deduction decision | M | OD-HR-20 (special windows blocked, owner 2026-11-04) |
| D17-07 | Remarks | none | DEF | H494 | administrative note (not about a person) | Leave | after a precedence + deduction decision | L | OD-HR-20 (special windows blocked, owner 2026-11-04) |
| D17-08 | Multiple windows | none | DEF | H495 | — | Leave | after a precedence + deduction decision | L | OD-HR-20 (special windows blocked, owner 2026-11-04) |
| D17-09 | Overlapping windows | none | DEF | — | — | Leave | after a precedence + deduction decision | M | OD-HR-20 (special windows blocked, owner 2026-11-04) |
| D17-10 | Priority / conflict resolution | none | DEF | — | deterministic: most specific wins, ties refused at write | Leave | after a precedence + deduction decision | M | OD-HR-20 (special windows blocked, owner 2026-11-04) |

### D18 — Staff lifecycle

| ID | Requirement | Existing implementation | Status | Evidence | Gap | Owner | Phase | Risk | Gate |
|---|---|---|---|---|---|---|---|---|---|
| D18-01 | Onboarding | `EmployeeOnboardingService` (atomic, Campus-aware) | IMP | H130–H145 | — | HR | — | — | — |
| D18-02 | Joining | employment `starts_on` | IMP | H117 | — | HR | — | — | — |
| D18-03 | Probation | `probation_ends_on` | DEF | H175, H322 | no probation state or confirmation event (HR-090) | HR | HR.12 | L | D-1 (HR owner 2026-11-08, HR.R1-C16), was PART; re-review: an owner answer to HR-090, which stays OWNER_DECISION_REQUIRED |
| D18-04 | Confirmation | none | DEF | H117 | lifecycle event `confirmed` | HR | HR.12 | L | D-1 (HR owner 2026-11-08, HR.R1-C16), was MISS; re-review: an owner answer to HR-090, which stays OWNER_DECISION_REQUIRED |
| D18-05 | Transfer | named transfer (HR.1A) | IMP | H174–H178 | — | HR | — | M | — |
| D18-06 | Promotion | named promotion (HR.1A) | IMP | — | — | HR | — | L | — |
| D18-07 | Designation change | named designation change (HR.1A) | IMP | H225 | — | HR | — | M | — |
| D18-08 | Department change | named department change (HR.1A) | IMP | — | — | HR | — | M | — |
| D18-09 | Inactive status | `separated` / `terminated` / `retired` / `deceased` + archive | IMP | H127, H351 | — | HR | — | — | — |
| D18-10 | Resignation | separation case with closed `SeparationReason` (HR.12) | IMP | H483 | — | HR | — | M | — |
| D18-11 | Resignation request | `employment_separation_cases` recorded by HR (`SeparationCaseService::record`, HR.12); no employee self-service (not authorized) | IMP | H483–H487 | self-service would need an OD-HR-4 decision | HR | — | M | — |
| D18-12 | Resignation reason | closed `SeparationReason`: resignation, retirement, contract end, mutual agreement (HR.12) | IMP | H351 | no dismissal (OD-HR-8) or health (HRX-L1) reason | HR | — | M | HRX-L1 for health |
| D18-13 | Notice / served date | `notified_on`, planned / final last working day; approved case → `notice_period` (HR.12) | IMP | H483 | no notice-length calculation (no approved contract) | HR | — | L | — |
| D18-14 | Relieving date | `ends_on` (the ACTUAL last day; written only by `end()` / an approved separation, never at creation, HR.R1-C2) | IMP | H483 | — | HR | — | — | — |
| D18-15 | Department-wise clearance | School-configured administrative items (closed responsibility, snapshot at approval) + owners' read-only answers (Leave, Timetable, Transport, Identity) (HR.12) | PART | H352–H353, H485 | Library, Inventory and Finance record no staff obligation: NOT_INTEGRATED (R-HR12-2, HR.15 / owners) | HR + owners | HR.15 | M | rule 105 |
| D18-16 | Final approval | another person decides (`hr.separations.approve`, shared approval foundation, fresh MFA) (HR.12, HR-061) | IMP | H486 | single stage (ADR 0065 §6 spirit; no delegation) | HR | — | M | — |
| D18-17 | Offboarding | case finalization through `end()`; completion gated on mandatory clearance and open owner obligations; School access flagged, off-boarded under Staff accounts (HR.12) ; HR.R1-C2: `end()` clips placements to the last day and records later-starting ones as never effective (HRC-2 closed) | DEF | H351 | domain-evidence clearance for Library / Inventory / Finance; relieving / NOC documents (Documents review) | HR + Identity | HR.15 | H | D-1 (HR owner 2026-11-08, HR.R1-C16), was PART; re-review: a relieving-letter contract (a Documents owner type and a wording review); the clearance half stays D18-15 |
| D18-18 | Rehire | new employment, same Employee and number ; HR.R1-C2: refused for an Employee recorded deceased; HR.R1-C9: an archived Employee is restored first (HRC-12, owner decision 2026-11-07) | IMP | H129 (reactivate) | — | HR | — | — | — |
| D18-19 | Deactivation preserves history | no runtime DELETE; append-only evidence | IMP | — | — | HR | — | H | — |
| D18-20 | Contract staff register | `employment_type` + `ends_on` | ALT | H124, H246 | contract amounts are Payroll; documents via HR documents | HR | HR.12 | L | — |
| D18-21 | Superannuation / retirement date | none | DEF | H125–H126 | owner decision HR-084 (derived from DOB, no new field) | HR | HR.12 | L | — |

### D19 — Staff task management (Workforce Operations)

| ID | Requirement | Existing implementation | Status | Evidence | Gap | Owner | Phase | Risk | Gate |
|---|---|---|---|---|---|---|---|---|---|
| D19-01 | Task catalogue | `workforce_task_templates` + immutable versions (`TaskCatalogueService`) | IMP | H499–H506 | — | WorkforceTasks | — | L | — |
| D19-02 | Designation mapping | template version `position_id` (HR Position, composite key) | IMP | H191, H499 | — | WorkforceTasks | — | L | — |
| D19-03 | Teaching | version `applies_teaching` (HR.1A staff type on the occurrence date) | IMP | H499 | — | WorkforceTasks | — | L | — |
| D19-04 | Non-Teaching | version `applies_non_teaching` | IMP | H499 | — | WorkforceTasks | — | L | — |
| D19-05 | Outsource | version `applies_outsourced` | IMP | H499 | — | WorkforceTasks | — | L | — |
| D19-06 | Task category | `workforce_task_categories` (School-configured, retired never deleted) | IMP | H516 | — | WorkforceTasks | — | L | — |
| D19-07 | Academic task (class-section / subject-wise) | optional Section / SubjectOffering reference on a one-off task (labels only, no access) | DEF | H501 | not on recurring templates | WorkforceTasks | later | L | D-1 (HR owner 2026-11-08, HR.R1-C16), was PART; re-review: a task-relationship contract with TeachingAssignments and Academic Structure |
| D19-08 | Admin task | a School-configured category (no closed "admin" type) | ALT | H501 | — | WorkforceTasks | — | L | — |
| D19-09 | General task | a School-configured category (no closed "general" type) | ALT | H501 | — | WorkforceTasks | — | L | — |
| D19-10 | Daily frequency | `daily` on chosen weekdays (calendar-neutral) | IMP | H499 | no holidays (WorkSchedule gated) | WorkforceTasks | — | L | — |
| D19-11 | Weekly frequency | `weekly` on one weekday | IMP | H499 | — | WorkforceTasks | — | L | — |
| D19-12 | Monthly frequency | `monthly` on day 1-28 or the last day | IMP | H499 | — | WorkforceTasks | — | L | — |
| D19-13 | Yearly frequency | `yearly` on an existing date or the month's last day (never 29 Feb) | IMP | H499 | — | WorkforceTasks | — | L | — |
| D19-14 | Target / deadline | available / due dates (School-local); due offset in calendar days | DEF | H504 | no numeric targets or due times | WorkforceTasks | later | L | D-1 (HR owner 2026-11-08, HR.R1-C16), was PART; re-review: a task-relationship contract with TeachingAssignments and Academic Structure |
| D19-15 | Class / section relationship | `workforce_tasks.section_id` (composite key; Academic Structure labels) | DEF | H501 | one-off tasks only | WorkforceTasks | later | M | D-1 (HR owner 2026-11-08, HR.R1-C16), was PART; re-review: a task-relationship contract with TeachingAssignments and Academic Structure |
| D19-16 | Subject relationship | `workforce_tasks.subject_offering_id` (composite key) | DEF | H501 | one-off tasks only | WorkforceTasks | later | M | D-1 (HR owner 2026-11-08, HR.R1-C16), was PART; re-review: a task-relationship contract with TeachingAssignments and Academic Structure |
| D19-17 | Employee submission | `TaskWorkflowService::submit()` (assignee only) | IMP | H507 | — | WorkforceTasks | — | L | — |
| D19-18 | Remarks | bounded remarks on the append-only timeline (500 chars) | IMP | H507 | — | WorkforceTasks | — | M | — |
| D19-19 | Attachments | none (owner decision 2026-11-04: deferred) | DEF | H514 | a Documents owner type `workforce_task` + ADR 0012 review | WorkforceTasks + Documents | later | M | — |
| D19-20 | Manager feedback | bounded reviewer feedback (complete / return once); never a rating | IMP | H507 | — | WorkforceTasks | — | M | HR-L11 for ratings |
| D19-21 | Direct manager assignment | `hr.tasks.team` + current HR.3A line (`team_task_manager` add-on) | IMP | H514 | — | WorkforceTasks | — | M | — |
| D19-22 | Assigned by me | Assigned by me (`TaskReadService::assignedByMe`) | IMP | H514 | — | WorkforceTasks | — | L | — |
| D19-23 | Assigned to me | My tasks (`/app/my-tasks`, current employment) | IMP | H514 | — | WorkforceTasks | — | L | — |
| D19-24 | Status | closed status (assigned, submitted, returned, completed, cancelled); overdue derived | IMP | H515 | — | WorkforceTasks | — | L | — |
| D19-25 | Task reports | Staff Task Monitor + `hr.task_occurrences`, `hr.task_summary` (occurrence counts by status that reconcile) and `hr.task_generation_runs` (coverage per day; missed days shown as "No run") (HR.14) | IMP | H510–H513 | rates stay behind ADR 0040 (owner decision 2026-11-05: gated) | WorkforceTasks | HR.14 | L | ADR 0040 for rates |
| D19-26 | Task monitoring | Staff Task Monitor (`/app/hr/tasks`, server-filtered and paged) | IMP | H514–H517 | — | WorkforceTasks | — | L | — |

### D20 — HR reporting and MIS

| ID | Requirement | Existing implementation | Status | Evidence | Gap | Owner | Phase | Risk | Gate |
|---|---|---|---|---|---|---|---|---|---|
| D20-01 | Staff directory | `EmployeeDirectoryService` (paged, Campus-scoped, filters); date-aware employment state (HR.1A, ORG10-D01) | IMP | H060, H124 | — | HR | — | L | — |
| D20-02 | Customizable staff-details report | `hr.staff_details` (HrReportRegistry, HR.14): employments covering a chosen date with the terms and primary placement of THAT day; server-decided columns (placement and manager need whole-School `hr.employees.assignments.view`; a forged or prohibited column is a 422); browser-only (owner decision 2026-11-05) | IMP | H546–H549 | export waits for a D-11 HR extension (owner decision) | HR | HR.14 | H | D-11 for export |
| D20-03 | Joiners | directory "joined from/to" (HR-056) | IMP | H562 | — | HR | — | — | — |
| D20-04 | Inactive / leavers | directory employment filter (HR-040) | IMP | H560–H561 | — | HR | — | — | — |
| D20-05 | Birthdays | none | NR | H120, H557 | birthday features not adopted (`REFERENCE-PARITY-MATRIX.md`) | — | — | M | — |
| D20-06 | Work anniversaries | `hr.work_anniversaries` (HR.14): from the CURRENT employment's start date only (owner decision 2026-11-05; a rehire restarts); 29 Feb → 28 Feb; no greeting or notification | IMP | H557 | — | HR | HR.14 | L | — |
| D20-07 | Headcount | none | GATE | H128, H354 | — | Analytics | — | M | ADR 0040 |
| D20-08 | Yearly headcount comparison | none | GATE | H550 | — | Analytics | — | M | ADR 0040 |
| D20-09 | Department headcount | none | GATE | H354 | — | Analytics | — | M | ADR 0040 |
| D20-10 | Reporting hierarchy | `hr.reporting_lines` (HR.14): manager and level (any depth) on a chosen date from HR.3A lines; "without a manager" filter | IMP | H565–H580 | — | HR | HR.14 | L | — |
| D20-11 | Attendance dashboard | register + month report + absentee list (HR.14) | PART | H001–H010, H307 | operational status counts only; rates and charts are Analytics | StaffAttendance | — | M | ADR 0040 for rates |
| D20-12 | Daily attendance | register | IMP | H307 | — | StaffAttendance | — | — | — |
| D20-13 | Monthly attendance | `monthSummary()` | IMP | H317–H318 | — | StaffAttendance | — | — | — |
| D20-14 | Present | register | IMP | H308 | — | StaffAttendance | — | — | — |
| D20-15 | Absent | register | IMP | H309 | — | StaffAttendance | — | — | — |
| D20-16 | Leave (on leave on a date) | read model | IMP | H402 | — | StaffAttendance + Leave | — | — | — |
| D20-17 | Week-off | read model | IMP | H307 | — | StaffAttendance | — | — | — |
| D20-18 | Timetable substitution | none | XDOM | H042, H315, H411, H529 | Timetable-owned (HR-054); HR exposes availability only through Leave / attendance readers | Timetable | HR.15 | M | — |
| D20-19 | Biometric reports | none | GATE | H599 | — | StaffAttendance | HR.8 | H | L2b |
| D20-20 | Late / early reports | none | GATE | H392–H397 | — | StaffAttendance | HR.8 | M | L2a |
| D20-21 | Staff leave details | Leave requests list (HR-043) | IMP | H591 | — | Leave | — | — | — |
| D20-22 | Month-wise leave | `/app/leave/monthly` (HR-042) | IMP | H594 | — | Leave | — | — | — |
| D20-23 | As-on-month leave | `hr.leave_balances` (HR.14): opening, credits, debits and balance per account as at a School-local date (posting clock) from the one ledger; year-end equals the statement's closing | IMP | H563 | — | Leave | HR.14 | M | — |
| D20-24 | Leave balances | ledger balance reads | IMP | H449 | — | Leave | — | — | — |
| D20-25 | Leave analytics | none | GATE | H044–H047, H452 | — | Analytics | — | M | ADR 0040 |
| D20-26 | Staff task reports | the HR.14 task reports (D19-25) | PART | H510–H513 | rates and percentages (ADR 0040; owner decision 2026-11-05 keeps them gated) | WorkforceTasks | — | L | ADR 0040 |
| D20-27 | Roaming staff | none (a punch at another branch's device) | GATE | H408, H542 | — | StaffAttendance | HR.8 | M | L2b |
| D20-28 | GPS / self check-in report | none | GATE | H545 | — | StaffAttendance | HR.8 | H | L2b |
| D20-29 | Staff-child report | none (the reference matches by mobile number) | XDOM | H518–H520, H541 | an explicit HR ↔ SIS contract (owner decision HR-050); **never PII matching** | SIS + HR | HR.15 | H | M-1, E47 |
| D20-30 | Inventory issued to staff | none (Inventory issues to Departments) | XDOM | H581–H586 | a new Inventory custody contract | Inventory | HR.15 | M | rule 105 |
| D20-31 | Staff replacement | none | XDOM | H582 | Timetable substitution record | Timetable | HR.15 | L | — |
| D20-32 | Sessions attended | none (no screen captured; training) | DEF | H602 (menu only) | training register is outside the D-map | — | — | L | HR-L11 |
| D20-33 | Attendance modification log | `hr.attendance_corrections` (HR.14): every correction dated in a range, before/after halves, closed reason, corrector display name only (owner decision 2026-11-05) | IMP | H587–H590 | — | StaffAttendance | HR.14 | M | — |
| D20-34 | Salary / compensation modification log | effective-dated immutable compensation rows + audit (Payroll) | XDOM | H587–H590 | Payroll-owned, compensation-sensitive tier | Payroll | HR.15 | H | OD-HR-1/2 |
| D20-35 | MIS (existing + new − inactive) | none | GATE | H550–H559 | — | Analytics | — | M | ADR 0040 |
| D20-36 | Staff profile cards | none | DEF | H531–H537 | printable minimal card later (HR-053); never DOB, blood group, ids or bank | HR | post-HR.16 | M | — |
| D20-37 | Staff student fee details | none | XDOM | H361–H368 | Fees-owned; owner decision HR-055 | Fees | HR.15 | H | E47 |
| D20-38 | FR attendance report | none | GATE | H521 | — | StaffAttendance | HR.8 | H | L2b |
| D20-39 | Praise / feedback / complaints report | none | DEF | H544 | outside the D-map (XF) | — | — | H | HR-L10 |

## 5. Totals

**Method.** Each requirement row counts once: **361 rows**. The counts
come from the status column of §4, read mechanically: one row per
`| Dn-nn |` line. **No capability is dropped because it is deferred.**

| Status | HR.0A baseline | After HR.1A | After HR.3A | After HR.2A | After HR.9 | After HR.10 | After HR.11 (2026-11-04) | After HR.12 (2026-11-04) | After HR.13 (2026-11-04) | After HR.14 (2026-11-05) | After HR.R1-C16 D-1 (2026-11-08) | After HR.R1-C18 WO-6 (2026-11-08) |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| IMPLEMENTED | 96 | 110 (30.5 %) | 114 (31.6 %) | 116 (32.1 %) | 123 (34.1 %) | 128 (35.5 %) | 129 (35.7 %) | 134 (37.1 %) | 152 (42.1 %) | 161 (44.6 %) | 161 (44.6 %) | **162** (44.9 %) |
| PARTIALLY_IMPLEMENTED | 40 | 29 | 26 | 25 | 23 | 24 | 23 | 22 | 28 | 20 | 10 | **9** |
| MISSING | 90 | 87 | 86 | 85 | 76 | 69 | 42 | 39 | 12 | 11 | 2 | **1** |
| IMPLEMENTED_DIFFERENTLY_AND_ACCEPTABLE | 18 | 18 | 18 | 18 | 18 | 18 | 18 | 18 | 20 | 20 | 20 | **21** |
| CROSS_DOMAIN | 8 | 8 | 8 | 8 | 8 | 8 | 8 | 7 | 7 | 7 | 7 | 7 |
| DEFERRED | 16 | 16 | 16 | 16 | 20 | 21 | 48 | 48 | 49 | 49 | 68 | 68 |
| BLOCKED_BY_GATE | 87 | 87 | 87 | 87 | 87 | 87 | 87 | 87 | 87 | 87 | 87 | 87 |
| NOT_REQUIRED | 6 | 6 | 6 | 6 | 6 | 6 | 6 | 6 | 6 | 6 | 6 | 6 |
| **Total** | **361** | **361** | **361** | **361** | **361** | **361** | **361** | **361** | **361** | **361** | **361** | **361** |

HR.R1-C18 (2026-11-08, WO-6) changed two ACTIVE rows on evidence: D8-10 MISS → ALT (the month lock, no reopening by owner decision S6-1) and D8-13 PART → IMP (the lock as the window, two-person fresh-MFA locked-month corrections, a fresh authority read on open-month corrections). Two ACTIVE rows remain: D14-08 and D14-09 (WO-5, after D-6). `HR-R1-C18-WO6-MONTH-LOCK.md`.

HR.R1-C16 (2026-11-08, D-1, HR owner) changed 19 rows to DEF by owner decision, each with its earlier status and a re-review trigger in its Gate column: the 10 ungated HR.4–8 rows of D-1 Option B (D5-02, D5-03, D5-05, D5-06, D8-03, D8-04, D14-02, D14-03, D14-04, D14-07) and the nine rows outside HR.4–8 (D4-07, D1-07, D18-03, D18-04, D18-17, D19-07, D19-14, D19-15, D19-16). The 61 gated HR.4–8 rows are HELD: they stay GATE, and a deferral opens nothing. **Four rows stay ACTIVE, undecided and unbuilt:** D8-10 and D14-08 (MISS), D8-13 (S-6) and D14-09 (S-16) (PART). Nothing was built. `HR-R1-C16-D1-DECISION.md`.

HR.R1-C14 (2026-11-07) changed no row's status, and recounted 361.

HR.R1-C13 (2026-11-07) changed no row's status: it hardened writers and models and recounted 361, with the same totals as HR.R1-C10.

HR.R1-C11 (2026-11-07) changed no row's status: it hardened writers and recounted 361, with the same totals as HR.R1-C10.

HR.16 (2026-11-05) changed no row's status: it corrected reporting semantics (status on a date, as-posted leave), closed R-HR15-1 and the Staff Attendance cap, and added browser, axe and performance evidence (`HR-16-READINESS.md`).

HR.14 (2026-11-05, owner decisions: browser-only, current-employment
anniversaries, task rates gated, corrector display name) closed D20-02, D20-10,
D20-23, D20-33, D4-04, D8-15, D9-15 and D19-25 (PART → IMP) and D20-06
(MISS → IMP) through the HR report catalogue (`HrReportRegistry`). D20-11 and
D20-26 stay PART (rates and charts: ADR 0040). The ADR 0040 rows (D20-07,
D20-08, D20-09, D20-25, D20-35) stay GATE; D20-05 stays NR; the XDOM rows
(D20-18, D20-29, D20-30, D20-31, D20-34, D20-37) stay with their owners.
Previous status of each row is in git history at 7e05f8d6.

HR.13 (2026-11-04, owner decisions) moved all 26 D19 rows from MISS: 18 IMP,
5 PART (D19-07, D19-14, D19-15, D19-16, D19-25), 2 ALT (D19-08, D19-09:
School-configured categories) and 1 DEF (D19-19 attachments, owner decision);
D20-26 MISS -> PART (counts only; rates wait for ADR 0040 / HR.14).

HR.15 (2026-11-04) changed no row's status: HR.15A fixed the X-1 pickers (a
finding, not a row); D20-30 stays XDOM (Inventory custody deferred by owner
decision); D18-15 / D18-17 stay PART (Library, Inventory, Finance deferred);
D20-18, D20-29, D20-31, D20-34, D20-37 and D1-35 stay with their owners and gates.

HR.12 (2026-11-04, owner decisions HR-061) closed D18-10, D18-11, D18-12,
D18-13 and D18-16 (IMP); D18-15 moved XDOM → PART (administrative clearance +
owners' answers; Library, Inventory, Finance NOT_INTEGRATED); D18-17 stays PART
(no domain-evidence clearance, no relieving document); D1-07 stays PART
(probation confirmation, D18-03/04, not built). Previous status of each row is
in git history at 60da2011.

HR.1A closed D1-02, D1-11, D1-12, D2-01…D2-04, D2-06, D2-10…D2-13 and
D18-05…D18-08. It narrowed D1-07, and hardened D3-06 (S-9) and D20-01
(ORG10-D01), which were already IMPLEMENTED. The per-section table below
shows the HR.1A figures for D1, D2 and D18.

HR.3A closed D2-05, D4-05, D4-11 and D4-12, and re-described D2-13, D4-02,
D4-03 and D4-06 (already IMPLEMENTED) for dated lines. D4-01 (L-depth
presentation), D4-04 and D20-10 (tree view) and D4-07 (dotted lines) stay
open. The per-section table shows the HR.3A figures for D2 and D4.

HR.2A closed D1-03 (reference codes, MISS → IMP) and D1-26 (governed
documents, PART → IMP), and hardened D1-17, D1-19, D1-24 and D1-27 (already
IMPLEMENTED or ALT): archive history, derived experience, stale-form tokens.
The gated profile families stay BLOCKED_BY_GATE or DEFERRED. The
per-section table shows the HR.2A figures for D1.

HR.9 closed D10-03, D10-09, D10-10, D10-20 and D12-04 (MISS → IMP) and
D10-11 and D12-05 (PART → IMP), and re-described D10-21 (rules on the
version). The sandwich family -- D10-13, D10-14, D10-15 and D14-11 -- moved
MISS → DEFERRED behind owner decision OD-HR-15 (ADR 0065 §28.9); D10-16
stays PART (fixed exclusion) behind the same decision. D10-05 and D10-19
stay MISS for HR.10. The per-section table shows the HR.9 figures for D10,
D12 and D14.

HR.10 closed D10-05, D11-01, D11-03, D11-16 and D12-01 (to IMP), narrowed
D10-19 and D12-03 (MISS → PART: monthly accrual from the first whole month)
and deferred D12-08 (MISS → DEF) behind OD-HR-17 (ADR 0065 §22.2 stands).
D11-06 stays PART behind OD-HR-16 (consumption order). D11-13 and D12-11
were already IMPLEMENTED and are re-described (S-7 MFA; accrual
idempotency). The per-section table shows the HR.10 figures for D10, D11
and D12.

HR.11 (partial) closed D13-20 (PART → IMP: the `line_manager` add-on role,
OD-HR-5). On the owner's 2026-11-04 decisions, 27 rows moved MISS →
DEFERRED. That is a recorded block, NOT progress:
- comp-off: D11-09/10 and D15 except the GATE and ALT rows (OD-HR-18);
- staff permissions: D16's non-gated rows (OD-HR-19);
- special windows: all of D17 (OD-HR-20).

D13-15…17 stay DEFERRED (ADR 0065 §6 kept). The per-section table shows
the HR.11 figures for D11, D13, D15, D16 and D17.

**Per section:**

| Section | IMP | PART | MISS | ALT | XDOM | DEF | GATE | NR | Rows |
|---|---|---|---|---|---|---|---|---|---|
| D1 Workforce master | 20 | 1 | 0 | 2 | 1 | 4 | 4 | 3 | 35 |
| D2 Assignments | 11 | 0 | 0 | 0 | 0 | 0 | 1 | 1 | 13 |
| D3 Organization | 6 | 0 | 0 | 2 | 0 | 0 | 0 | 0 | 8 |
| D4 Hierarchy | 9 | 0 | 1 | 2 | 0 | 1 | 0 | 0 | 13 |
| D5 Calendar & shifts | 2 | 2 | 2 | 0 | 0 | 0 | 14 | 0 | 20 |
| D6 Attendance events | 2 | 0 | 0 | 1 | 0 | 0 | 19 | 0 | 22 |
| D7 Evaluation | 6 | 1 | 0 | 2 | 0 | 0 | 7 | 0 | 16 |
| D8 Attendance operations | 7 | 1 | 3 | 1 | 0 | 2 | 2 | 0 | 16 |
| D9 Biometric | 1 | 0 | 0 | 1 | 0 | 0 | 15 | 0 | 17 |
| D10 Leave policy | 12 | 2 | 0 | 2 | 0 | 5 | 3 | 0 | 24 |
| D11 Ledger | 11 | 1 | 0 | 0 | 0 | 2 | 2 | 0 | 16 |
| D12 Allocation | 9 | 1 | 0 | 0 | 0 | 1 | 0 | 0 | 11 |
| D13 Workflow | 11 | 0 | 0 | 1 | 0 | 3 | 4 | 1 | 20 |
| D14 Holidays | 3 | 2 | 4 | 2 | 0 | 1 | 0 | 0 | 12 |
| D15 Comp-off | 0 | 0 | 0 | 1 | 0 | 8 | 2 | 0 | 11 |
| D16 Permissions | 0 | 0 | 0 | 0 | 0 | 7 | 4 | 0 | 11 |
| D17 Special windows | 0 | 0 | 0 | 0 | 0 | 10 | 0 | 0 | 10 |
| D18 Lifecycle | 15 | 3 | 1 | 1 | 0 | 1 | 0 | 0 | 21 |
| D19 Tasks | 19 | 4 | 0 | 2 | 0 | 1 | 0 | 0 | 26 |
| D20 Reports & MIS | 17 | 2 | 0 | 0 | 6 | 3 | 10 | 1 | 39 |

**Reading the totals:**
- **Gates block 87 capabilities:**
  - 65 are HRX-L2 clock-time, biometric or device data (D2, D5, D6, D7,
    D9, D11, D16 and D20);
  - 11 are HRX-L1 free text or attachments;
  - 5 are the ADR 0040 person-count gate;
  - 2 are HRX-L4;
  - 2 are ADR 0036 statutory payroll;
  - 1 is HRX-L3;
  - 1 is D-11.
- **Implemented or acceptably different: 114 (31.6 %) at HR.0A; 128 (35.5 %) after HR.1A; 132 (36.6 %) after HR.3A; 134 (37.1 %) after HR.2A.**
- **Buildable without any gate, at HR.0A:** the 90 MISSING and 40 PARTIAL rows of that date, the substance of
  HR.1–HR.3 and HR.9–HR.14. **Current counts (HR.R1-C18, §5):** 1 MISS, 9 PART (HR.R1-C10 recount: 11 MISS, 20 PART).

## 6. Reference behaviours deliberately not adopted

| Reference behaviour | Why not | Lycenza contract |
|---|---|---|
| "Access Level" (login role) chosen on the staff form (H092, H142, H153) | job titles are never roles | rules 92–93 |
| Linking staff to children by matching mobile numbers (H518–H520) | identity inference from contact data | rule 109 (never PII matching), M-1 |
| Free-text leave reasons and exit remarks that carry health or performance detail (H351, H591) | health and disciplinary data by accident | HRX-L1, ADR 0065 §10, HR-L10 |
| Inline "Add New" master creation from the profile form (H086–H091) and uncurated masters (H191, H347) | duplicate and garbage masters | masters are administered on their own pages |
| Gross salary in inactive / joining HR reports (H560–H562) | pay is compensation-sensitive (Payroll) | rule 95, `payroll_sensitive` |
| One bulk grid editing every sensitive class (H221–H248) | bypasses per-section authorization | §E of the HR.0A record |
| Profile cards with DOB, blood group, Aadhaar, PAN, bank (H531–H536) | maximal disclosure on paper | D20-36 |
| AI-generated role / responsibility text (H195–H204) | not an HR need; AI writes nothing (rule 8) | — |
| Fixed L0/L1/L2/L3 manager columns (H478–H480) | fixed depth, denormalised | D4-01 |

## 7. Families outside the D-map (dispositions, not built in HR.1–HR.16)

| Family | Disposition | Record |
|---|---|---|
| XC collaboration (wall, groups, meetings, greetings) | Communications; walls and birthday features not adopted | HR-063/080/081/082 |
| XT training / PD / achievements / research log | DEFERRED (OD-HR-7, HR-L11) | HR-007/008 |
| XF praise / feedback / complaints / internal notes | DEFERRED, legal first (OD-HR-8, HR-L10) | HR-052 |
| XO outsourcing vendors and contracts | Finance / procurement; staff remain Employees with an outsource category | HR-062 |
| XD handbook / SOP library | Documents (HR-064, post-v1) | HR-064 |
| XP Form 12C, UAN / PF / ESI, pay type | Payroll statutory (ADR 0036) | HR-088 |
| XTR staff transport | Transport (staff riders not built) | — |
| XTT substitution / workload | Timetable | HR-054 |
| XA access level | Identity (rules 92–93) | — |
