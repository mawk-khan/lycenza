# SIS 1.1 — Current-state architecture, functional and gap audit (2026-10-26)

Status: **audit record, documentation only.** First checkpoint of the Student
Information System (SIS) programme after the Organization local closure
(ADR 0076 §82).
- Audited `main` = `origin/main` = `6d1b5640`. Nothing was built.
- Every classification below was confirmed in code: routes, controllers,
  services, migrations, Vue pages, seeders and tests.
- Rendered phone-width evidence comes from the 2026-10-16 reassessment; the
  other UX notes are code inspection.
- Historical records are not rewritten. Where one is stale, this record says
  so (§2.3).

**Headline.** The SIS is functionally usable end to end on local DDEV, with
limitations. **SIS Local Completion Score: 82/100.**
- 4 Must-Complete gaps, no S0/S1. They are correction and validity paths,
  not missing workflows.
- 9 Should-Complete groups (36 items).
- 13 decision- or legal-blocked items.

## 1. Baseline

| Item | Value |
|---|---|
| HEAD / `main` / `origin/main` | `6d1b5640` (branch `main`), clean tree, one worktree |
| Stash | `phase-5d3-preflight` (preserved) |
| Latest CI | `CI` on `6d1b5640`: success (docs lane); release qualification run 40327776824 on `66be83a6`: success |
| Latest full regression | exact-SHA `66be83a6`: 9,585 cases, 9,584 passed, 1 ESI-12 skip, 0 failures (counter 0/5) |
| DDEV | `lycenza` OK; `https://lycenza.ddev.site` serves 200 (Docker Desktop engine, ORG.10 data) |
| Known local caveat | **STG-04:** CLI `ddev exec` still reaches the stale WSL docker-ce database. Reconcile it before stateful local SIS work (ORG-LOCAL-CLOSURE §7). |
| Update (SIS 1.2, 2026-10-26) | **STG-04 resolved.** Local stateful browser proof is now reliable: one engine (Docker Desktop), one DDEV database, `ddev env-check` passes. No other conclusion of this audit changes. |
| Update (SIS 3.1, 2026-10-26) | **MC-1 closed:** applicant and application correction (state-aware), decision-note correction and discarding an unused draft, browser-proven on DDEV (`docs/modules/ADMISSIONS.md` §6.1). Must-Complete remaining: 3 (MC-2, MC-3, MC-4). The 82/100 score is not re-scored here. |

## 2. Historical SIS work already completed

### 2.1 Checkpoints reconciled (49)

| Group | Checkpoints | State |
|---|---|---|
| Phase 0D / Phase 1 (1A–1H) | Academic Structure (`6edbe2d8`); Student/Guardian identity (1A); Enrollment + rollover (1B, `4ebbe23b`); subject enrollment (1C); Admissions (1D, `ed87ca83`); lifecycle architecture (1E-0); electives, subject rollover, Phase 1 close (1F–1H) | COMPLETE |
| Attendance / privacy | 0H.2 Student class Attendance; 0H.4D-P2 processing authorizations (ADR 0038) + S5 lock order; ADR 0041 encrypted Guardian contact digest; TCH.4 teacher attendance (E33 controls) | COMPLETE (E33 production-gated) |
| Retention / fees / Guardian | Student retention expiry; OPF.3 admission fee intent at conversion; Guardian unlink 409 (`cbb672b8`) | COMPLETE |
| Portal | POR.0–POR.5 Guardian portal (`9dc5ca17`) | COMPLETE (local; production E46) |
| Web surfaces | SWC.2 Academic Structure web | COMPLETE |
| V11 | V11.0 / V11.7 contract and closure; V11.2 SIS search, reports, history (`80aa6bda`); V11.5 Data Health (`1d53f672`); V11.3 closed-year placement (`8756b16a`); X-01..X-04; SIS benchmark delta + D-14..D-17 | COMPLETE |
| Local UAT | Local UAT + reassessment; UAT-C1, C2 (rollover), C3, C4 (search/a11y), C5 (guide), C6 | COMPLETE |
| Local completion | LC audit (`9f7fc3ba`); LC-1 (no 500s), LC-2 (year boundary), LC-4 (SB-03b) | COMPLETE |
| ORG SIS parts | ORG.7D-1..4, ORG.7F-0..3 (Campus SIS reads, writes, allocator, onboarding, transfer, placement freeze), ORG.8A-R1, ORG.8E (lifecycle reasons, MFA, events), ORG.8G (lifecycle DB integrity), ORG.9C-1 (register status, Student attendance history), ORG.10 SIS scenarios | COMPLETE |
| Open / partial | LC-5 (M5 corrections), LC-7 (Data Health exits), LC-10 (phone/a11y subset), V11.1 (people-count, legally blocked), Student portal (E46); partial: LC-6 (attendance), LC-8 (small UX) | see §2.2 |

### 2.2 Counts

| Completed | Partially complete | Superseded | Still open |
|---|---|---|---|
| **41** | **2** (LC-6, LC-8) | **1** (SB "post-staging P2" sequencing, by the LC audit) | **5** (LC-5, LC-7, LC-10, V11.1, Student portal) |

Documentation-only follow-ups are counted with their parent.

### 2.3 Stale entries in earlier records (recorded, not rewritten)

- **Built by ORG.9C-1 (`8a5cbb72`, ADR 0076 §69):** ATT-07 (one Student's
  attendance over a range) and SB-01 (registers not taken). The LC audit
  (2026-10-17) §J/§K still lists both as OPEN.
