# V11.0 — Commercial Readiness Repository Audit (evidence record)

- Date: 2026-10-15. Read-only audit; no code or data changed.
- Baseline: `origin/main` = local `main` = `HEAD` =
  `6e11bc143529106044f6657952fc7de2500f433b` (PPR.2D). Tree clean, one
  worktree, the historical stash `phase-5d3-preflight` untouched.
- Contract: [ADR 0075](../architecture/adr/0075-v11-commercial-readiness-programme-contract.md).
- *(2026-10-15: V11.2 implemented the justified Group B rows (B-02, B-04,
  B-09, B-11, B-12) and list row totals; B-10 was not built.
  V11.5 implemented Group E as eight read-only checks (ADR 0075 §10).
  V11.3 implemented C-10 (narrowed: completion of closed-year placements, ADR 0075 §11). See ADR 0075 §9 for
  the implementation audit and what stays deferred. This record stays the
  V11.0 evidence as written.)*
- Scope: the six candidate groups (A–F) in the owner's V11 brief. **No
  benchmark material exists in this repository.** No competitor screenshot,
  text, customer data or personal data was used or recorded. Every
  candidate below is a business need stated in the owner's brief, checked
  against Lycenza's own code, schema, tests and decisions.

Paths are relative to `apps/platform/` unless they begin with `docs/`.

## Classification key

| Code | Meaning |
|---|---|
| 1 | Already implemented |
| 2 | Implemented, but poorly exposed in the staff web |
| 3 | Partially implemented |
| 4 | Derivable from existing authoritative data |
| 5 | New domain behaviour |
| 6 | Inappropriate, out of scope, or blocked by a recorded decision |

**"Gated"** means a recorded legal, privacy or architecture decision blocks
the work today. The gate is cited each time.

## The governing boundary: operational lists versus Analytics

This boundary decides most of the classifications below, so it comes first.

- **Operational query** (ADR 0040 §1, lines 73–79): "serves one business
  transaction's own UI/API need, inside the owning module". It may return
  single rows. A filtered list of records the viewer may already see is not
  Analytics.
- **Row totals** (ADR 0042 §11, line 285): "A total of the rows the viewer is
  already looking at … discloses nothing new and is allowed; any other
  person count is Analytics and follows ADR 0040."
- **Person-counting Analytics.** Any cell or denominator that counts
  Students, Guardians, Employees, applicants or visitors falls here (ADR 0040
  amendment 2026-09-23 §1). It is **blocked and fails closed**:
  - `App\Domain\Analytics\Application\CohortSuppressionPolicy` returns
    HTTP 503 while `analytics.minimum_person_cohort_size`
    (`config/analytics.php:23`) is unset.
  - `Tests\Feature\Analytics\AnalyticsArchitectureGuardTest` (line 77)
    refuses any registered read model with `countsPeople = true`.
- **What unblocks it** (`docs/security/ANALYTICS-SMALL-COHORT-POLICY-GATE.md` §7, §9):
  - 7.1: the minimum number (legal/privacy, product and security sign-off);
  - 7.2: the suppression mode and filter granularity;
  - 7.3: the policy scope;
  - 7.4: counsel review for Student-data Analytics (LEGAL).
- **Access is separate both ways** (ADR 0040 §5). Holding a source-record
  capability never grants Analytics, and `analytics.view` never grants
  drill-down. That makes it non-compliant to reuse `students.view` as the
  gate for a headcount dashboard.

## Group A — Executive / enrollment intelligence

**Authoritative lifecycle facts** (see Group C for the full contract):
- `students.status` is `active|inactive`, with no date and no reason
  (`StudentService::changeStatus()`). This was decided in
  `docs/students/PHASE-1E-0-STUDENT-LIFECYCLE-ARCHITECTURE.md` §9–§12.
- `student_enrollments.status` is
  `active|completed|withdrawn|transferred|cancelled`, with `starts_on` and
  `ends_on` (inclusive). The status is enforced in the application only;
  there is no database CHECK on it.
