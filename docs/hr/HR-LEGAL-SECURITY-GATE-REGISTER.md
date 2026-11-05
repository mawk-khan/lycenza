# HR legal, privacy & security gate register

Status: **register, documentation only.**
- First written in HR.EXP-0 (2026-10-30, baseline `c5e3dd23`).
- **Re-issued by HR.0A** (2026-11-03, baseline `7a4068fe`) in the HR.0A
  column format. Corrections: E16 dates (0R-F2), the proposed HRX-L2
  split, Analytics, export and Inventory boundaries, and the
  not-yet-raised determinations the WMSCB reference pack makes visible.

It consolidates existing records. **It decides nothing, opens nothing and
changes no gate.** Every outcome below is quoted from a repository record.
Nothing is inferred from the reference application.

Companion records:
- `HR-0A-ARCHITECTURE-RECONCILIATION.md` (the HR.0A contract);
- `HR-REFERENCE-CAPABILITY-MAP.md` (which capability each gate blocks).

**Development vs production.** An open production gate does not by itself
prohibit development. Each row says what the existing contract permits in
development. "Dev: bounded" means development may proceed **without** the
gated data or effect (ADR 0065 §19: the gates "do not block HRX.1–HRX.4
development; they bound it").

## 1. Recorded legal, privacy and production gates

| Id | Scope | Current outcome (quoted) | Development effect | Production effect | Affected role | Affected data | Approving authority | Expiry / re-review | Implementation impact |
|---|---|---|---|---|---|---|---|---|---|
| **HRX-L1** | Staff health / medical data | "OPEN — structurally absent (guards); no medical upload" (`docs/modules/HRX-READINESS-AND-CLOSURE.md` §9; ADR 0065 §10, §19) | **bounded**: no health field, medical reason, certificate or attachment; guard tests fail on any such column | absent by construction (not an environment refusal) | all | health, blood group, disability, medical certificates; **also any free-text or attachment on leave** (ADR 0065 §10) | qualified legal/DPO review; none recorded | needs a decision and an ADR 0065 amendment; no expiry | WMSCB "Health Details" and "Marks of Identification" profile tabs, leave attachments and mandatory-attachment policies: **BLOCKED_BY_GATE** |
| **HRX-L2** | Biometric, device, face recognition, GPS/geofence attendance | "OPEN — no clock time, device, location, photo or biometric column (guards)" (same §9); Integration Hub lists `attendance.biometric` / `attendance.rfid` as unavailable (`OrganizationIntegrationCatalogue`) | **bounded**: no such data. ADR 0065 §8 separately puts clock-in/out, shifts, overtime and late minutes **out of v1 scope**, and the parity record ties shifts to this gate (`reference-parity/functions.csv` HR-005, HR-006, HR-017) | absent by construction | all | biometric templates and codes, device ids, punches, clock times, location, photographs | legal/security review; none recorded | needs a decision and an ADR 0065 amendment | D5 shift timing, D6 sources, D7 clock-based evaluation, D9 biometrics, GPS/FR: **BLOCKED_BY_GATE**. See the proposed split, §3 OD-HR-14 |
| **HRX-L3** | Statutory leave entitlements | "OPEN — LEGAL REVIEW REQUIRED. No jurisdiction is cleared" (`docs/security/HRX-L3-STATUTORY-LEAVE-APPLICABILITY-MATRIX.md`) | bounded: School-configured policy only; no statutory claim | no statutory leave claimed | `hr_officer`, `school_admin` | leave entitlements labelled statutory (maternity, earned leave under a State Act, …) | legal review | per jurisdiction | D10/D12 may build **configurable** policy only, never a "statutory" label or formula |
| **HRX-L4** | Loss of pay / EPFO NCP | "MECHANISM IMPLEMENTED / LEGAL ACTIVATION BLOCKED" (`docs/security/HRX-L4-PAYROLL-LOSS-OF-PAY-DETERMINATION.md`); policy `pending_hrx_l4` | bounded: evidence is captured; **no money effect** | no path from HR evidence to an amount; ECR NCP stays `0` | `payroll_officer`, approver | unpaid leave and absence as wage deductions | decision + amendment | on activation | D7 "LOP candidate" may be **displayed as evidence only**; D11 "LOP ledger entry" and any payroll deduction: **BLOCKED_BY_GATE** |
| **ADR 0036** statutory payroll | PF, ESI, PT, LWF, TDS calculation, identifiers, exports | `payroll.statutory.*` legal-gated; held by **no** production role; calculation and posting have no route or CLI caller (ADR 0076 §60.8a) | demo-only role `demo.payroll_statutory` (`LocalCatalogueFixtures`) | held by "no role holds it" and "not wired", **not** by an explicit environment refusal (S-4) | none in production | UAN, PAN, PF member id, ESIC IP | catalogue release + legal sign-off | — | statutory ids stay Payroll-owned; never copied onto the HR profile |
| **E45 / PAY-L1** | ESI disability wage threshold | "DRAFT REQUEST — NOT SENT, NOT ANSWERED" | ESI-12 test skipped; no disability fact collected | n/a | — | disability status | legal | awaiting answer | no disability field anywhere in HR |
| **E33 / TCH-L1** | Teacher processing of **Student** attendance (not staff attendance) | "DETERMINED — APPROVED WITH CONDITIONS" (ADR 0058; DPO, 2026-10-07; `docs/security/TCH-L1-TEACHER-ATTENDANCE-DETERMINATION.md`) | permitted under §1–§7 (binding) | teacher bearer API refused in production (`EnsureTeacherAttendanceApiDevelopmentOnly`); the rest needs §9 evidence at deployment | `teacher` | Student attendance | DPO (recorded) | no fixed expiry; re-review triggers in §11 | **HR must not change** ActingEmployee resolution, TeachingAssignment ownership or the `teacher` role's key set without a §11 re-review |
| **E47 / SR-L1** | Staff personas (`hr_officer`, `payroll_officer`, …) | "QUESTION DRAFTED (2026-10-09, not sent, not answered)… Development is not blocked" (ADR 0058) | permitted | production policy prerequisite | HR personas | all HR data | owner/legal | — | any new HR role or capability set extends this question |
| **E21** | Retention (HR evidence D9 8 y; ancillary 2 y) | "PROJECT-ADOPTED FOR IMPLEMENTATION — PENDING FINAL LEGAL/COMPLIANCE RATIFICATION" | implemented | production gate | — | all HR evidence | ratification | ratification | every new HR table declares a retention unit in the same change |
| **ADR 0040** person-count Analytics | Analytics that counts people | "Person-counting Analytics stays blocked… fails closed (HTTP 503)" (ADR 0040 §2) | blocked (architecture guard) | blocked | all | headcounts, gender/age breakdowns, trends | owner: minimum cohort size + suppression mode | until decided | D20 headcount, yearly headcount comparison, department headcount, gender/age charts, leave analytics: **BLOCKED_BY_GATE** as Analytics. Transactional lists in HR remain allowed |
| **D-11** governed export | Exports | SIS-only governed contract (rule 125; `StudentReportExportService`) | HR has **no** export path | none | — | every HR column | owner (D-11 extension) | — | every HR "export" needs a D-11 extension (per-dataset capability, declared columns, audit), never an ad-hoc download |
| **Rule 105 / E21.2G O5** | Inventory movements | "Never record a person on a movement" | refused by contract | refused | — | staff custody of items | owner (new Inventory custody contract) | — | D20 "inventory issued to staff": **CROSS_DOMAIN**; needs an Inventory asset-custody contract, never a person on a movement |
| **Rules 92/93** | Access level / role on the staff form | job titles are never roles; the staff catalogue is closed (17 roles) | refused by contract | refused | — | role grants | reviewed release | — | WMSCB "Access Level" on the staff form: **NOT_REQUIRED** (roles come only through Staff accounts) |
| **ADR 0065 §6** | Multi-level / HR-final approval, delegation, proxy approvers | out of v1 scope | out of scope until reopened | out of scope | approvers | leave decisions | owner (ADR 0065 amendment) | — | D4 delegation, D13 delegated approval and escalation need the amendment |
| **OD-HR-1** (S-1) | Payroll checker visibility | **DECIDED 2026-11-05 (owner) and IMPLEMENTED for development**: approve needs approve + sensitive view; purpose-limited review packet; approval bound to the reviewed calculation (`docs/modules/PAYROLL.md` "Approval integrity") | — | development only; Payroll production still blocked by HRX-L4 / E45 / statutory gates | approver + `payroll_approval_reviewer`, `payroll_officer` | totals, changes, overrides, deltas, compensation changes | owner | 2026-11-05 | — |
| **E16** | Release vulnerability exceptions (not HR-specific) | `OWNER-0O-E16-2026-11-01`, 119 records, last valid day **2026-11-28** (corrects the earlier "2026-11-12", 0R-F2) | — | from 2026-11-29 every publication is refused | — | — | owner (fresh-scan successor) | review ~2026-11-20 | a successor is needed before HR.1 publication if HR.1 runs past 2026-11-28 |
| **ORG10-D01** | Directory "Current" lists future hires | OPEN UAT-MINOR, accepted for staging | — | — | HR readers | — | — | — | fixed in HR.1 (status by date) |
| **M-1** | Unlinked staff parent | recorded production prerequisite (ORG.8F §64.7) | — | policy prerequisite | approvers | Guardian links | owner | — | relevant to the D20 staff-child report |

## 2. Determinations not yet raised (needed before the feature exists)

These are **not gates yet**. No record exists, so nothing is decided. The
reference pack shows each as a feature. Each needs its own legal or privacy
determination before any build. The ids are **proposed** and are allocated
when a request is drafted.

| Proposed id | Subject | Reference evidence | Status | Blocks |
|---|---|---|---|---|
| HR-L5 | Bank / payment details and disbursement files | Account Details profile tab; bank fields | not raised | D1 bank, Payroll disbursement |
| HR-L6 | Family, dependants and statutory nominees | Family Details, Nominee Details tabs | not raised (OD-HR-12) | D1 family/dependants |
| HR-L7 | Government identifiers on the general profile (Aadhaar, passport, driving licence) | Personal Details, transport licence | not raised; D-15 discipline keeps only Payroll's encrypted statutory ids | D1 statutory identifiers outside Payroll |
| HR-L8 | Sensitive-demographic eligibility (gender, marital status) for leave rules | leave-type eligibility fields | not raised; **gender is not modelled** in HR. **HR.9 kept it closed:** the eligibility dimensions are exactly staff type, Employee Category and Department; `LeaveArchitectureGuardTest::hr_l8_no_leave_table_or_rule_can_use_a_protected_characteristic` fails on any protected column (ADR 0065 §28.3) | D10-07, D10-08 gender/marital eligibility |
| HR-L9 | Recruitment / candidate data, consent and public forms | resume register, applied-post fields | not raised (OD-HR-6) | recruitment (outside this programme's D-map) |
| HR-L10 | Disciplinary, complaint and memo records | complaints and memo screens | not raised (OD-HR-8) | XF family (deferred) |
| HR-L11 | Performance / appraisal data | staff assessment, roles & responsibilities "use in assessment" | not raised (OD-HR-7) | XT and assessment |
| HR-L12 | Background or police verification | onboarding checks | not raised | onboarding extensions |
| HR-L13 | Staff photographs | Change Profile Pic | not raised. Media also waits for a governed media pipeline (rule 115) | D1 profile photograph |

## 2a. Owner decision (not legal): OD-HR-15 — non-working days inside leave (HR.9)

- **Status:** raised by HR.9 (2026-11-03). Not decided. **Nothing is built**,
  and there is no dormant toggle.
- **Subject:** whether a School's leave policy may count holidays or weekly
  offs inside a leave (sandwich, D10-13) or next to it (prefix / suffix,
  D10-14 / D10-15). This is the configurable inclusion of D10-16 / D14-11.
- **Why a decision first:** ADR 0065 §4.7 counts working half-days only.
  That count is frozen into every approved request's days (§22.6), the
  never-negative balance and Payroll's HRX evidence (§26). Changing it
  changes what Payroll sees as leave.
- **Questions to decide:**
  1. Is it allowed at all, and per policy version only?
  2. Prospective only (never recalculating approved leave)?
  3. Does a sandwiched non-working day become a leave half for Staff
     Attendance and for Payroll's classification?
  4. What is the paid / unpaid effect?
  5. Is there an HRX-L3 / labour-law interaction for a "statutory" label?
     (No statutory label is allowed today.)
- **Then:** an ADR 0065 amendment, then a pure unit calculator and its
  tests. ADR 0065 §28.9 records the analysis.

## 2b. Owner decisions (not legal) raised by HR.10

**OD-HR-16 — consumption order for in-year expiry of carried units (D11-06).**
- **Status:** not decided. Nothing is built; `carried_expires_on` stays
  recorded, not enforced (ADR 0065 §23.8, §29.10).
- **Ambiguity:** the ledger sums one balance per account; it does not track
  credit lots. To expire "unused carried units", one must decide which units
  each leave consumed.
- **Options:**
  1. **carried-first** (oldest grant first): recommended, the common
     convention;
  2. current-year-first;
  3. pro rata.
- **Implications:** each option changes balances on the expiry date and the
  meaning of past consumption in reports. Payroll is unaffected (it reads
  leave days, never balances). Migration: none; the expiry is posted
  prospectively as an `expiry` entry with its own reconciliation.

**OD-HR-17 — proration of a joining or leaving period (D10-19, D12-03, D12-08).**
- **Status:** ADR 0065 §22.2 (*no automatic proration, anywhere*) is a final
  owner decision and stands.
- **What HR.10 already provides:** monthly accrual credits every WHOLE month
  of employment. A part month is reported (`partial_period`), never
  credited automatically. An administrator may grant exact units.
- **Options:**
  1. keep §22.2: recommended;
  2. day-based proration of a part month, with an explicit half-day
     rounding rule (needs a §22.2 amendment);
  3. a per-version first-period rule (full / none / prorated).
- **Implications:** no Payroll effect. Historical balances are never
  recalculated, because any change is prospective.

## 2c. Owner decisions recorded by HR.11 (2026-11-04)

| Id | Subject | Decision | What would reopen it |
|---|---|---|---|
| OD-HR-5 | Who approves leave as a line manager | **Decided: Option 2.** The `line_manager` add-on role holds exactly `hr.leave.approve`; it still needs the current HR.3A line; whole-School grant only (ADR 0071 §30) | — |
| ADR 0065 §6 | Delegation, substitutes, escalation, multi-level approval | **Kept excluded.** Single-stage decisions only | an ADR 0065 §6 amendment designed and approved |
| OD-HR-18 | Compensatory off (D15, D11-09/10) | **Blocked.** Staff Attendance refuses non-working halves, so no worked-day evidence exists | a Staff Attendance amendment (§24), then a typed credit rule; expiry also needs OD-HR-16 |
| OD-HR-19 | Staff permissions (D16) | **Blocked.** Time fields are HRX-L2 / OD-HR-14; without them a permission duplicates a half-day leave request | OD-HR-14, plus a permission-versus-leave contract |
| OD-HR-20 | Special leave windows (D17) | **Blocked.** Needs a precedence rule for overlaps and deduction semantics that do not reopen OD-HR-15 | an owner decision on precedence and deduction (a restriction-only "blackout" variant was offered) |

## 3. Proposed owner decision: OD-HR-14 — split HRX-L2

**Problem.** HRX-L2 currently bundles two very different data classes:
- **Timing evidence:** shift definitions, expected start/end, a manually
  entered in/out time, late or early minutes. This is Restricted
  employment data, not biometric.
- **Biometric and device evidence:** fingerprint or face templates, device
  punches, GPS location, photographs. This is Highly Sensitive.

Shifts, permissions and every clock-based attendance rule are blocked by
the timing half, though the legal risk sits in the biometric half.

**Proposal (not decided).** Split the gate:
- **HRX-L2a — timing evidence.** Needs an ADR 0065 §8 reopening (owner)
  plus a privacy note. It unlocks HR.4 shifts and the timing half of HR.6
  and HR.7.
- **HRX-L2b — biometric, device, GPS and FR.** Stays a legal/security
  gate. It blocks HR.8 only.

**Until decided:** HR.4–HR.8 stay design-only. HR.1–HR.3 do not depend on
it.

## 3a. Recorded direction for later Payroll and scheduling work (HR.1A)

These are records of direction, not approvals. No gate changes.

- **OD-HR-14: project-adopted architectural direction (2026-11-03).**
  - **Split** scheduling and clock-time *policy* from biometric, device,
    FR, GPS and provider-event *processing*.
  - **WorkSchedule owns scheduling data:** shift start and end, grace
    windows, scheduled working duration, week-offs and work calendars. That
    data does not need biometric-device authorization merely because
    biometrics may later consume it.
  - **Biometric, device, FR, GPS and provider-event processing stays
    separately gated (HRX-L2 / L2b).**
  - **Not yet approved:**
    - HRX-L2 itself remains OPEN. It is not marked approved or split by this
      record.
    - Building timing data (L2a) still needs the ADR 0065 §8 reopening and
      the privacy note.
- **OD-HR-1 / OD-HR-2: target contract for the Payroll-integrity slice.**
  - A Payroll approver receives a **purpose-limited, immutable approval
    snapshot**: the payroll inputs and results needed to make an informed
    approval decision (totals, joiners and leavers, every override and
    delta, and the compensation changes since the last run), under fresh
    MFA and audited per view.
  - The approver never gains general confidential HR-profile access.
  - Implementation: the HR.15 Payroll-integrity slice. **Not implemented**
    by HR.1A; S-1 stays OPEN.

## 4. Production effect summary

- **Explicit production refusal in code:**
  - the teacher bearer attendance API (E33);
  - certificate issuance (SIS, ADR 0081), shown for contrast.
- **Held only by absence, guard tests and role non-assignment:**
  - HRX-L1–L4;
  - statutory payroll.
- **Rule for every HR phase.** Anything built next to a gate uses an
  explicit availability gate (the `StudentCertificateAvailability`
  pattern). It never relies on "no role holds it".

## 5. Security findings (HR.EXP-0 audit, re-verified by HR.0A)

HR, Leave, StaffAttendance and Payroll code is **unchanged** between
`c5e3dd23` and `7a4068fe` (`git diff --stat` empty), so every HR.EXP-0
finding stands as recorded. HR.0A adds X-1–X-3.

| # | Severity | Finding | Status | Destination |
|---|---|---|---|---|
| **S-1** | **MEDIUM (P0)** | **The Payroll checker approves what it cannot see.** `school_admin` holds `payroll.runs.approve`/`.post` but not `payroll.compensation.sensitive.view`, and `PayrollRunSummary` carries **no figure at all** (0R-F3). A `payroll_officer` override is approved blind | **CLOSED for development (OD-HR-1, 2026-11-05)**: approval needs sensitive view, review packet, evidence binding (`PayrollApprovalReviewTest`) | — |
| S-2 | MEDIUM-adjacent, recorded residual | Compensation changes have no maker-checker (ADR 0076 §60.8a); compounds S-1 | **CLOSED for development (OD-HR-2, 2026-11-05)**: changes since the previous approval are listed in the run's review with their author, who joins the maker set (no separate request workflow, by decision) | — |
| S-3 | LOW | The HR self-administration guard does not cover notes, documents, personal details, addresses, emergency contacts or credential verification (ADR 0071 §26.13, extended) | **FIXED (HR.2A)**: every profile write (identity, personal, addresses, emergency contacts, notes, qualifications/experience/certifications incl. verify/reject, HR documents, reference codes) refuses one's own record. OD-HR-3 (self-service READ of one's own record) stays open | — |
| S-4 | LOW (latent) | Statutory identifier reveal and the ECR/TDS exports carry no MFA or no-store header | latent | before any statutory grant |
| S-5 | LOW | Fresh-MFA proof is optional at the Payroll service layer | open | with OD-HR-1 |
| S-6 | LOW | Staff attendance correction: no window, MFA or second person; cached check | recorded decision; re-review at HRX-L4 activation | HR.7 |
| S-7 | LOW | Leave adjustments and allocations need no fresh MFA; cached check | open | HR.10 |
| S-8 | LOW | Employment create (API) accepts any status; `/end` accepts a future date | **FIXED (HR.1A)**: create accepts draft / pre_joining (future) / active only; web and API `/end` go through the separation command (no future date) | — |
| S-9 | LOW | Department reparent checks for a cycle outside the transaction, without locks | **FIXED (HR.1A)**: the check runs inside the transaction under the per-School advisory lock, with database backstop `trg_hr_departments_no_cycle`; two-process test | — |
| S-10 | LOW | "One current employment" and indirect reporting cycles are application-only | **FIXED (HR.1A + HR.3A)**: `trg_employment_records_no_overlap`, `trg_employee_assignments_primary_no_overlap` (HR.1A); reporting loops and overlap by `trg_reporting_relationships_guard`, date-aware, with two-process tests (HR.3A) | — |
| S-11 | LOW | `Employee::$fillable` includes `school_id` / `record_status`; `employee_number` immutability is Eloquent-only | **FIXED (HR.1A)**: `school_id` / `record_status` not fillable; `trg_employees_identity_guard` | — |
| S-12 | LOW | `employment_type` and document `category` are free text | **FIXED**: `employment_type` closed (HR.1A); HR document `category` closed `EmployeeDocumentCategory`, database CHECK, identity documents always Highly Sensitive (HR.2A) | — |
| S-13 | LOW | `PayrollPostingService::post()` has no capability check; the demo posts as a user without the key | open | with OD-HR-1 |
| S-14 | LOW | G-9 `setManager` / manager-path Leave deadlock returns a 500 | **FIXED (HR.3A)**: the assignment pair locks are gone. A Leave decision share-locks the one line row and the manager's employment. A writer takes placement locks, the reporting advisory lock and the employment share locks (all compatible with the decision's) before the line FOR UPDATE, so one side always waits and no cycle forms (`LeaveRequestConcurrencyTest`, both orders) | — |
| S-15 | LOW | A Guardian-only account can be linked as an Employee and counts as current Staff | **FIXED (HR.1A)**: the link picker shows the account kind (staff / Guardian-only / member) | — |
| S-16 | LOW | Six ancillary profile tables and `staff_holidays` are hard-deleted at runtime | **FIXED for the six profile tables (HR.2A)**: archived with a closed reason, frozen by trigger, runtime DELETE revoked (OD-HR-13 decided: archive). `staff_holidays` remains | HR.4 (`staff_holidays`) |
| **X-1** | **LOW (new, HR.0A)** | **Cross-domain Employee reads without currency.** Timetable (`TimetableEntryController::searchTeachers()`, entry validation), Transport (driver assignment) and Visitor (host) read `App\Domain\HR\Infrastructure\Employee` directly (rule 4 drift). They filter at most on `record_status = 'active'`, so a **separated** but not archived Employee can be scheduled, assigned as a driver or named as a host. The Timetable picker also lists every non-archived Employee of the School to a Campus-scoped scheduler (names only). HR already provides `EmployeePickerReadService`, `EmploymentCoverage` and `CurrentEmployeeAssignments` | **DECISION (HR.1A): not changed**. HR.1A does not change these consumers' behaviour, so there is no new regression. The compatibility read `WorkforceAsOfReader::isCurrentlyEmployed()` exists for the migration | **HR.12 (2026-11-04): write paths FIXED** -- Timetable entry create/update, Transport driver assignment and Visitor host check-in require `EmploymentCoverage::hold()` = COVERED on the School-local today; history unchanged. **Remaining (R-HR12-1, LOW): the pickers still list separated Employees by name** -- HR.15 |
| **X-2** | **INFO (new)** | `employee_assignments.manager_assignment_id` is a live pointer. A manager change overwrites it, and history survives only in the audit ledger (field names, no values). Historical "who managed whom on date D" is not reconstructible | **FIXED (HR.3A)**: the pointer is removed. `reporting_relationships` holds dated employment-level lines answered for any date (`ReportingAsOfReader`). Pre-cut-over history stays unknown and is never fabricated | — |
| **X-3** | **INFO (new)** | `employment_records.employment_type` and `employee_category_id` are updated in place (`EmploymentService::update()`). Category history is not effective-dated | **FIXED (HR.1A)**: `employment_terms` is the append-only history; the projection is database-maintained and direct updates are refused | — |
| M-1 | recorded production prerequisite | An unlinked staff parent is not detected by beneficiary refusals | recorded | owner policy |