- **Addressed by ORG.8E:** STU-14 (confirmation on Mark inactive). ATT-06 is
  half addressed: a closed reason is now required, but there is still no
  confirmation step (§10).
- **LC-6 and LC-7 never ran as slices.** No commit after LC-4 `f183c851`
  carries an LC tag.

## 3. Current SIS architecture

- **Domains.** `app/Domain/Students` owns Students, enrollments
  (placements), lifecycle, rollover and the Student search. `Guardians` owns
  Guardians and contacts. `Admissions` owns applicants and applications.
  `Attendance` owns Student attendance. Portal, Data Health and Documents
  sit beside them.
- **Placement is the authoritative academic membership** (`student_enrollments`).
  - Year, Campus and Grade are copied from the Section and bound to it by a
    composite FK.
  - Placement context is frozen (`student_enrollments_freeze_placement`).
  - One active placement per Student per year.
  - Roll number is unique per Section.
  - Rows are born `active`; `ends_on` stays within the year; terminal
    placements carry lifecycle evidence.
- **SIS consumes Academic Structure; it does not duplicate it.**
  `CurrentAcademicYearResolver`, Sections and SubjectOfferings are owned by
  Academic Structure (Organization setup).
  - The direction is allowed by DOMAIN-MAP (Students → Academic Structure).
  - About 32 SIS files read Academic Structure Eloquent models directly
    rather than through its Application services — a literal rule 4 drift.
    It is not a reverse dependency, and it is recorded as an observation,
    not a defect.
  - `ElectiveGroupService` reading `StudentSubjectEnrollment` is a
    documented permanent exception.
- **Integrity is split across three layers:**
  - **Database:** composite same-School FKs, RLS (`TenantRls`), the freeze
    and lifecycle triggers, status CHECKs, and the year-date freeze.
  - **Services:** active Student/Section/Campus (`assertPlaceable`), fresh
    authority under locks, closed reasons with fresh MFA, and the Campus
    transfer blockers.
  - **Data Health:** reports only, never repairs.
- **Campus:** reads go through `StudentAccess`. Campus writes are limited to
  name edits, atomic onboarding and transfer (rule 101). All other SIS writes
  are declared whole-School.

## 4. Student Master

| Capability | Classification | Evidence | Gap |
|---|---|---|---|
| Create (identity) | COMPLETE — IMPROVEMENT POSSIBLE | `app.students.create/store`, `StudentController.php:62-82`, `Students/Create.vue` | Date of birth has no "not in the future" rule; onboarding has one (`StudentDirectoryController.php:205`) |
| Atomic onboarding (Campus / whole School) | COMPLETE | `CampusStudentOnboardingService.php:78` | — |
| Student number | PARTIAL | Unique per School (DB); clean 422 | `StudentNumberAllocator` is used only by onboarding. Create and admission conversion take a typed number, so one School has two numbering rules |
| Names | COMPLETE | `Students/Edit.vue`; Campus `EditNames.vue` → `StudentNameEditService` | — |
| Status (inactive / active) | COMPLETE | `StudentService::changeStatus` with closed reason, confirmation, fresh MFA and lifecycle event (ORG.8E) | — |
| Profile (identity, placement, history, Guardians, account link) | COMPLETE | `Students/Show.vue` | — |
| Editable attributes | COMPLETE — IMPROVEMENT POSSIBLE | Browser (whole School): number, names, DOB. Browser (Campus): names. API: same set. School and id: immutable | No raw-code or UUID display (reason labels fall back to the code but every code has a label) |
| Gender / statutory demographics | LEGAL / PRIVACY BLOCKED | D-15 / SB-05 (ADR 0075 §22) | — |
| Admission number, government IDs, address | DEFERRED / POST-V1 | `STUDENT-GUARDIAN-IDENTITY.md` | — |
| Current academic context | COMPLETE | Directory column and profile, with `enrollments.view` | — |
| History | COMPLETE — IMPROVEMENT POSSIBLE | `app.students.history`, `StudentActivityHistoryService` | A Guardian event shows "Guardian" without a name; no reason label |
| Inactive Students | COMPLETE | Directory status filter (Campus directory: active only, by design) | — |
| Duplicate prevention | PARTIAL | DB-unique Student number only | No same-name-plus-DOB warning at create or conversion (UAT-STU-12, ADMISSIONS §10) |
| Authorization / isolation | COMPLETE | `capability:students.view/manage`, `StudentAccess`, `StudentAdminUiTest`, `StudentGuardianRlsIsolationTest` | — |

## 5. Guardians and relationships (ADR 0041 preserved)