- The Student has no admission date. Admission conversion supplies the
  enrollment `starts_on`, and `admission_applications.converted_at` is a
  timestamp.

| ID | Candidate | Cls | Evidence | Current state / gap | Legal & authz | Schema | Checkpoint |
|---|---|---|---|---|---|---|---|
| A-01 | Total / active / inactive students | 4 → **gated** | `Student.status`; `StudentController@index` filters by status | A filtered list works today; the row total is allowed (ADR 0042 §11) but not rendered (`Pagination.vue` shows links only). As a dashboard figure it is person-counting | Small-cohort gate 7.1–7.4; `analytics.view` | none | V11.1 (blocked); total on list: V11.2 |
| A-02 | Withdrawn / transferred / passed-out | 4 → **gated** (passed-out: 6) | Enrollment terminal statuses; rollover `terminal_grade` creates no target | "Passed-out" is not a Lycenza state. Graduation is deferred (PHASE-1E-0 §13.3) | Gate; graduation decision | none (passed-out would be new state) | V11.1 (blocked) |
| A-03 | Newly admitted | 4 → **gated** | `admission_applications.converted_at`; enrollment `starts_on` | Use `converted_at` (School-local date), never `students.created_at` (brief §15). Direct Student creation, outside Admissions, has no admission event | Gate | none | V11.1 (blocked) |
| A-04 | Reactivated | **not derivable as a dated metric** | `student.status_changed` audit event only | No effective date. Counting by audit timestamp would replace a business-effective date with a technical one, which brief §15 forbids | Gate, plus reopening PHASE-1E-0 §13.2 | new state (effective date) | V11.3 decision first |
| A-05 | School / Campus / grade / section strength | 4 → **gated** | `StudentEnrollmentReadService::directory()` filters (year, campus, grade, section, status) | The filtered list exists at `/app/enrollments`. Grouped headcounts are the gate's own example of high small-cohort risk (gate §4 row 1) | Gate 7.1–7.4 | read model | V11.1 (blocked) |
| A-06 | Curriculum / board breakdown | 4 → **gated** | `education_boards` (platform catalogue); School board on the profile | Per-School board, so a School-level grouping is trivial. Per-Student board is not modelled | Gate | none | V11.1 (blocked) |
| A-07 | Academic-year comparison, month-on-month movement | 3 → **gated** | `starts_on` / `ends_on`; transfer = old row `transferred` + new row | Admissions, transfers and withdrawals have dates. Inactivation and reactivation do not (A-04). **Rollover leaves the source enrollment `active`** (`EnrollmentRolloverItemExecutionService` docblock), so "current population" counts a Student twice across years unless every query pins one academic year | Gate | none | V11.1 (blocked) |
| A-08 | Promotions | 1 (per plan) | `EnrollmentRolloverReadService::executionSummary()`; `EnrollmentRollovers/Show.vue` totals | Per-plan summary exists (process counts within one plan) | `enrollments.rollovers.view` | none | — |
| A-09 | Staff totals; teaching / non-teaching | 4 → **gated**; teaching split: 5 | `EmployeeDirectoryService`; `employee_categories` (School-configurable, nullable on `employment_records`); `TeachingAssignment` | Counting Employees is person-counting (gate §4 "HR employees"). "Teaching" has no fixed meaning: a School names its own categories. The authoritative teaching fact is a dated TeachingAssignment | Gate; ADR 0071 sensitivity | new mapping if a fixed split is wanted | V11.1 (blocked) |
| A-10 | Campus / School comparison | **gated**; cross-School: 6 | ADR 0048 allows Group reporting for `curriculum.coverage` only | A cross-School person count needs both the gate and an ADR 0048 amendment | Gate + ADR 0048 | none | V2 / amendment |
| A-11 | Occupancy / capacity | 4 → **gated** | `sections.capacity` and `rooms.capacity` are advisory (`docs/modules/ACADEMIC-STRUCTURE.md:145`); nothing enforces them | Capacity alone is not person data. "Enrolled versus capacity" is a headcount | Gate | none | V11.6 (count part blocked) |
| A-12 | Drill-down from metrics | 6 until A-01…A-11 | ADR 0040 §5; gate §6 item 7 | Never into a suppressed cell; always also checks the source capability | — | — | with V11.1 |