**HIGH: none.**

## HR.12 residuals (2026-11-04)

| Id | Severity | Finding | Disposition | Owner |
|---|---|---|---|---|
| R-HR12-1 | LOW | X-1 pickers (Timetable teacher search, Transport driver and Visitor host selects) still list separated, non-archived Employees by name; any choice is refused at write time | **CLOSED (HR.15A, 2026-11-04)**: all four searches use `DutyCandidateReader` (the read form of `EmploymentCoverage::hold()`) | — |
| R-HR12-2 | MEDIUM | Library, Inventory and Finance record no staff obligation, so their clearance is NOT_INTEGRATED (never shown as clear, never blocking) | **DEFERRED by owner decision (HR.15, 2026-11-04)**: custody, staff borrowing and staff dues are not built; Schools use an administrative clearance item | Inventory / Library / Finance owners |
| R-HR10-1 | LOW | a backdated end may leave monthly credits for months after the last day | **detected** on the separation case (Leave obligation, ATTENTION); still corrected by an ordinary adjustment that names the credit | Leave (manual) |
| R-HR12-3 | LOW | a separated person keeps School staff access until someone off-boards them; completion of the case is blocked meanwhile | by owner decision (flag, never automatic); HR.15E shows kept access as RETAINED, never clear | Identity (rule 92) |
| R-HR15-1 | LOW | Identity grants are not locked by HR: a School role granted in the same instant a case completes can be missed | **CLOSED (HR.16B):** completion holds Identity's `SchoolAccessLock` first; two-process proof `SeparationAccessCertificationTest` | — |