| Capability | Classification | Evidence | Gap |
|---|---|---|---|
| Create | COMPLETE — IMPROVEMENT POSSIBLE | `app.guardians.store`; "Create new guardian" on Add-Guardian | Contact is added on a second visit; no duplicate check |
| Link, several Guardians, optional Guardian, relationship type and flags | COMPLETE | unique `(school_id, student_id, guardian_id)`, partial unique primary; `RelationshipType` labels | — |
| Contact information (email, mobile) | **PARTIAL** | add, set primary, deactivate (`routes/web.php:707,748-749`) | **No edit and no reactivate.** The unique key `(school_id, guardian_id, type, lookup_hash)` includes inactive rows, so a mistakenly deactivated contact cannot be re-added. **SQL is the only correction** |
| Edit names, status | COMPLETE — IMPROVEMENT POSSIBLE | `app.guardians.update/status` | Status toggle has no confirmation or reason |
| Unlink / relink | COMPLETE | hard delete; retained evidence → inline refusal (409 on API) | The `primary` concurrency error is not shown on the page |
| Self-administration refusal | COMPLETE — IMPROVEMENT POSSIBLE | `GuardianSelfAdministrationGuard` | The explanatory message is replaced by a generic 403 |
| Account link, portal invitation, off-boarding | COMPLETE (portal production-gated, E46) | `account-link.*`, `account-invitation.*`, `portal-offboard` | — |
| Siblings | DERIVABLE | Guardian page lists linked Students | No sibling block on the Student profile (PV1-17 governs family-contact lists) |
| Privacy lookup | COMPLETE (by design) | exact-match HMAC contact lookup; no partial contact search (ADR 0041, SRCH-02) | **Not reopened** |
| Guardian name search | PARTIAL | `GuardianController.php:52-53` | First OR last name only, so "Ravi Kumar" finds nothing. `%`/`_` not escaped. Results show only names, so namesakes cannot be told apart |

## 6. Admissions

| # | Step | Classification | Evidence / gap |
|---|---|---|---|
| 1 | Application creation | COMPLETE — IMPROVEMENT POSSIBLE | Staff-entered, two steps (`Applicants/Create.vue`, `Admissions/Create.vue`). Inactive Campuses are offered and then refused. Public form DEFERRED |
| 2 | Applicant details | COMPLETE — IMPROVEMENT POSSIBLE | Names + DOB by design; DOB printed as raw ISO on Show |
| 3 | Guardian/contact | COMPLETE — IMPROVEMENT POSSIBLE | At conversion (none / create / link). Earlier capture deferred (ADMISSIONS §9) |
| 4 | Review / status machine | COMPLETE | draft→submitted→accepted/rejected/withdrawn; accepted→converted. Service-enforced. DB: provenance CHECK, `one_open_per_context`, terminal guard |
| 5 | Offer / acceptance | DERIVABLE | `accepted` is the offer; no family response step |
| 6 | Decision | COMPLETE | accept/reject with note. Note correction promised (§6), not built |
| 7 | Conversion to Student | COMPLETE — IMPROVEMENT POSSIBLE | `AdmissionConversionService` (row lock, `AlreadyConverted`, concurrency test). Typed Student number; no duplicate-child warning |
| 8 | Initial placement | COMPLETE | compatible active Sections only; `PlacementTargetUnavailable` checks |
| 9 | Finance handoff | COMPLETE | fee intent recorded at conversion (append-only); Charge by fee run (OPF.3) |
| 10 | Withdraw / reject / cancel | PARTIAL | A draft cannot be withdrawn or discarded, and it blocks a new application for the same context (`one_open_per_context`). Workaround: submit, then withdraw |
| 11 | Search / filter | COMPLETE — IMPROVEMENT POSSIBLE | name, status, year, Campus, grade. No date filter; `hasFilters` not reactive |
| 12 | **Editing after creation** | **MISSING** | `ApplicantService` has only `create`. No applicant or application update route in web or API (the only admissions PUT is the fee head, `web.php:1115`). **M5 / LC-5 / PV1-18 / UAT-ADM-14 still open** |

Authorization: `admissions.view` / `.manage` (school_admin, principal,
admissions_officer). Application lifecycle is Campus-adopted; applicants,
conversion and Guardian search are declared whole-School. Isolation:
`AdmissionApplicationIntegrityTest` (raw RLS), service, read and HTTP
foreign-School tests, and `AdmissionsCapabilityTest`.

## 7. Enrollment / Placement

| Capability | Classification | Evidence / gap |
|---|---|---|
| Initial placement | **PARTIAL** | `StudentEnrollmentService::enroll()` (:118). **`starts_on` is only validated as a date** (web `StudentEnrollmentController.php:80`, conversion `AdmissionApplicationController.php:293`). `assertPlaceable` does not check the year's dates or status. `starts_on` is then frozen, and an end must lie in `[starts_on, year end]`. **A start after the year's end makes a placement that can never be ended or corrected** |
| Current placement | COMPLETE | active placement in the active year (`StudentEnrollmentReadService.php:71-74`); does not consider `starts_on` |
| History, end date | COMPLETE | `historyFor()`, `Students/History.vue`, `end_within_year` |
| Section transfer (same Campus) | COMPLETE — IMPROVEMENT POSSIBLE | `transferPlacement()` ends the source and inserts the target. Accepts a future date |
| Grade progression | COMPLETE | through rollover only; mid-year grade change refused |
| Campus transfer | COMPLETE — IMPROVEMENT POSSIBLE | `CampusStudentTransferService` + blockers. The **whole-School Enrollment transfer can also change Campus without `StudentTransferBlocker`** (Hostel, Transport, elective) — declared §47.3 behaviour, still a risk |
| Inactive / closed-year placement | COMPLETE | `ClosedYearPlacementClosureService`, `ClosedYearPlacements.vue`, Data Health |
| Correction of a wrong placement | PARTIAL | cancel (`entered_in_error`/`duplicate_placement`) and re-enroll; transfer for a wrong Section. No roll-number correction (no writer, §47.1). Wrong `starts_on` not correctable |
| Section picker (ENR-01) | COMPLETE — IMPROVEMENT POSSIBLE | `sectionOptions()` sorts the newest year first, so the draft next year is listed before the active year (`StudentEnrollmentController.php:241`) |
| Authorization / isolation | COMPLETE | `capability:enrollments.manage`, fresh authority under lock, `StudentEnrollmentUiTest`, `StudentEnrollmentIntegrityTest` (raw RLS), `PlacementFreezeTest` |