## Group B — SIS search, reporting and operational visibility

| ID | Candidate | Cls | Evidence | Gap | Authz | Schema | Checkpoint |
|---|---|---|---|---|---|---|---|
| B-01 | Global cross-entity search | 3 | Searches are per module only (`StudentController@index`, `GuardianController@index`, `HrEmployeeController@index`); no omni-search in `resources/js` | No cross-entity box. Each result row must still pass its own capability check | per entity | none | V11.2 |
| B-02 | Staff search with campus / department / position / sort | **2** | `EmployeeDirectoryService::search()` and the API `EmployeeDirectoryController` already support them; the web `HrEmployeeController@index` passes only `search` and `include_archived` | Web lags the API | `hr.employees.view` | none | V11.2 |
| B-03 | Identifier search (student number, roll number) | 1 / 2 | `student_number` exact on Students and Enrollments; `roll_number` exact only on `/app/enrollments` | Roll number is missing on Students. Application number deliberately deferred (`docs/modules/ADMISSIONS.md` §5) → 6 | `students.view`, `enrollments.view` | none | V11.2 |
| B-04 | Campus / year / grade / section filters on the student list | **2** | `StudentEnrollmentController@index` has all of them; `StudentController@index` has none (Students carry no placement) | Two list models with no link between them. Fix: cross-link, or placement filters through `StudentEnrollmentReadService` | `enrollments.view` is independent of `students.view` | none | V11.2 |
| B-05 | Guardian contact lookup | 6 as a search | `GuardianContactService::findCandidatesBySchool()` (exact HMAC, ADR 0041) | Sanctioned only as a duplicate check while linking. ADMISSIONS.md §14: "never a staff-facing search feature" | ADR 0041 decision | none | Owner decision |
| B-06 | Strength, admissions-funnel, teacher-load counts | **gated** | Gate §4; ADR 0040 amendment §1 | As Group A | Gate | read model | V11.1 (blocked) |
| B-07 | Admissions, inactive, withdrawn lists | 1 | Admissions directory (status, year, campus, grade, name); Enrollments by status | No date-range filter. No withdrawal reason (not modelled → 5) | module `.view` | none | V11.2 (date filter) |
| B-08 | Promotion results | 1 | Rollover Show summaries and item filters | — | `enrollments.rollovers.view` | none | — |
| B-09 | Attendance exceptions (absentees across registers) | 4 | `AttendanceController@index` filters by date only; absentees visible per register (`attendance_records` → enrollment) | No cross-register absentee list. Absence **rates** are gated Analytics | `attendance.view` | read model | V11.2 |
| B-10 | Sibling / shared-guardian view | 4 | `Guardians/Show.vue` lists linked Students | No sibling block on the Student page. Portal sibling disclosure is restricted (ADR 0070 §26.5) | `students.view` + `guardians.view` | none | V11.2 |
| B-11 | Teacher workload list (per teacher) | **2** | API `TeachingAssignmentController@index` filters by employee, section and offering; the web index filters by year only | A per-teacher filter is missing on the web. Load **counts** are gated | `TeachingAssignmentService::CAPABILITY_VIEW` | none | V11.2 |
| B-12 | Per-Student change history / lifecycle activity | 4 | Audit events `student.*`, `student_enrollment.*`, `student_guardian.*`, `admission_application.*`, `enrollment_rollover.item_*`; `SchoolAuditEventReader::page()` has no subject filter | No per-Student timeline. ADR 0042 §10 planned filters, then narrowed them out of v1 | `school.audit.view` (Highly Sensitive; each read audited as `compliance.audit_log.viewed`) | none | V11.2 |
| B-13 | Activity log by actor, type or campus | 3 | Audit log is School-wide, 50 per page, no filters | No filters. Campus is not an audit dimension (→ 5) | `school.audit.view` | none (campus: new) | V11.2 |
| B-14 | Safe CSV export | 6 today | `analytics.export` seeded, granted to no role; no `compliance.export`; ADR 0042 §8 "No Compliance export exists" | Needs a capability and ADR decision. Output must equal the audited, suppressed UI exactly (gate §6 item 8) | new capability | new | Owner decision → V11.2 or later |

