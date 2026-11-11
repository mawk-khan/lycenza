# HR domain ownership map

Status: **authoritative ownership map for the HR programme.**
- First written in HR.EXP-0 (2026-10-30).
- **Re-issued by HR.0A** (2026-11-03, baseline `7a4068fe`) against code
  truth. It adds future HR concepts, the cross-ERP map, and the coupling
  evidence found.

**Rule 4.** Each function has one owner. Every other domain reaches it
only through that owner's Application service, or by listening to its
domain events. It never reads the owner's Eloquent models or tables. HR.0A
**records** ownership. It fixes nothing silently: deviations are listed in
§3 with their destination phase.

## 1. Source of truth per function

| Data / function | Owner (source of truth) | Not owned by | Contract / reader | Status |
|---|---|---|---|---|
| School, Campus, School profile, Network governance | **Organization** (ADR 0080; `Campus` model, `ActiveCampus`) | HR | composite `(campus_id, school_id)` keys; `ActiveCampus` for new work | existing |
| Tenant boundary | **Platform tenancy** (`BelongsToSchool`, `TenantRls`, `TenantContext`; ADR 0004/0021) | HR | rules 5, 17–23 | existing; HR adds no mechanism |
| Login identity, memberships, invitations, role and capability grants | **Identity / IAM** (rules 92–94, `CapabilityResolver`) | HR | Employee ↔ User link only through `EmployeeService::linkUser()` | existing; a job title or designation is never a role |
| Staff identity (Employee), employee number | **HR** (`employees`, `EmployeeNumberAllocator`) | Identity, Organization | `EmployeePickerReadService`, `ActingEmployeeResolver` | existing |
| Employment (contract period, type, category, lifecycle status) | **HR** (`employment_records`, `EmploymentService`, `EmployeeLifecycleService`) | Payroll | `EmploymentCoverage`, `EmploymentRoster` | existing; category and type effective-dated since HR.1A (X-3 fixed) |
| Employment terms history (type, staff type, category, effective-dated) | **HR** (`employment_terms`, `EmploymentTermsService`; HR.1A) | Payroll, Leave | `WorkforceAsOfReader::termsOn()` | implemented (HR.1A); the employment columns are a database-maintained projection |
| Named placement changes (transfer, promotion, designation / department change, primary correction) | **HR** (`employee_placement_changes`, `PlacementChangeService`; the primary correction row by `EmployeeAssignmentService::setPrimary()`; HR.1A) | — | Profile history | implemented (HR.1A) |
| Historical ("as of") workforce placement | **HR** (`WorkforceAsOfReader`; HR.1A) | every consumer | `placementOn()`, `placementsOn()`, `currentPlacement()`, `isCurrentlyEmployed()` | implemented (HR.1A); operational currency stays `CurrentEmployeeAssignments` |
| Staff assignment (Campus, Department, Position, primary flag, dates) | **HR** (`employee_assignments`), referencing Organization's Campus | Organization | `CurrentEmployeeAssignments` (the one currency reader, rule 101) | existing, effective-dated |
| **Department master** (staff org units) | **HR** (`hr_departments`, School-wide or Campus-scoped, hierarchical) | Organization, Academic Structure | `DepartmentReference` (Inventory consumes it) | existing. `academic_departments` (Academic Structure) groups **Subjects**: a deliberately different concept (`docs/modules/HR.md` §2), **not a duplicate** |
| Designation | **HR** (`positions`) | Identity | — | existing; no level or grade (WMSCB L0–L3 is presentation, §2) |
| Staff category / staff type (Teaching, Non-Teaching, Outsource) | **HR**: School `employee_categories` (label) + the closed `StaffType` on the employment terms (HR.1A) | — | `WorkforceAsOfReader` | staff type closed and never derived from category, Department, Position or role (HR.1A) |
| Reporting hierarchy | **HR** (`reporting_relationships`; `ReportingRelationshipService`, `ReportingLine`, `ReportingAsOfReader`; HR.3A) | Identity, Leave | `ReportingLine::managerOf()`, `holdManagerOf()`, `reportsOf()`; `ReportingAsOfReader` | implemented (HR.3A): dated employment-to-employment lines; the 8A.5 pointer is removed |
| HR profile sections (personal, contact, addresses, emergency contacts, qualifications, experience, certifications, notes) | **HR** | — | `EmployeeProfileWorkspaceService`, `StaffProfileReader` | existing; HR.2A: archived never deleted, self-guarded, stale-form tokens, derived experience |
| External reference codes (Staff Reference, ERP, legacy) | **HR** (`employee_reference_codes`, `EmployeeReferenceCodeService`; HR.2A) | Identity, StaffAttendance (biometric identity is not a reference code) | directory exact search | implemented (HR.2A) |
| HR document records | **HR** (record: closed category, tier, dates, archive) + **Documents** (bytes, owner type `employee`) | — | `DocumentService::createForEmployee()`, `reclassifyForEmployee()`, `archiveForEmployee()`, `DocumentReadService::employeeContent()` | HR.2A: governed uploads; `employee_documents.document_id` (ADR 0029 amendment) |
| ActingEmployee (who the signed-in staff member is) | **HR** | every consumer | `ActingEmployeeResolver` (identifies, never authorizes) | existing; E33 condition |
| Teacher ownership (who teaches what) | **TeachingAssignments** (ADR 0063) | HR | `teaching_assignments`, elective assignments | existing; ended by employment end (ADR 0063 §47) |
| Work calendar (weekly working pattern, holidays) | **Leave** today (`staff_working_weekdays`, `staff_holidays`, `StaffCalendarService`) | StaffAttendance, Payroll | `StaffCalendarService::calculator()` | existing, School-wide only. **Proposed:** move to a WorkSchedule owner in HR.4 (decision HR-AD-05) |
| Shifts, shift rules, shift assignments | **proposed WorkSchedule** (HR.4) | Leave, StaffAttendance | — | not built; HRX-L2/L2a gated |
| Attendance sources, devices, attendance identities (biometric codes), raw events | **StaffAttendance** (HR.5, HR.8) | HR (Employee identity) | — | not built; HRX-L2/L2b gated. A biometric code is an **external-system mapping**, never Staff identity |
| Evaluated staff attendance day and corrections | **StaffAttendance** (`staff_attendance_records`, `staff_attendance_corrections`) | Student Attendance, Leave | `StaffAttendancePresenceReader`, `PayrollAbsenceEvidenceReader` | existing (half-day present/absent) |
| Leave types, years, policies, policy assignments, ledger, requests, decisions, allocation, year close | **Leave** | HR, Payroll | `LeaveCoverageReader`, `LeaveReadService`, `LeaveRequestReadService` | existing |
| Leave decisions by line managers (the `line_manager` add-on role; paged approvals inbox, decision history; HR.11) | **Leave** (decisions) + **Identity** (the closed role catalogue) | HR (current reporting line only, `ReportingLine::holdManagerOf`) | `LeaveRequestReadService::managedPage()` / `managedShow()` | implemented (HR.11, partial); comp-off, permissions and windows blocked (OD-HR-18/19/20) |
| Monthly accrual, opening balances, statement (accrual runs and items; `LeaveAccrualService`, `LeaveAccrualCalculator`, `LeaveStatementReader`; HR.10) | **Leave** | HR (facts only: `EmploymentRoster::pageOverlapping()`, `EmploymentCoverage::holdRecordsSpanning()`) | `LeaveStatementReader::statement()` / `grid()` | implemented (HR.10); Payroll never reads balances |
| Leave policy rules and eligibility (request-timing rules and eligibility rules on the version, eligibility runs) | **Leave** (`leave_policies` rule columns, `leave_eligibility_rules`, `leave_eligibility_runs`; `LeaveEligibilityService`, `LeaveEligibilityResolver`; HR.9) | HR (facts only, read one way) | `LeaveEligibilityService::applicability()` | implemented (HR.9); facts from HR's `EmploymentRoster`, `WorkforceAsOfReader`, `EmployeeCategoryReference`, `DepartmentReference`; HR never reads Leave |
| Compensatory off, short-duration permissions, special leave windows | **Leave** (proposed, HR.11) | StaffAttendance | — | not built |
| Staff lifecycle events (joining, confirmation, resignation, notice, relieving, clearance) | **HR** (`employment_separation_cases`, `SeparationCaseService`, HR.12) | Inventory, Library, Finance (their own clearance answers) | — | separation exists; workflow not built |
| Workforce tasks | **WorkforceTasks** sub-domain of HR (HR.13), independent of Attendance and Leave | Academic (class/subject references only through Academic Structure readers) | — | implemented (HR.13) |
| Student | **SIS / Students** | HR | `StudentAccess`, `StudentPurposeLookup` | existing |
| Staff ↔ Student relationship (staff children) | **explicit integration contract, not yet defined** (owner decision HR-050) | HR, SIS | — | none exists. A Guardian account linked to an Employee is the only indirect path (M-1) |
| Student fee balance | **Fees / Payments** (`ChargeStateReader`, Outstanding fees) | HR | Finance read paths | existing; staff-ward fee report is an owner decision (HR-055) |
| Staff inventory assets | **Inventory** (issues to a Department, never a person; rule 105) | HR | `DepartmentReference` | person custody needs a new Inventory contract (gate register §1) |
| Timetable, teacher scheduling | **Timetable** | HR | — | existing; reads HR's `Employee` model directly (X-1) |
| Substitute / replacement assignment | **Timetable** (not built, HR-054) | HR | — | not built |
| Transport operations, driver assignment, staff riders | **Transport** | HR | — | driver assignment exists and reads `Employee` directly (X-1); staff riders not built |
| Payroll calculation, compensation, payslips, statutory engine | **Payroll** | HR, Finance | `PayrollRunService`, `PayslipReadService` | existing |
| Compensation fields (structures, effective-dated assignments, values) | **Payroll** (`employee_compensation_assignments`, `compensation_assignment_values`) | HR | `CompensationService` (fresh MFA) | existing. **HR never stores pay** |
| Bank / payment profile, disbursement | **not built** (would be Payroll for the profile, Payments/Finance for disbursement) | HR | — | gated (HR-L5) |
| Statutory identifiers (UAN, PAN, PF, ESIC) | **Payroll** (`employee_statutory_identifiers`, encrypted with HMAC lookup) | HR | — | existing; legal-gated (ADR 0036) |
| Journal posting, accounts, periods | **Finance** (`LedgerService`) | Payroll | — | existing |
| Staff audiences, staff messages, greetings | **Communications** via `CurrentStaffMembers` (rule 108) | HR | — | existing; walls and birthday features not adopted |
| Workforce headcount, trends, gender/age analytics | **Analytics** (ADR 0040 person-count gate) | HR | — | blocked; HR keeps transactional lists |
| Audit ledger | **platform audit** (`school_audit_events`, `AuditRecorder`, `SchoolAuditEventReader`) | HR | ids, closed codes, changed field names | existing. HR never adds a second audit framework |
| Retention | **platform retention** (E21, ADR 0066) with HR units | — | `EmployeeRetentionEligibility`, `EmployeeSeparation` | existing |
| Exports | **D-11 governed export** (rule 125) | HR | `StudentReportExportService` (SIS only today) | HR export needs a D-11 extension |
| Recruitment, performance, training, discipline | owner/legal decision | — | — | outside this programme's D-map (deferred, `HR-0A-ARCHITECTURE-RECONCILIATION.md` §15) |

