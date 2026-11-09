# HR.R1-C22: WO-5, staff calendar history (2026-11-09)

**HR.R1 stays OPEN, and this is not the closure audit.** It implements D-6 as decided in HR.R1-C21 (`HR-R1-C21-D6-DECISION.md` §6), on the HR owner's authorization D6-7. Baseline `b2a1dff3`.

## 1. Authority

| Item | Recorded |
|---|---|
| Implementation | Authorized by the HR owner on 2026-11-08 (D6-7: "Authorize WO-5 next"), to the contract in the C21 record §6 |
| Policy | D6-1 to D6-6 and CM-1 as decided in C21; nothing new was decided here |
| Not authorized and not done | A historical import or backfill; WorkSchedule, shifts, clock times, Campus or department calendars; any Payroll formula, Leave ledger or approved-record change; staging, AWS or production |

## 2. What was built

**Week versions.** `staff_working_week_versions` replaces the overwrite of `staff_working_weekdays`.

- **Shape:** a School, an `effective_from` date, seven weekday portions (`full`, `first_half`, `off`), a status (`active`, `withdrawn`, `superseded`), a closed reason, the recording actor and the database's recording time, and the status-change actor and time.
- **One answer per date:** at most one active version per School and date (partial unique index). The week in force on a date is the active version with the greatest `effective_from` not after it.
- **Withdraw:** a version not yet in effect is withdrawn (never deleted). Once in effect it is refused.
- **Supersede:** a past-dated correction on the date of an existing version supersedes it in the same statement chain; the replacement names it (`supersedes_id`).

**Holiday lifecycle.** `staff_holidays` gains `status` (`active`, `withdrawn`), a recorded reason (past-dated adds), a recorded time, and the withdrawal's actor, time and reason. One active holiday per date. A holiday not yet in effect is withdrawn at once; one on or before the School's today is withdrawn only as a correction.

**No backfill (D6-3).** A past date needs a history start at or before it. A School without history (none at cutover, or created later) starts today or later: its first week or holiday on a past date is refused, even as a correction, in the service (`LEAVE_CALENDAR_BEFORE_HISTORY`) and the database (`staff_calendar_before_history`). Today and later are never "before the history start", so a future first version never blocks today's. Test fixtures get history before the dates they record through the owner-only `cutover` row, written through the local/testing fixture seam (`Tests\Support\StaffCalendarFixture`, `LocalCatalogueFixtures`), never through the service.

**Corrections (D6-2, D6-5).** A date before the School's local today is a correction. The web and API controllers check `hr.leave.configure` first, then require a closed `CalendarChangeReason` (late declaration, entered in error, administrative review), then the actor's own fresh MFA (`FreshMfaRequirement::requireForAction`, outside the transaction). The service refuses a missing reason, a missing or foreign proof, and re-reads the capability with `ApprovalDecisionAuthority::requireCurrent()` under the calendar lock. A correction never reaches a month whose Staff Attendance is locked.

**Reader.** `StaffCalendarHistory` is the one reader:

- `day(school, date)` → `CalendarDay` (status `RECORDED`, `NOT_RECORDED` or `NOT_CONFIGURED`; the working portion; the holiday; the version id);
- `range(school, from, to)` → one query for versions, one for holidays;
- `calculator(school, from, to)` → the unchanged `WorkingDayCalculator` formula over each date's own calendar, refusing `LEAVE_CALENDAR_NOT_CONFIGURED` or `LEAVE_CALENDAR_NOT_RECORDED`;
- `today(school)` → the database's School-local date (`staff_calendar_local_today`), the same "today" the triggers use.

**Policy as-of.** `LeavePolicyAsOf::forEmployment(school, employment, leaveType, date, actor)` returns the immutable version assigned on that date, or none. Read-only, `hr.leave.view`.

**Consumers.**