## Group C — Student lifecycle and annual rollover

**As-built contract:**
- **Student status.** `StudentService::changeStatus()` (line 101) accepts only
  `active|inactive`, in both directions. It takes no effective date and no
  reason, does not lock the row, and writes one audit event,
  `student.status_changed`. There is no database CHECK on the status.
  - The UI (`Students/Show.vue:177`) toggles it in one click with no
    confirmation.
  - Student status is deliberately independent of enrollment
    (PHASE-1E-0 §3).
- **Enrollment transitions** (`StudentEnrollmentService`).
  - `complete()`, `withdraw()` and `cancel()` each take `$endedOn` and act
    only on `active` rows. They take the Section lock first and never change
    a terminal row.
  - `transferPlacement()` ends the old row as `transferred` with
    `ends_on = effective − 1`, then creates a new `active` row in the same
    year and grade.
  - Database protections: `student_enrollments_date_range_check`,
    `student_enrollments_one_active_per_student_year`, and roll-number
    uniqueness per Section.
- **Rollover.**
  - Plans move `draft → validated → executing → completed | completed_with_errors`.
  - Items are decided `undecided | promote | repeat | exclude | manual_review`.
  - The dry run classifies items `ready | excluded | already_enrolled | review | blocked`, with placement and subject reason codes.
  - Execution creates the target enrollment and **never ends the source
    enrollment**.

| ID | Candidate | Cls | Evidence / gap | Legal / decision | Schema | Checkpoint |
|---|---|---|---|---|---|---|
| C-01 | Controlled deactivate / withdraw | 2 | Both actions exist. The status toggle has no confirmation, and there is no warning for an "inactive Student with an active enrollment" (PHASE-1E-0 §13.1 OPEN) | — | none (warning = read model) | V11.3 |
| C-02 | Reactivate | 1 | The same toggle. It must never imply re-admission (deferred, ADMISSIONS.md §10) | — | none | — |
| C-03 | Transfer | 1 | `transferPlacement`, `Enrollments/Transfer.vue`, `OppositeDirectionTransferConcurrencyTest`. Cross-year and cross-grade moves are refused by design | — | none | — |
| C-04 | Suspension | 6 | PHASE-1E-0 §4 "NOT APPROPRIATE" (no discipline domain) | — | — | V2 |
| C-05 | Leaving / transfer certificate | 6 (deferred) | `docs/modules/STUDENT-ENROLLMENT.md:2685` (out of scope); Student Documents not activated (OD-SWC-2) | Highly Sensitive document; statutory format unknown | new state | V2 / owner |
| C-06 | Lifecycle reason; effective date (Student) | 5 | PHASE-1E-0 §11 and §13.2: OPEN, "revisit only against a real requirement". Enrollment transitions already carry `ends_on` | Reopens a recorded decision; E21 retention exit rule (D7) reads enrollment endings | new state | V11.3 (owner decision first) |
| C-07 | Supporting evidence / attachment | 5 | `documents.student_id` exists, but the Student owner type and its capability are not activated | Highly Sensitive (`docs/security/DATA-CLASSIFICATION.md`) | Documents activation | V2 |
| C-08 | Approval (maker-checker) | 5 | No approval exists for Student, enrollment or rollover actions. The only control is the capability split | — | new state | V11.3 (owner) |
| C-09 | Audit history | 2 | Events recorded; visible only through the unfiltered School audit log (see B-12) | — | none | V11.2 |
| C-10 | Bulk operations | 3 | Rollover is the only bulk path. Item decisions are set one at a time (`ItemsPanel.vue`). **No bulk completion of source enrollments after rollover** | — | none (orchestrates existing `complete()`) | V11.3 |
| C-11 | Promotion preparation, readiness, unresolved blockers | 1 / 3 | Dry run and `configuration_version` staleness (`EnrollmentRolloverDryRunServiceTest`). Placement and subject issues only | — | none | — |
| C-12 | Required-data verification | 4 | Missing fields are derivable. No rule set is defined | Which fields are "required" is an owner/product decision | read model | V11.5 |
| C-13 | Required-document verification; guardian reconfirmation; verified-by / at; exception reason | 5 | None exists. `guardian_contacts.verified_at` has no workflow (ADR 0055 §8.3) | Documents and privacy | new state | V2 |