## 8. Student lifecycle (D-13 not reopened)

| Capability | Classification | Evidence |
|---|---|---|
| Active ↔ inactive (both directions = reactivation) | COMPLETE | closed reason, MFA, event, lock (ORG.8E/8G) |
| Withdraw / cancel placement | COMPLETE | closed `StudentLifecycleReason`, `StudentLifecycleAssuranceTest`, `StudentLifecycleConcurrencyTest` |
| Transfer out | COMPLETE (as a reason) | `transferred_out` |
| Promote / repeat / exclude / manual review | COMPLETE | rollover item decisions |
| Effective date, request/approval | DEFERRED / POST-V1 | D-13; ADR 0076 §65.3 |
| Graduation status | DEFERRED / POST-V1 | `completed_studies` reason only |

Deactivating a Student does not end their placements; Data Health reports
it (`InactiveStudentWithActiveEnrollmentCheck`).

## 9. Academic Year and rollover

| Capability | Classification | Evidence / gap |
|---|---|---|
| Rollover preview / dry run | COMPLETE | `EnrollmentRolloverDryRunService` (year order, Student/Section/Campus active, roll-number conflicts) |
| Bulk decisions | COMPLETE — IMPROVEMENT POSSIBLE | promote/exclude, ≤100, version-guarded; repeat/manual review per item only |
| Stale versions | COMPLETE | `StaleRolloverDecisionsException`, version match at execution |
| Target-year validation | **PARTIAL** | "differs" and "starts later" only (`EnrollmentRolloverPlanService`). **The target year's status is never checked** (only the target Section's); no test |
| Execution, resume, activation separation | COMPLETE | batches, three concurrency tests, never activates or closes a year; "Next steps" on completion |
| Browser workflow | COMPLETE | `EnrollmentRollovers/*` pages |
| Year name / code; term name / code | COMPLETE | web + API rename |
| **Draft year start/end dates** | **MISSING** | No path. The `academic_years_freeze_dates` trigger refuses any date change for **every** status, years cannot be deleted, and the overlap check covers every status. **A mistyped draft year also blocks creating the correct one.** Needs a narrow schema exception (draft, no dependants) + ADR note. M5's "no schema" statement is wrong for years |
| **Term dates / sequence** | **MISSING** | rename only; no delete; terms are referenced by exams, curriculum delivery and fee structures. No freeze trigger (service change + dependant check) |
| Term creation in a closed year | COMPLETE — IMPROVEMENT POSSIBLE | `AcademicTermService` has no year-status check |
| Year lifecycle (no reopen) | COMPLETE (deliberately immutable) | `AcademicYearActivationDateTest` |

**Ownership.** Year and term corrections belong to Academic Structure
(Organization setup), not SIS. They are included in the SIS roadmap (5.1)
**only** because a wrong draft year blocks SIS year transition with no
browser or API recovery. The change stays in the Academic Structure module.

## 10. Attendance

| Capability | Classification | Evidence / gap |
|---|---|---|
| Register per timetable entry and date | COMPLETE | `AttendanceController::take`, `AttendanceSubmissionService::submit` |
| Office/admin marking | COMPLETE — IMPROVEMENT POSSIBLE | `/app/attendance/take` within Campus scope; no mark-all (ATT-08 decision) |
| Teacher marking | COMPLETE | `/app/my-attendance/*`, `attendance.teacher` + `mfa-page`, ownership, audited reads |
| Period / subject snapshot, overlap refusal | COMPLETE | session snapshot; `OverlappingAttendanceSessionException` |
| School-local date | COMPLETE | `SchoolTimezone`; `AttendanceSchoolDateTest` |
| Future-date rule | PARTIAL | Server refuses (`assertNotInFuture`). The Take date input has no `max` (`Take.vue:120`), so future weekdays list classes that fail only on submit (ATT-09 residual) |
| Active-year scoping | COMPLETE | LC-2 |
| Correction | COMPLETE — IMPROVEMENT POSSIBLE | closed absence-neutral reasons, DB evidence row (ORG.8E). No confirmation step (ATT-06 residual). Register shows "(corrected)" only |
| Exceptions (absent/late/excused) | COMPLETE | `/app/attendance/exceptions` |
| Register status | COMPLETE — IMPROVEMENT POSSIBLE | `/app/attendance/registers` (SB-01 closed). Rows do not link to Take or the register |
| Student attendance history | IMPLEMENTED BUT POORLY EXPOSED | `/app/attendance/students/{student}` (ATT-07 closed). Linked only from Exceptions rows, not from the Student profile or Registers. A Student with no in-scope record gets a 404 |
| Register heading | COMPLETE — IMPROVEMENT POSSIBLE | `Show.vue:115` prints `sectionCode` (ATT-08 heading residual) |
| Rates / summaries | LEGAL / PRIVACY BLOCKED | by design (ADR 0040, `DailyOperationsReadModelGuardTest`) |
| Daily (non-period) attendance, mark-all | DECISION BLOCKED | PV1-19 / ATT-08 owner decision (ATTENDANCE.md §5) |

**Reconciliation**

