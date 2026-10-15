# ADR 0075: V11 Commercial Readiness Programme Contract (V11.0)

- Status: **Accepted. V11.0 CONTRACT PUBLISHED (2026-10-15, documentation
  only). V11.1 Executive Dashboard & Enrollment Intelligence is BLOCKED (§4).
  V11.2 SIS Search, Reports & Operational Visibility — IMPLEMENTED
  (2026-10-15, OD-V11-2, §9; no migration, no new capability, no export).**
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