## Group D — Communications administration

| ID | Candidate | Cls | Evidence | Gap / conflict | Checkpoint |
|---|---|---|---|---|---|
| D-01 | Organisation-level sender identity | 1 | `App\Support\Email\SenderIdentity` (`MAILBOXES = ['notifications']`); `OutboundEmailGateway.php:124`; `OutboundEmailGatewayTest` | None. From is fixed by design | — |
| D-02 | School / campus / department From address | **6** | ADR 0055 §8.2 ("no per-message From and no free-form local part"); §8.4 (School sending domains are "a separate future contract … not v1") | Needs an ADR 0055 amendment and DNS evidence | V2 / ADR |
| D-03 | Campus or department display name | 6 | ADR 0055 §8.3 fixes `"<School> via <platform>"` | Needs an amendment | V2 / ADR |
| D-04 | Reply-To / Cc / Bcc | **6** | CLAUDE.md rule 89; ADR 0055 §8.3 and §13; ADR 0060; guard `EmailArchitectureTest::nothing_ever_sets_a_reply_to_cc_or_bcc` | Prohibited | Not V11 |
| D-05 | Signatures | 6 now | ADR 0055 §13 (text authoritative; HTML only from repository templates; no logos) | A School-authored plain-text sign-off inside the body would need a decision; a transport-level signature conflicts | Owner decision |
| D-06 | Configuration inheritance and fallback | 3 | Instance flag → `SchoolChannelPolicyService` | No campus or department level | — |
| D-07 | Configured / not-configured state; effective sender visibility | 2 / 4 | `CommunicationChannelPolicyController.php:64`; `Settings/Channels.vue`; provider state is CLI only (`platform:mail-status`) | A read-only "effective sender" preview is derivable, with no secrets and no provider ids | V11.4 |
| D-08 | Test email | 5 / 6 | `EmailPurpose` is a closed catalogue with no test purpose (ADR 0055 §3) | Needs an ADR 0055 §3 amendment. Real sending waits on E17–E20 (deferred to pre-production, ADR 0060 §31) | ADR first |
| D-09 | Event-based notification policies | **6 (gated)** | Outbox consumers: `NotifyActorOfSettingChangeConsumer` (demo), `WebhookFanoutConsumer`, `AutomationTriggerConsumer` (`PlatformServiceProvider.php:97-104`). ADR 0043 §3 and §12: tier-1 staff notifications wait on the owner decision; notifying Guardians, Students or outsiders is **tier 4, prohibited** | Needs the ADR 0043 §12 decision. Admissions, guardian, Student-attendance and export events do not exist yet (5) | V11.4 (staff, after decision) |
| D-10 | Audience configuration | 1 | `Application/Audience/*Resolver` (school-wide, individual, guardian, guardians-of-students, student, grade, section, subject offering, cohort) | No staff-by-role or campus audience (5) | V11.4 |
| D-11 | Duplicate-recipient detection | 1 per identity / 3 per address | `ResolvedAudience` dedupe; `GuardianEmailAddressResolver` one address per Guardian | Two Guardians sharing one address receive two messages. "One recipient per message" (ADR 0055 §13) must stay | V11.4 |
| D-12 | Missing communication address | 1 / 2 | `RecipientDestinationUnavailable`; `recipient_email_missing` counts per announcement | No School-wide "Guardians without an email" list | V11.5 |

