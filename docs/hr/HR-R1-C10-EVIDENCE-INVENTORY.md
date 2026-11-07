> **HR.R1-C10 evidence inventory: R1-03, HR.4–8, the fingerprint, decisions, criteria, the security register, stale records.** Read-only, against `82e70a8a`, by an independent pass. Citations are that commit's. Every recommendation is a **proposal**, and every decision is **pending**. Items since corrected by C10 are in `HR-R1-0-AUDIT-REGISTER.md`, which wins where the two differ.

# HR.R1 evidence inventory B (read-only audit)

Commit audited: `82e70a8a4e9e863e36ba09cb4cfd5a72d8bc9a6f` (detached HEAD, equal to `origin/main`).
Date: 2026-11-07. Nothing here is an approval or a decision. Every recommendation is a proposal for the named owner.
No test, ddev, migration or database command was run. Statements about behaviour are from reading code, unless a test is named, and a named test was not executed here.


**Path abbreviations.** CC = `docs/hr/HR-R1-CLOSURE-CONTRACT.md`. AR = `docs/hr/HR-R1-0-AUDIT-REGISTER.md`. REG = `docs/hr/HR-LEGAL-SECURITY-GATE-REGISTER.md`. CM = `docs/hr/HR-REFERENCE-CAPABILITY-MAP.md`. RM = `docs/hr/HR-EXPANSION-ROADMAP.md`. H16 = `docs/hr/HR-16-READINESS.md`. OM = `docs/hr/HR-DOMAIN-OWNERSHIP-MAP.md`. OPS = `docs/operations/HR-OPERATIONS.md`. A65 = `docs/architecture/adr/0065-hrx-leave-staff-attendance-contract.md`. A71 = ADR 0071. A76 = `docs/architecture/adr/0076-platform-organization-control-plane-contract.md`. E21 = `docs/security/E21-RETENTION-DETERMINATION.md`. Code paths are under `apps/platform/`.

---

## 1. R1-03: calendar and policy history

R1-03 text (CC:40): "**No missing temporal relationship.** Assignment, category, employment type, manager, shift, calendar, policy and entitlement each answer an as-of-date query | as-of-date tests per concept".

### 1.1 What is stored

| Concept | Stored | Effective-dated? | Overwritten or hard-deleted? | As-of reader |
|---|---|---|---|---|
| Weekly pattern | `staff_working_weekdays`, one row per ISO weekday (migration `2026_11_17_090000`:162-172) | **No.** No date column | **Overwritten** by `updateOrCreate` (`StaffCalendarService.php:51-56`). Before/after go to audit metadata only (`:57`) | **None.** `pattern()` reads current rows (`:94-98`) |
| Staff holidays | `staff_holidays`, one row per date (`:174-185`; model `StaffHoliday.php:24`) | Dated by `holiday_on`, but no recorded-from/removed-at | **Hard-deleted** at runtime (`StaffCalendarService.php:87-88`). Runtime DELETE is kept: the table is on neither revoke list (migration `:374-379`). Audit keeps date and portion, never the name (`:89`) | **None** |
| Leave type flags | `leave_types`, `is_paid`, `tracks_balance`, `allows_half_day` | No | Frozen only once a policy or ledger row exists (`leave_types_freeze`, migration `:275-287`) | None. See M7 |
| Leave year | `leave_years`, materialized and append-only (`:374-376`); prospective start changes | Yes, bounded rows | Append-only | The rows are the answer |
| Policy version | `leave_policies`, immutable except active to retired, with `retired_at` (`:290-300`; `LeavePolicy.php:10-12`) | Version, with `supersedes_policy_id`. No effective date on the version itself | Never overwritten. DELETE revoked (`:377-379`) | **No public reader** of "versions active on date D". `LeaveEligibilityResolver.php:60-73` reads `status = 'active'` today |
| Policy assignment | `leave_policy_assignments`, `effective_from`/`effective_to`, `ended_at` (`:139-160`) | Yes | `effective_to` may be shortened once; the prior value is lost (audit carries only the new date, `LeavePolicyAssignmentService.php:95,103`) | **No public as-of reader.** Inline queries: `LeaveRequestService.php:428-430,439`, `LeaveLedgerService.php:386-392`, `LeaveRequestTimingPolicy.php:38-41`. `LeaveReadService.php:135` lists them all. CC:207 agrees |
| Eligibility rules | `leave_eligibility_rules`, on the version, never updated (rule 133; migration `2027_01_28`) | By version | Immutable | Evaluated only against today's active versions |
| Eligibility run | `leave_eligibility_runs` header, provenance on the assignment (`2027_01_28`:101-124) | `as_of` | Append-only (`:187`) | Run rows |
| Request timing rules | Columns on the version (`LeavePolicy.php:25`). Applied version ids are in audit only (`LeaveRequestService.php:338`) | By version | Immutable | Derivable from assignments and audit |
| Chargeable days | `leave_request_days` (2026_11_19:100-119): date, portion, units, year, assignment, policy | Snapshot at approval | Append-only (`:379-380`), guarded on insert only (`:287-307`) | The rows are the answer |
| Leave ledger | `leave_ledger_entries`, append-only | Posting time. No effective date for allocation or adjustment (H16:21) | Never | Posting clock only: `LeaveBalanceReport`, `LeaveStatementReader` |
| Employment terms, placement | `employment_terms`, `employee_assignments` | Yes (rule 130) | Terms append-only. HRC-7: `setPrimary` demotes an open primary in place (AR:57) | `WorkforceAsOfReader::termsOn`, `placementOn`, `placementsOn` (`:62,82,93`) |
| Reporting line | `reporting_relationships` | Yes (rule 131) | Void plus replacement, never edited | `ReportingAsOfReader::managerOn`, `chainOn`, `treeOn` (`:96,124,225`) |
| Staff attendance | `staff_attendance_records` + append-only `staff_attendance_corrections` | Dated, versioned (A65 §24.8) | Record changes only by correction with history | Records and correction rows; no calendar beside them |
| Payroll HRX snapshot | `payroll_run_hrx_inputs`, per-half `evidence`, `fingerprint`, unit counts (`2026_11_22`:34-63) | Snapshot at **calculation** (`PayrollRunService.php:407,427`) | Replaced by recalculation; frozen once `approved`/`posted` (`2026_11_22`:84-93) | The row |
| Shift | Absent by design (A65:77; CM D5-07 to D5-20) | n/a | n/a | n/a |

### 1.2 What each historical output reads

| Output | Reads | Reliable? |
|---|---|---|
| Approved leave's chargeable days | Calendar held under shared lock **at approval** (`LeaveRequestService.php:411-413`), written to `leave_request_days` (`:460-464`). A65 §22.6 (:771-785), §23.4 (:836): never recomputed. Test: `LeaveRequestLifecycleTest::the_approval_snapshot_never_changes_with_later_calendar_policy_or_leave_year_configuration` (:179) | **Reliable: snapshot at decision.** The calendar that produced it cannot be replayed, but the result is stored |
| Leave charge (`consumption`) | Sum of those rows (`:467-471`) | **Reliable: snapshot** |
| Policy behind a charge | `leave_policy_assignment_id` and `leave_policy_id` on the day, tracked types only (`:463`) | **Reliable**, with M9 |
| `submitted_units` | Calendar at submission (`:313`). Approval recomputes (`:413`), so the two can differ | Reliable as a record of each moment. Which calendar was seen is not stored |
| Paid/unpaid class of a leave half | **Current** `leave_types.is_paid` (`LeaveCoverageReader.php:51-52,64`) | Reliable only where the freeze holds. **Unreliable in the M7 case** |
| Payroll HRX inputs, captured | Calendar and evidence **at last calculation**, frozen per half (`PayrollAbsenceEvidenceReader.php:111-134`) | **Reliable as a snapshot at calculation, not at approval.** CM:296 says "frozen at approval"; capture happens at calculate, and the approval review does not read HRX (no `hrx` in `PayrollApprovalReviewService.php`) |
| Payroll HRX `differenceState` | Fingerprint of a **fresh `read()` under today's calendar** (`PayrollHrxInputReadService.php:92,103,113`). `working` is in the fingerprint (`PayrollAbsenceEvidenceReader.php:176`) | **Unreliable as a cause.** A calendar edit, an attendance correction and a leave cancellation all give the same `source_changed_after_approval`, and the fresh side stores no per-half detail. It is computed only when someone opens or checks (`PayrollRunController.php:159`, `Show.vue:156`) |
| Attendance record (present/absent) | Recorded values; working-time check used **today's** calendar at write (`StaffAttendanceService.php:177-178,232,249`) | **Reliable as a value.** A65:1117-1119 keeps a recorded half effective over a later calendar change |
| Register `holiday`/`off_day`/`unrecorded` on a past date | **Today's calendar** (`StaffAttendanceReadService.php:35-36,126,355-361,392-405`) | **Unreliable, cannot be reconstructed from a read path** |
| Month view counts | Same, via `monthCounts` (`:171,175,206,227`) | **Unreliable** for `holiday`, `off_day`, `unrecorded` |
| Staff history and self-service attendance | Same `day()` (`:89,94,250,257`) | Same |
| Payroll `read()` on a past period | Today's calendar (`PayrollAbsenceEvidenceReader.php:99,111`) | Same |
| Absentee report, correction report | Recorded rows and approved days, no calendar (`LeaveCoverageReader.php:85-94`) | Reliable for what they state |
| Month-wise leave | `leave_request_days` joined to **current** status approved (`LeaveRequestReadService.php:153-156`) | Units reliable. Membership is today's: a later cancellation removes the row |
| Leave balances report | Ledger by posting time (`LeaveBalanceReport.php:108-113`), labelled "as posted" (REG:430) | **Reliable on its declared basis.** Not an effective-date answer. R1-03 names "entitlement": it answers as posted only, and CC:207 does not list that |
| Year-close items, accrual items | Append-only rows | Reliable: snapshot |