| Item | Status |
|---|---|
| ATT-06 | Partly superseded by ORG.8E (reason required); confirmation still absent → SHOULD |
| ATT-07 | FIXED (ORG.9C-1); exposure → SHOULD |
| ATT-08 | Labels fixed (C4). Heading residual → SHOULD. Mark-all → DECISION |
| ATT-09 | Other-year fixed (LC-2). Future date in UI → SHOULD |
| SB-01 | FIXED (ORG.9C-1) |
| SB-02 | OPEN → SHOULD (LC-7) |

## 11. Teacher SIS (local functionality; E33 is production-only)

| Capability | Classification | Evidence / gap |
|---|---|---|
| Assigned classes | PARTIAL | only as the day's owned classes on My Attendance (plus curriculum, LMS and exam pages); no "My classes" list |
| Roster | PARTIAL | roll number and name inside Take only (audited) |
| Attendance | COMPLETE | §10 |
| Student view | COMPLETE (by design) | the `teacher` role holds only `attendance.teacher`; no Student directory |
| Timetable relation | COMPLETE | ownership from TeachingAssignment; timetable is never authority |

E33/TCH-L1 is approved with conditions. Production `teacher` grants wait on
re-verification and E21; this is not a local gap.

## 12. Search and operational visibility

| Capability | Classification | Evidence |
|---|---|---|
| Full name, any token order; partial Student number | COMPLETE | `StudentSearch` (C4 `78b68d6d` intact; used by the directory, enrollment directory and 12 pickers) |
| Status / inactive | COMPLETE | directory filter |
| Grade / Section / Campus / year | COMPLETE — IMPROVEMENT POSSIBLE | on `/app/enrollments`, not on the Student directory (two screens) |
| Guardian name search | PARTIAL | §5 |
| Global search | DEFERRED | D-12, not proposed |

## 13. Portal

| Portal | Classification | Notes |
|---|---|---|
| Guardian portal (inbox, calendar, attendance, fees, conversations) | COMPLETE locally | `Pages/App/Portal/*`, `PortalAvailability` = local/testing only. Production-gated (POR-L1/E46, E21, E30–E32), not a local defect |
| Guardian portal UX | COMPLETE — IMPROVEMENT POSSIBLE | School-wide announcements do not reach the Guardian inbox (ADR 0070 §28.7); an off-boarded Guardian-only membership cannot be reinstated |
| Student portal | DEFERRED | not built (ADR 0070 §28.12, POR-L1 Q16–Q21) |

## 14. Student documents

**DEFERRED / POST-V1** (OD-SWC-2, PV1-15, E21).
- `documents` has `student_id`/`guardian_id` columns with same-School FKs.
- `DocumentService` and `DocumentListingService` refuse the `student` and
  `guardian` owner types (`DocumentOwnerTypeNotSupportedException`).
- No Student document UI, categories or required-document rules exist.
- Certificates (PV1-14) are DEFERRED.

## 15. Data Health

Eight registered checks (`PlatformServiceProvider.php`, `/app/data-health`):
1. no active academic year;
2. active year without Sections;
3. active placement in a closed year;
4. active placement in an inactive Section;
5. inactive Student with an active placement;
6. active Student without a current placement;
7. Section without schedule;
8. assignment on inactive configuration.

Tests: `DataHealthChecksTest`, `DataHealthUiTest`. There is no Attendance,
Fees or Guardian check.

| Candidate | Status |
|---|---|
| SB-02: attendance after a back-dated exit | OPEN → SHOULD |
| SB-03a: Charge period after exit | OPEN, **Fees-owned** check (coordinate; SIS supplies the lifecycle facts) → SHOULD |
| DH-12: next-year placement read as "no class this year" | OPEN (`ActiveStudentWithoutCurrentPlacementCheck.php:40,72`) → SHOULD |
| DH-18: "C (6C)" label | OPEN (`AssignmentOnInactiveConfigurationCheck.php:65`, also `SectionWithoutScheduleCheck.php:53`) → SHOULD |
| Guardian checks D-05/D-06 | DEFERRED (owner decisions) |

## 16. Reporting

| Report | Classification |
|---|---|
| Student directory (incl. inactive) | COMPLETE |
| Admissions list | COMPLETE |
| Placements directory (year, Campus, Grade, Section, status) | COMPLETE |
| Closed-year placements | COMPLETE |
| Per-Student movement history | COMPLETE |
| Attendance: registers, exceptions, Student history | COMPLETE |
| School-wide movement report (transfers/withdrawals by date) | DERIVABLE |
| Attendance rates, people counts (V11.1) | LEGAL / PRIVACY BLOCKED, not reopened |
| Exports / nominal roll | DEFERRED (D-11 / PV1-16) |
| Sibling and family-contact list | DEFERRED (PV1-17, ADR 0041 review) |

## 17. Browser-only operability

| Workflow | Needs today | Class |
|---|---|---|
| Correct an applicant's name/DOB, or a draft/submitted application's year, Campus or grade | nothing (no path at all) | **MUST FIX** |
| Discard a mistaken draft application | submit-then-withdraw workaround | SHOULD (with 3.1) |
| Revive a mistakenly deactivated Guardian contact | **SQL** | **MUST FIX** |
| Correct a draft academic year's dates (or replace the year) | **SQL** as the database owner, bypassing the `academic_years_freeze_dates` trigger | **MUST FIX** (Academic Structure-owned) |
| Correct term dates | **SQL** | **MUST FIX** (same checkpoint) |
| Repair a placement whose start date lies outside its year | **SQL** as the database owner (`starts_on` is frozen) | **MUST FIX** (prevent at entry) |
| Correct a roll number | cancel + re-enroll | SHOULD (decide correction vs re-enroll) |
| Reach a Student's attendance history from the profile | navigate via Exceptions | SHOULD |
| Student documents, certificates, exports | not built | DEFERRED |
| Platform/School lifecycle, root provisioning | console by design | deliberate administrator/platform operation |