## Group E — Data health and reconciliation

| ID | Check | Cls | Evidence | Surface | Checkpoint |
|---|---|---|---|---|---|
| E-01 | Platform verifiers | 1 | `platform:verify-database`, `verify-storage`, `verify-restore`, `finance-balances-verify`, `retention-holds-reconcile`, `operations-status`, `mail-status`, `school-closure-status` | CLI (operator) | — |
| E-02 | Setup completeness | 3 | `SchoolSetupController::index`: five booleans (profile, campus, active year, grade levels, subjects) | School setup page | V11.5 (terms, sections, offerings, fee setup) |
| E-03 | Student without a guardian | 4 | Derivable from `student_guardian_relationships` | none | V11.5 |
| E-04 | Student without a current enrollment | 2 / 4 | Empty state on `Students/Show.vue:298` only | per record | V11.5 |
| E-05 | Inactive Student with an active enrollment | 4 | PHASE-1E-0 §13.1 (OPEN, "data-quality smell") | none | V11.5 |
| E-06 | Last year's enrollments still active after rollover | 4 | C-10 | none | V11.5 / V11.3 |
| E-07 | Employee without an assignment | 4 | `EmployeeDirectoryQuery` has no such filter | none | V11.5 |
| E-08 | Required subject offering with no teaching assignment | 4 | Writes are guarded (`EmploymentEndedTeachingOwnership`) | none | V11.5 |
| E-09 | References to inactive configuration | 3 | Write-time guards only | per page | V11.5 |
| E-10 | Duplicate-guardian sweep | 4 | `GuardianContactService` lookup at link time only | linking only | V11.5 (exact hash, same School, never merge) |
| E-11 | Malformed contact | 1 | `EmailNormalizer::normalize`, `PhoneNormalizer` at write time | — | — |
| E-12 | Missing communication address | 2 | D-12 | — | V11.5 |
| E-13 | Import reconciliation | 1 (HR) | `EmployeeImportService`, `EmployeeDuplicateDetector`, `HR/Import/Result.vue`. Student and Guardian import are OUT (OD-SWC-4) | — | — |

A Data Health finding is an **operational list**: the records needing
correction, each linked to the owning module's existing page. It is shown
only to holders of that module's view capability, and its only count is the
ADR 0042 §11 row total. A grouped "health score" across Schools or people is
not part of it. Corrections always go through the owning service. There is
no generic editor.

## Group F — Rooms / academic capacity

| ID | Candidate | Cls | Evidence | Checkpoint |
|---|---|---|---|---|
| F-01 | Room, room type, standard capacity | 1 | `rooms`: `room_type` (application values `classroom\|laboratory\|auditorium\|library\|sports\|other`, no DB CHECK), `capacity` (nullable, min 1), unique `(school, campus, code)`; `RoomAdministrationService` | — |
| F-02 | Building, floor, block | 5 | No columns | V11.6 (owner) |
| F-03 | Examination capacity / seating | 6 | `examination_papers` deliberately has no `room_id` (docs/modules/EXAMINATIONS.md:126) | V2 |
| F-04 | Section allocation / home room | 5 | No `room_id` on `sections` | V11.6 |
| F-05 | Timetable room usage | 1 | `timetable_entries.room_id` + `timetable_entries_room_slot_unique`; `RoomNotAvailableException`; `TimetableEntryConcurrencyTest` | — |
| F-06 | Subject / learning-space suitability | 5 (deferred) | docs/modules/TIMETABLE.md:489 | V11.6 |
| F-07 | Room in-charge | 5 | No column | V11.6 (owner) |
| F-08 | Capacity versus Section strength | **gated** | `sections.capacity` is advisory; strength is a headcount | V11.6 (after gate) |