**Reconstruction limit.** The only trace of past calendars is `school_audit_events` (`leave.calendar.pattern_changed` before/after; `holiday_added`/`holiday_removed` date and portion). That is not a read path, omits the holiday name, and E21-D1 adopts 7 years from the event (E21:293) while leave evidence lasts 8 years after final separation (E21:674). Audit therefore cannot cover the whole evidence lifetime, and using audit as business data is not a contract anywhere.

**Labelling.** Past-date pages say only "the staff calendar" (`Monthly.vue:66`, `Register.vue:147-148,171`), and `hr.attendance_monthly` is declared `D::PERIOD` (`HrReportRegistry.php`). A65:1111-1112 does say "today". Rule 141 says "Never show a current value as history without labelling it". By reading, the two pages do not meet that.

### 1.3 Misclassification scenarios

- **M1, pattern change.** On 1 August Saturday moves from `off` to `first_half` (`setWeeklyPattern`, `StaffCalendarService.php:51-56`). `monthSummary('…-04')` then reports every April Saturday's first half as `unrecorded`, not `off_day` (`StaffAttendanceReadService.php:171,227,355-361`). The reverse change turns genuine unrecorded working time into `off_day`, hiding a gap.
- **M2, holiday removal.** Removing a past holiday (`:87-88`) moves that date from `holiday` to `unrecorded`. `read()` raises `unresolvedWorkingHalfUnits` and turns completeness to `input_incomplete` (`PayrollAbsenceEvidenceReader.php:140-143,160`). A posted run then shows `source_changed_after_approval` (`PayrollHrxInputReadService.php:113`) though no leave or attendance fact changed.
- **M3, holiday added after a charge.** A sudden closure recorded after approval leaves the leave charged (§22.6 intends this). The register shows `leave` beside `calendar: holiday`. The only remedy is cancel and re-request, and a closed year refuses a new charge (`LeaveRequestService.php:448-449`). Not a read error, but the employee stays charged for a holiday and nothing surfaces it.
- **M4, change between calculate and approve.** A holiday added after calculation is not in the snapshot, and approval does not look. The run shows `recalculate_to_refresh` before approval only if someone opens it. No money effect (HRX-L4 open, `PayrollHrxArchitectureGuardTest::hrx_evidence_cannot_reach_any_amount_or_the_ecr_while_hrx_l4_is_open`:54).
- **M5, historical write.** After M1's reverse change, entering a worked April Saturday as present is refused `STAFF_ATTENDANCE_NOT_WORKING_TIME` (`StaffAttendanceService.php:249`; correction gaining evidence, `:177-178`), though it was a working day then. A65:1188-1190 records "no override in v1".
- **M6, eligibility on a past date.** `applicability()` takes a date but always evaluates today's active versions (`LeaveEligibilityService.php:81-92`, `LeaveEligibilityResolver.php:60-65`). A date before a supersession is judged under rules that did not then exist. It is a view, nothing is stored, and `retired_at` could answer it but no reader does.
- **M7, `is_paid` on a type with no policy (new, not in AR).** `leave_types_freeze` checks only `leave_policies` and `leave_ledger_entries` (migration `2026_11_17`:281-282). A `tracks_balance = false` type needs no policy to be requested or approved (`LeaveRequestService.php:426,438`), and a request writes neither. `PATCH …/leave/types/{id}` (`routes/api.php:2476`, `LeaveConfigurationController.php:92-99`, `LeaveTypeService.php:62`) can then flip `is_paid`, so every past approved day of that type changes class between `leave_paid` and `leave_unpaid`. `LeaveCoverageReader.php:63` and A65:1616-1617 both claim the flag is frozen. Reachable through the API only; the web has no type update route. The test at `LeaveFoundationTest.php:98` covers a type already used by a policy or ledger. **By reading only, not reproduced.**
- **M8, cancelled leave and month-wise leave.** Payroll's snapshot keeps the leave. Month-wise leave for that month drops it (`LeaveRequestReadService.php:155`). The two are honest about different things, and nothing tells the reader.
- **M9, assignment shortened past a charged day.** `end()` and `trg_leave_policy_assignments_accrual_bounds` guard accrual only (`LeavePolicyAssignmentService.php:91-101`; `2027_01_29`:177-187). `leave_request_days_guard` runs on insert only. A charged day can then name an assignment that no longer covers it, so a future as-of reader and the snapshot would disagree. The balance key is unaffected.

### 1.4 Owner choices (pending)

1. Accept a recorded residual, or build history.
2. If history: recorded-time only (what did we believe when), effective-date only, or both.
3. May a calendar change be retroactive? Sudden closures suggest yes, which is why both clocks matter.
4. Past-date pages: calendar as of the date, or today's calendar with a label.
5. Effect on approved leave: stays frozen (§22.6) or a surfaced list of requests a change touches, without rewriting them.
6. Whether Payroll's difference should distinguish a calendar-only change.
7. Pre-cutover history: unknown and never fabricated, as X-2 (REG:402) and OD-HR-25 row 19 (REG:261) already treat history.
8. Timing: before staging, before production, or with HR.4.

### 1.5 Proposal: minimal, no WorkSchedule or holiday engine (not a decision)

- **Holidays:** keep the table. Add `removed_at`, `removed_by_user_id`, a closed removal reason. Revoke runtime DELETE. Change uniqueness to a partial index on live rows. Remove becomes a marked row, as OD-HR-13 did for the six profile tables (REG:400).
- **Week:** a new append-only `staff_working_week_versions`: School, `effective_from`, seven CHECKed portion columns, actor, `recorded_at`. Keep `StaffWorkingWeekday` as the projection or retire it in one release. No JSON, nothing per Campus, no shift column.
- **One reader:** `StaffCalendarService::calculatorAsOf()`, feeding the existing pure `WorkingDayCalculator` a per-date pattern. Optionally a `recordedBy` bound. Writers keep `LeaveLocks::calendar`.
- **Consumers:** the attendance read service and `PayrollAbsenceEvidenceReader` move to the as-of reader. `leave_request_days` stays the only truth for approved leave and is never recomputed.
- **Cutover:** one row stating the pattern known at cutover, with an explicit "history before this date unknown". Pre-cutover dates keep today's answer, so existing fingerprints do not all move. Whether `hrx_payroll_input.v1` needs a new version is a design question, because the column CHECK pins the value (`2026_11_22`:65).
- **M7, separately:** extend `leave_types_freeze` to `leave_requests`. M7 needs no calendar work and is a candidate for its own slice.

**Privacy and retention.** The calendar is School configuration with no personal data (A65:469), in the `leave_configuration` unit, `TENANT_LIFETIME` (`TenantRetentionCatalog.php:214-215`), so the new table belongs there in the same change. New actor columns need `UserReferenceCatalog` entries (`:54-55` shows the existing `RETAIN_REFERENCE`). The holiday `name` is free text kept for the tenant's lifetime, so it should stay School-event wording. Tenant-lifetime retention outlives D9 evidence, which is what makes an as-of answer stable. RLS per rule 18, DELETE revoked, a verifier pin. No Region, Campus or person dimension.

**Acceptance tests (proposed).** A pattern change leaves past months' counts unchanged. A removed holiday still reads as a holiday for its date, and as removed by recorded time. A retroactive holiday appears under the chosen rule, and `leave_request_days` does not change. Runtime role cannot DELETE or UPDATE history (raw SQL). Two-process race of writer against approval and against `captureForPayroll`. Payroll fingerprint unchanged by a future-dated change. Legacy dates say "unknown", never a guess. RLS raw isolation for each new table. Rollback and re-apply identical. Architecture guard: nothing outside the service writes, and no `shift`, `clock` or `device` column. `is_paid` refused once a request exists.

### 1.6 Home: OD-HR-14 or HR.4? What S-16 says

- **S-16, exact (REG:400):** "| S-16 | LOW | Six ancillary profile tables and `staff_holidays` are hard-deleted at runtime | **FIXED for the six profile tables (HR.2A)**: archived with a closed reason, frozen by trigger, runtime DELETE revoked (OD-HR-13 decided: archive). `staff_holidays` remains | HR.4 (`staff_holidays`) |".
- **The records say HR.4:** S-16's destination, CM:428 (D14-09 "versioned change, never delete", HR.4), OM:37 (HR-AD-05, proposed), and D-6's audit recommendation "Record a residual until HR.4" (AR:91).
- **OD-HR-14 is the gate decision, not the home.** It does name "week-offs and work calendars" as WorkSchedule data (REG:338-341).
- **The catch.** RM:247-248 makes HR.4 depend on "OD-HR-14 decided (L2a); ADR 0065 §8 reopened; HR-AD-05 accepted". Yet 12 of HR.4's 27 CM rows carry **no gate**, including D14-09 and D14-12. Calendar history needs no timing data. So the records put an ungated need behind a gate that concerns shifts.
- **Proposal:** split a calendar-history slice out of HR.4 (a roadmap amendment needing the HR owner), and keep OD-HR-14 for shifts. Leave S-16's destination as is until the owner rules.