No ordinary SIS workflow is **API-only**: the missing paths are missing in
the API too.

## 18. UX, accessibility and phone width

- **Error handling (LC-1 standard).** No 500 path was found on SIS forms.
  `WebDomainRefusalResponder` turns domain refusals into form errors and
  hides UUID-bearing messages. Malformed ids return 404 (`WebRouteParameters`).
  Residuals:
  - the Admissions transition error shows raw status keys;
  - the Guardian self-administration message is hidden;
  - the `primary` concurrency error is not shown.
- **Labels (LC-3 standard).** No UUIDs are rendered. Residuals:
  - the attendance register heading uses `sectionCode`;
  - Data Health "C (6C)" labels (DH-18);
  - applicant DOB printed as raw ISO;
  - Student history Guardian events have no name.
- **Phone width.** Rendered evidence from 2026-10-16
  (`LOCAL-UAT-READINESS-REASSESSMENT-2026-10-16.md`): Students, attendance,
  admissions, rollover and the portal fit at 390 px.
  - Code inspection flags two pages with tables lacking `overflow-x-auto`,
    each with four radios or buttons per row: `Attendance/Take.vue` and
    `Attendance/Show.vue`.
  - These must be re-verified in a browser (9.1).
  - SIS pages use a 32 px gutter, not the 16 px norm.
- **Accessibility:**
  - The a11y lint covers only SWC files (`eslint.config.js`); Students,
    Guardians, Admissions and Attendance pages are outside it (S-16).
  - `window.confirm` remains in `Students/Show.vue` (2) and
    `Guardians/Show.vue` (4).
  - Radio groups lack a fieldset/legend in Take and the conversion Guardian
    mode.
  - Unlabelled Guardian name inputs in conversion create mode.
  - Missing middle/last-name errors on `Applicants/Create.vue`.
  - The Guardian search list is not a `SearchCombobox`.
  - These reconcile into LC-10's SIS subset; nothing new outside it.

## 19. Security and tenancy

- **Isolation is proven at three layers** for Student, Guardian, Admissions,
  placement, lifecycle, rollover, Attendance and search:
  - raw-SQL RLS: `StudentGuardianRlsIsolationTest`,
    `AdmissionApplicationIntegrityTest`, `StudentEnrollmentIntegrityTest`,
    `EnrollmentRolloverIntegrityTest`, `LifecycleEvidenceRlsIsolationTest`,
    `GuardianContactIntegrityTest`, account-link and invitation RLS tests;
  - service and Eloquent tests;
  - HTTP foreign-School 404 tests: `StudentAdminUiTest`,
    `GuardianAdminUiTest`, `AdmissionsUiTest`,
    `HistoricalPlacementClosureUiTest`,
    `StudentSearchTest::search_never_reaches_another_school…`.
- All of these were green in the exact-SHA regression on `66be83a6`.
- Gap (test only): no Eloquent/HTTP-layer cross-School test for
  `student_lifecycle_events` (raw RLS exists).
- 404 information hiding is intact.
- **Roles:**

| Role | SIS access |
|---|---|
| School Admin | everything, including rollover manage and Data Health |
| Principal | Students, Guardians, Admissions, enrollments and Attendance manage; rollover **view** only |
| Teacher | `attendance.teacher` only: owned classes; no Student directory, by design |
| Admissions officer | `admissions.view/manage` |
| Front office | no SIS capability (visitors, by ADR 0071 §4.7) |
| Guardian | portal (local only) |
| Student | no portal (deferred) |

  No missing workflow access was found; each boundary above is intentional.
- **Legal/privacy blocks are enforced in code:**
  - `PortalAvailability` (local/testing only);
  - Student document owner refused;
  - no attendance rates (guard test);
  - no Guardian contact search;
  - E33 production gating.

## 20. M5 reconciliation

| M5 component | Owner | SIS roadmap? | State |
|---|---|---|---|
| Edit applicant / application (ADM-14, PV1-18) | Admissions (SIS) | **yes, 3.1** | OPEN |
| Discard draft application; correct decision note | Admissions (SIS) | yes, 3.1 | OPEN |
| Draft academic-year date correction | Academic Structure | **yes, 5.1 (SIS-blocking dependency)** | OPEN; needs trigger exception + ADR note |
| Term date correction | Academic Structure | yes, 5.1 | OPEN |
| Reactivate canteen items/outlets | Canteen | no | OPEN (LC-5 non-SIS remainder) |
| Reactivate hostels/rooms/beds | Hostel | no | OPEN |
| Reactivate transport routes/vehicles | Transport | no | OPEN |
| Reactivate timetable entries/periods | Timetable | no | CLOSED by LC-2 |
| Guardian contact reactivation (found here, not in M5) | Guardians (SIS) | yes, 2.1 | OPEN |

## 21. SIS Local Completion Score — 82 / 100

