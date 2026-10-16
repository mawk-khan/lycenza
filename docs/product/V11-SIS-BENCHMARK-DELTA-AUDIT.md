# V11 — Completed SIS Benchmark Delta Audit (evidence record)

- Date: 2026-10-16. **Audit only:** inspect, map, classify, prioritise. No
  product code, schema, capability, route or configuration changed.
- Baseline: `origin/main` = local `main` = `HEAD` =
  `d0fe3590d30cf4651877539ce2ffcea508afc2cc` (UAT-C5, ADR 0075 §20). The
  tree was clean. UAT-C5 had reached its terminal published state before
  this audit started.
- Contract: [ADR 0075](../architecture/adr/0075-v11-commercial-readiness-programme-contract.md)
  (§21 records this audit). Earlier V11 evidence:
  - [V11.0 audit](V11-COMMERCIAL-READINESS-AUDIT.md), rows A-xx to F-xx;
  - [V11.7 closure](V11-CLOSURE-AUDIT.md), decision register D-01 to D-13;
  - [Local UAT](LOCAL-UAT-2026-10-15.md) and its
    [reassessment](LOCAL-UAT-READINESS-REASSESSMENT-2026-10-16.md);
  - the [School Administrator Guide](SCHOOL-ADMINISTRATOR-GUIDE.md).
- **Benchmark use.**
  - **Source:** the owner's brief describes the completed external SIS
    benchmark as a list of business observations. Those observations are
    the only input. **No benchmark material is in the repository**, and
    none was added: no competitor code, screenshot, wording, layout,
    navigation, report name, formula, branding, customer data or personal
    data.
  - **What was extracted:** only the business problem, operational
    requirement, reporting need or workflow expectation.
  - **What it changes:** nothing about V11's status. It does not restart
    V11, reopen UAT-C1 to UAT-C6, or authorise any build.

Paths are relative to `apps/platform/` unless they begin with `docs/`.

## Classification key

**Primary classification** (exactly one per observation):

| Code | Meaning |
|---|---|
| 1 | Already implemented |
| 2 | Implemented but inadequately exposed |
| 3 | Partially implemented |
| 4 | Derivable from existing authoritative data |
| 5 | Genuinely missing |

The V11.0 record used a six-value key, where 6 meant "out of scope /
blocked". Here that meaning is a qualifier instead.

**Qualifiers** (zero or more):

| Code | Meaning |
|---|---|
| **L** | Legally or privacy blocked, including the small-cohort Analytics gate |
| **O** | Intentionally out of scope |
| **D** | Requires an owner decision |
| **I** | Integration-dependent |
| **B** | Duplicate of an already accepted backlog item or recorded decision (the item is cited) |

**Action:** NO ACTION · UX EXPOSURE · DERIVE REPORT · DATA HEALTH CANDIDATE ·
OWNER DECISION · LEGAL/PRIVACY REVIEW · LATER CHECKPOINT · GENUINE FEATURE
GAP · OUT OF SCOPE.

**Priority:**

| Priority | Meaning |
|---|---|
| P0 | Blocks current safety or release correctness |
| P1 | Significant staging, pilot or commercial operational need |
| P2 | Usability or reporting maturity |
| P3 | Later enhancement |
| DECISION | Cannot be prioritised until an owner or legal decision is made |

## A. Baseline

| Item | State at `d0fe3590` |
|---|---|
| V11.0 contract | Published `bb3177e1` |
| V11.1 executive / enrollment intelligence | **BLOCKED**: small-cohort gate 7.1–7.4 (D-01–D-04) |
| V11.2 SIS visibility | Complete within approved scope `80aa6bda` |
| V11.3 lifecycle / rollover | Complete within approved scope, narrowed to R-1 `8756b16a` |
| V11.4 communications | Decision-gated (D-07, D-08) |
| V11.5 Data Health | Complete, read-only, 8 checks `1d53f672` |
| V11.6 learning spaces | Decision-gated (D-09) |
| V11.7 closure | Published `d3492dbc` |
| X-01–X-04 | Resolved `5f77ef62` |
| Local UAT | UAT-C1, C2, C3, C4, C6 and C5 published (`c03cbaa4`, `c9f54b20`, `4fe4167a`, `78b68d6d`, `3ca6c58c`, `d0fe3590`) |
| Post-C6 determinations | No Local-UAT S1 open. Local UAT ready with limitations. **Product-side staging handoff YES.** Actual staging blocked outside this stream (PPR, E16-R2 hold). |

## B. Audit method

**Repository inspected:**
- **Migrations:** the full `Schema::create` table list.
- **Domain modules:** Students, Admissions, AcademicStructure, Attendance,
  StaffAttendance, Leave, Fees, Payments, Finance, Documents,
  TeachingAssignments, Timetable, CurriculumDelivery, HR, Transport,
  Hostel, Visitor, Communications, Automation, Analytics, Examinations.
- **Support code:** `App\Support\Audit`, `App\Support\DataHealth`.
- **Web layer:** controllers, routes and Inertia pages for each surface
  named below.

**Documents inspected:**
- ADR 0040 and its 2026-09-23 amendment, ADR 0041, 0042, 0043, 0055, 0063,
  0065, 0070 and 0075;
- `docs/security/ANALYTICS-SMALL-COHORT-POLICY-GATE.md` and
  `DATA-CLASSIFICATION.md`;
- `docs/students/PHASE-1E-0-STUDENT-LIFECYCLE-ARCHITECTURE.md`;
- the module documents for STUDENT-ENROLLMENT, ADMISSIONS, ATTENDANCE,
  FINANCE, DOCUMENTS, TEACHING-ASSIGNMENTS, TIMETABLE, ANALYTICS and HR.