---

## 2. HR.4 to HR.8

### 2.1 Scope (RM)

| Phase | Prerequisites | Scope (exact) | Rollout gate | Line |
|---|---|---|---|---|
| HR.4 Work calendar & shifts | "**OD-HR-14 decided (L2a)**; ADR 0065 §8 reopened; HR-AD-05 accepted." | "D5 (all); D14-02…09/12; D2-07." | L2a availability gate | RM:245-272 |
| HR.5 Attendance events | "HR.4; OD-HR-14." | "D6-07…21 (manual and import sources first; device sources wait for HR.8)." | L2a | RM:274-292 |
| HR.6 Attendance evaluation | "HR.5." | "D7-07…13/16." | L2a | RM:294-311 |
| HR.7 Attendance operations | "HR.6. Manager correction needs the ADR 0065 §7 reopening." | "D8-03/04/10/13/14/15; S-6." | "none for the lock; L2a for timing-based regularization." | RM:313-332 |
| HR.8 Biometric / FR / GPS | "**HRX-L2b legal / security determination**; device provider review; Integration Hub family made available." | "D6-01/03/04/05; D9 (all); D20-19/27/28/38." | L2b + production availability gate | RM:334-351 |

### 2.2 Capability ids pointing at each phase (counted from the CM Phase column)

82 rows in all. 61 are GATE.

| Phase | Rows | Ids | Status split | Gate column |
|---|---|---|---|---|
| HR.4 | 27 | D2-07, D5-02, 03, 05-20, D14-02, 03, 04, 05, 07, 08, 09, 12 | GATE 15, MISS 6, PART 4, ALT 2 | L2a 15 (two also naming ADR 0065 §8), none 12 |
| HR.5 | 15 | D6-07 to D6-21 | GATE 15 | L2a 8, L2b 6, both 1 |
| HR.6 | 8 | D7-07 to D7-13, D7-16 | GATE 7, ALT 1 | L2a 6, L2b 1, none 1 |
| HR.7 | 8 | D7-14, D8-01, 03, 04, 06, 10, 13, 14 | MISS 3, DEF 2, ALT 1, IMP 1, PART 1 | ADR 0065 §7: 1; none 7 |
| HR.8 | 24 | D1-04, D6-01, 03, 04, 05, D9-01 to 14, D20-19, 20, 27, 28, 38 | GATE 24 | L2b 23 (one as "HRX-L2 (L2b)"), L2a 1 (D20-20) |

Whole-map check: 34 L2a + 30 L2b + 1 both = 65, matching CM:696. RM lists D8-15 in HR.7, but CM:300 gives it to HR.14 as IMP. RM has no HR.7 row for D8-01 or D7-14, which CM gives to HR.7.

### 2.3 Authorized attendance versus gated processing

- **Authorized and built (no gate):** daily half-day status (A65 §7:309, §24; CM D6-02, D7-01 to 06, D8-01, 02, 05, 07, 09). Administrator-recorded, closed reasons. Self-marking and manager recording are out of v1 (A65:317-321).
- **Gated by owner reopening, not biometric:** shifts, clock-in/out, late, early, overtime (A65:77-78,309; §24.14 at :1280). L2a if split.
- **Gated by legal/security review:** biometric, device, FR, GPS, provider events (A65:72-73,383-385,549-550). L2b if split.
- **A citation defect.** A65:77-78 cites "(§8)" for clock and shift scope. A65 §8 (:334-345) is "Leave ↔ Attendance relationship" and says nothing on clock time. The operative text is §1 and §7. REG:29, REG:322, REG:347, RM:247 and CM:219 inherited "ADR 0065 §8". A reopening needs to name the right sections.
- **Ungated but stranded:** HR.7's D8-03, 04, 10, 13 and HR.4's 12 rows carry no gate, but sit behind phases that do. RM:330 says the lock has no gate, while RM:315 makes HR.7 depend on HR.6, which is gated.

### 2.4 Exact text

- **HRX-L2 (REG:29):** "| **HRX-L2** | Biometric, device, face recognition, GPS/geofence attendance | "OPEN — no clock time, device, location, photo or biometric column (guards)" (same §9); Integration Hub lists `attendance.biometric` / `attendance.rfid` as unavailable (`OrganizationIntegrationCatalogue`) | **bounded**: no such data. ADR 0065 §8 separately puts clock-in/out, shifts, overtime and late minutes **out of v1 scope**, and the parity record ties shifts to this gate (`reference-parity/functions.csv` HR-005, HR-006, HR-017) | absent by construction | all | biometric templates and codes, device ids, punches, clock times, location, photographs | legal/security review; none recorded | needs a decision and an ADR 0065 amendment | D5 shift timing, D6 sources, D7 clock-based evaluation, D9 biometrics, GPS/FR: **BLOCKED_BY_GATE**. See the proposed split, §3 OD-HR-14 |".
- **OD-HR-14 (REG:309-329):** "## 3. Proposed owner decision: OD-HR-14 — split HRX-L2". Problem: "HRX-L2 currently bundles two very different data classes: **Timing evidence:** shift definitions, expected start/end, a manually entered in/out time, late or early minutes. This is Restricted employment data, not biometric. **Biometric and device evidence:** fingerprint or face templates, device punches, GPS location, photographs. This is Highly Sensitive." "Shifts, permissions and every clock-based attendance rule are blocked by the timing half, though the legal risk sits in the biometric half."
- **L2a (REG:322-324):** "**HRX-L2a — timing evidence.** Needs an ADR 0065 §8 reopening (owner) plus a privacy note. It unlocks HR.4 shifts and the timing half of HR.6 and HR.7."
- **L2b (REG:325-326):** "**HRX-L2b — biometric, device, GPS and FR.** Stays a legal/security gate. It blocks HR.8 only."
- **Until decided (REG:328-329):** "HR.4–HR.8 stay design-only. HR.1–HR.3 do not depend on it."
- **Direction (REG:335-348):** "**OD-HR-14: project-adopted architectural direction (2026-11-03).** … **Split** scheduling and clock-time *policy* from biometric, device, FR, GPS and provider-event *processing*. **WorkSchedule owns scheduling data:** shift start and end, grace windows, scheduled working duration, week-offs and work calendars. That data does not need biometric-device authorization merely because biometrics may later consume it. **Biometric, device, FR, GPS and provider-event processing stays separately gated (HRX-L2 / L2b).** **Not yet approved:** HRX-L2 itself remains OPEN. It is not marked approved or split by this record. Building timing data (L2a) still needs the ADR 0065 §8 reopening and the privacy note."
- Section heading says "records of direction, not approvals. No gate changes." (REG:333). Who adopted it is not stated.
- RM:670 calls OD-HR-14 an owner decision "Needed by" HR.4–HR.8. CM:114-116 calls L2a and L2b "**proposed**". **HRX-L2 is not split.**

### 2.5 Is there an owner DEFERRED record?

**No, for HR.4 to HR.8.** Searched `docs/`, `CLAUDE.md` and commit messages for HR.4–8 with DEFER. Hits are AR:87, CC:68, CC:202 ("neither publication nor an owner DEFERRED record"), REG:328, RM:32, RM:670, MASTER-ROADMAP:174 ("HR.4–HR.8 not built"). CM has no phase-level deferral. The DEF rows that do exist are elsewhere: D8-06, D8-14, and owner-recorded D15, D16 and D17 (OD-HR-18 to 20, REG:126-128). Owner-deferred HR.15B-D (RM:577-582, REG:413) is a separate record.

CC:28-29 requires the deferral to be "recorded in `HR-REFERENCE-CAPABILITY-MAP.md`". A row in REG alone would not satisfy the sentence.

### 2.6 Deferral options and consequences (proposals)

| Option | Effect | Consequence |
|---|---|---|
| A. Blanket owner DEFERRED for HR.4–8 in CM, tied to HRX-L2 and OD-HR-14 | Meets CC §2 | The 61 GATE rows stay GATE. S-6 (destination HR.7), S-16 (HR.4), R-HR13-2 and D-6 lose their destination, so each needs a re-homed residual or R1-12 stays a finding. Ungated D8-10 (period lock, risk H) and D8-13 go with them |
| B. Partial: defer gated rows; name calendar history and the attendance lock as open or separately decided | Meets §2 with less loss | Needs an RM amendment splitting HR.4 and HR.7 and a dependency fix (RM:315) |
| C. Decide OD-HR-14 and build L2a | Removes the deferral need for HR.4–7 | A65 reopening and privacy note first. Large scope. HR.8 still needs a record |
| D. Amend CC §2: a phase held by an open gate is "gate-held", not deferred | Avoids a formal deferral | Changes the contract, and is an owner decision about the contract itself |

Proposal: Option B. It is the only one that does not silently drop D8-10 and S-16. Status: pending the HR owner.

---

## 3. Reference pack fingerprint