## Strengths confirmed by this audit

Each item is backed by repository evidence. None should be simplified to
match a simpler workflow elsewhere.

- **Fail-closed Analytics.** No person count is ever exposed without an
  approved disclosure floor (`CohortSuppressionPolicy`,
  `AnalyticsArchitectureGuardTest`). Source access and Analytics access are
  separate (ADR 0040 §5).
- **Enrollment history is immutable.**
  - Transfers create a new row.
  - Terminal rows never change.
  - The database enforces one active placement per year.
  - Roll numbers are unique per Section.
  - Lock order is proven by real-process concurrency tests.
- **Rollover is validated and drift-checked.** It has a dry-run taxonomy,
  `configuration_version` staleness and source-change detection, and it runs
  resumably.
- **Email identity is safe by construction.** From is fixed on a reserved
  domain, the display name is sanitised, Reply-To, Cc and Bcc are absent (a
  guard test enforces it), there is one recipient per message, and the
  purpose catalogue is closed (ADR 0055).
- **Automation never contacts Guardians.** Notifying Guardians, Students or
  outsiders is tier 4 (ADR 0043).
- **Guardian contacts are minimised.** Contacts are encrypted with HMAC
  lookup, duplicates are flagged but never merged, and the lookup is not a
  search tool (ADR 0041).
- **Audit-log reads are themselves audited.** Viewing the audit log is
  Highly Sensitive (ADR 0042).
- **Timetable double-booking is impossible.** Teacher, Section and room
  partial unique indexes enforce it.

## Incidental defects found during the audit (not V11 scope)

*(2026-10-15: X-01, X-02, X-03 and X-04 are all RESOLVED by OD-V11-7,
ADR 0075 §12. X-05 (a stray untracked directory) is not a repository
change.)*

Each fix stays within an existing contract and needs no new decision.

| ID | Defect | Evidence | Category |
|---|---|---|---|
| X-01 | Success messages are never shown: 64 controller redirects flash `flash` or `status`, but `HandleInertiaRequests::share()` shares neither and no page reads them | `grep "with('flash'\|with('status'" app` = 64; `app/Http/Middleware/HandleInertiaRequests.php` | UX / workflow feedback |
| X-02 | The School timezone is validated only as `required\|string\|max:64`, and a typo is stored. Date logic then breaks, including a database trigger that evaluates `now() AT TIME ZONE v_timezone` | `SchoolSettingsController.php:56`; migration `2026_11_02_090100_create_late_fee_runs_table.php:94` | Data integrity |
| X-03 | The MFA authenticator label uses `config('app.name')` ("School OS") instead of `ProductIdentity::NAME` | `app/Support/Auth/Mfa/MfaEnrollmentService.php:63`; `app/Support/ProductIdentity.php` docblock | Product identity |
| X-04 | Timetable filters ask for raw UUIDs ("Section ID", "Teacher ID") | `resources/js/Pages/App/Timetable/Schedule/Index.vue:103-116` | UX |
| X-05 | Stray empty directory `apps/platform/apps/platform/` (owned by root) | filesystem | Hygiene (untracked) |

## V2 discovery (recorded, not implemented)

SMS, WhatsApp, voice and push (E28); DLT/provider billing; advanced
gradebook, curriculum-specific assessment, report cards and transcripts
(E39–E42); teaching plans; online exams; day-specific timetable
substitution (ADR 0063 D-15/D-17 already treats cover as a dated
TeachingAssignment); an organisational reporting hierarchy beyond the HR
reporting line; inventory asset register; extended visitor management;
concerns / service desk; school store; expense management; recruitment;
performance management; RFID; infirmary (legal flag, DATA-CLASSIFICATION
health row); alumni (PHASE-1E-0 §13.3); a general digital document library
(OD-SWC-2).