**Method:**
1. Each observation was checked against the current code and schema before
   it was classified.
2. Every "missing" claim names what was searched for.
3. An observation that maps to an existing V11.0 row, D-xx decision or UAT
   backlog item is marked **B** and is not counted as new.

## C. Enrollment intelligence

**Authoritative sources:**
- **Placements:** `student_enrollments`, with status
  `active|completed|withdrawn|transferred|cancelled`, `starts_on` and
  `ends_on`. Rollover never ends the source placement, so every population
  figure must pin one academic year (ADR 0075 §4.2).
- **Admissions:** `admission_applications.converted_at`.
- **Student status:** `students.status`, `active|inactive`. It carries no
  date and no reason.
- **Capacity:** `sections.capacity` and `rooms.capacity`, both advisory.

**Every person count** (strength, admissions, exits, movement,
distributions) is Analytics under the ADR 0040 amendment §1. It fails closed
today:
- `CohortSuppressionPolicy` returns 503;
- `AnalyticsArchitectureGuardTest` refuses any `countsPeople` read model;
- the only registered read model is `curriculum.coverage`
  (`countsPeople: false`).

Ordinary record-view capabilities never authorise these counts (ADR 0040
§5). The V11.7 D-01–D-03 values (N = 10, "fewer than N" with complementary
suppression, platform floor) remain **recommendations, not approved
decisions**.