- **CC:30-31, exact:** "The HR reference pack fingerprint is unchanged (`HR-REFERENCE-CAPABILITY-MAP.md` §1), or a re-capture has been mapped."
- **CM:20-32, exact rows:**
  - "Location | owner-held: `…/Lycenza/Screenshots Downloaded/HR`, with companion `HR.zip`"
  - "Files | **602** PNG, no sub-folders. `HR.zip` holds exactly the same 602 names; its presence is the capture-finished signal, as with the SIS and Organisation packs"
  - "Byte-distinct | 601: one exact duplicate (H431 = H432)"
  - "Tall captures | 148 screens taller than 1,300 px; the tallest is 30,000 px"
  - "Pack fingerprint (SIS convention: SHA-256 over each file, in sorted file-name order, of its UTF-8 name followed by its hex SHA-256) | `40ed6a836f5a99c65694118c0481bc468b1fb6ebb379a0275cc33839fa7a1ac3`"
  - "Relation to HR.EXP-0R | The 335-file provisional set (`c21e40f7…d4d4`) was a subset still being captured. **Superseded**: this 602-file pack is the HR reference baseline"
  - "Screen ids | `H001`–`H602` in sorted file-name order"
- **Recorded artefact:** a folder of 602 PNG plus `HR.zip`. One fingerprint over the PNG set. HR-0A:141,555 repeat it as frozen.
- **Not recorded:**
  - the separator or newline between name and hash, the hex case, and the collation of "sorted file-name order", so the byte stream cannot be rebuilt exactly from the text alone;
  - any hash of `HR.zip` itself;
  - a per-file inventory: "The screen-id → file-name index stays outside Git" (CM:16-18);
  - a pack version beyond the date and the fingerprint;
  - the script that produced it.
  The Organisation and earlier packs use a different convention ("SHA-256 of the sorted per-file SHA-256 list", `ORG-12-REFERENCE-CAPABILITY-MAP.md:23`), so the two must not be mixed.
- **On disk:** `find the development workspace -xdev` by name for `HR.zip`, `Screenshots Downloaded`, `H00[0-9]*.png`, `H60[0-2]*.png`, `*reference*pack*`, `SIS.zip`, `Organisation.zip` returned **nothing**. `git ls-files` shows no pack file. HR.EXP-0R places it on "OneDrive Desktop" (`HR-EXP-0R-REFERENCE-RECONCILIATION.md:50`). Paths outside `the development workspace` were not searched, as instructed.
- **Fingerprint state:** not verifiable from the repository, as CC:203 says. No hash or count was invented here.
- **Where evidence may be stored:**
  - Pack and id → file index: outside Git, owner-held (CM:16-18,24).
  - Browser and axe tooling: "used temporarily in the session scratchpad, never added to the repository" (H16:8-9; `HR-TEST-STRATEGY.md:244`). Walkthrough scripts "stay outside the repository" (CC:194).
  - Results may be **recorded** in docs: H16 §4 and CC evidence paragraphs do this.
  - No personal value from the pack is copied (CM:16).
  - Measurement scripts have been scratch only (PAYROLL.md:1374,1389).
- **Proposal:** the owner, or a run on the owner's machine, recomputes and records a dated result in a named row. Record the exact byte convention and the script's hash at the same time. Status pending; this is the owner's action.

---

## 4. Decision packages

All statuses are **PENDING: no owner record exists**. Recommendations are proposals.

### D-1 HR.4–8 deferral

- **Question (AR:87):** "HR.4–8 deferral record". The audit's recommendation: "Record DEFERRED, tied to HRX-L2 and OD-HR-14".
- **Current behaviour:** not built; absent by construction (REG:29). No WorkSchedule module or attendance event table. `StaffAttendanceArchitectureGuardTest::only_two_closed_half_values_are_stored_and_nothing_sensitive_or_time_tracking_exists` (:59) and `::own_attendance_is_read_only_no_device_surface_exists_and_student_attendance_is_separate` (:145).
- **Choices, recommendation:** §2.6 options A to D. Proposed: B, because it keeps D8-10 and S-16 alive.
- **Affected modules:** Leave, StaffAttendance, Payroll, proposed WorkSchedule.
- **Implementation:** a CM section (the place CC:29 requires), plus an RM amendment if B.
- **Staging and production:** none in code. Production NO-GO either way (H16:146). Clock attendance stays disabled in staging (H16:145).
- **Authority:** legal/security for L2b; owner for the L2a reopening. Neither is recorded.
- **Owner:** HR owner (AR:87).
- **Evidence required:** an owner record naming rows, reason, re-review trigger, and the re-homed destinations of S-6 and S-16.

### D-2 Payroll to HR coupling (R1-02)