## HR.13 residuals (2026-11-04)

| Id | Severity | Finding | Disposition | Owner |
|---|---|---|---|---|
| R-HR13-1 | LOW | Task remarks and feedback are free text (bounded, escaped, with a fixed notice) -- a user could still type sensitive content | by owner decision; no attachments; never a rating (OD-HR-7) | HR |
| R-HR13-2 | LOW | Recurrence ignores holidays (calendar-neutral); a task can fall on a School holiday | by owner decision until WorkSchedule (OD-HR-14) | HR.4+ |
| R-HR13-3 | LOW | A missed generation day is not back-filled (today only) | by owner decision | HR |

## HR.14 residuals (2026-11-05)

| Id | Severity | Finding | Disposition | Owner |
|---|---|---|---|---|
| R-HR14-1 | LOW | Leave "as at" uses the ledger's posting clock (when an entry was recorded), not an effective date; a back-dated adjustment counts from when it was posted | **CLOSED (HR.16A, owner decision 2026-11-05):** relabelled "Leave balances as posted" (`posted_by`, basis POSTED); boundary and backdated tests | — |
| R-HR14-2 | LOW | Staff details shows the employment's status TODAY beside an as-of placement (status history is not recorded per date) | **CLOSED (HR.16A, owner decision 2026-11-05):** "Status on the date" from HR.12 evidence; "not recorded" where unknown; "Status today" separate | — |
| R-HR14-3 | LOW | Reports are browser-only: no export or print | owner decision 2026-11-05; a D-11 HR extension is a later decision | HR |
| R-HR14-4 | LOW | A bulk-loaded School has no planner statistics until autovacuum analyzes it, so the first report pages can be slow | **MEASURED (HR.16D):** 75 s → 49 ms (absentees) after ANALYZE; runbook `docs/operations/HR-OPERATIONS.md` §6; no index | Operations |
| R-HR14-5 | INFO | Rates, headcount and MIS totals stay GATE (ADR 0040, owner decision) | recorded block, not progress | Analytics |

## HR.16 residuals (2026-11-05)

| Id | Severity | Finding | Disposition | Owner |
|---|---|---|---|---|
| R-HR16-1 | LOW | The Staff Attendance month view composes every employment for its totals: ~4.3 s at 5,000 staff (local) | measured; no target agreed (recommendation in `HR-16-READINESS.md` §3) | StaffAttendance |
| R-HR16-2 | LOW | Write flows needing fresh MFA (leave approval, separation decisions, attendance correction, task review) were exercised by HTTP/service tests, not in a browser (demo personas have no MFA factor) | recorded | HR (UI) |
| R-HR16-3 | LOW | No screen-reader pass; Chromium's native date input has a segment without its own focus ring | recorded; axe clean on 22 pages | HR (UI) |
| R-HR16-4 | INFO | Legacy path-only HR documents: 0 on local DDEV; staging/production count unknown | pre-staging inventory | HR + Documents |