| Area (weight) | Score | Deduction |
|---|---|---|
| Student Master (15) | 13 | dual numbering rule; no duplicate warning; DOB rule |
| Admissions (12) | 8.5 | no correction path at all (M5); draft cannot be discarded |
| Guardian / relationships (8) | 6.5 | contact correction needs SQL; weak name search |
| Enrollment / Placement (15) | 12 | out-of-year start date is unrecoverable; whole-School transfer skips blockers; ENR-01; roll number |
| Lifecycle / Rollover (12) | 10 | wrong draft year/term dates block transition; rollover target-year status unchecked |
| Attendance (12) | 10.5 | UI future date, confirmation, heading, links (mark-all is a decision, not deducted) |
| Search / operational visibility (8) | 7 | Guardian search; history poorly exposed |
| Data Health / integrity (8) | 6.5 | SB-02, SB-03a, DH-12, DH-18 |
| UX / accessibility / browser operability (7) | 5 | SIS pages outside a11y lint; native confirms; two pages to re-verify at 390 px |
| Documentation / demo (3) | 2.5 | LC audit stale for ATT-07/SB-01 |
| **Total (100)** | **82** | |

### 21.1 Per-area scorecard (0–100)

| Area | Score | Main gap | Action |
|---|---|---|---|
| Student Master | 87 | two numbering rules, no duplicate warning | 2.3 |
| Guardians | 80 | contact correction needs SQL | 2.1, 2.2 |
| Admissions | 70 | no applicant/application correction | 3.1 |
| Enrollment | 80 | start date not bounded to year | 4.1 |
| Placement (transfer/correction) | 80 | blockers on whole-School transfer; roll number | 4.2 |
| Lifecycle | 90 | D-13 deferred by decision | none |
| Rollover | 85 | target-year status unchecked; year/term dates | 5.1, 5.2 |
| Attendance | 87 | UI polish and exposure | 6.1 |
| Teacher SIS | 80 | no "My classes" list | 6.3 (optional) |
| Search | 87 | Guardian full-name search | 2.2 |
| Portal (local) | 90 | inbox scope notes; Student portal deferred | none now |
| Documents | n/a (DEFERRED) | OD-SWC-2 | none |
| Data Health | 80 | LC-7 items | 8.1 |
| Reporting | 85 | movement report derivable only | none required |
| UX / accessibility | 70 | lint scope, confirms, 390 px re-check | 9.1 |
| Documentation / demo | 85 | guide and role matrix refresh before UAT | 9.2 |

## 22. Must-Complete (4)

1. **MC-1 Applicant and application correction** (3.1): edit applicant
   identity; edit a draft or submitted application's year, Campus or grade;
   discard a draft.
2. **MC-2 Placement date validity** (4.1): bound `starts_on` to the
   Section's academic year and refuse a closed year at entry (enroll,
   conversion, transfer target), so a typo can no longer create a frozen,
   unendable placement.
3. **MC-3 Draft academic-year and term date correction** (5.1, Academic
   Structure-owned): a narrow, dependant-checked correction path, with a
   migration that has a working `down()` and an ADR note.
4. **MC-4 Guardian contact correction** (2.1): reactivate (and correct) a
   deactivated contact without SQL, inside ADR 0041.

## 23. Should-Complete (9 groups, 36 items)

1. **Guardians (2.2):**
   - full-name, any-order Guardian search with escaping;
   - disambiguator (linked-Student count);
   - status confirmation + reason;
   - show the hidden refusal messages (self-administration, `primary`
     conflict).
2. **Student master (2.3):**
   - allocator as the default Student number at create and conversion;
   - same-name-plus-DOB duplicate warning;
   - DOB not in the future on create/edit.
3. **Admissions polish (3.2):**
   - decision-note correction;
   - hide inactive Campuses;
   - human transition messages;
   - DOB formatting;
   - Guardian input labels and fieldset;
   - reactive `hasFilters`;
   - missing name errors.
4. **Placement safety (4.2):**
   - blockers on the whole-School transfer when the Campus changes;
   - future-date rule on transfer;
   - ENR-01 picker order;
   - roll-number correction decision.
5. **Rollover (5.2):**
   - target-year status validation and test;
   - bulk repeat/manual review;
   - no term creation in a closed year.
6. **Attendance productivity (6.1):**
   - `max` date on Take (ATT-09);
   - correction confirmation (ATT-06);
   - heading label;
   - Registers row links;
   - Student history link from the profile;
   - empty state instead of 404.
7. **Data Health (8.1, LC-7):** SB-02, DH-12, DH-18; SB-03a with the Fees
   owner.
8. **Accessibility / phone (9.1, LC-10 SIS subset):**
   - SIS pages into a11y lint;
   - `ConfirmDialog` instead of `window.confirm`;
   - fieldsets;
   - browser re-check of Take/Show at 390 px.
9. **Teacher "My classes" list (6.3):** optional, may collapse to NO ACTION.

## 24. Deferred / blocked (13 decision- or legal-blocked; plus post-v1)

**Decision- or legal-blocked:**
- V11.1 people-count dashboards (legal, D-01..D-04, D-14);
- broad Guardian contact search (ADR 0041 / SRCH-02);
- demographics and statutory fields (D-15);
- Guardian portal production (E46);
- teacher production grants (E33);
- exports (D-11 / PV1-16);
- lifecycle effective date and approval (D-13);
- certificates (PV1-14);
- Student documents (PV1-15 / OD-SWC-2);
- sibling and family-contact lists (PV1-17);
- daily attendance and mark-all (PV1-19 / ATT-08);
- Student Leave (D-16);
- houses/clubs (D-17).

