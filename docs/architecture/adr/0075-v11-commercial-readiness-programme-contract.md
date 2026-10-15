# ADR 0075: V11 Commercial Readiness Programme Contract (V11.0)

- Status: **Accepted. V11.0 CONTRACT PUBLISHED (2026-10-15, documentation
  only). V11.1 Executive Dashboard & Enrollment Intelligence is BLOCKED (§4).
  V11.2 SIS Search, Reports & Operational Visibility — IMPLEMENTED
  (2026-10-15, OD-V11-2, §9; no migration, no new capability, no export).
  V11.5 Data Health & Reconciliation Center — IMPLEMENTED, READ-ONLY
  (2026-10-15, OD-V11-5, §10; eight checks, no migration, no stored findings).
  V11.3 Historical Placement Closure (narrowed to R-1) — IMPLEMENTED
  (2026-10-15, OD-V11-3, §11; existing Complete/Withdraw exposed for
  closed-year placements plus a preview-then-complete batch; R-1 resolved).
  OD-V11-7 corrections X-01–X-04 — RESOLVED (2026-10-15, §12).**
- Date: 2026-10-15
- Programme: **V11 — Benchmark-Driven Commercial Readiness**
  (`MASTER-ROADMAP.md`, post-foundation programme 10). It is a post-v1
  improvement programme.
- Baseline:
  - `origin/main` = local `main` = `HEAD` =
    `6e11bc143529106044f6657952fc7de2500f433b` (PPR.2D).
  - The tree is clean. There is one worktree, and the historical stash
    (`phase-5d3-preflight`) is untouched.
  - Ruleset `main-protection` 24909660 is active with no bypass actor. The
    exact-SHA `CI gate` on `6e11bc14` is green.
- Evidence: [`docs/product/V11-COMMERCIAL-READINESS-AUDIT.md`](../../product/V11-COMMERCIAL-READINESS-AUDIT.md),
  which holds the full A–F matrix, strengths, incidental defects and V2
  discovery.

## 1. Context

The owner asked for a programme that improves Lycenza's commercial
usability and operational depth. Competitor observations may be used only
as research into business requirements.

**No benchmark material exists in the repository.** None was used:
- no competitor code, wording, layout, branding or screenshot;
- no competitor customer data or personal data.

The candidates are the six groups (A–F) in the owner's brief. Each was
checked against Lycenza's own code, schema, tests and recorded decisions
before being classified.

## 2. Boundaries (what V11 does not change)

1. **SWC stays CLOSED / DEVELOPMENT COMPLETE** (ADR 0073 §20). V11 does not
   reopen SWC. Work that only renames or re-exposes an SWC item is not V11
   work.
2. **PPR and Phase 0O are untouched.** V11 does not:
   - modify, supersede, weaken or bypass ADR 0074;
   - change any ADR 0058 row (O1 stays NOT SATISFIED);
   - count toward PPR evidence.

   Nothing in V11 is a production-readiness claim.
3. **No V11 checkpoint reopens a closed v1 programme** (FEE, TCH, HRX, OPF,
   RES, POR, SR) or its legal gates.
4. **V11 channels are in-app and email only.** SMS, WhatsApp, voice and
   push stay E28 / V2.
5. **Every rule in `CLAUDE.md` applies unchanged.** In particular: tenancy
   rules 5 and 17–23, capabilities rules 6 and 24, financial immutability
   rule 91, the email layer rule 89, and publication rule 96.

## 3. Audit outcome (summary)

The full matrix is in the evidence document. Key results:

| Group | Already built | Exposure gap (2) | Derivable (4) | New domain behaviour (5) | Blocked / out of scope (6, gated) |
|---|---|---|---|---|---|
| A Executive / enrollment intelligence | Per-plan promotion summary | — | Most metrics, **but every person count is gated** (§4.1) | Reactivation date, teaching/non-teaching meaning | Cross-School person counts (ADR 0048) |
| B SIS search / visibility | Admissions, inactive, withdrawn and promotion lists | Staff directory filters, Student placement filters, per-teacher assignment filter | Per-Student history, absentee list, sibling view | Campus audit dimension | Guardian contact search (ADR 0041), export (no capability), counts (gate) |
| C Lifecycle / rollover | Transfer, rollover dry run and validation | Status-toggle confirmation, history view | Required-data checks | Reason and effective date (reopens PHASE-1E-0 §13.2), approval, verification, documents | Suspension, leaving certificate (deferred) |
| D Communications administration | Fixed sender, audiences, identity dedupe | Configured state, effective sender | — | Address dedupe, staff-by-role audiences | Sender overrides, Reply-To/Cc/Bcc (ADR 0055, rule 89), test-email purpose, event notifications (ADR 0043 §12) |
| E Data health | Platform verifiers (CLI), HR import reconciliation | — | Nine School-level findings | Field-requirement rules | — |
| F Learning space | Room type and capacity, timetable room slots | — | — | Building/floor, home room, suitability, in-charge | Exam seating (ADR / Examinations), capacity vs strength (gate) |

## 4. V11.1 determination: BLOCKED

V11.1 as briefed is a person-counting Executive dashboard. The brief requires
a stop when "required legal/privacy authorization is genuinely missing" or
"authoritative lifecycle semantics cannot be determined". Both conditions
hold.