| # | Observation | Source | Counts people | Suppression applies | New transactional state? | Cls | Action |
|---|---|---|---|---|---|---|---|
| C1 | Current Student strength | active placements, one year | yes | yes | no | 4 · L · B (A-01, A-05) | OWNER DECISION (D-01–D-04) |
| C2 | Opening population (as of a date) | `starts_on` / `ends_on` interval | yes | yes | no | 4 · L · B (A-07) | OWNER DECISION |
| C3 | Admissions in a period | `converted_at`; a directly created Student has no admission event | yes | yes | no | 4 · L · B (A-03) | OWNER DECISION |
| C4 | Exits in a period | terminal placements with `ends_on`; Student inactivation is undated (D-13) | yes | yes | no for placements; yes for inactivation | 4 · L · B (A-02, A-04) | OWNER DECISION |
| C5 | Net movement, monthly closing strength | C2 to C4 | yes | yes; month windows only (D-02 recommendation) | no | 4 · L · B (A-07) | OWNER DECISION |
| C6 | Grade / Section strength | placements | yes | yes (high small-cell risk, gate §4) | no | 4 · L · B (A-05) | OWNER DECISION |
| C7 | Campus / School strength | placements; cross-School also needs ADR 0048 | yes | yes | no | 4 · L · B (A-05, A-10) | OWNER DECISION |
| C8 | Curriculum / board breakdown | `schools.education_board_id`; a board per Student is not modelled | — | — | — | 3 · B (A-06) | NO ACTION: inside one School the breakdown is the School itself |
| C9 | Gender distribution | **No gender column on `students` or `applicants`** | yes | yes | **yes** | 5 · L · D | See **SB-05** |
| C10 | Age distribution | `students.date_of_birth` (required); `StudentAge` | yes | yes (children's data) | no | 4 · L | OWNER DECISION (same gate as C1; adds nothing new) |
| C11 | Capacity | `sections.capacity`, `rooms.capacity` (advisory) | no | no | no | 1 | NO ACTION |
| C12 | Occupancy, vacancy, capacity versus Section strength | capacity + placements | yes | yes | no | 4 · L · B (A-11, F-08) | OWNER DECISION |
| C13 | Admissions ledger (who was admitted in a date range) | Admissions directory filters by status, year, campus, grade and name; **no date-range filter** | list only (row total allowed, ADR 0042 §11) | no | no | 3 · B (B-07) | DERIVE REPORT (already accepted as B-07) |
| C14 | Exits ledger | `/app/enrollments?status=withdrawn`; no `ends_on` range; no reason (D-13) | list only | no | no | 3 · B (B-07) | DERIVE REPORT (B-07) |
| C15 | Admissions by Grade / Campus (counts) | applications | yes | yes | no | 4 · L · B (A-03, A-05) | OWNER DECISION |
| C16 | Drill-down from summary to Students | — | — | the drill-down must re-check the source capability; never into a suppressed cell | — | 4 · L · B (A-12) | with V11.1 |
| C17 | Configurable / detail-column reports | Lists have fixed columns; no column chooser (searched `visibleColumns`, `columnChooser`, `hiddenColumns`) | — | — | — | 5 · O | OUT OF SCOPE: a presentation feature. Tabular takeaway belongs to SIS export (D-11) |

**Net-new:** C9 only (SB-05). Everything else repeats V11.0 Group A and
stays behind the existing gate.

## D. Admission commercial intelligence

**Where the data comes from:**
- **Charges.** `charges` holds `student_id`, `academic_year_id`, `amount`
  `NUMERIC(14,2)` INR, `due_date` and `cancelled_at`. It has no status, fee
  head, campus, grade or application column.
- **Fee head, period and placement.** These reach a charge only through
  `fee_assessments`: `fee_head_id`, installment, `student_enrollment_id`.
  Ad-hoc charges have no fee head.
- **Concessions.** `fee_concessions` is the request; `fee_adjustments` is
  the posted effect. The charge amount never changes.
- **Collected.** `ChargeStateReader` gives the effective allocation:
  allocations, plus correction lines, minus adjustments, with the
  closed-period carry-forward. This mirrors `payments_effective_allocated()`
  and is what the Student statement and Outstanding fees already use.
- **No cross-domain read model** joins Admissions with Fees or Payments
  (searched for `converted_at` / `admission_applications` in Fees,
  Payments, Finance and Analytics).

**Financial semantics any future report must keep:**
- "collected" is the **effective** allocation (UAT-C3, ADR 0031 amendment
  §3), never the original allocations;
- concessions come only from live `fee_adjustments`;
- cancelled charges are excluded from charged;
- posted payments are immutable;
- refunds and reversals are `payment_corrections`.

| # | Observation | Data owner | Cls | Action |
|---|---|---|---|---|
| D1 | Admissions by Grade / Campus | Admissions | 4 · L · B (C15) | as C15 |
| D2 | Gross fee charged per admission cohort | Admissions (cohort) × Fees (`ChargeService`) | 4 · L · D | **SB-04** |
| D3 | Concessions / discounts per cohort | Fees (`fee_adjustments`) | 4 · L · D | SB-04 |
| D4 | Net billed | Fees | 4 · L · D | SB-04 |
| D5 | Collected | Payments (`ChargeStateReader`, effective) | 4 · L · D | SB-04 |
| D6 | Outstanding | Payments | per Student: **1** (Finance → Outstanding fees, UAT-C3); grouped: 4 · L · D | grouped: SB-04 |
| D7 | Revenue effect of cancellation / inactivation | Fees (`cancelled_at`, cancellation journal). Withdrawal never cancels charges (FINANCE.md "Student/enrollment boundary") | 4 · L · D | SB-04; leaver dues are SB-03 |
| D8 | Average fee / revenue per admission | ratio of two people-derived cells | 4 · L · D | SB-04 (suppress the ratio if either part is suppressed) |
| D9 | Curriculum × Grade, curriculum × Campus | board per School | 3 · B (A-06) | NO ACTION: reduces to Grade / Campus |
| D10 | Fee-head breakdown | `fee_assessments.fee_head_id`; ad-hoc charges have none | 3 · L · D · B (V11.7 §15 finance reports P1) | SB-04 for grouped money. A School-level collection/day-book is already accepted backlog |
| D11 | Admission source / locality | **Not modelled.** `applicants` deliberately has no contact fields (migration comment); no source, referral or channel column | 5 · L · O | OUT OF SCOPE: new personal data for marketing purposes needs a privacy basis first |
| D12 | Before-admission versus admitted fee / discount | No pre-conversion fee exists (FINANCE.md "Admissions boundary": deferred to its own ADR). `admission_fee_selections` is intent, never money (OPF.3) | 5 · O | OUT OF SCOPE |

**Net-new:** SB-04, a decision rather than a build. Grouped money for an
admission cohort is a Highly Sensitive aggregate (ADR 0040 §6: an
aggregate takes its strongest source tier). In a small Grade it would show
one family's balance. The 2026-09-23 amendment §1 covers cells that "could
disclose information about people". Whether that brings Grade-grouped money
under the minimum-cohort rule is **not stated in words**, so it is an owner
decision, not an assumption.

## E. Student lifecycle

As built (unchanged since V11.0 C-01 to C-13; V11.3 did not reopen
PHASE-1E-0):

| # | Observation | Lycenza equivalent | Cls | Action |
|---|---|---|---|---|
| E1 | Inactive / withdrawn Students | `StudentService::changeStatus()` (`active↔inactive`); `StudentEnrollmentService::withdraw()` | 1 | NO ACTION |
| E2 | Request date | none | 5 · D · B (D-13) | OWNER DECISION (D-13) |
| E3 | Approval date, approver | none (C-08) | 5 · D · B (D-13) | OWNER DECISION (D-13) |
| E4 | Effective date | placement `ends_on`: yes. Student status: no | 3 · D · B (D-13) | OWNER DECISION (D-13) |
| E5 | Requestor | the actor is in the audit ledger (`school.audit.view`); no request object | 3 · D · B (D-13) | OWNER DECISION (D-13) |
| E6 | Reason | none on Student or placement. Only an admission accept/reject `decision_note` | 5 · D · B (C-06, D-13) | OWNER DECISION (D-13) |
| E7 | Reactivation | the same toggle; never re-admission; undated | 1 | NO ACTION |
| E8 | Suspension | none: no discipline domain (PHASE-1E-0 §4) | 5 · O · B (C-04) | OUT OF SCOPE |
| E9 | Revocation | no Lycenza meaning. The nearest are `cancel()` for a placement and application withdrawal | — | NO ACTION |
| E10 | Promotions | rollover plans with dry run; UAT-C2 bulk decisions | 1 | NO ACTION |
| E11 | Promotion pending | rollover item `undecided` / `manual_review`; dry-run `review` / `blocked` | 1 | NO ACTION |
| E12 | Branch (campus) transfer | `transferPlacement()` allows a campus change in the same School, year and grade | 1 | NO ACTION |
| E13 | Section / class movement | `transferPlacement()`: old row `transferred`, new row `active` | 1 | NO ACTION |
| E14 | Movement history | V11.2 Student history (record, placement, guardian, admission); Enrollment history | 1 | NO ACTION. Rollover-item events are outside the history allowlist, but the resulting placements show |
| E15 | Lifecycle audit trail | `student.*`, `student_enrollment.*`, `admission_application.*`, `enrollment_rollover*` events | 1 | NO ACTION |

**Boundary kept:** reason, effective date and approval remain the existing
**D-13** owner decision. The benchmark adds no new requirement here, only
the same one. Nothing in this audit reopens PHASE-1E-0 §13.2.

## F. Inactive-student checkpoints

| # | Benchmark checkpoint | Structurally prevented | Validated on mutation | Data Health finding today | Could be derived live | Lacks authoritative data | Result |
|---|---|---|---|---|---|---|---|
| F1 | Status / placement consistency after exit | one active placement per year (DB) | — | **yes:** `student.inactive_with_active_placement`, `enrollment.active_in_closed_year`, `enrollment.active_in_inactive_section` | — | — | 1 · NO ACTION |
| F2 | Back-dated effective date | no | only `ends_on >= starts_on` (service and DB CHECK); no comparison with today or with existing attendance | no | yes, through its consequences (F3, F6) | — | 4 → SB-02, SB-03a |
| F3 | Attendance after effective exit | **new registers: yes.** The roster is the interval rule `starts_on <= date <= ends_on`, and submission must match it exactly (`AttendanceSubmissionService`) | — | **no** | **yes**: `attendance_records` → session date versus the placement's `ends_on` | — | 4 → **SB-02** |
| F4 | Transfer / leaving-certificate status | — | — | — | — | yes: no certificate concept (C-05) | 5 · B (C-05) · NO ACTION |
| F5 | Dues outstanding at exit | — | — | — | yes (Outstanding fees per Student exists, but leavers cannot be isolated) | — | 3 → **SB-03b** |
| F6 | Fee plan still assigned | **There is no per-Student fee plan.** Fee runs exclude inactive Students (`student_inactive`) and placements that ended before the billing period. But charges billed **before** a withdrawal for later periods stay live (FINANCE.md: no auto-cancel) | run eligibility, at execution | no | **yes**: `fee_assessments` (installment period, `student_enrollment_id`) versus the placement's `ends_on` | — | 4 → **SB-03a** |
| F7 | Missing documents | — | — | — | — | yes: no required-document rule; Student documents not activated | 5 · B (C-13, OD-SWC-2) · NO ACTION (never infer from empty storage) |
| F8 | Approval ageing | — | — | — | — | yes: no lifecycle approvals (D-13) | NO ACTION |
| F9 | Reason validity | — | — | — | — | yes: no reasons (D-13) | NO ACTION |
| F10 | Re-entry / reactivation consistency | — | — | yes: `student.active_without_current_placement` | — | — | 1 · NO ACTION |

**Noted, no action:**
- **Late fees.** `LateFeeRunService` selects overdue charges without
  checking the Student's status. A leaver's unpaid dues are still owed,
  so this is consistent, not a defect.
- **Wrong end dates.** If a back-dated end date was itself wrong, there is
  no correction path: an ended placement keeps its `ends_on` and status
  (retention guard trigger, `2026_11_30_090100`). Changing that would
  reopen the lifecycle contract, so it belongs to **D-13**.

## G. Attendance

**What a register is.** One `AttendanceSession` per TimetableEntry per
date: a Section × SubjectOffering × Period × teacher. Records hold only
`present|absent|late|excused`. By rule there is no reason, arrival time or
minutes-late field (`AttendanceArchitectureGuardTest`; ATTENDANCE.md §3–4).
Student Attendance emits no domain events.

| # | Observation | Lycenza | Cls | Action |
|---|---|---|---|---|
| G1 | Daily attendance | Per-period registers; a day view per register; the day exceptions list | 4 | NO ACTION (a day status is derivable; no daily register by design) |
| G2 | Monthly attendance per Student | not built; rates are Analytics | 4 · L · B (ATT-07 multi-day view) | backlog ATT-07; rates OWNER DECISION |
| G3 | Grade / Section reporting | per register; the exceptions list filters by Section | 1 | NO ACTION |
| G4 | Campus-consolidated reporting | the exceptions list is School-wide; no campus filter | 3 | NO ACTION (a filter, P3, not a new report) |
| G5 | Session-wise (morning / afternoon) | not modelled for Students (staff attendance has halves). Period registers are finer-grained | 5 · O | OUT OF SCOPE: a different attendance mode, not a reporting gap |
| G6 | Period-wise | **the register is the period** | 1 | NO ACTION |
| G7 | Subject / course | the register is Section × SubjectOffering | 1 | NO ACTION |
| G8 | Examination attendance | `student_marks.status` `present|absent|exempt` (development only, RES-L1) | 3 · L | NO ACTION (legal gate E35–E37) |
| G9 | Average attendance | a rate is Analytics (ADR 0040) | 4 · L | OWNER DECISION (gate) |
| G10 | Late-comer analysis | the `late` status, one day at a time; no arrival time by design | 3 · L · B (ATT-07) | NO ACTION |
| G11 | Attendance-not-marked exceptions | the Take page marks each of the day's classes `alreadySubmitted` (`AttendanceController`), for `attendance.manage` and one date. **No list of registers still not submitted** (searched unmarked, not marked, not taken, missing register) | 2 | **SB-01** |
| G12 | Biometric / RFID | **absent.** Repo-wide search found only negative comments. ADR 0065 §1 and §10 exclude device clock-in; HRX-L2 open; ATTENDANCE.md §16 excludes capture | 5 · I · L · O | OUT OF SCOPE as attendance reporting. It is an **integration capability** needing device architecture, authentication, ingestion, replay/idempotency, biometric legal review, retention and vendor trust. It is never a prerequisite for attendance maturity (V2 discovery: RFID) |

**Net-new:** G11 (SB-01).

## H. Student leave

- **No Student leave entity exists.** Searched: student leave, absence
  intimation/request/report, leave application. The Leave domain is
  staff-only: `leave_requests` is bound to `employment_record_id` and
  `employee_id`. HR leave is not reusable as is: its approvers,
  entitlements, working-day calendar and payroll evidence are all
  Employee-bound.
- **`excused` carries no reason or attachment by design.** ATTENDANCE.md
  places reasons in the Health tier, which is a legal gate.
- **No Guardian path reports an absence.** The portal attendance view is
  read-only and development-only (POR-L1, E46).
- **Notifications** must follow Business Event → Notification Policy →
  Audience → Channel → Delivery:
  - Guardian-facing automation is ADR 0043 tier 4 (prohibited);
  - tier-1 staff notifications wait on D-08;
  - V11 channels are email and in-app only;
  - no recipient lists in controllers.

**Classification:** 5 · L · D, see **SB-06**.

## I. Relationships, segmentation and groups

| # | Observation | Lycenza | Semantics | Cls | Action |
|---|---|---|---|---|---|
| I1 | Guardian relationships | `student_guardian_relationships` (type, primary, legal, emergency, pickup) | — | 1 | NO ACTION |
| I2 | Sibling derivation | Derivable from a shared `guardian_id`, with no sibling table and no contact lookup (ADR 0041 untouched). `RelationshipType::Sibling` means "the Guardian is the Student's sibling", not a Student-to-Student link | — | 4 · B (B-10, not built in V11.2) | NO ACTION beyond B-10 |
| I3 | Students with / without siblings (counts) | person counts | — | 4 · L | OWNER DECISION (gate) |
| I4 | Temporary cohorts | Communications audiences resolve grade, Section or offering at send time | temporary cohort | 1 | NO ACTION |
| I5 | Academic groups / streams | elective groups (mutually exclusive choices) + subject offerings per grade | structural academic grouping | 3 | NO ACTION (a named "stream" package is not modelled; no pilot need recorded) |
| I6 | Practical / lab batches (sub-Section groups) | **none.** Timetable and attendance address whole Sections | structural academic grouping | 5 | SB-07: would change Timetable and Attendance, not reporting |
| I7 | Houses | **none** (searched house, segment, tag) | reporting tag / membership | 5 | SB-07 |
| I8 | Clubs, extracurricular activities, participation | **none** (searched club, activity, co-curricular) | co-curricular membership + participation | 5 | SB-07 |
| I9 | Custom groups, subgroup membership, active / inactive membership | **none** | reporting tag | 5 | SB-07 |

## J. Documents and profile operations

- **What the schema supports.** `documents` can be owned by `employee_id`,
  `student_id`, `guardian_id`, `learning_content_id` or `assignment_id`.
  It has a `classification_tier` and `status` (`active|archived`), but **no
  category or document-type column**.
- **Activated owner types:** Employee, learning content and assignment
  only. Student and Guardian throw `DocumentOwnerTypeNotSupportedException`
  (OD-SWC-2, deferred post-v1).
- **Download** is an application-mediated stream checked by capability.
  There are no public or signed URLs.
- **Storage paths** are built by `TenantStoragePath::for()`.
- **Retention:** a document is purged only with its owner, under E21 D7.

| # | Observation | Cls | Action |
|---|---|---|---|
| J1 | Student documents | 3 · B (OD-SWC-2) | NO ACTION. Activation stays deferred. **Do not create a second repository.** When activated, a category column will be needed (HR's `employee_documents` already has one) |
| J2 | Guardian documents | 3 · B (OD-SWC-2) | NO ACTION |
| J3 | Profile photographs | 5 · L (ADR 0075 §10.2: privacy review before modelling) | LEGAL/PRIVACY REVIEW (existing) |
| J4 | Certificates (transfer, leaving, bonafide) | 5 · B (C-05) | NO ACTION |
| J5 | Report cards | 5 · L (E39–E42) | NO ACTION |
| J6 | Document categories | 5 | folded into J1 |
| J7 | Status / completeness | 5 · B (C-13) | NO ACTION: no "required document" concept exists, so completeness must not be inferred from empty storage |
| J8 | Secure download | 1 | NO ACTION |

**Sensitive dimensions:**

| Dimension | Stored today? | Classification / purpose | Cls | Action |
|---|---|---|---|---|
| Blood group | no | Health: Highly Sensitive, LEGAL REVIEW REQUIRED | 5 · L | OUT OF SCOPE (never for analytics) |
| Mother tongue | no | not modelled (ADMISSIONS.md deferred fields) | 5 · L | OUT OF SCOPE; statutory-return question in SB-05 |
| Religion / caste / category | no | not modelled | 5 · L | OUT OF SCOPE; SB-05 |
| Gender | no | not modelled | 5 · L · D | SB-05 |
| Transport user | derivable: active `transport_student_assignments` (purpose: route operation; location is Sensitive, TRANSPORT.md) | — | 4 · L | NO ACTION; secondary reporting use is a gate question |
| Hostel / day scholar | derivable: active `hostel_residency_assignments` (Sensitive) | — | 4 · L | NO ACTION |

## K. Teacher and staff reporting

| # | Observation | Lycenza | Cls | Action |
|---|---|---|---|---|
| K1 | Subject-teacher list | Teaching assignments with teacher and Section filters (V11.2); dated, `end_reason` | 1 | NO ACTION |
| K2 | Class-teacher list | **absent by decision:** ADR 0063 D-02 "APPROVED — DEFERRED"; no `sections` teacher column | 5 · D · B (ADR 0063 D-02) | NO ACTION (owner revisits D-02 only if a pilot needs it) |
| K3 | Weekly / daily timetable workload per teacher | the admin schedule filtered by teacher shows the weekly entries; `teacher_id` is schedule evidence, not authority | 2 · B (B-06, B-11) | NO ACTION. A period count per teacher was classed as gated in V11.0 B-06 and is not reclassified here |
| K4 | Assigned versus committed workload | **no contracted-workload data** (`positions` and `employment_records` have no load field) | 5 · O | OUT OF SCOPE: timetable periods must never be labelled "workload compliance" |
| K5 | Assignment / change history | dated assignments; HR Employee timeline **with actor** (`hr.employees.personal.view`) | 1 | NO ACTION |
| K6 | Teaching-staff activity | curriculum delivery per Section × unit (no teacher column, no lesson log); register `submitted_by_user_id` | 3 | NO ACTION |
| K7 | Substitution / cover | a dated cover TeachingAssignment (ADR 0063 D-15) | 1 | NO ACTION (day-specific substitution: V2) |
| K8 | Non-teaching duties | none | 5 · O | OUT OF SCOPE (V2) |
| K9 | Teacher's own timetable | "classes today" inside My Attendance only | 3 · D · B (V11.7 §15 P2; ADR 0063 authorization) | NO ACTION |

## L. Audit and operational visibility

| # | Observation | Lycenza | Cls | Action |
|---|---|---|---|---|
| L1 | Student update history | V11.2 Student history. **Actor deliberately not shown**; who acted is behind `school.audit.view`, whose reads are themselves audited | 1 | NO ACTION; keep the boundary |
| L2 | Staff change history | HR Employee timeline (with actor, `hr.employees.personal.view`) | 1 | NO ACTION |
| L3 | Branch / School activity | School audit log: 50 per page, unfiltered; campus is not an audit dimension | 3 · B (B-13; ADR 0042 amendment item 6) | NO ACTION |
| L4 | Lifecycle / movement changes | audited events | 1 | NO ACTION |
| L5 | Timestamps, actor identity | `occurred_at`, `actor_user_id` | 1 | NO ACTION |
| L6 | IP address, device, fingerprint | **not stored** on `school_audit_events`. Platform audit stores IP and user agent but no reader returns them | — | OUT OF SCOPE: **do not add** for display parity. A reporting UI must not widen who sees actor identity |

## M. Daily operations / School Pulse

| Signal | Lycenza | Cls |
|---|---|---|
| Staff attendance | daily staff register (`hr.staff_attendance.*`) | 1 |
| Classes / registers taken | per-period sessions; not-taken list missing | 2 → SB-01 |
| Student absences today | attendance exceptions (V11.2) | 1 |
| Rounds / classroom observations | none | 5 · O |
| Meetings | none | 5 · O |
| Events / School calendar | none (`staff_holidays` is a staff-only calendar owned by Leave; no Student holidays) | 5 |
| Incidents / discipline | none (C-04; health and safety records `[LEGAL REVIEW REQUIRED]`) | 5 · O · L |
| Admissions today | `converted_at`; no date filter | 3 · B (B-07) |
| Promotion / inactivation / reactivation today | rollover dated; inactivation undated | 3 · B (D-13) |
| Transfers today | `transferred` rows with `ends_on` | 4 |
| Visitors | Visitor log (status filter, no date filter) | 1 |
| Outstanding fees / overdue | Finance → Outstanding fees (UAT-C3) | 1 |
| Data Health | 8 checks (V11.5) | 1 |
| Operational notes / diary | none | 5 · O |

**Determination.**
- **Signals:** the signals that exist are each served by their own module
  page.
- **Dashboard:** the staff home (`DashboardController`) deliberately shows
  no business data.
- **Daily routine:** the Administrator Guide §22 already prescribes the
  daily routine across those pages.
- **What a Pulse page could be:** a presence-and-link page ("3 checks need
  action", "registers not yet taken") would add modest value, provided it
  shows no person counts and every link re-checks its own capability.
- **What a Pulse needs that is missing:** observations, meetings, events
  and incidents are new domains, not reporting gaps.

Recorded as **SB-08** (LATER CHECKPOINT, P3). Not built.

## N. V11 stream mapping

| Stream | Benchmark delta mapped to it | Duplicates already accepted (not new) |
|---|---|---|
| V11.1 (Analytics, BLOCKED) | SB-04 (decision: grouped money for admission cohorts) | strength, movement, admissions and exits counts, capacity versus strength, rates, distributions (A-01 to A-12, D-01 to D-04) |
| V11.2 (operational lists) | SB-01 (registers not taken), SB-03b (leaver filter on Outstanding fees) | B-07 date ranges, B-10 siblings, B-13 audit filters, D-11 export, D-12 global search, ATT-07 multi-day view |
| V11.3 (lifecycle) | none new | D-13 reason, effective date and approval; wrong terminal end dates; C-05 certificates |
| V11.4 (communications) | SB-06 notifications, if Student leave is approved (tier-1 staff only via D-08; Guardians tier 4) | D-07, D-08 |
| V11.5 (Data Health) | SB-02 (attendance after a placement ended), SB-03a (charge billed for a period after the placement ended) | D-05, D-06 guardian checks |
| V11.6 (learning spaces) | none. Capacity data shows no concrete new use case; D-09 "nothing for launch unless a pilot asks" stands | F-02 to F-08 |
| Later / outside V11 | SB-05 (gender and statutory demographics), SB-06 (Student leave), SB-07 (groups / co-curricular), SB-08 (School Pulse) | biometric (HRX-L2), class teacher (ADR 0063 D-02), Student documents (OD-SWC-2) |

## O. Legal, privacy and owner decisions

**Existing decisions, unchanged:**
- **D-01–D-04:** the gate; the V11.7 values remain recommendations only.
- **D-11:** SIS export.
- **D-13:** lifecycle reason, effective date and approval.
- **ADR 0063 D-02:** class teacher.
- **OD-SWC-2:** Student documents.
- **HRX-L2:** biometric attendance.
- **E35–E42:** marks, results and report cards.
- **E46 / POR-L1:** the portal.
- **ADR 0043 tier 4:** no Guardian automation.

**Proposed additions** to the register. These are proposals, not approved
decisions, and none blocks any stage.

*(2026-10-16, owner decisions recorded in ADR 0075 §22:
- **D-14:** APPROVED CLASSIFICATION, privacy-gated.
- **D-15:** do not add now.
- **D-16:** defer.
- **D-17:** defer; no generic grouping framework.

SB-01 to SB-03b are accepted as a post-staging P2 backlog. They are not staging
blockers. The "Recommended answer" column below is kept as written.)*

| ID | Question | Why | Recommended answer (not approval) | Gate |
|---|---|---|---|---|
| D-14 | Are money totals grouped by a people dimension (admission cohort, Grade, Campus, fee head) person-derived Analytics cells under the ADR 0040 amendment §1 ("could disclose information about people")? | SB-04. A one-Student Grade discloses one family's balance | **Yes**: treat them as Highly Sensitive person-derived cells under the same N and complementary suppression, decided together with D-01–D-03. An ungrouped School-wide collection/day-book for Finance staff stays an operational Finance list (V11.7 backlog) | Owner + privacy |
| D-15 | Should Lycenza record Student gender (and any other statutory demographic) to support statutory returns? | SB-05. Benchmark distributions assume it; Lycenza stores none. Never introduce it for analytics alone | **Not now.** Only if a pilot School needs a specific statutory return, with the field list taken from that return. Purpose, legal basis and access decided first. Children's data | Owner → legal / DPO |
| D-16 | Is a Student leave / absence intimation workflow wanted, and who submits it (office on a Guardian's behalf, or the portal)? | SB-06. Interacts with `excused` (no reason by design), POR-L1 and ADR 0043 tier 4 | **Defer.** If a pilot asks, start office-recorded, staff-only, with no reason text (Health tier) and no Guardian notification | Owner (+ DPO if reasons or attachments) |
| D-17 | Are houses, clubs, activities or lab batches needed for the pilot? | SB-07. Four different semantics | **Defer** until a pilot names one. Lab batches touch Timetable and Attendance and need their own design | Owner |

## P. Completed SIS benchmark delta

Only real delta findings appear here. An observation that repeats accepted
V11 work or an existing decision is in the full matrices above, not here.

# Completed SIS benchmark delta

| New observation | Lycenza equivalent | Classification | Target V11 checkpoint | Action / no action |
|---|---|---|---|---|
| **SB-01** No list of class registers still not taken for a date | The Take page flags each of the day's classes `alreadySubmitted` (one date, `attendance.manage` only); no admin or principal list | 2 Implemented but inadequately exposed | V11.2 | **UX EXPOSURE.** An operational list of active-year timetable classes for one chosen date with no submitted register: Section, subject, period, timetabled teacher. Counts registers, not people. One date at a time, because no Student holiday calendar exists. P2 |
| **SB-02** Attendance recorded on dates after a placement's end date (back-dated withdrawal or completion) | `ends_on` accepts any date ≥ `starts_on`; corrections deliberately never re-check placement (`AttendanceCorrectionService`); no Data Health check | 4 Derivable | V11.5 | **DATA HEALTH CANDIDATE.** Attendance-owned, Review severity, gated by `attendance.view` + `enrollments.view`, live and read-only. Attendance stays authoritative; no automatic repair. A wrong terminal end date has no correction path (D-13). P2 |
| **SB-03a** Uncancelled charge for a billing period that starts after the Student's placement ended | Withdrawal never cancels charges (FINANCE.md boundary); runs exclude only placements already ended at run time; no check | 4 Derivable | V11.5 | **DATA HEALTH CANDIDATE.** Fees-owned; `fee_assessments` installment period versus the placement end through the Students read contract; `finance.charges.view`. Repair through the existing charge cancellation or assessment void, after any payment is moved. Never automatic. P2 |
| **SB-03b** Dues of Students who have left cannot be isolated | Outstanding fees lists every Student with dues, but its Grade/Section filter uses only active current-year placements; no campus filter | 3 Partially implemented | V11.2 (Finance → Outstanding fees) | **DERIVE REPORT.** A placement-state filter ("no active placement" / ended in year X) and a campus filter on the existing list; effective-allocation amounts unchanged; no export. P2 |
| **SB-04** Admission-cohort commercial view (charged, concessions, net, collected, outstanding by Grade / Campus / fee head) | Every input exists across the Admissions, Fees and Payments read contracts; no joined read model; grouped money is a Highly Sensitive aggregate | 4 Derivable · L · D | V11.1 | **OWNER DECISION (D-14)** before any design. DECISION |
| **SB-05** Gender (and other statutory demographics) for distributions and returns | Not stored for Students or applicants | 5 Genuinely missing · L · D | none (Students module, outside V11) | **LEGAL/PRIVACY REVIEW (D-15).** Never added for analytics. DECISION |
| **SB-06** Student leave / absence intimation, with staff notification | None. Leave is Employee-bound; `excused` has no reason; no Guardian absence path | 5 Genuinely missing · L · D | later (with V11.4 for notifications) | **OWNER DECISION (D-16)**, then LATER CHECKPOINT. P3 |
| **SB-07** Houses, clubs, co-curricular participation, lab batches | None (only Sections, elective groups and send-time audiences) | 5 Genuinely missing · D | later | **LATER CHECKPOINT (D-17)**, only on a named pilot need. P3 |
| **SB-08** Daily operations / School Pulse | Signals exist on separate pages; the Administrator Guide §22 daily routine; staff home shows no business data | 3 Partially implemented | later | **LATER CHECKPOINT.** A presence-and-link page, no person counts. P3 |

## Q. Net-new prioritised backlog

No P0 or P1 item was found.

| Priority | ID | Item | Kind | Schema | Capability |
|---|---|---|---|---|---|
| P2 | SB-01 | Registers not yet taken (one date) | operational list | none | `attendance.view` |
| P2 | SB-02 | Attendance after placement end | Data Health check (Attendance) | none | `attendance.view` + `enrollments.view` |
| P2 | SB-03a | Charge for a period after placement end | Data Health check (Fees) | none | `finance.charges.view` (+ `enrollments.view` for placement detail) |
| P2 | SB-03b | Leaver and campus filters on Outstanding fees | list filter | none | existing Outstanding gates |
| P3 | SB-08 | School Pulse presence-and-link page | composition | none | per-signal |
| P3 | SB-06 | Student leave | new domain behaviour | new | new (after D-16) |
| P3 | SB-07 | Groups / co-curricular | new domain behaviour | new | new (after D-17) |
| DECISION | SB-04 | Admission commercial intelligence | Analytics | none | `analytics.view` (after D-14 and D-01–D-04) |
| DECISION | SB-05 | Student gender / statutory demographics | new personal data | new | after D-15 |

**Expected decision standard met:**
- **One real customer problem:** collecting dues from Students who have left
  (SB-03).
- **One useful derivable report:** registers not taken (SB-01).
- **One missing operational control:** attendance after a back-dated exit
  (SB-02).

## R. Explicit no-action list

These benchmark capabilities are already adequately served:
- **Period-wise and subject-wise attendance:** the register is a period of a
  subject offering.
- **One day's absent / late / excused** across registers (V11.2).
- **Lifecycle movements:** promotions and promotion-pending through rollover
  with dry run and bulk decisions (UAT-C2); cross-campus and cross-Section
  transfer; reactivation.
- **Movement history:** per-Student movement history (V11.2), with actor
  identity correctly withheld.
- **Staff change history:** the HR Employee timeline, with actor.
- **Teaching:** subject-teacher list and per-teacher weekly schedule
  (V11.2, X-04); cover as dated assignments.
- **Status / placement consistency after exit,** and re-entry without
  placement (V11.5 Data Health).
- **Fees for inactive or already-ended placements** are excluded from new
  fee runs at execution.
- **Per-Student outstanding dues and overdue** (UAT-C3), with
  effective-allocation amounts after corrections.
- **Capacity fields** on Sections and Rooms.
- **Secure document download** (capability-checked stream, no public URLs).
- **Daily staff attendance,** visitor log, Data Health.
- **Exam absence** in marks (development only, legal gate).
- **Guardian relationships;** temporary cohorts via Communications
  audiences.

Deliberately not pursued:
- IP / device / fingerprint display;
- configurable columns;
- session (half-day) Student attendance;
- biometric / RFID as attendance maturity;
- contracted-workload "compliance";
- admission source / locality;
- pre-admission fee comparison;
- blood group and other health or demographic dimensions for analytics.

## S. Readiness impact

| Stage | Change? | Basis |
|---|---|---|
| Local UAT | **No change**: ready with limitations | No S1; every delta is P2, P3 or a decision |
| Product-side staging handoff | **No change: YES** | No newly discovered product blocker. SB-02 and SB-03a describe states reachable only through back-dated or late lifecycle actions, are visible per record today, and are reported, not corrupting |
| Actual staging | No change: blocked outside this stream (PPR, E16-R2 hold) | — |
| Pilot | **No new requirement.** SB-03 is worth having before a School collects dues from leavers in earnest; it is not a gate | — |
| Production | **No new requirement** | D-14 and D-15 join the decision register, not the O1 checklist |

Benchmark breadth alone is not a blocker, and readiness is not downgraded.
The post-C6 baseline (no Local-UAT S1, Local UAT ready with limitations,
staging handoff YES) holds.

## T. Recommended next checkpoint

**No new V11 implementation should precede PPR / staging.**
- Every net-new finding is P2, P3 or a decision.
- The owner's current direction already moves product work to PPR, E16-R2
  and legal-gate closure.

When product work resumes, the natural first slice is one small, read-only
bundle:
- SB-01, SB-02, SB-03a and SB-03b;
- no migration, no capability, no export;
- each item in its owning module.

It needs owner authorisation and is **not started**. D-14 to D-17 can be
answered at any time without engineering.

## Handoffs (recorded, not changed)

- **PPR:** none from this audit. AWS, staging, backup/restore, monitoring,
  production email and release qualification are untouched.
- **E16:**
  - not reopened;
  - the successor approval is active;
  - the nginx.org R2 candidate is still held;
  - the app image is not VERIFIED.