**Post-v1 or not now:** biometrics (HRX-L2), global search (D-12), the
Student portal, public admissions form, application fee before conversion,
offer letters, graduation status, queue-backed rollover, Guardian Data Health
checks (D-05/D-06).

## 25. Proposed numbered checkpoint roadmap

| Number | Checkpoint | State | Dependency | Type / size | May collapse? | Browser proof |
|---|---|---|---|---|---|---|
| 1.1 | Current-state audit (this record) | **COMPLETE** | — | audit | — | — |
| 1.2 | Architecture reconciliation (rule-4 read drift, stale LC rows) | READY | 1.1 | audit / S | **likely NO ACTION** | — |
| 2.1 | Guardian contact correction (MC-4) | READY | STG-04 for DDEV proof | build / S | no | deactivate → reactivate a contact |
| 2.2 | Guardian search and safety | READY | — | build / S | no | find "first last"; status needs reason |
| 2.3 | Student numbering, duplicate warning, DOB rule | READY | — | build / S–M | partly | create Student with allocator number; duplicate warning |
| 3.1 | Applicant and application correction (MC-1) | READY | — | build / M | no | edit applicant + draft application; discard draft |
| 3.2 | Admissions polish | READY | 3.1 | build / S | may fold into 3.1 | — |
| 4.1 | Placement date validity (MC-2) | READY | — | build / S | no | out-of-year start refused with field error |
| 4.2 | Placement safety (transfer blockers, ENR-01, roll number) | READY | 4.1 | build / S–M | partly | whole-School Campus change refused while a rider exists |
| 5.1 | Draft year and term date correction (MC-3, Academic Structure) | READY | owner OK on trigger exception | build + migration + ADR note / M | no | correct a draft year's end date; correct a term |
| 5.2 | Rollover target-year validation, bulk repeat/review | READY | 5.1 | build / S | partly | plan into a closed year refused |
| 6.1 | Attendance productivity | READY | — | build / S | no | future date not offered; confirm correction; links |
| 6.2 | Daily attendance / mark-all | BLOCKED | ATT-08/PV1-19 decision | — | NO ACTION unless decided | — |
| 6.3 | Teacher "My classes" | NOT STARTED | owner choice | build / S | **may be NO ACTION** | — |
| 7.1 | Sibling block on profile | NOT STARTED | PV1-17 scope check | build / S | **may be NO ACTION** | — |
| 7.2 | Student documents / certificates | DEFERRED | OD-SWC-2, PV1-14/15, E21 | — | — | — |
| 8.1 | Data Health LC-7 (SB-02, DH-12, DH-18; SB-03a with Fees) | READY | 4.1 | build / M | no | new checks listed and resolvable |
| 9.1 | SIS a11y / phone closure | READY | after 2–6 | build / M | no | Take/Show at 390 px; lint green |
| 9.2 | Documentation, demo, guide, role matrix | READY | all builds | docs / S | no | fresh `ddev demo-reset` |
| 10.1 | Comprehensive final local SIS UAT | NOT STARTED | all MUST + chosen SHOULD, STG-04 | UAT / L | no | full journey |
| 10.1.x | UAT correction slices | NOT STARTED | 10.1 findings | build | yes | — |

## 26. Checkpoint-count estimate (remaining prompts)

| Minimum | Likely | Maximum reasonable |
|---|---|---|
| **9** (4 MUST, 9.1, 9.2, 10.1; Should items folded in; 1.2/6.3/7.1 NO ACTION) | **15** | **24** (Should items as separate slices, correction sub-slices, two UAT rounds) |

## 27. Already-implemented count

| Measure | Count |
|---|---|
| Historical SIS checkpoints audited | **49** |
| Historical checkpoints completed | **41** (+2 partial, 1 superseded, 5 open) |
| SIS capabilities implemented: 70 capability rows classified COMPLETE or COMPLETE — IMPROVEMENT POSSIBLE in §4–§16, plus the 8 Data Health checks | **78** |
| Current open gaps | **40** (4 Must + 36 Should items) |
| Must-Complete | **4** |
| Should-Complete | **9 groups / 36 items** |
| Decision / legal blocked | **13** |

## 28. Definitions

**Before SIS 10 (final local SIS UAT):**
- all four Must-Complete items closed;
- the Should groups the owner selects closed;
- the remote exact-SHA regression green;
- STG-04 reconciled;
- a fresh `ddev demo-reset`;
- the School Administrator guide and role matrix accurate;
- no API or SQL needed for any supported SIS workflow.

**SIS locally complete** when all of the following hold:
1. no SIS S0/S1 is open;
2. all Must-Complete items are closed;
3. ordinary SIS workflows are browser-operable;
4. every data-entry mistake in a supported workflow has a correction path;
5. tenant and security tests are green;
6. Data Health and the integrity layers are coherent;
7. legal-gated capabilities stay gated in code;
8. documentation is accurate;
9. final local SIS UAT passes;
10. the owner accepts the remaining deferrals (§24).

## 29. Recommended next checkpoint

**SIS 3.1 — Applicant and application correction (MC-1).**
- It is the longest-standing ordinary-workflow gap: open since the
  2026-10-15 UAT (ADM-14) and the 2026-10-17 LC audit (M5).
- It has no workaround for applicant identity.
- It needs no schema change or owner decision.

SIS 4.1 (placement date validity) is small and should follow immediately.
