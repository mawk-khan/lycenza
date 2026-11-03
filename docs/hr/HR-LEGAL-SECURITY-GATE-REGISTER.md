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
| **OD-HR-1** (S-1) | Payroll checker visibility | **OPEN — owner design decision** (analysis: `HR-EXP-0R-REFERENCE-RECONCILIATION.md` §6) | — | blocks Payroll production | `school_admin` (approver), `payroll_officer` | per-employee results, overrides, deltas | owner | — | blocks every Payroll-touching HR phase (HR.15 Payroll items) |
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
| HR-L8 | Sensitive-demographic eligibility (gender, marital status) for leave rules | leave-type eligibility fields | not raised; **gender is not modelled** in HR | D10 gender/marital eligibility |
| HR-L9 | Recruitment / candidate data, consent and public forms | resume register, applied-post fields | not raised (OD-HR-6) | recruitment (outside this programme's D-map) |
| HR-L10 | Disciplinary, complaint and memo records | complaints and memo screens | not raised (OD-HR-8) | XF family (deferred) |
| HR-L11 | Performance / appraisal data | staff assessment, roles & responsibilities "use in assessment" | not raised (OD-HR-7) | XT and assessment |
| HR-L12 | Background or police verification | onboarding checks | not raised | onboarding extensions |
| HR-L13 | Staff photographs | Change Profile Pic | not raised. Media also waits for a governed media pipeline (rule 115) | D1 profile photograph |

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
| **S-1** | **MEDIUM (P0)** | **The Payroll checker approves what it cannot see.** `school_admin` holds `payroll.runs.approve`/`.post` but not `payroll.compensation.sensitive.view`, and `PayrollRunSummary` carries **no figure at all** (0R-F3). A `payroll_officer` override is approved blind | **OPEN — OD-HR-1** (options and recommendation: `HR-EXP-0R-REFERENCE-RECONCILIATION.md` §6) | before any Payroll work in HR.15 and before Payroll production |
| S-2 | MEDIUM-adjacent, recorded residual | Compensation changes have no maker-checker (ADR 0076 §60.8a); compounds S-1 | re-review with S-1 (OD-HR-2) | with OD-HR-1 |
| S-3 | LOW, open owner decision | The HR self-administration guard does not cover notes, documents, personal details, addresses, emergency contacts or credential verification (ADR 0071 §26.13, extended) | OD-HR-3 | HR.2 |
| S-4 | LOW (latent) | Statutory identifier reveal and the ECR/TDS exports carry no MFA or no-store header | latent | before any statutory grant |
| S-5 | LOW | Fresh-MFA proof is optional at the Payroll service layer | open | with OD-HR-1 |
| S-6 | LOW | Staff attendance correction: no window, MFA or second person; cached check | recorded decision; re-review at HRX-L4 activation | HR.7 |
| S-7 | LOW | Leave adjustments and allocations need no fresh MFA; cached check | open | HR.10 |
| S-8 | LOW | Employment create (API) accepts any status; `/end` accepts a future date | **FIXED (HR.1A)**: create accepts draft / pre_joining (future) / active only; web and API `/end` go through the separation command (no future date) | — |
| S-9 | LOW | Department reparent checks for a cycle outside the transaction, without locks | **FIXED (HR.1A)**: the check runs inside the transaction under the per-School advisory lock, with database backstop `trg_hr_departments_no_cycle`; two-process test | — |
| S-10 | LOW | "One current employment" and indirect reporting cycles are application-only | **FIXED for employment and primary assignments (HR.1A)**: `trg_employment_records_no_overlap`, `trg_employee_assignments_primary_no_overlap`, with two-process tests. **Indirect reporting cycles remain application-only** | HR.3 (reporting) |
| S-11 | LOW | `Employee::$fillable` includes `school_id` / `record_status`; `employee_number` immutability is Eloquent-only | **FIXED (HR.1A)**: `school_id` / `record_status` not fillable; `trg_employees_identity_guard` | — |
| S-12 | LOW | `employment_type` and document `category` are free text | **`employment_type` FIXED (HR.1A)**: closed catalogue, database CHECK. Document category remains free text | HR.2 (documents) |
| S-13 | LOW | `PayrollPostingService::post()` has no capability check; the demo posts as a user without the key | open | with OD-HR-1 |
| S-14 | LOW | G-9 `setManager` / manager-path Leave deadlock returns a 500 | recorded | HR.3 |
| S-15 | LOW | A Guardian-only account can be linked as an Employee and counts as current Staff | **FIXED (HR.1A)**: the link picker shows the account kind (staff / Guardian-only / member) | — |
| S-16 | LOW | Six ancillary profile tables and `staff_holidays` are hard-deleted at runtime | open (OD-HR-13) | HR.2 / HR.4 |
| **X-1** | **LOW (new, HR.0A)** | **Cross-domain Employee reads without currency.** Timetable (`TimetableEntryController::searchTeachers()`, entry validation), Transport (driver assignment) and Visitor (host) read `App\Domain\HR\Infrastructure\Employee` directly (rule 4 drift). They filter at most on `record_status = 'active'`, so a **separated** but not archived Employee can be scheduled, assigned as a driver or named as a host. The Timetable picker also lists every non-archived Employee of the School to a Campus-scoped scheduler (names only). HR already provides `EmployeePickerReadService`, `EmploymentCoverage` and `CurrentEmployeeAssignments` | **DECISION (HR.1A): not changed**. HR.1A does not change these consumers' behaviour, so there is no new regression. The compatibility read `WorkforceAsOfReader::isCurrentlyEmployed()` exists for the migration | HR.15 (route the three pickers and validations through HR's currency readers) |
| **X-2** | **INFO (new)** | `employee_assignments.manager_assignment_id` is a live pointer. A manager change overwrites it, and history survives only in the audit ledger (field names, no values). Historical "who managed whom on date D" is not reconstructible | architecture gap (P1) | HR.3 |
| **X-3** | **INFO (new)** | `employment_records.employment_type` and `employee_category_id` are updated in place (`EmploymentService::update()`). Category history is not effective-dated | **FIXED (HR.1A)**: `employment_terms` is the append-only history; the projection is database-maintained and direct updates are refused | — |
| M-1 | recorded production prerequisite | An unlinked staff parent is not detected by beneficiary refusals | recorded | owner policy |

**HIGH: none.**