- **Question (CC:39):** "Payroll's `EmploymentRecord` coupling is resolved or recorded as an owner-approved exception". AR:88 recommends "Exception with an exit plan".
- **Current behaviour:** 28 Payroll files import `HR\Infrastructure\EmploymentRecord` at the commit, with about 57 code references by a rough grep. `PayrollRunResult.php:5` imports `Employee`. Direct queries include `CompensationService.php:104`, `PayrollRunService.php:392,488`, `PayslipReadService.php:172,178`, `CompensationController.php:67,95,154`, `PayrollRunController.php:121,142,277,294,312`, `StatutoryEmployeeController.php:50,79`. **ADR 0034:42-44 says Payroll "reads HR's `Employee`/`EmploymentRecord` via HR's Application layer"**: the ADR is untrue of the code. OM:85 says 25 and 1 (dated `7a4068fe`). CC:206 says 25.
- **Also for R1-02:** Teaching, Timetable, Transport and Visitor import HR Infrastructure too (import lines by directory: Timetable 3, Transport 3, Visitor 3, TeachingAssignments 4, Documents 3, Attendance 1, WorkforceTasks 1. `app/Http/Controllers/App` has 42, which includes HR's own web layer, so that figure overstates the drift).
- **Choices:** (1) exception with an exit plan; (2) a thin HR reader for reads, FK and relationships kept as an exception; (3) full reader migration.
- **Proposed:** (2). FKs to `employment_records` are structural evidence and a reader cannot replace them; reads are the drift. An exception must also amend ADR 0034:43.
- **Affected:** Payroll, Payroll Statutory, HR, plus architecture guards.
- **Implementation:** (1) docs only; (2) a reader and a guard test; (3) many call sites and regression risk across Payroll.
- **Staging and production:** none functional. It is a closure condition, not a runtime one.
- **Authority:** engineering. Rule 4 and rule 15 require an ADR note for a deviation.
- **Owner:** Architecture (AR:88).
- **Evidence:** a scan on the baseline, a guard test pinning the allowed list, an ADR 0034 amendment.

### D-3 Accrual and eligibility scheduling

- **Question (AR:89):** "Accrual scheduling and monitoring". Audit options: "Keep manual and correct the docs, or schedule with a system actor".
- **Current behaviour, verified:** `routes/console.php` has **no** accrual or eligibility entry (`:25-295`; `workforce-tasks:generate` at `:63-66`). There is no `app/Console/Kernel.php`. No console command references accrual or eligibility for this purpose (`grep` hits only `PruneFinanceRecords.php`, `BackfillLifecycleMarkers.php`, unrelated). `config/observability.php` has no accrual entry. `docs/operations/alerts/lycenza-alerts.rules.yml:52-82` names `workforce-tasks-generate` and nothing for leave. Runs are human: `LeaveAccrualPageController`, `LeaveAccrualController`, `routes/api.php:2502`, `routes/web.php:2071`, all taking a `User $actor` (`LeaveAccrualService.php:111,129,174`).
- **The claims that are false at the commit:**
  - `H16:142`: "Scheduled-task heartbeats and alert rules exist for task generation and accrual (HR.10, HR.13)". True for tasks, false for accrual.
  - `OPS:15-18`: heartbeats for "the leave accrual and eligibility runs".
- **OPS §4 (:57-65) is accurate:** it describes a manual re-run from the accrual workspace.
- **Choices:** (1) stay manual, correct H16 and OPS; (2) schedule with a system actor; (3) stay manual and add a "month not run" detection.
- **Proposed:** (1) now, with (3) as an owner option. (2) has real cost: every service takes a human `User`, `hr.leave.manage` is capability-checked, and a system actor is a new authority concept. ADR 0065 §29 has no scheduling contract (`:2286-2327`); its web path is a person re-pressing 20 batches at a time (`:2321`).
- **Affected:** Leave, Observability, Operations.
- **Implementation:** (1) two doc lines; (2) a command, heartbeat, alert, ADR 0065 amendment, actor design.
- **Staging and production:** a missed manual month is silent under (1). Worth an operator procedure.
- **Authority:** product and engineering. No legal gate.
- **Owner:** HR owner (AR:89).
- **Evidence:** a corrected doc; if scheduled, a heartbeat test.

### D-4 Fresh MFA on Leave writes and HR documents

- **Question (AR:90):** "Fresh MFA on leave allocation (S-7), and HRC-3 once re-verified". Audit: "Require for Highly Sensitive documents".
- **Leave writes, exact state at the commit:**

| Write | Web | API | Fresh MFA |
|---|---|---|---|
| Adjustment | `LeaveAdminController.php:242,251` | `LeaveLedgerController::adjust` :119,130; `api.php:2492` | **Yes** on both |
| Opening balance | `:258,263` | `:148,156`; `api.php:2499` | **Yes** on both |
| Allocation (one) | `:233` | `api.php:2489` | **No** |
| Annual allocation run | `:268` | `executeRun`; `api.php:2491` | **No** |
| Accrual run | `web.php:2071` | `api.php:2502` | **No** |
| Eligibility run | `web.php:2057` | `api.php:2497` | **No** |
| Policy assignment store, end | `:217,225` | `api.php:2487-2488` | **No** |
| Year close | `:408` | `api.php:2523` | **No** |
| Request approve, reject (both paths) | `:367`; `LeaveApprovalsController` | `api.php:2513-2520` | **No.** Fresh capability read instead (`LeaveRequestService.php:484`) |
| Cancel, withdraw, submit | `:353,367` | `api.php:2511-2516` | **No** |
| Configuration: types, policies, years, settings, calendar | `:152-176` | `api.php:2469-2484` | **No** |
| Self-service submit, withdraw, cancel | `StaffSelfServiceController` | `api.php:2541-2544` | **No** |

  Enforcement is at the controller (`LeaveLedgerService::adjust` takes no proof). Authority at allocation is `authorizeCapabilityFor` (`LeaveLedgerService.php:63,114`), the cached path S-7 names; only request decisions and two paths at `LeaveRequestService.php:235,372` read fresh.
  ADR 0071's step-up table (:1283-1298) lists no Leave write. A65 §29.7 (:2329-2330) covers adjustments. `LeaveOpeningAdjustmentTest::web_and_api_require_a_fresh_mfa_code_after_the_capability` (:98) tests those two.
- **HR documents at the commit: HRC-3 IS REAL. This contradicts AR:35.**
  - HR register routes take the step-up: web `HrEmployeeDocumentController.php:52,73`; API `EmployeeDocumentController.php:41,68,81` (`HighlySensitiveDocumentStepUp::requireFor`).
  - **The generic Documents routes do not.** `POST /employees/{employee}/documents` (`routes/api.php:953`, `DocumentController::storeForEmployee`, commit :64-75) and `POST /documents/{document}/archive` (`:981`, commit :244) carry no `requireFor` and no `requireForAction` at the commit.
  - The capability check still applies. `DocumentService.php:568` picks `.sensitive.manage` for the tier, so this is a missing **step-up**, not a missing capability.
  - `HrSelfAdministrationGuard` does not appear in `app/Domain/Documents` at the commit, so the generic path also has **no self-administration refusal**. That undercuts S-3 "FIXED" (REG:387) and rule 132 ("Every profile write"), and it is a finding AR does not hold. `DocumentService::archive` (`:518-560`) never touches `employee_documents`, so by reading a generic archive leaves a register record out of step.
  - The coverage guard cannot see this: `SchoolRouteCoverage.php:227,232` declare `DocumentService` as the contract, against `:201-203` for the register. A coverage entry proves authorization, never assurance.
  - Rule 95 and ADR 0071:1286 require "web + API".
  - R0-08 read only the register routes. At the commit, tests are `SensitiveActionMfaTest` (:156-160) and `StaffProfileFoundationTest` (:228-248). The test named in the parallel candidate is untracked.
- **Choices for Leave:** (1) allocation and runs need fresh MFA; (2) allocation only; (3) record no step-up as deliberate in A71 §26.7, with `requireCurrent`; (4) keep S-7 open.
- **Proposed:** close HRC-3 as a defect, not a decision, since ADR 0071 already decided it. For Leave, (2) with `requireCurrent`, because a run is bounded by policy units while a direct allocation names any amount. That is a judgement call for Security.
- **Affected:** Documents, HR, Leave, OpenAPI (`StepUpMfaCode`).
- **Implementation:** controller step-up plus self-guard on the generic path; `requireForAction` on allocate; OpenAPI; tests for web and API allow and deny.
- **Staging and production:** demo personas have no factor (H16:125-126). A School needs an enrolled factor before allocation.
- **Authority:** Security (AR:90). A71 §8 and §26.7, rule 95.
- **Evidence:** deny and allow tests on both surfaces, generic routes included.

### D-6 Calendar as-of (LVA-3)

- **Question (AR:91):** "Calendar as-of (LVA-3)". Audit: "Record a residual until HR.4".
- **Current behaviour:** §1 above.
- **Choices:** §1.4. **Proposed:** an owner-accepted residual now, with a calendar-history slice split from HR.4 before real data, plus the M7 freeze fixed on its own. Reason: history starts accruing the day a School goes live, and past-date history lost before cutover cannot be recovered (the OD-HR-25 row 19 lesson, REG:261).
- **Affected:** Leave, StaffAttendance, Payroll HRX, HR reports.
- **Implementation:** §1.5.
- **Staging:** synthetic data, residual harmless. **Production:** the first pattern edit makes past month views wrong, silently. No money effect while HRX-L4 is open. Before HRX-L4 opens, Payroll evidence quality matters, and A65:1646 says a future monetary policy "must refuse incomplete input".
- **Authority:** product. None legal yet; HRX-L4 is the trigger.
- **Owner:** HR owner (AR:91).
- **Evidence:** §1.5 tests, or a residual row with an owner and a re-review trigger.

### D-7 Performance targets

- **Question (AR:92):** "Performance targets (month view, Payroll packet)". Audit: "Set a p95 target".
- **Current behaviour:** month view about 4.3 s at 5,000 staff (H16:80; OPS:30-31; REG:440). Packet p95 900 ms at 10,000, later 287 ms on another fixture, "not comparable" (`PAYROLL.md:1361-1366,1380-1388`). Reports under 50 ms after ANALYZE (H16:73-79). One local machine. LVA-7 has no definition beyond AR:39.
- **Proposals already on record, none approved:** report page p95 under 500 ms at 5,000 (H16:83-84); packet p95 under 1 s and approval under 1 s at 10,000 (`PAYROLL.md:1372-1373`). Reversal 5 s: "deferred to staging, so there is no approved target" (REG:165-166).
- **Choices:** (1) set targets now; (2) defer all to staging hardware; (3) set a School size first.
- **Proposed:** (3) then (2). Numbers from one laptop-class machine do not transfer (H16:85-86), and the owner has already chosen that for reversal.
- **Affected:** StaffAttendance, Payroll.
- **Implementation:** none until a target fails.
- **Staging:** measurement becomes possible. **Production:** no gate today.
- **Authority:** product. **Owner:** Product (AR:92).
- **Evidence:** measured p95 on a named environment.

### D-9 TRR-5 classification

- **Question (AR:67,93):** "The "employments examined" column in task-generation reports is close to an ADR 0040 time series". Audit: "Accept as operational, or bound the column".
- **Current behaviour:** `TaskGenerationRunReport.php:54` `new C('examined', 'Employments examined', 'number')`; one row per School-local day (`:20`); range up to 93 days; `hr.tasks.view`; whole School.
- **Key point:** dropping the column alone does not remove the number. `:26` states "examined x occurring templates = created + existing + not matched", and the other three columns remain.
- **Choices:** (1) accept as operational; (2) replace with "reconciled yes/no"; (3) drop or band all count columns.
- **Proposed:** (1) with a recorded reason, because it counts employments a job touched rather than a cohort, or (2) if the ADR 0040 owner reads it as a daily headcount. It is a classification for that owner.
- **Affected:** WorkforceTasks, HR reports, rule 140 ("never count people").
- **Implementation:** (1) none; (2) a read-model change and a pin.
- **Staging and production:** none. No operational dependency: the reconcile test would need adjusting under (2) (`HrReportsTest::task_reports_count_occurrences_by_status_and_reconcile_without_rates_or_remarks`:443).
- **Authority:** ADR 0040 cohort owner (REG:37).
- **Owner:** Product (AR:93).
- **Evidence:** a recorded classification.

### D-10 Extend E47

- **Question (AR:94):** "Extend E47 to the add-on roles". Audit: "Extend before sending".
- **Current state:** `SR-L1-STAFF-ROLE-PERSONAS-DPO-QUESTION.md:3` "DRAFT QUESTION — NOT SENT, NOT ANSWERED". Roles named at `:23-29`. `line_manager`, `team_task_manager` and `payroll_approval_reviewer` appear in neither that file nor ADR 0058 (`grep`: no hit). ADR 0058:615 is the E47 row. REG:35 says "any new HR role or capability set extends this question".
- **Finding:** adding names is not enough. The question's data sets are (a)-(e) at `:48-56`. `payroll_approval_reviewer` fits (c). `line_manager` touches direct reports' leave requests, and `team_task_manager` touches staff tasks: neither set is in (a)-(e).
- **Code:** roles in the catalogue, pinned at 20 (`StaffRoleCatalogueSnapshotTest.php:240`).
- **Choices:** (1) amend names only; (2) amend names and add a leave and task data set; (3) send now and supplement.
- **Proposed:** (2).
- **Affected:** Identity, Leave, WorkforceTasks, Payroll.
- **Implementation:** docs only. No code path changes.
- **Staging:** synthetic data, no effect. **Production:** policy prerequisite (REG:35).
- **Authority:** DPO. Sending the question is a real external communication, so it needs the owner's explicit act (rule 16).
- **Owner:** Legal (AR:94).
- **Evidence:** a dated, answered determination recorded verbatim, per `:75-76`.

---

## 5. Closure criteria

### 5.1 Exact text and evidence

Re-run on a chosen baseline: use a clean snapshot worktree of that SHA, never this dirty tree. Scoped: `apps/platform/bin/safe-test --filter='<names>'` (`bin/safe-test` forwards to phpunit), with `SAFE_TEST_ISOLATED=1` if anything else is running. Full: the exact-SHA `CI gate` is canonical (rule 96). None was run here.

- **R1-04 (CC:41):** "**No authorization gap.** Every HR route has a coverage contract (rule 98); manager relationship ≠ data visibility; export and column authority match the screen | `CoverageGuard`, deny tests".
  - Evidence: `AuthorizationCoverageGuardTest` (`every_registered_route_is_classified_exactly_once`:31, `every_school_route_has_an_authorization_contract`:37, `every_declared_contract_is_shown_by_the_code`:49), `SchoolRouteRefusalSweepTest`, `RepresentativeRouteAuthorizationTest`, `HrDefaultDenyTest`, `HrMutationAuthorizationTest`, `HrMultiSchoolAuthorizationTest`, `LeaveLineManagerTest`, `HrReportsTest::a_campus_grant_of_an_adopted_key_is_never_whole_school_report_authority`:184, `::placement_columns_and_filters_need_whole_school_assignment_authority`:241.
  - Gap: the guard proves a contract, not assurance or self-administration (D-4). A PASS here would not have caught HRC-3.
  - Export: HR has none (REG:38). Statutory exports exist in Payroll (S-4).
  - `CC:208` state: Recorded.
- **R1-05 (CC:42):** "**No stale reporting contract.** Every HR report is in the registry with pinned filters, sorts and columns; none counts people outside the Analytics gate | registry pin tests".
  - Evidence: `HrReportsTest` (`the_catalogue_is_closed_and_every_report_names_its_owner_authority_and_reference`:135, `filters_sorts_and_columns_are_closed_and_validated_on_the_server`:195), `HrReportTemporalSemanticsTest`. 10 rendered + 8 governed = 18 (`HrReportRegistry.php`; CC:52 agrees). Open interaction: D-9.
- **R1-07 (CC:44):** "**No unresolved lifecycle issue.** Separation closes assignments, teaching ownership, reporting lines, shifts, Transport driver assignments and Timetable entries per each owner's contract | lifecycle integration tests".
  - Evidence: `WorkforceLifecycleIntegrityTest`, `WorkforceLifecycleConcurrencyTest`, `EmploymentEndTeachingOwnershipTest`, `EmploymentEndTeachingOwnershipConcurrencyTest`, `EmploymentEndArchitectureGuardTest`, `TeachingAssignmentEmploymentEndGuardTest`, `ReportingLineEmploymentEndConcurrencyTest`, `TimetableEmploymentEligibilityTest`, `CurrentEmploymentDutyEligibilityTest`, `SeparationCaseServiceTest`.
  - "Per each owner's contract" does the work: teaching ownership is closed through `EmploymentEndParticipant` (`AppServiceProvider.php:149`). Timetable and Transport answer as obligations and are never closed by HR (`TimetableSeparationObligations.php`, `TransportSeparationObligations.php`, rule 137). Shifts do not exist. Open neighbours: XRP-4, TRR-8.
- **R1-08 (CC:45):** "**Payroll / attendance / leave reconciliation.** Every payroll run's HR inputs reproduce from Leave and StaffAttendance evidence; post-approval source changes surface (`differenceState`); no LOP money effect without HRX-L4 | reconciliation tests".
  - Evidence: `PayrollHrxSnapshotTest` (:46, `a_posted_snapshot_is_frozen_and_later_hrx_changes_surface_as_a_difference_never_a_rewrite`:83, `hrx_evidence_never_changes_an_amount_while_hrx_l4_is_open`:66, :114, :142), `PayrollHrxInputApiTest`, `PayrollAbsenceEvidenceReaderTest`, `PayrollHrxConcurrencyTest`, `PayrollHrxArchitectureGuardTest`, `PayrollHrxOpenApiCoverageTest::no_ncp_loss_of_pay_or_wage_deduction_operation_exists_anywhere`:62, `PayrollHrxInputUiTest`, `StaffAttendanceLeaveReconciliationTest`.
  - Limit: "reproduce" fails for a past run once the calendar changed (§1.3 M1, M2). Snapshot is sound; reproduction from sources is not. A literal reading makes R1-08 depend on D-6. Also M7. Also LVA-8: `labels()` reaches `rows()` with `limit(2000)` (commit `EmploymentRoster.php:136,163`), so `PayrollHrxInputReadService.php:89` blanks names beyond 2,000 staff.
- **R1-09 (CC:46):** "**Offboarding dependencies.** Inventory custody, Library loans, Finance clearance, Timetable substitution and Transport each answer the clearance question | clearance integration tests".
  - Evidence: `SeparationCaseServiceTest::owners_that_record_no_staff_obligation_are_shown_as_not_integrated_never_clear`:347, `SeparationObligationTruthTest`, `SeparationAccessCertificationTest`, `LeaveSeparationCreditBoundaryTest`.
  - State: Transport answers. Library, Inventory, Finance are fixed NOT_INTEGRATED (`SeparationObligations.php:34-38`), by owner decision (REG:413, RM:579-581). Timetable answers about entries; **substitution does not exist** (OM:53, CC:57).
- **R1-10 (CC:47):** "**Gates intact.** HRX-L1–L4, ADR 0036, the ADR 0040 person-count gate and D-11: each still refused, or opened only by a recorded decision | gate register re-verification".
  - Evidence: `LeaveArchitectureGuardTest::no_leave_table_can_hold_health_biometric_free_text_or_a_stored_balance`:69, `::hr_l8_no_leave_table_or_rule_can_use_a_protected_characteristic`, `StaffAttendanceArchitectureGuardTest`, `PayrollHrxArchitectureGuardTest`, `AnalyticsReadGateTest`, `CohortSuppressionPolicyTest`, `AnalyticsArchitectureGuardTest::no_registered_read_model_counts_people_while_the_cohort_policy_is_undecided`:71, `StaffRoleCatalogueSnapshotTest` (:305 gated keys on no School role), `StatutoryExportsTest`.
  - Limit: REG:370-372 says these are "Held only by absence, guard tests and role non-assignment", against the rule at REG:373-375 that anything next to a gate needs an explicit availability gate. The statutory screens and exports are built and held by non-assignment (S-4).
- **R1-11 (CC:48):** "**Retention.** Every HR table has a unit; purge and hold paths are tested | retention catalogue tests".
  - Evidence: `TenantClosureReadinessTest::the_catalog_classifies_exactly_the_live_tenant_tables`:40, `::an_unclassified_tenant_table_fails_closed`:108, `EmployeeRetentionClassificationTest`, `EmployeeRetentionPruneTest`, `HrxEvidenceRetentionTest`, `HrxRetentionGuardTest`, `HrxPayrollRetentionIndependenceTest`, `PayrollEvidenceRetentionTest`, `PayrollAccountLinkRetentionTest`, `RetentionHoldsTest`, `RetentionDeleteBoundaryTest`.
  - CC:215 records 85 of 92. E21 is "PENDING FINAL LEGAL/COMPLIANCE RATIFICATION" (E21:3).
  - New staleness: E21:490 says the Employee↔User link has "No separate history table". `employee_account_links` exists since HR.R1-C7 (REG:245).
- **§4 verdict rule (CC:78-84), exact:** "An HR.R1 record in `docs/hr/` with a verdict of **PASS**, **PASS WITH CORRECTION** or **BLOCKED**. Rule for the verdict: any BLOCKER or MAJOR finding means **not closed**; corrections are their own published units, then HR.R1 is re-run."
- **CC:218:** "Whether legal and environment items block a development-scope verdict is not stated in §4. The owner needs to define it."

### 5.2 Is §1 satisfiable today?

CC:10-19: "HR.R1 runs only after the wider ERP programme has connected HR with each of these" (Organization, SIS, Finance / Payroll, Inventory, Transport, Timetable / Academic, IAM / security, retention / audit).

**Not on a literal reading.** "Connected" is undefined. CC:57 itself lists "still blocked: HR ↔ SIS (staff children), Inventory, Finance, Payroll salary logs, Timetable substitution, Analytics". Inventory custody, Library staff borrowing and Finance staff dues are deferred by owner decision (REG:413), so those connections will not exist unless the owner reverses that. Organization, Payroll, Transport, Timetable (entries), IAM and retention do have real contracts. Whether §1 is met is therefore **an owner reading, not a fact the repository can settle**.

### 5.3 Proposed interpretation (a proposal; the owner decides)

| Dimension | Meaning | Evidence | Today |
|---|---|---|---|
| Development-scope completion | Every slice published, or held by a recorded decision | CC §2, CM | Finding: D-1 |
| Architecture closure | Findings closed, or a recorded exception with an owner | AR, ADR notes | Open: R0-15 to R0-21, D-2, HRC-3 |
| Owner-decision completeness | Each D-n, OD-HR-14 to 21 answered or carried with a re-review trigger | REG, AR | Open |
| Legal and security authorization | Never implied by a verdict. Lists gates as OPEN and intact | REG §1, E21, ADR 0058 | HRX-L1 to L4, E45, E47, E21 unratified |
| Staging execution readiness | Conditions in H16:145, restore and encryption evidence | R0-04, GOV-9 | Paused, infrastructure-blocked (REG:199) |
| Production readiness | Outside HR.R1's verdict; carried as NO-GO | H16:146 | NO-GO |

Proposed reading of §4: "BLOCKER or MAJOR" applies to findings against the development-scope and architecture rows. Open legal, staging and production items do not fail the verdict provided each is recorded, unchanged and refused in code or by absence, and the verdict names itself "PASS for development scope; production not addressed". "Permanently closed" (CC:23-24) would then need a second, later assessment, or the phrase needs narrowing.

Proposed reading of R1-09: each owner gives one of three recorded answers: INTEGRATED with a test; DEFERRED BY OWNER and shown NOT_INTEGRATED, never clear, with a re-review trigger; or NOT BUILT, with an owner naming it. A NOT_INTEGRATED owner never counts as "answered clear". This matches the code's stance (`SeparationObligations.php:17-20`) and does not pretend the question is answered.

### 5.4 Proposed closure baseline

| Baseline | Pins | Rule |
|---|---|---|
| Source code | One exact SHA on `main`, with a green `CI gate` on that SHA | C10 candidate committed or discarded first. Latest full evidence is `415f7bbb` (CC:194); `82e70a8a` is two doc commits later (`539ef9d9`, `82e70a8a`) and no gate result for it appears in the docs read here |
| Migrations | Count, last migration name, `platform:verify-database` result, schema dump hash from that SHA | Recorded per rule 10 and rule 143 |
| Reference pack | `40ed6a83…1ac3`, owner-run recomputation, date, byte convention, script hash | Owner action; outside Git |
| Legal and security decisions | SHA of REG plus an explicit list: id, status, determination date, expiry. E16 last valid day 2026-11-28 (REG:43) | A dated snapshot, never an implied approval |
| Local test environment | CI run id, shard count, cases, skip list (ESI-12), PHP and Postgres versions, demo reset evidence | State clearly that it is synthetic data on one machine |

---

## 6. Security register

Every S and X entry not FIXED or CLOSED. Exact entries quoted. "Production NO-GO" applies to all.

### S-4 (REG:388)

- **Entry:** "| S-4 | LOW (latent) | Statutory identifier reveal and the ECR/TDS exports carry no MFA or no-store header | latent | before any statutory grant |".
- **Runtime path:** API reveal `routes/api.php:2038-2040` (capability only), `StatutoryIdentifierController.php:32-35`. Web reveal `routes/web.php:2458`, `StatutoryEmployeeController.php:150-160`. Exports API `api.php:2052-2060`, web `web.php:2468-2470`; both controllers set Content-Type and Disposition only. `grep` for `no-store`, `private-no-store`, `FreshMfa`, `mfa` in the Statutory directories: no hit.
- **Preconditions:** a role holding `payroll.statutory.identifiers.view` or `.exports.generate`. No production role does (REG:32).
- **Controls:** capability, role non-assignment, snapshot test (`StaffRoleCatalogueSnapshotTest.php:305`), demo-only `demo.payroll_statutory` (`DemoDataBuilder.php:89-96`).
- **Tests:** `StatutoryAdminApiTest::identifiers_are_masked_by_default…`:147, `StatutoryExportsTest`. None asserts no-store or MFA.
- **ADR:** 0036, rule 95, rule 142. **Owner:** Payroll. Not recorded in REG; RM:588 puts S-4 in the HR.15 Payroll slice, which never closed it.
- **Action:** `mfa` and `private-no-store` before any statutory grant. CSV formula guard: TRR-1 (at the commit `StatutoryEsiContributionWorksheetExportService.php:107`, `StatutoryTdsDraftStatementExportService.php:112` have no `CsvCell::safe`).
- **Staging:** statutory off (H16:145). **Production:** blocker for any statutory grant. R1-12 wants FIXED or a residual with an owner decision: neither exists.

### S-6 (REG:390)

- **Entry:** "| S-6 | LOW | Staff attendance correction: no window, MFA or second person; cached check | recorded decision; re-review at HRX-L4 activation | HR.7 |".
- **Runtime path:** web `routes/web.php:2126`, API `routes/api.php:2568` (no `idempotent`), `StaffAttendanceService::correct` (`:136-215`), `authorizeCapabilityFor` at `:138`.
- **Preconditions:** whole-School `hr.staff_attendance.manage`; not one's own employment (`:153`); current version.
- **Controls:** closed reason, compare-and-swap, append-only correction row, audit, `staff_attendance.corrected.v1`, self refused, no future date.
- **Tests:** `StaffAttendanceCorrectionTest`, `StaffAttendanceConcurrencyTest`.
- **ADR:** 0065 §24.8; rule 95.
- **The search for the "recorded decision".** `git log --all -S` on the phrase, `-G` on `S-6`, `--grep`, whole-repo docs, ADRs, memory:
  1. The label first appears at `3a54892c` (HR.EXP-0 draft, 2026-10-30). That text reads: "Recorded as "NO_APPROVAL; keep (self refused)" (`APPROVAL-AUTHORITY-MATRIX.md`)", "recorded decision; **re-review at HRX-L4 activation** (it feeds loss-of-pay evidence)". The citation was dropped when HR.0A reissued the row at `592feef4`.
  2. So the source is `docs/security/APPROVAL-AUTHORITY-MATRIX.md:48`: "NO_APPROVAL; keep (self refused)", last changed at `6fa511dc` (2026-10-21, "approval authority and assurance contract audit").
  3. That text comes from the audit at A76 §56.23 (:8334-8341): "Staff: separate system (HRX), direct correction with closed reason, version CAS, correction evidence trigger, self refused; NO_APPROVAL, unchanged." It sits in an audit whose student half is explicitly a "Recommendation".
  4. The matrix row is about **approval only**. It says nothing on MFA, a time window or the cached check.
  5. OD-8.6 was closed by the owner, but 8E-D2 says "**Student** Attendance correction stays direct; no approval and no time window (OD-8.6 CLOSED)" (A76:9291-9292), and A76:9311 says "Staff Attendance is untouched". The matrix's generic wording (`:127`) does not extend it.
  6. A65 §24.8 is the design contract. It is silent on MFA and second person, and carries no owner record for them. A71 §26.7 does not list it.
  7. "re-review at HRX-L4 activation" appears only in the register row and its draft.
- **Finding: no owner determination for Staff Attendance MFA, window or second person was found.** The label is a developer's or auditor's carry-forward of an audit classification, and the audit register is right that it is "not located" (CC:216). The nearest owner act (OD-8.6) concerns Student Attendance.
- **Owner:** HR owner, unnamed in the entry.
- **Action:** a real owner record, or HR.7 per RM:332 ("S-6 is closed").
- **Staging:** none. **Production:** R1-12 finding. It starts to matter when HRX-L4 opens, since corrections feed absence evidence.

### S-7 (REG:391)

- **Entry:** "| S-7 | LOW | Leave adjustments and allocations need no fresh MFA; cached check | open | HR.10 |".
- **Stale as written.** Adjustments and openings take fresh MFA (D-4 table; RM:404; CM:370). The allocation half stands, with the cached check. REG shows `open`; CC:216 says partial.
- **Preconditions:** `hr.leave.manage`, never one's own employment.
- **Controls:** idempotent routes, append-only ledger, never-negative trigger, audit, self refusal.
- **Tests:** `LeaveOpeningAdjustmentTest`:98.
- **ADR:** 0065 §29.7. **Owner:** Security (D-4). **Action:** D-4.
- **Staging:** factor enrolment. **Production:** R1-12 finding.

### S-16 (REG:400)

- **Entry:** §1.6.
- **Path:** web `web.php:2060`, API `api.php:2484` DELETE, `StaffCalendarService.php:80-90`.
- **Preconditions:** `hr.leave.configure` (`school_admin`, `principal`, A65:413).
- **Controls:** audit with date and portion, exclusive calendar lock, leave evidence frozen.
- **Tests:** `LeaveFoundationTest::the_staff_calendar_counts_only_working_halves_and_refuses_until_configured`:150. None pins that removal is safe for history.
- **ADR:** 0065 §4.7, §22.6. Rule 73's spirit, though `staff_holidays` is not listed there.
- **Owner:** HR owner. **Action:** D-6.
- **Staging:** none. **Production:** history corrupts silently (M2).

### X-1 (REG:401): what it still covers

- **Entry, last cell:** "**HR.12 (2026-11-04): write paths FIXED** -- Timetable entry create/update, Transport driver assignment and Visitor host check-in require `EmploymentCoverage::hold()` = COVERED on the School-local today; history unchanged. **Remaining (R-HR12-1, LOW): the pickers still list separated Employees by name** -- HR.15".
- **That cell is stale.** R-HR12-1 is closed (REG:412, HR.15A). `DutyCandidateReader` is in the Timetable and Transport controllers and `TaskReadService` at the commit.
- **What X-1 still covers:**
  1. **Rule 4 drift.** Timetable, Transport and Visitor still import `HR\Infrastructure\Employee` and call `Employee::query()->findOrFail()` directly: `TimetableEntryController.php:241,430` (web), `:321` (API), `TransportOperationsController.php:143`, `TransportRouteAssignmentController.php:50`, `VisitorVisitController.php:139` (web), `:87` (API). Each then passes `hold()`. RM:590 lists "X-1 (Timetable, Transport, Visitor via HR readers)" as HR.15 scope, not done.
  2. **Name-only read:** `TimetableEntryController.php:141` reads scheduled teachers via `Employee::query()` for a filter (scheduled only; no currency risk, rule 4 only).
  3. **Adjacent, not X-1 by name:** `TeachingAssignmentController.php:163` and `ElectiveTeachingAssignmentController.php:71` list by `record_status = 'active'` (XRP-3, R0-21). Rule 138 names Timetable, Transport and Visitor, so these fall outside it.
- **Preconditions:** none harmful on write. **Tests:** `DutyCandidateSelectorTest`, `CurrentEmploymentDutyEligibilityTest`, `TimetableEmploymentEligibilityTest`. **Owner:** Architecture (with D-2). **Staging, production:** R1-02 and R1-12 finding only.

### Others not FIXED

- **M-1 (REG:404):** "| M-1 | recorded production prerequisite | An unlinked staff parent is not detected by beneficiary refusals | recorded | owner policy |". Production policy prerequisite. `APPROVAL-AUTHORITY-MATRIX.md:131`.
- **S-3 (REG:387): marked FIXED, not fully at the commit.** See D-4: the generic employee Document routes have no self-administration refusal.
- **Other findings marked FIXED or CLOSED** (S-1, S-2, S-5, S-8 to S-15, X-2, X-3) were not re-examined here. They are as recorded.
- **Residual tables:** R-HR12-2 (MEDIUM, DEFERRED by owner), R-HR12-3, R-HR10-1 (PARTIAL), R-HR13-1 to 3, R-HR14-3, R-HR14-5, R-HR16-1 to 4 (REG:413-443), OD-HR-25 second-account and collusion limits (REG:271,282,288).

---

## 7. Stale records

Each item checked at the commit. "Correction" is the text change needed, a proposal.

| Item | Location | Current text | Verdict | Correction |
|---|---|---|---|---|
| **TRR-3** | `CLAUDE.md:1944` (rule 140); `docs/modules/HR.md:5281-5282`, `:5287` | "a range above `maxRangeDays` is a 422, never a truncation"; HR.md: "anything else is a 422" | **Confirmed.** `HrReportController.php:60-66,85-90,123-131` renders 200 with `errors`. `HrReportsTest.php:107-112,203`. H16:39 explains the change | "…is refused with a page error, never a truncation". Same for columns at HR.md:5287 |
| **GOV-3** | REG:441 | "Write flows needing fresh MFA (leave approval, separation decisions, attendance correction, task review)" | **Confirmed.** Only separation takes it (`HrSeparationController.php:84,121,130`). No MFA in `LeaveApprovalsController`, `LeaveRequestController`, `StaffAttendanceAdminController`, `HrTaskController` | Split: separation needs MFA; the other three were browser-unexercised and need none |
| **GOV-4, S-7** | REG:391 | `open`, "Leave adjustments and allocations" | **Confirmed stale** | "Adjustments and openings FIXED (HR.10); allocation open" |
| **GOV-4, §3a** | REG:357 | Audit quoted "S-1 stays OPEN". Actual: "by HR.1A; S-1 stayed OPEN then." with an Outcome at :358-363 | **Already corrected at `852fb06a`.** AR:76 is out of date on this half | Remove that half from LVA-9 |
| **GOV-4, 17 roles** | REG:40; `CLAUDE.md:1125` (rule 93); `DDEV-DEMO-REVIEW.md:272,341,355,648` | "17 roles" | **Confirmed, and wider than listed:** two more DDEV lines. `StaffRoleCatalogueSnapshotTest.php:240` asserts 20. A71:1634,1913 | 20, and rule 93's "(ADR 0071 §4, §25)" gains §30, §32, §33 |
| **GOV-4, School Admin payroll** | `DDEV-DEMO-REVIEW.md:272` | "payroll amounts and statutory screens are 403 for her own account" | **Confirmed.** Same row and `DemoDataBuilder.php:305-306` grant `payroll_approval_reviewer`, which holds `payroll.compensation.sensitive.view` (seeder :1823-1827) | Payroll amounts visible under current MFA on the approval review; rest stands |
| **PAY-10** | REG:349-363 (the brief's lines ~170-178 have moved) | "purpose-limited, immutable approval snapshot" at :350-351, then an Outcome saying a LIVE packet | **Mostly resolved.** Heading says "target contract". Only REG:350 keeps the term | Optional wording. Rule 93 as above |
| **LVA-9** | `LeaveEligibilityResolver.php:22` (commit) | "HR's EmploymentRoster::on()" | **Confirmed.** `on()` no longer exists (grep at commit); the same file's `:81` says so | `pageOverlapping()` |
| **TRR-9, task** | `TaskGenerationService.php:22` (commit) | "run nightly" | **Confirmed.** `routes/console.php:63-66` every minute | "run every minute" |
| **TRR-9, resolver** | as LVA-9 | | Same | |
| **TRR-9, balance** | `LeaveBalanceReport.php:110` (commit); also `LeaveReadService.php:208-209`, `LeaveStatementReader.php:61` (commit) | Restates the credit predicate | **Confirmed** (LVA-5; rule 134 "nowhere else") | One predicate. A code change |
| **TRR-9, HR-16** | H16:42 | "**OPEN** at HR.16; since DECIDED and implemented" | **Already corrected** | None |
| | H16:138,146 | §5: OD-HR-1/2 listed as preserved and as a production blocker; "Blocked by OD-HR-1/2 (Payroll)" | **Confirmed stale** against H16:151-160 and REG:42 | Replace with HRX-L4, E45, statutory |
| | H16:142 | Accrual heartbeats | **Confirmed false** (D-3) | "for task generation only" |
| **HRC-5** | ADR 0028:290-291 | "**Editable profile records carry a stale-form token**" | **Confirmed** against `CLAUDE.md:1859` and HR.md:4663 | "Identity and personal edits", or the owner extends the rule |
| **HRC-6** | `EmploymentService.php:431-433` (commit) | "NOT database-constrained… sole enforcement point" | **Confirmed.** HR.1A trigger exists (REG:394, rule 130). HR.md "Temporal data strategy" (:388) needs the same check | Name `trg_employment_records_no_overlap` |
| **XRP-5** | REG (whole file) | No StudentMark row (`grep`: only "Marks of Identification" and "remarks") | **Confirmed** | Add a row, or state it is out of HR's register |
| **XRP-6** | `DOMAIN-MAP.md:111` | HR depends on "Schools, Identity & Access" | **Confirmed.** HR reads `school_memberships` (`CurrentStaffMembers.php:50,105`), `roles` (`EmployeePickerReadService.php:85`), `Campus` throughout (`HrEmployeeAccess.php:158,212`) | Add Campuses, note Identity tables |
| **GOV-5** | `SchoolRouteCoverage.php:1359-1360` | "HR.R1-0 GOV-5: the run page's own check is getRun" | **Already fixed in code at `852fb06a`.** `PayrollRunController.php:220-223`. **AR:46 lists it open and AR:18 says its text is unrecoverable** | Close it in AR |
| **OM:23** | OM | "existing; category/type not effective-dated (X-3)" | **Confirmed stale.** X-3 FIXED (REG:403); OM:24 says so | "effective-dated since HR.1A" |
| **OM:46-47** | OM | "proposed `employment_lifecycle_events`… workflow not built"; "proposed WorkforceTasks… not built" | **Confirmed stale.** HR.12 and HR.13 published (rules 137, 139) | Separation cases and WorkforceTasks, implemented |
| **OM §3** | OM:80-87 | Dated `7a4068fe`, "25 places", X-1 "Destination HR.15"; OM:52,54 | **Stale in part.** 28 importing files. Pickers closed. The rule 4 half stands | Re-date and restate |
| **HR-OPERATIONS** | OPS:18 | "the leave accrual and eligibility runs" heartbeats | **Confirmed false** | Delete. §4 stands |
| **CM:705** | CM | "**Buildable now without any gate:** the 90 MISSING and 40 PARTIAL rows" | **Confirmed stale.** Now 11 and 20 (CM:590-591 and a recount). CM:704 stops at HR.2A, too | Restate, and §5 of the roadmap with it |
| **MASTER-ROADMAP:172** | `docs/roadmap/MASTER-ROADMAP.md:172` end | "**Next: HR ERP — HR.EXP-0R reference-pack capability map (read-only).**" | **Confirmed stale.** Done; line 174 is current | Point at row 16 |
| **HR-16 §5** | as above | | Confirmed | |
| **New: AR:31** | AR R0-04 | "See "Staging" in `HR-R1-CLOSURE-CONTRACT.md`" | **Dead reference.** CC has no Staging section; the only hit is CC:60 | Point at H16:145 or write the section |
| **New: AR:35** | AR R0-08 | "Not reproduced on reading" | **Wrong at the commit** (D-4) | Reopen HRC-3 |
| **New: REG:401** | X-1 last cell | "pickers still list" | Stale | Point at REG:412 |
| **New: E21:490** | E21 | "No separate history table" | Stale since HR.R1-C7 | Point at `employee_account_links` |
| **New: ADR 0034:43** | ADR | "via HR's Application layer" | Untrue of code (D-2) | Amend |
| **New: A65:77-78** | ADR | "(§8)" | Wrong section (§2.3) | Cite §7 and §24.14 |
| **New: CM:296** | CM D8-11 | "frozen at approval" | Captured at calculation, frozen from approval | Reword |
| **New: `LeaveCoverageReader.php:63`, A65:1616-1617** | code, ADR | `is_paid` "frozen once the type is used" | Not for a policy-less untracked type (M7) | Fix the trigger |