Writer boundaries that existing contracts require are pinned by
`HrWriterBoundaryTest` (HR.R1-C11). "One writer" itself is undefined until
Architecture decides (`HR-R1-C11-HARDENING.md` §3).

## 2. Reference presentation concepts that are not owners

| Reference concept | Lycenza treatment |
|---|---|
| Designation levels L0/L1/L2/L3 | **Presentation only**: the depth of an Employee in the reporting tree as of a date, computed by traversal. Never `level_1_manager_id` columns |
| "Access level" chosen on the staff form | Identity role grant through Staff accounts (rule 92). Never an HR field |
| "Payroll entity" per staff member | **NOT_REQUIRED**: the School is the employer of record (HR-089 DO_NOT_BUILD) |
| Biometric code on the profile | StaffAttendance attendance identity (external mapping), gated by HRX-L2 |
| Staff bus route on the profile | Transport rider assignment (Transport) |
| Staff wall, groups, meetings, greetings | Communications (walls and birthday features not adopted) |

## 3. Current coupling that deviates from rule 4 (recorded, not fixed)

Cross-domain imports of HR classes at `7a4068fe`:

| Consumer | Uses | Assessment |
|---|---|---|
| Leave, StaffAttendance, LMS, Examinations, CurriculumDelivery, Attendance, Communications, Inventory, TeachingAssignments | HR **Application** readers (`ActingEmployeeResolver`, `EmploymentCoverage`, `EmploymentRoster`, `ReportingLine`, `CurrentStaffMembers`, `DepartmentReference`, `HrSelfAdministrationGuard`, retention readers) | compliant |
| Payroll | `HR\Infrastructure` models in 25 Payroll files and 3 App controllers: 62 executing sites, 9 composite FKs, 8 Payroll SQL functions (re-verified at `a3f36cd2`) | **Decided by D-2 (HR.R1-C23, Architecture): Option B. Implemented by HR.R1-C24 (WO-3):** the 22 sites read through `EmploymentRoster`; 26 narrow exceptions and 12 no-change sites remain, named in `HR-R1-C23-D2-DECISION.md` §3 and pinned by `PayrollHrBoundaryRegisterTest`. Payroll never writes HR |
| TeachingAssignments, Attendance, Documents | `belongsTo(Employee::class)` relationships; Documents' `find()` for the owner check | relationship-only use. Accepted pattern for FK display; the Documents owner check is a candidate for an HR reader |
| **Timetable, Transport, Visitor** | `Employee::query()` lookups in controllers, filtered at most on `record_status` | **X-1** (gate register §5): rule 4 drift **and** missing employment currency. Destination HR.15 |

No HR code depends on Timetable, Attendance, LMS, Teaching Assignments,
Transport or Inventory (ADR 0063 §29 preserved). Leave and StaffAttendance
depend on HR, and HR never depends on them. The direction
HR ← {Leave, StaffAttendance, Payroll} must hold for every new module in
the roadmap.