### 4.1 Person-counting Analytics is blocked in contract and in code

- **Every V11.1 metric counts people.** Student totals, strength by
  Campus, grade or Section, admissions, movement and staff totals all do.
  The ADR 0040 amendment of 2026-09-23 §1 puts every such cell or
  denominator under the minimum-cohort rule.
- **The gate names this exact dataset.**
  `docs/security/ANALYTICS-SMALL-COHORT-POLICY-GATE.md` §4 rates "Student
  enrollment / headcounts by grade, section" as a Highly Sensitive
  aggregate with high small-cohort risk.
- **The gate prohibits the obvious shortcuts** (gate §5):
  - treating a School-wide total as safe;
  - assuming source-record access implies Analytics access;
  - shipping Student-data Analytics to a real School before counsel review.
- **Code enforces the block.** `CohortSuppressionPolicy` fails closed with
  HTTP 503 while `analytics.minimum_person_cohort_size` is unset.
  `AnalyticsArchitectureGuardTest` refuses any read model that counts
  people. Shipping V11.1 would mean rewriting that guard, which this ADR
  forbids.
- **Gating V11.1 on `students.view` instead of `analytics.view` would breach
  ADR 0040 §5.** Source access and Analytics access are separate in both
  directions.
- **Open decisions** (gate §7):
  - **7.1:** the minimum number (legal/privacy, product and security);
  - **7.2:** the suppression mode and filter granularity;
  - **7.3:** the policy scope;
  - **7.4:** counsel review for Student-data Analytics (LEGAL; required by
    the Children's-data row of `DATA-CLASSIFICATION.md`).

  None may be assumed, defaulted or hard-coded.

### 4.2 Some movement metrics have no authoritative effective date

- **Reactivation and inactivation.** `students.status` changes
  (`StudentService::changeStatus()`) carry no effective date and no reason.
  This is a decided design (PHASE-1E-0 §9–§12; open question §13.2). Dating
  them from the audit timestamp would substitute a technical timestamp for
  a business date, which the owner's brief §15 forbids.
- **"Newly admitted."** This can use
  `admission_applications.converted_at` (School-local date). A Student
  created directly, outside Admissions, has no admission event, so that
  metric must say it covers converted applications only.
- **"Current population" double-counts across years.** Rollover execution
  never ends the source enrollment (`EnrollmentRolloverItemExecutionService`
  docblock). A Student promoted for next year is `active` in both years
  until staff complete the old row. Every population metric must therefore
  pin one academic year.
- **"Passed-out" is not a Lycenza state.** Graduation is deferred
  (PHASE-1E-0 §13.3).
- **"Teaching / non-teaching" has no fixed meaning.** `employee_categories`
  is reference data each School configures, nullable on
  `employment_records`. The authoritative teaching fact is a dated
  TeachingAssignment.

### 4.3 What would unblock V11.1

1. The owner records decisions 7.1–7.3 and the 7.4 route as an amendment to
   ADR 0040 §6 and to `DATA-CLASSIFICATION.md`'s Analytics row, with the
   approving roles named (gate §9).
2. Then V11.1 is built inside Analytics, as the gate requires:
   - a `countsPeople` read model with suppression and complementary
     suppression;
   - the guard test changed only by the same recorded decision;
   - `analytics.view`;
   - drill-down that also checks the source capability;
   - aggregate read contracts in the Students and HR modules;
   - audit of Highly Sensitive aggregate reads.
3. The metric definitions in §4.2 are adopted as written, or the owner
   reopens PHASE-1E-0 §13.2 under V11.3 first.

## 5. Sequence

The owner's order was V11.1 → V11.7. Only §4 changes it: V11.1 cannot
start until the gate decisions exist. No other concrete dependency forces a
change. The recorded order is below. Each checkpoint needs owner
authorisation before it starts. None is auto-started.

| Checkpoint | Status | Scope that is implementable now | Waits on |
|---|---|---|---|
| **V11.1 Executive Dashboard & Enrollment Intelligence** | **BLOCKED** | Nothing person-counting. Non-person structural figures (configured Campuses, Sections, Rooms) are not the briefed product | Gate 7.1–7.4 (§4.3) |
| **V11.2 SIS Search, Reports & Operational Visibility** | Ready to authorise; the first implementable checkpoint | Operational lists only (ADR 0040 §1), each under its own module's capability, with no new schema. See B-02, B-03, B-04, B-07, B-09, B-10, B-11, B-12, B-13 | Owner authorisation. Export (B-14) needs its own capability decision |
| **V11.3 Student Lifecycle Approval & Annual Rollover** | Decision-gated | Status-toggle confirmation (C-01); bulk completion of source enrollments after rollover through the existing `complete()` (C-10) | Reason / effective date / approval reopen PHASE-1E-0 §13.2 and touch E21 D7 (retention exit). Needs an owner decision first |
| **V11.4 Communication Administration** | Partly decision-gated | Read-only effective-sender and configured-state view (D-07); address-level dedupe (D-11) | Sender overrides, Reply-To, Cc/Bcc and test email need ADR 0055 amendments. Staff event notifications need the ADR 0043 §12 decision. Guardian event notifications are prohibited (tier 4) |
| **V11.5 Data Health & Reconciliation** | Ready to authorise | Read-only School-scoped finding lists E-02…E-10 and E-12, each linking to the owning module's page | Which Student or Guardian fields count as "required" (C-12) is an owner decision |
| **V11.6 Learning-Space / Capacity** | Mostly new state | Display of existing room type and capacity | Building/floor, home room, suitability and in-charge are new state (owner). Capacity vs strength waits on the gate |
| **V11.7 Commercial Readiness Closure Audit** | Last | — | — |

## 6. Rules for every V11 checkpoint

1. **Operational query, not Analytics.** A V11 list returns records the
   viewer may already see, inside the owning module, under that module's
   capability. Its only count is the total of the visible rows (ADR 0042
   §11). Any other person count goes through ADR 0040 and the gate.
2. **No second source of truth.** Use the existing Application read
   services. Do not add mutable counters, projections, caches or snapshots
   (ADR 0040 §7, §9, §10).
3. **No second lifecycle.** `StudentService` and `StudentEnrollmentService`
   stay authoritative. A reason, effective date or approval is added to
   them, never alongside them, and only after the owner decision in §5
   (V11.3).
4. **Corrections go through the owning service.** No generic repair
   endpoint and no database editor (V11.5).
5. **The email layer is unchanged** unless ADR 0055 is amended first (rule
   89).
6. **Guardian-facing automation stays prohibited** (ADR 0043 tier 4).
7. **Every checkpoint adds allow/deny and cross-School tests** (rules 13 and
   28) and follows rule 82 and rule 96 publication.

## 7. Incidental defects (outside V11 scope)

The audit found five defects inside existing contracts (evidence document,
X-01…X-05):
- **X-01:** flashed success messages are never shown (64 sites);
- **X-02:** the School timezone is accepted unvalidated, and a database
  trigger evaluates it;
- **X-03:** the MFA label shows the old product name;
- **X-04:** the timetable filters ask for raw UUIDs;
- **X-05:** a stray untracked directory.

They are not V11 features. A small correction slice can fix them under the
existing contracts, once the owner authorises it.

## 8. Decisions requested from the owner

| ID | Decision | Unblocks |
|---|---|---|
| OD-V11-1 | Small-cohort gate 7.1–7.3, plus the 7.4 route (counsel review or a default-off flag pending review) | V11.1; counts in V11.2, V11.5, V11.6 |
| OD-V11-2 | Authorise V11.2 (operational visibility) as the first implementable checkpoint | V11.2 |
| OD-V11-3 | Reopen PHASE-1E-0 §13.2: a reason and effective date for Student inactivation/reactivation, and whether withdrawal needs approval | V11.3, A-04 |
| OD-V11-4 | A SIS export capability and its holders (B-14) | Export |
| OD-V11-5 | ADR 0043 §12: whether tier-1 staff notifications are wanted | V11.4 event policies |
| OD-V11-6 | Whether any ADR 0055 amendment (sender, Reply-To, test purpose) is wanted | V11.4 sender items |
| OD-V11-7 | Authorise the X-01…X-04 correction slice | Defects |

## 9. V11.2 — SIS Search, Reports & Operational Visibility (implemented, 2026-10-15)

Authorised by the owner as OD-V11-2. Baseline `origin/main` = `bb3177e1`.
**No migration, no new table, no new capability, no new route outside the
School web surface, no export.** V11.1 stays BLOCKED (§4); SWC and PPR are
unchanged.

### 9.1 Implementation audit (before code)

| Candidate | Backend support | API | Web before | Authorization | Schema | Decision |
|---|---|---|---|---|---|---|
| Staff filters (campus, department, position, sort) | `EmployeeDirectoryService` + `EmployeeDirectoryQuery` | yes (`EmployeeDirectoryController`) | search + archived only | `hr.employees.view`; option lists `hr.departments.view` / `hr.positions.view` | none | **Implemented** |
| Student placement on the Student list | `StudentEnrollmentReadService::currentFor()` (per Student) | — | none on the list; full placement search on `/app/enrollments` | `students.view` + `enrollments.view` (independent, Phase 1B.6) | none | **Implemented** (batch read + link to the Enrollments directory) |
| Teacher assignment visibility | `TeachingAssignmentReadService::list()` filters | yes | year filter only | `teaching.assignments.view` | none | **Implemented** (teacher and Section filters; Employee profile link) |
| Student operational history | audit events with Student subject or `metadata.studentId` | — | enrollment history only | per category: `students.view`, `enrollments.view`, `guardians.view`, `admissions.view` | none | **Implemented** (read projection, §9.4) |
| Absentee / attendance-exception list | `attendance_records` + sessions | — | per register only | `attendance.view` | none | **Implemented** (operational list, §9.5) |
| Result totals | paginator `total` already in every list payload | — | not rendered | the list's own gate | none | **Implemented** as the row total only (ADR 0042 §11) |
| Global Student/staff search | none (per-module searches only) | — | — | would need a per-result-type capability check | none | **Deferred** to a later V11.2 sub-checkpoint: new cross-module search architecture |
| Guardian / contact lookup | `GuardianContactService` exact HMAC lookup | duplicate-check only | duplicate-check while linking | ADR 0041; ADMISSIONS.md §14: "never a staff-facing search feature" | none | **Rejected** (needs an ADR 0041 decision) |
| Export | none for SIS; `analytics.export` granted to no role; no `compliance.export` | — | — | needs a capability decision | — | **Not authorised** (OD-V11-4 open); nothing added |

### 9.2 Staff directory

`HrEmployeeController@index` passes `campus_id`, `department_id`,
`position_id` (UUID-validated) and the allow-listed `sort`/`direction`
through the existing `EmployeeDirectoryQuery`. Campus options go to every
directory reader (rows already show the campus). Department and Position
options are sent only to holders of `hr.departments.view` /
`hr.positions.view`, so the directory never becomes a wider route to the
organisation structure. A foreign-School id matches nothing.

### 9.3 Students and teaching assignments

- `StudentEnrollmentReadService::currentForMany()` is `currentFor()` for a
  page of Students in one query: the active Enrollment in the School's
  active AcademicYear, never a historical row. The Student list shows it
  only when the viewer also holds `enrollments.view`, and links to the
  Enrollments directory for placement search.
- `TeachingAssignmentReadService::filterOptions()` derives the teacher and
  Section choices from that year's own assignments. The HR Employee profile
  links to the filtered list for holders of `teaching.assignments.view`,
  using the catalogue key, not a TeachingAssignments class (that module
  depends on HR, never the reverse).
- Not built: a teacher's own "my classes / my timetable" page. Which
  capability gates a teacher's own assignment list is an authorization
  decision under ADR 0063 (owner).

### 9.4 Student history

- **Read contract:** `App\Support\Audit\SchoolAuditSubjectHistoryReader` is
  the shape ADR 0042 §10 names: one entity's events, a CLOSED event-type
  allowlist with per-type metadata keys, and scalar values only. It returns
  no actor, IP, user agent or request id. It is a separate class from
  `SchoolAuditEventReader` (the audit-log review contract, which still
  never loads metadata; `ComplianceArchitectureGuardTest` unchanged).
- **Service and page:** `StudentActivityHistoryService` (Students module)
  is the read projection, modelled on the HR Activity Timeline (8A.11).
  `/app/students/{student}/history` requires `students.view`.
- **Gating:** each category needs its source capability, so it is never
  wider than the source (ADR 0042 §4):
  - record: `students.view`
  - placement: `enrollments.view`
  - guardian links: `guardians.view`
  - admission: `admissions.view`
- **Known limits, shown on the page:**
  - only what the ledger recorded appears;
  - effective dates appear only where the event carried one (enrollment
    endings); a status change has none (PHASE-1E-0 §11);
  - who made a change is left to the School audit log (`school.audit.view`,
    itself audited);
  - the newest 200 entries, with a truncation notice;
  - demo data written by seed factories has no audit events.
- **Not access-audited**, like the HR timeline.

### 9.5 Attendance exceptions

`AttendanceExceptionReadService` and `/app/attendance/exceptions` (under
`attendance.view`, linked from the admin registers page only, never from
My Attendance) list one School-local day's `absent`/`late`/`excused`
records across submitted registers. The filters are:
- the date (required; today by default);
- the Section (choices are that day's registers);
- the status.

It returns the same individual records the register page shows: name
columns only, never date of birth. It has no rate, percentage or
per-Student tally (ATTENDANCE.md §16 "no analytics" holds). The date is
compared directly, so the `(school_id, attendance_date)` index applies.

### 9.6 Result totals

`Components/Pagination.vue` takes optional `from`/`to`/`total` and renders
"Showing x–y of N results". These are the paginator's own values, already
in each list payload. Opted in on Students, Enrollments, Employees and
Attendance exceptions; the Teaching Assignment list shows its existing
total. This is the ADR 0042 §11 row total of the visible list, never a
population figure. No grouped or dashboard count was added, and no
Analytics guard was touched.

### 9.7 Also changed

- Filter state is reactive (the Students and Employees empty-state
  `hasFilters` was computed once and went stale).
- Every filter page has a "Clear filters" action.
- On pages this slice touched, wide tables scroll inside their own box at
  phone width: Attendance registers, Teaching Assignments, and the new
  exceptions list. Before, the page scrolled sideways.

### 9.8 Deferred from V11.2

| Item | Reason |
|---|---|
| Global cross-module search | New architecture (per-result-type authorization); a later V11.2 sub-checkpoint |
| Guardian contact search | ADR 0041 / ADMISSIONS.md §14 decision |
| SIS export | OD-V11-4 (capability decision) |
| Teacher's own assignment / timetable view | ADR 0063 authorization decision |
| Actor names in Student history | Audit evidence stays behind `school.audit.view` (ADR 0042) |
| Audit-log filters (ADR 0042 §10) | Narrowed out of v1 by ADR 0042; unchanged |

## 10. V11.5 — Data Health & Reconciliation Center (implemented, 2026-10-15)

Authorised by the owner as OD-V11-5 (read-only first). Baseline
`origin/main` = `80aa6bda`. **Read-only. No migration, no table, no stored
finding, no new capability, no mutation route, no automatic repair.**
V11.1 stays BLOCKED; X-01–X-04, SWC and PPR are unchanged.

### 10.1 Audit matrix

| Candidate | Authoritative source | Existing contract | Derive now? | Viewer | Repair path | Schema | Decision |
|---|---|---|---|---|---|---|---|
| Active placement in a **closed** year (rollover leftover) | `student_enrollments` + `academic_years.status` | Closing (or activating the next year, rule 65) never ends placements; rollover never ends the source (`EnrollmentRolloverItemExecutionService`) | yes | `enrollments.view` | `StudentEnrollmentService::complete()` exists, but the Student page offers Complete only for the current year: **no browser action** (R-1) | none | **Implemented** — Action required |
| Active placement in a deactivated Section | `student_enrollments` + `sections.status` | "Deactivate, never delete" stops new placements, keeps existing ones (ACADEMIC-STRUCTURE.md) | yes | `enrollments.view` | Transfer on the Student page (current year); reactivate the Section | none | **Implemented** — Review |
| Inactive Student with an active placement | `students.status` + `student_enrollments` | PHASE-1E-0 §13.1 names it a "data-quality smell, not a hard inconsistency" | yes | `students.view` + `enrollments.view` | Withdraw/complete or reactivate on the Student page | none | **Implemented** — Review (never an error) |
| Active Student with no current placement | `students` + active-year `student_enrollments` (`currentFor()` rule) | Placement is not required for an active Student; registers, rosters and fee runs start from placements | yes | `students.view` + `enrollments.view` | Enroll (Student page) | none | **Implemented** — Review |
| No active academic year | `academic_years` | `CurrentAcademicYearResolver` drives every current-year surface | yes | `academics.years.view` | Activate (School setup) | none | **Implemented** — Blocking |
| Active year without active Sections | `academic_years` + `sections` | A placement needs a Section of its year (rule 66) | yes | `academics.structure.view` | Create Sections (School setup) | none | **Implemented** — Blocking |
| Active-year Section with no active timetable class | `sections` + `timetable_entries` | A register is taken per TimetableEntry and date (ATTENDANCE.md) | yes | `timetable.schedule.view` | Schedule a class | none | **Implemented** — Review |
| Current/upcoming teaching assignment on a deactivated Section or offering | `teaching_assignments` + `sections`/`subject_offerings` | Deactivation does not end assignments | yes | `teaching.assignments.view` | End the assignment (Teaching assignments) | none | **Implemented** — Review |
| Duplicate/contradictory "current" placements | `student_enrollments_one_active_per_student_year` | Database-enforced | — | — | — | — | **Rejected**: cannot occur |
| Placement in an inappropriate year (two active years) | — | A next-year placement while the current year is active is legitimate rollover planning | — | — | — | — | **Rejected**: not a defect (the closed-year check covers the real leftover) |
| Malformed guardian email/phone | encrypted `guardian_contacts` | Validated on write (`EmailNormalizer`, `PhoneNormalizer`); ADR 0041 "never decrypt-and-scan" | — | — | — | — | **Rejected**: a scan would decrypt every contact (ADR 0041) |
| Invalid/duplicate relationship rows | `student_guardian_relationships` | Composite FKs, primary-guardian partial unique index | — | — | — | — | **Rejected**: database-enforced |
| Student without a guardian; only inactive guardians | relationships | Not required (admission conversion allows "none") | yes | — | — | none | **Owner decision** (register §10.2) |
| Duplicate-guardian sweep (shared contact hash) | `guardian_contacts.lookup_hash` | Lookup sanctioned only while linking (ADMISSIONS.md §14) | yes | — | — | none | **Owner decision** |
| Active Employee without current employment / primary assignment | HR records | Valid state: "a gap between Employments is not the same thing as archived" (HR.md) | — | — | — | — | **Rejected**: not a defect |
| Assignment held after employment ended | — | Ended by the S7 cascade (ADR 0063 §47) | — | — | — | — | **Rejected**: cannot occur |
| Required offering with no teaching assignment | `teaching_assignments` | Teacher ownership is not required | yes | — | — | none | **Deferred** (advisory; not in the first set) |
| Elective assignments on a deactivated offering | `elective_teaching_assignments` | as above | yes | — | — | none | **Deferred** (second table) |
| Import/migration reconciliation | HR import (create-only, result not retained) | No persisted provenance | — | — | — | — | **Rejected**: no provenance to report |
| Retention/configuration warnings | platform CLIs (`platform:*`) | Platform-operator scope | — | — | — | — | **Rejected**: not School-operational |

### 10.2 Required-field register (Student and Guardian)

| Field / relationship | Required today? | Evidence | Operational value | Status |
|---|---|---|---|---|
| Student number, first name, date of birth | yes | `StudentService::create()` validation; `unique(school_id, student_number)` | identity | already enforced on write; no check needed |
| Middle/last name | no | nullable | — | optional, never flagged |
| Current class placement (active Student) | no | placement is a separate aggregate (PHASE-1E-0 §3) | registers, rosters, fee runs | **review** finding only (`student.active_without_current_placement`) |
| At least one Guardian | no | admission conversion offers "no guardian" | contact, portal, consent | **owner decision** |
| A legal guardian | no | `is_legal_guardian` is per link, optional | portal eligibility (POR, development-only) | **owner decision** + POR-L1 (E46) |
| Guardian email / phone | no | contacts optional; email validated when given | communication reach | **owner decision**; any sweep needs an ADR 0041 decision |
| Student contact, address, photo, identifiers | not modelled | ADMISSIONS.md §4 defers them | — | **legal/privacy review** before modelling |

No validation rule was changed.

### 10.3 Architecture

- **Contract:** `App\Support\DataHealth`:
  - `DataHealthCheck` is the interface: `definition()`, `hasFindings()`
    (an EXISTS query) and `findings()` (paginated).
  - `DataHealthCheckDefinition` holds the stable code, area, title,
    condition, why it matters, severity, kind, view capabilities, repair
    text and `automaticRepair`.
  - `DataHealthFinding` and `DataHealthLink` describe one affected record
    and its links.
  - `DataHealthRegistry` is the closed list, wired in
    `PlatformServiceProvider` like the audience and event-consumer
    registries.
  - `DataHealthService` authorizes and runs the checks.
- **Domain-owned checks:** each check lives in the owning module's
  `Application/DataHealth`:
  - Students: four checks;
  - AcademicStructure: two;
  - Timetable: one;
  - TeachingAssignments: one.

  Each queries that module's own models, with an explicit `school_id`
  predicate, inside `TenantContext::withSchool()`.
- **No second source of truth:** findings are recomputed on every request
  from authoritative tables. Nothing is cached, stored or projected.
- **Severity** (consequence, not drama):
  - Blocking: work cannot go ahead.
  - Action required: records disagree and must be corrected.
  - Review: probably unintended.
  - Advisory: optional, unused by the first set.
- **Kind:** invalid, inconsistent, incomplete, or review recommended.
  Optional data is never "invalid".

### 10.4 Authorization and privacy

- **Hub:** `/app/data-health` is a navigation hub (`StaffNavigation::HUBS`)
  in the Administration group. It opens for any of `enrollments.view`,
  `academics.years.view`, `academics.structure.view`,
  `timetable.schedule.view` or `teaching.assignments.view`, and each one
  alone opens at least one check (`HubAuthorizationTest`).
- **Per check:** a check is visible only to a viewer holding ALL of its
  view capabilities, the same capabilities that show that data at its
  source. Opening a check you cannot see is 403; an unknown code is 404.
  Holding the hub never widens what a viewer can learn.
- **Per link:** links are filtered to those whose capabilities the viewer
  holds. Repair links point to existing pages only, which re-check their
  own capability.
- **Display-safe rows:** a name and Student number, Section, year, roll and
  dates of placement. Never a date of birth, contact value, actor or audit
  detail. The ADR 0042 audit boundary holds.
- **No people counts on the overview:** each check reports only whether it
  found anything. A finding list shows its own row total (ADR 0042 §11).
  No Analytics guard was touched.

### 10.5 Repair architecture

| Check | Repair class (§18 of the brief) |
|---|---|
| Closed-year placement | 2: the service can repair (`complete()`/`withdraw()`), **but no browser action exists for a non-current placement** (R-1) |
| Placement in a deactivated Section | 1: Transfer on the Student page when the year is the active year; otherwise 2 |
| Inactive Student with an active placement | 1 (Student page) |
| Active Student without placement | 1 (Enroll) |
| No active year / no Sections | 1 (School setup) |
| Section without schedule | 1 (Schedule a class) |
| Assignment on a deactivated class | 1 (End assignment) |

No automatic repair was added. None meets "exactly one valid resulting
state": a placement's real end date, or the right replacement Section or
teacher, needs a person.

**R-1 (owner decision).** A browser action to complete or withdraw a
placement outside the current academic year, or a bulk completion of the
previous year's placements after activation. This is the remediation for
the most important finding. It belongs to V11.3, the Student lifecycle
and rollover checkpoint, through the existing `StudentEnrollmentService`.

### 10.6 Performance

- `hasFindings()` is one EXISTS query per visible check (at most eight per
  overview).
- Finding lists are paginated (25) and eager-load their display relations.
  The query count is independent of the number of findings (tested).
- Every predicate uses existing indexed columns (`school_id`, `status`, and
  the year, Section and Offering foreign keys). No index was added; with
  seed-scale data no plan warranted one.

## 11. V11.3 — Historical Placement Closure (narrowed to R-1, implemented, 2026-10-15)

Authorised by the owner as OD-V11-3, limited to R-1. Baseline `origin/main`
= `1d53f672`. **No new lifecycle semantics, no migration, no table, no
capability, no new transition.** PHASE-1E-0 §13.2 (a reason, effective
date or approval) is not reopened. V11.1 stays BLOCKED; X-01–X-04 are
unchanged.

### 11.1 Preflight

| Question | Evidence | Determination |
|---|---|---|
| Which service completes a placement? | `StudentEnrollmentService::complete()` | "the academic year's teaching period genuinely ended for this Student in this placement" |
| Which service withdraws it? | `withdraw()` | "the Student left this placement before it ran its normal course" |
| Cancel? | `cancel()` | "should not continue as an operational placement"; it is a record kept for history and recasts the placement as never operational. **Not offered** for a past placement that really ran |
| Can they operate on a closed year? | `transitionToTerminalStatus()` checks status `active` and `ends_on >= starts_on`, never the year's status | yes |
| Authorization | `StudentEnrollmentController::transition()`: `enrollments.manage`; `findOrFail` under SchoolScope/RLS | reused unchanged |
| Constraints | Section-then-Enrollment lock, compare-and-set `WHERE status='active'`, `student_enrollments_date_range_check` | unchanged |
| Audit | `student_enrollment.completed` / `.withdrawn` (studentId, academicYearId, sectionId, endsOn) | unchanged, one per placement |
| Repeatable? | a second call finds the row no longer `active`, so `InvalidEnrollmentTransitionException` (shown as a form error) | safe |
| Another current placement? | Placements are per year; the transition touches only its own row | untouched (tested) |
| Historical records | status and `ends_on` change; year, Section, roll, start date, attendance and audit are kept | preserved |
| Why the browser lacked it | `Students/Show.vue` offered Complete/Withdraw/Cancel only for `currentEnrollment` (active year), although `POST /app/enrollments/{id}/complete` accepts any placement | UI-only gap |
| Bulk infrastructure | rollover runs per item, resumable, 100 per request | the convention followed |

### 11.2 Single placement (required)

- **Where it appears:** in the Student page's Enrollment history, a
  placement that is `active` while its academic year is **closed** shows
  "Still open, but <year> has been closed". For holders of
  `enrollments.manage` it offers **Complete** and **Withdraw**, each with
  its existing meaning.
- **The form:** an inline confirmation names the year, grade and Section,
  and takes the end date. Complete defaults it to the year's end.
- **The request:** the form posts to the existing
  `/app/enrollments/{id}/complete|withdraw`. No new route and no
  controller change.
- **The presenter** adds only the year's `status` and `endsOn`.
- **Feedback:** a local confirmation (Inertia `preserveState`). It does not
  rely on flashed messages (X-01).
- **Next-year placements:** an `active` placement in a draft year is
  legitimate rollover planning and is not offered.

### 11.3 Bulk prior-year completion (conditional; implemented)

Every proof point held, so bulk was built as pure orchestration.

**What was added:**
- `App\Domain\Students\Application\ClosedYearPlacementClosureService`
  (`enrollments.manage`).
- `/app/enrollments/closed-year-placements`: GET preview; POST `/complete`.

**How it works:**
- **Eligible:** same School, `active`, its year `closed`. The set is
  deterministic, and current-year, next-year and terminal placements are
  impossible to include.
- **One transition:** `complete()` with the year's own end date, called per
  row. Each row has its own transaction, locks and audit event. There is
  no outer transaction, so one refusal never undoes another row.
- **Preview first:** at most 100 rows per request, the total shown, and an
  explicit confirmation (`confirm` accepted) before anything changes.
- **Re-checked at execution:** every submitted id is checked again against
  the School, the year and `active`. Ids from another School or year,
  tampered ids and already-ended rows are reported as `not_eligible` and
  never changed.
- **Truthful outcomes per row:**
  - `completed`;
  - `no_longer_open`: ended concurrently;
  - `not_eligible`;
  - `date_conflict`: the placement starts after its year's end, so the
    existing date rule refuses it and a person decides.

  The results page lists every row that did not complete.
- **Retries:** a retry or double submit is safe by state (rule 29: the
  Placement's own status is the idempotency key; no `Idempotency-Key`
  needed).

### 11.4 Data Health

`enrollment.active_in_closed_year` now links to:
- "Complete or withdraw" (Student page, Enrollment history; needs
  `students.view` + `enrollments.manage`);
- "Complete this year's open placements" (needs `enrollments.manage`).

Its repair text is updated. After a transition, the recomputed check no
longer returns the placement. No "resolved" record exists, and Data Health
still writes nothing. **R-1 is resolved.**

### 11.5 Rollover limitation (recommendation, not built)

Rollover execution and year activation still leave last year's placements
open, so the leftover recurs every year until staff complete it, now in
two clicks per year.

Recommendation (a future checkpoint; owner decision): offer this same
preview-and-confirm completion as an explicit step at year activation, or
at the end of a rollover run. It must stay a staff action, never an
automatic side effect of `AcademicYearService::activate()`. This is the
open question in docs/modules/STUDENT-ENROLLMENT.md ("Source completion
semantics").

## 12. OD-V11-7 — Cross-cutting corrections X-01 to X-04 (resolved, 2026-10-15)

Authorised by the owner as OD-V11-7. Baseline `origin/main` = `8756b16a`.
One correction candidate: all four fixes stayed small and independent.
**No migration, no table, no constraint, no capability, no new business
rule.** V11.1 stays BLOCKED; guardian/contact decisions, V11.4, V11.6 and
rollover prevention are untouched.

### 12.1 X-02 — School timezone validation (resolved; data integrity)

- **Root cause.** `SchoolSettingsController@update`, the only runtime write
  path, accepted any `string|max:64`. A typo was stored and then broke:
  - Carbon/`DateTimeZone` consumers in Finance, Communications, Students
    and Attendance;
  - the PostgreSQL triggers that evaluate `now() AT TIME ZONE v_timezone`
    (late-fee runs, payment receipts).

  `SchoolTimezone::resolve()` already fell back to UTC and its docblock
  asked for source validation.
- **Write-path audit.**
  - School creation sets no timezone (column default `Asia/Kolkata`).
  - The `/api/v1` School profile does not write it.
  - Factories and demo data use `Asia/Kolkata`.
  - The settings registry's `timezone` type had the same loose check, but
    no setting uses it today.
  - Every stored value is `Asia/Kolkata`, so no data correction or
    migration is needed.
- **Fix.**
  - `SchoolTimezone::isSupported()` requires a canonical PHP IANA
    identifier (no deprecated aliases) that PostgreSQL's
    `pg_timezone_names` also has, the actual consumer contract.
  - `SupportedSchoolTimezone` is the validation rule on the settings write
    path, and `SettingDefinition`'s `timezone` type uses the same check.
  - The settings page offers `SchoolTimezone::identifiers()`, the
    PHP ∩ PostgreSQL list (419 zones locally), as a select, with the error
    shown.
- **Database constraint: not added.** A CHECK cannot consult
  `pg_timezone_names`, and a trigger would duplicate the application
  validator on a single write path. `resolve()` keeps its UTC fallback.

### 12.2 X-01 — Success messages (resolved)

- **Root cause (single, architectural).** 64 controller redirects flash a
  success message (`flash` ×46, `status` ×18), but
  `HandleInertiaRequests::share()` shared neither and no page read them.
  - Every `status` value is a success text; refusals use
    `ValidationException`.
  - `status_message`, `lifecycleNotice` and `account_recovery_status` are
    already read explicitly by their own controllers and are unchanged.
- **Fix.**
  - One lazy shared prop, `flash.success` (`flash`, else `status`; strings
    only), namespaced so it cannot collide with a page's own `status`
    prop.
  - One `Components/FlashMessage.vue`, mounted once in the signed-in
    layout: `role="status"`, escaped text, dismissible, gone on the next
    visit (single-request flash).
- **No duplicates.** No page shows a local message for an action that also
  flashes. The V11.3 Student-page confirmation posts to an endpoint that
  does not flash, so it is kept.

### 12.3 X-03 — MFA authenticator branding (resolved)

- **Root cause.** `MfaEnrollmentService::begin()` used `config('app.name')`,
  which is `APP_NAME`: "School OS", an internal cookie and cache namespace
  that `ProductIdentity` deliberately does not change.
- **Fix.** The issuer and label are `ProductIdentity::NAME` (Lycenza).
  - Only the label changes: secret, SHA1, 6 digits and 30 s are unchanged.
  - Existing factors keep verifying, and nobody re-enrols (tested).
- **Same leak, fixed.** `.env.example` set `MAIL_FROM_NAME="${APP_NAME}"`,
  so an environment copied from it sent "<School> via School OS".
  `config/email.php` already defaults to "Lycenza", and DDEV overrides it.
  The example is now `MAIL_FROM_NAME="Lycenza"`; `APP_NAME` is unchanged.
- **Remaining "School OS" references are left alone:**
  - code comments in seven classes;
  - the `school_os` database, role and Compose names (internal
    identifiers);
  - `APP_NAME` itself;
  - CLAUDE.md, ADRs and module history.

### 12.4 X-04 — Timetable filters (resolved)

- **Root cause.** `Timetable/Schedule/Index.vue` asked for "Section ID" and
  "Teacher ID" as free text.
- **Fix.**
  - Two selects. Their options are the Sections and teachers on this
    School's ACTIVE timetable classes, so nothing is offered beyond what
    the list itself shows, never every Section or the HR directory.
  - Section labels include the grade, because codes are unique only within
    a grade (the demo has five "A" Sections). Teachers narrow to the chosen
    Section, and there is "Clear filters".
  - The request contract is unchanged: UUID `section_id` / `teacher_id`,
    malformed ids get a validation error, foreign ids match nothing.
  - The page's table now scrolls inside its own box at phone width (it
    widened the page to 527 px before).

### 12.5 Tests

- `tests/Feature/Corrections/`: 18 tests, 96 assertions.
  - `SchoolTimezoneValidationTest`: valid saved and evaluated by PostgreSQL;
    misspelt, malformed, lower-case, deprecated, offset, injection and
    empty values refused with nothing changed; other fields kept; manage
    capability required; offered list = both-consumer list; settings type.
  - `FlashMessageSharingTest`: the message reaches the next page exactly
    once; validation errors have no success; both keys namespaced, strings
    only; one escaped banner in the layout.
  - `MfaAuthenticatorBrandingTest`: label `Lycenza:<email>`, issuer
    Lycenza, no "School OS", SHA1/6/30, secret unchanged; confirm and
    existing factors still verify.
  - `TimetableFilterSelectorsUiTest`: active-only options, filtering and
    narrowing, combined filters, no foreign options or matches, malformed
    id refused, capability required, distinct labels for same-named
    Sections.
- Affected suites and guards: 1686 OK.