| Consumer | Behaviour now |
|---|---|
| Leave submission and approval | Count with the calendar in force on each date; a range touching a date before the history start is refused (`LEAVE_CALENDAR_NOT_RECORDED`), nothing written |
| Staff Attendance writers | A date before the history start refuses new evidence |
| Register and month view | Pre-history halves read "Calendar not recorded" (`calendar_not_recorded`), never working or off; the month summary counts them in their own column |
| Payroll HRX capture | Pre-history halves are `calendar_not_recorded`; the evidence is `input_incomplete` with that reason and `requiredWorkingHalfUnits` null; each half carries the version id it was read from. The contract is now `hrx_payroll_input.v2`; a v1 snapshot is shown as not comparable, never as a changed source (§5) |

**Notice (D6-4, D14-08).** Before saving, the configuration page can ask what a change would touch in its range (a week: a year from its date; a holiday: its day): pending leave requests and recorded attendance days, counts only. The saved change repeats the counts in its confirmation. Nothing is recalculated.

**Pages.** Leave configuration: the week editor gains an effective-from date (default the School's today); a past date reveals the reason and the code field; the version table shows each version, its state and reason, and Withdraw for a scheduled one; holidays show their state and Withdraw (with reason and code once in effect); a "Check what this touches" action on each form fills a live status region. Register and month view: the "Calendar not recorded" state. The API's `DELETE` on a holiday withdraws it (204).

## 3. Database

| Mechanism | What it guarantees |
|---|---|
| `trg_staff_working_week_versions_guard` | Born active; `cutover` only from the owner (`pg_has_role(current_user, owner)`, the codebase's maintenance precedent); `recorded_at` from the database clock; nothing before the history start; a past date needs a non-cutover reason; terms frozen; state final; withdrawal only before effect; supersession only by its replacement |
| `trg_staff_holidays_history_guard` | The same rules for holidays: born active, past-dated adds need a reason, terms frozen, withdrawal once and, once in effect, with a reason |
| `trg_staff_attendance_calendar_month_guard`, `trg_staff_attendance_holiday_month_guard` | Staff Attendance's own guard (fires first): takes each touched past month's key SHARED and refuses a locked month (`staff_attendance_month_locked`) |
| `staff_calendar_require_read_committed()` | Every calendar guard refuses any isolation level but READ COMMITTED |
| `staff_calendar_local_today(school)`, `staff_calendar_history_start(school)` | The School-local date on the database clock; the School's first active or superseded version date |
| Indexes | `staff_working_week_versions_one_active`, `staff_holidays_one_active` (partial, `status = 'active'`) |
| Privileges | Runtime DELETE and TRUNCATE revoked on both tables (S-16); `staff_working_weekdays` read-only for the runtime role |
| RLS, keys | FORCE RLS on the new table; same-School composite key for `supersedes_id`; RESTRICT to users |

**Lock order.** School FOR SHARE → the month keys SHARED, ascending (past-dated only; `PastCalendarChangeGate`, implemented by Staff Attendance, so Leave never names a Staff Attendance class) → `LeaveLocks::calendar` exclusive → rows. Staff Attendance writers take the month key before the calendar key, so no cycle exists.

**Cutover.** The migration locks both legacy tables, then records one version per School with a complete seven-day pattern, effective from that School's local date when it runs (`reason_code = 'cutover'`, no actor). A School without a complete pattern gets none and stays "not configured". Existing holidays become `active`, recorded at their `created_at`. Nothing is backfilled.

**Rollback (rule 143).** `down()` lifts FORCE in its transaction and refuses once any non-cutover or non-active version, withdrawn holiday or reasoned holiday exists. The first draft compared `reason_code <> 'cutover'`, which is NULL for a week scheduled at runtime, so a rollback would have dropped it; it now uses `IS DISTINCT FROM`, and `StaffCalendarRollbackGuardTest` proves both refusals.

**Retention and audit.** Versions join the existing `leave_configuration` unit (School configuration, kept with the School). Audit: `leave.calendar.week_recorded` (with `supersedes`), `week_withdrawn`, `holiday_added`, `holiday_withdrawn`; ids, dates, portions, closed codes and `mfa: fresh` for corrections. The user-reference catalogue lists the three new user columns (RETAIN).

**Verifier.** `platform:verify-database` gains `staff_calendar_history_invariants`: the triggers present, enabled and defined as shipped; each guard function's whole body pinned (an md5 of its source, comments stripped and whitespace collapsed); every calendar CHECK pinned (md5 of `pg_get_constraintdef`); the helpers' bodies; FORCE RLS; the partial indexes' predicates; no runtime write on the retired table. `runtime_destructive_privileges_restricted` covers the three tables. A new calendar backstop changes a pinned hash on purpose.

**HRX contract v2.** `2027_02_15_090100_admit_hrx_payroll_input_v2` rewrites `payroll_run_hrx_inputs_shape_check` from its live definition to admit `hrx_payroll_input.v1` and `.v2` and nothing else; existing snapshots are untouched. Its `down()` refuses while a v2 snapshot exists.

## 4. Evidence

| Test | Proves |
|---|---|
| `StaffCalendarHistoryTest` (13) | Not configured; a School without history never starts in the past, and a future first version never blocks today's; not recorded before the start, never today's week; each date reads its own version across a boundary, a leap day and a year boundary; a scheduled version applies only from its date and is withdrawable until then; a past date needs the reason and the actor's own fresh proof; same-date supersession; nothing before the start; holiday withdrawal; a locked month refused; a timezone change moves no stored date; suspended School and missing key refused; policy as-of at an assignment boundary, a superseded version and none |
| `StaffCalendarConsumersTest` (3) | Leave refuses an unrecorded day and writes nothing; Staff Attendance refuses evidence on it and labels it in the register and month; Payroll capture `input_incomplete`, version provenance, stable fingerprint, a correction detected |
| `StaffCalendarRawRoleTest` (6) | As `school_os_app`: a School without history cannot backdate its first week or holiday; no DELETE, no write to the retired week; terms frozen, state final, withdrawal only before effect; nothing before the start, no past date without a reason, no runtime cutover; no raw change into a locked month; another School sees and changes nothing |
| `StaffCalendarConcurrencyTest` (3) | Two OS processes, contender observed waiting: a correction in flight finishes before a month lock; one waiting on a lock is refused when it commits; REPEATABLE READ refused, READ COMMITTED the control |
| `LeaveRequestConcurrencyTest` (existing, migrated) | A Leave approval against a past-dated week correction, both orders: one calendar, never a mix |
| `PayrollHrxConcurrencyTest` (+3) | A Payroll capture against a calendar correction, both orders: the second waits and the change is detected. A v1 snapshot is not comparable, never changed, and the difference check records nothing |
| `StaffCalendarUiTest` (6) | The real web and API routes: page props; future vs past holiday, reason and code; capability first (403 before validation); withdrawal not deletion; week scheduling, withdrawal and correction; the pre-save notice to a configurer only, bounded to a year |
| `StaffCalendarVerifierTest` (2) | The verifier passes on the real schema and fails for each of 19 committed breaks, restored afterwards, including logic changes that keep every fragment a needle looks for (a runtime cutover via `true OR`, an early `RETURN NEW`, a weakened past-date rule, a CHECK replaced by `CHECK (true)`) |
| `StaffCalendarRollbackGuardTest` (2) | `down()` refuses with a reason-less scheduled week and with a withdrawn holiday, before any DDL |

**Independent review.** A separate reviewer read the final uncommitted delta against the C21 contract and the project rules: **no HIGH**, one MEDIUM, four LOW, four INFO. All were fixed and then tested, apart from two INFO items: one could not occur, and one is a recorded deviation (§5).

- **M-1 (backfill).** A School without history could backdate its first week indefinitely, and the fixtures did exactly that. Now refused in the service and the database (above); fixtures use the owner-only cutover.
- **L-1 (rollback atomicity).** `down()` now locks `staff_holidays` before its check.
- **L-2 (pre-WO-5 Payroll snapshots).** Contract v2; comparison within one contract version; the Payroll run page and the API say how many snapshots are of an earlier contract.
- **L-3 (verifier needles).** Whole guard bodies and every CHECK are pinned; four logic-change regressions added.
- **L-4 (registry).** The past-dated correction is declared in `ApprovalWorkflowRegistry::DIRECT_ASSURED`.
- **INFO.**
  - Setup readiness now needs a week in force today.
  - A future first version no longer blocks today's.
  - The midnight 500 cannot occur, because the reason is checked before the proof.
  - The missing API withdraw is a recorded deviation (§5).

**Targeted review of the fixes (after publication, §63).** A separate reviewer read the fixed final code of `337631b7` (M-1, L-1 to L-4, the fixture seam, readiness and the error display). It found **no HIGH and no MEDIUM**, and confirmed all five fixes correct:

- no SECURITY DEFINER function writes the calendar tables;
- the fixture seam stays local/testing only, and the verifier fails if its schema exists elsewhere;
- `down()` locks before it checks;
- the CHECK rewrite is exact;
- the registry entry meets its test.

It raised four LOWs, recorded here and not fixed in this checkpoint:

- **C22-N1:** the verifier does not assert that the guard functions stay SECURITY INVOKER with their shipped `proconfig`, and it hashes the function by name rather than through the trigger's `tgfoid`. A later `ALTER FUNCTION … SECURITY DEFINER` would make `current_user` the owner for every caller, allowing runtime cutover rows and backdating, and the check would still pass. Fix: assert `NOT prosecdef` and `proconfig`, and hash via `tgfoid`.
- **C22-N2:** the body hash strips `--` comments before hashing, so deliberately crafted owner DDL hiding logic in a string literal could keep the same hash. Accidental drift is still caught. Fix: hash the raw `prosrc`, collapsing whitespace only.
- **C22-N3:** a School with no history start (no complete pattern at cutover) can still withdraw a past legacy holiday, with a reason and fresh MFA. No answer changes, because those days are NOT_CONFIGURED, but the rule is not uniform with the insert path.
- **C22-N4:** a calculated, unapproved Payroll run holding only v1 snapshots now shows "not compared" instead of prompting a recalculation, which is the remedy for such a run. Pay is unaffected (`pending_hrx_l4`). The CHECK still admits a raw v1 insert, which would never be compared; the only writer always uses the current version.

**INFO from the same review:**

- A future holiday dated before a future first week can be withdrawn only after that week is.
- `down()` and a holiday withdrawal take their locks in opposite orders, so one could abort the other. This applies to local/testing rollback only.
- The Access Center lists `hr.leave.configure` as MFA-assured, though only past-dated changes require MFA.
- A withdrawal form's errors carry over to the next holiday opened.

**Accessibility fix found in the browser.** A past-dated save without a reason returned a `reason_code` error the page never showed; each reason select (and the effective-from date) now shows its error, described by `aria-describedby`.

**Local DDEV (forward only, every change recorded).** The Lycenza Demo School's October 2026 lock and its Staff Attendance history were preserved. The md5 of every row of `staff_attendance_records`, `_corrections`, `_locked_corrections` and `_month_locks` is identical before the migration, after it, after the browser walkthrough and after the final patch (`fb397a90…`, `960a31ea…`, `e28093ad…`, `ab161362…`).

- **Migrations.** `2027_02_15_090000` was forward-applied with `migrate --database=pgsql_admin`. The cutover is one version for the one School with a pattern, effective 2026-11-09 (the School-local date; Mon–Fri full, Sat and Sun off). Every date before it, including the locked October, now reads "Calendar not recorded", as D6-3 decided. `2027_02_15_090100` was forward-applied after the review.
- **Guard patch.** DDEV had run the pre-review draft of the calendar migration, so the two changed guard functions (`staff_working_week_versions_guard`, `staff_holidays_history_guard`) were replaced with their final definitions from the migrated test database (`CREATE OR REPLACE`, no row touched). Nothing was rolled back, reset or unlocked.
- **Verifier.** `platform:verify-database` then passed with the pinned hashes: `failed=0 operator_evidence_required=1`.
- **Rows the walkthrough wrote:**
  - one week version scheduled for 2026-12-09, then withdrawn;
  - a holiday on 2026-11-27, withdrawn before effect;
  - a holiday on 2026-11-09, withdrawn as a correction (`entered_in_error`, fresh code);
  - four `leave.calendar.*` audit events, plus the correction's with `mfa: fresh`.

**Browser walkthrough.** Headless Chromium and axe (the C20 method, ephemeral in the session scratchpad):

| Step | Observed |
|---|---|
| Configuration as the principal | History start 2026-11-09 stated; the cutover version labelled "History start" with no Withdraw |
| Schedule a week for 2026-12-09 (Friday off) | No reason or code asked; "Check what this touches" said, before saving, "from 2026-12-09 to 2027-12-09: nothing pending or recorded is touched"; saved |
| Withdraw it | Withdrawn (database: `withdrawn`, actor and time) |
| A future holiday | Checked, added, withdrawn at once with no reason |
| A holiday today | Added as ordinary; its Withdraw opened the correction form (reason and code). No reason: "Choose why this past date is being corrected." shown and described. Wrong code: "That code is not valid." Reason and fresh code: withdrawn |
| A week from 2026-10-15 | Reason and code fields appear; with both, refused: "Staff attendance for 2026-10 is locked, so its calendar does not change. (LEAVE_CALENDAR_MONTH_LOCKED)" |
| Keyboard | The impact check operates with Enter |
| Register 5 October | Each half "Calendar not recorded"; the recorded halves (absent/present) and the lock still shown |
| Month view October | "2026-10 is locked since 8 Nov 2026"; a "Calendar not recorded" column |
| Register and month view today | Ordinary states |
| Mobile (390 px) | No horizontal overflow |
| A configurer without a factor | The past date explains enrollment instead of a code field |
| Teacher | 403 on the configuration page |
| Payroll runs (August posted, September calculated) | "8 of these were captured under an earlier evidence contract and are not compared with today's evidence"; no false change |

**axe (WCAG 2.0/2.1 A and AA): 0 violations in all 14 scans:**

- the configuration page: initial, with the notice, with the correction form, with a past week, with each error, without a factor, and on mobile;
- the October and today registers;
- the October and November month views;
- both Payroll runs.

A manual screen-reader pass was not performed (C18-A12 stays PENDING).

**Publication.** One candidate, `337631b7`.

- **CI gate:** run 42586811898, green on its first attempt. Four shards of 2,898 = **11,592 cases, 0 failures, 0 errors, 1 skip (ESI-12, the legal deferral), 466,313 assertions**, with the coverage union proven. That is 42 more cases than C20.
- **Publication:** `publication-check` publishable; E16 valid through 2026-11-28. `main` was fast-forwarded to `337631b7`.
- **This record:** the CI figures were added in a docs-only evidence commit on top.

## 5. Deviations from the C21 contract

- **Payroll fingerprint.** §6.7 said the fingerprint includes the calendar version. It does not: each half carries `calendarVersionId` as provenance only, because a correction that changes a half's answer already changes its classification, which is hashed (proved in `StaffCalendarConsumersTest`). What did change is the contract: a half before the history start now classifies differently, so every snapshot taken before WO-5 would read as a changed source. The contract is therefore `hrx_payroll_input.v2`, and a snapshot is compared only with evidence of its own contract version (the first draft's note that leaving the id out avoided false differences was wrong; the independent review found it, L-2).
- **The notice** counts pending leave requests and recorded attendance days. §6.5 also named calculated, unposted Payroll runs. A Payroll source would make Payroll depend on Leave, which `LeaveArchitectureGuardTest` forbids; Payroll's own `checkDifferences` reports a changed capture.
- **Runtime UPDATE** stays table-level. §6.3 said "UPDATE of the status columns only". The guard triggers freeze every other column for every role, including the owner, and the verifier pins them.
- **No API endpoint** withdraws a scheduled week; the web page does. §6.5 named the service operation only.
- **Supersession** is recorded in `leave.calendar.week_recorded` (`supersedes`), not a separate event.

## 6. Limits

- **Before the history start** every date is "not recorded". Whether that satisfies R1-03 is a closure-time reading (C21 §5). Rows in `staff_holidays` dated before a School's cutover are preserved but never read.
- **Who acted** stays an application boundary (rule 102): the database requires a reason and refuses locked months, but cannot see MFA.
- **Isolation:** the guarantees hold at READ COMMITTED; other levels are refused.
- **C18-A12** (manual screen-reader pass) stays PENDING; this checkpoint's pages are not certified for screen readers either.
- **Same-person identity** (open MEDIUM) is unchanged.
