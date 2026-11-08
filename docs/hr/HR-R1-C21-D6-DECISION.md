# HR.R1-C21: D-6 calendar history decided, WO-5 contract (2026-11-08)

**HR.R1 stays OPEN, and nothing is built here.** This records the HR owner's D-6 decision and the dependent decisions taken with it, and specifies WO-5 for the next checkpoint. Baseline `d7370a04`.

## 1. D-6 as the docket states it (`HR-R1-C12-DECISION-DOCKET.md` §8)

| Item | Docket text |
|---|---|
| Question | "Accept a residual, or build calendar history." |
| Current implementation | "The weekly pattern is overwritten and holidays are hard-deleted. Approved leave and Payroll HRX snapshots are sound. Past holiday states, month views and policy as-of are not." |
| Problem | "R1-03 has no as-of answer, S-16 stays open, and R1-08's 'reproduce' fails for past runs once a calendar changes." |
| Options | "Residual; recorded-time history; effective-date history; both. Separately, what past-date pages show and whether a change may be retroactive." |
| Recommendation | "Acknowledge the missing history now, keep the reliable snapshots, and prepare an effective-dated contract as its own slice before real data." |
| Risks | "History starts on the day a School goes live. What is lost before that cannot be recovered, and it must never be invented." |
| Authority | HR owner |
| Acceptance | "Past dates keep their answer after a change; missing history reads 'not recorded', never today's value; runtime DELETE revoked on `staff_holidays`." |
| Related | LVA-3, S-16, R1-03, R1-08; capability rows D14-08, D14-09; WO-5 |

## 2. Decisions (HR owner, 2026-11-08, in session)

| # | Question | Decision | Authority |
|---|---|---|---|
| D6-1 | D-6 option | **Effective-date history.** An append-only working-week version with an effective-from date, holidays with a lifecycle (never deleted), and one as-of reader. Each change also records who made it and when, but there is no recorded-time query (the "both" option was not chosen) | HR owner |
| D6-2 | May a change apply to a past date? | **Only into open months.** A change takes effect from a chosen date. A past date is allowed only as an audited correction with a closed reason, never into a month whose Staff Attendance is locked. Approved leave and Payroll snapshots never change; a difference is reported, never recalculated | HR owner |
| D6-3 | Where history starts | **The day WO-5 ships.** Today's calendar becomes each School's first version from that day. Earlier dates read "not recorded"; a leave request or attendance record for one is refused with a clear message. No real School is live, so no real history is lost | HR owner |
| D6-4 | D14-08 publication | **Effective date, no drafts.** A change is saved with its effective-from date (now or scheduled), can be withdrawn until it takes effect, and shows a notice of what it touches. Nothing is recalculated automatically. D14-08 would become ALT, not IMP | HR owner |
| D6-5 | Assurance for a past-dated correction | **A closed reason and a fresh MFA code** (rule 95), with `hr.leave.configure` re-read under the calendar lock. Today- and future-dated changes need the capability only, as now | Security with HR (owner, in session), as S6-3 was decided in C17 |
| D6-6 | Leave policy as-of | **In WO-5:** one read-only reader over the existing immutable versions and dated assignments; no schema or formula change | HR owner |
| D6-7 | WO-5 authorization | **Authorized as the next checkpoint.** It does not auto-start | HR owner |
| CM-1 | Staff Attendance current-month locking (carried from C19 §8, C20 §6) | **Option A, ended months only.** It matches the code: no change | HR owner |

**Not decided here, and not inferred:** any Legal or DPO matter (E21-D9 stays unratified; no retention period), D-2, D-3, D-4, D-7, D-9, D-10, R1-01, R1-09, §1 and §4, and the closure baselines. No historical import is authorized (§6.3).

## 3. Historical evidence inventory (Deliverable B)

| Fact | Canonical owner (table, writer) | Historical evidence today | As-of behaviour today | Gap | WO-5 action |
|---|---|---|---|---|---|
| Staff working week | Leave: `staff_working_weekdays`, `StaffCalendarService::setWeeklyPattern` | **UNKNOWN** for earlier patterns: one row per weekday, overwritten (`updateOrCreate`). The audit ledger keeps before/after, but audit metadata is not product data (rules 103, 119) | None: every reader uses today's pattern | Past patterns lost | Append-only versions with effective dates; history from the ship date (**PROSPECTIVELY VERSIONED**) |
| Staff holidays | Leave: `staff_holidays`, `addHoliday`/`removeHoliday` | Present rows are **VERIFIED HISTORICAL RECORDS**. A removed holiday is gone (hard delete, S-16); only an audit event remains | A removed past holiday silently stops applying | Removals unrecoverable | A lifecycle (active, withdrawn) with no runtime DELETE; history from the ship date |
| Leave policy | Leave: `leave_policies` (immutable versions), `leave_policy_assignments` (dated) | **VERIFIED DERIVATION** | No public as-of reader | A reader | `LeavePolicyAsOf` (D6-6), read-only |
| Approved leave days | Leave: request day evidence, frozen at submission (ADR 0065 §22.6) | **VERIFIED HISTORICAL RECORD** | Frozen | None | Unchanged; a later calendar difference is reported only |
| Payroll HRX evidence | Payroll: `payroll_run_hrx_inputs`, fingerprinted at capture | **VERIFIED HISTORICAL RECORD** | Frozen; later differences detected | None | Unchanged; the capture reads the as-of calendar, and a not-recorded day makes it `input_incomplete` |
| Staff Attendance records | Staff Attendance, month-locked (rule 147) | **VERIFIED HISTORICAL RECORD** | Records frozen; the derived working or off status of a day uses today's calendar | Derived history drifts | The register and month view read the as-of calendar; pre-history days show "Calendar not recorded" |
| School timezone | Organization: `schools.timezone` (one mutable column) | **UNKNOWN** history | Only "today" depends on it; stored calendar facts are School-local dates | None for stored dates | Out of WO-5 scope; recorded as a limit |

## 4. Semantics

- **A calendar date** is a School-local date (`date` columns, no time). It is never converted, so a later timezone change cannot move a stored fact. Only "today" (and "has this date passed") uses `SchoolTimezone::today()`.
- **Effective date:** the date from which a version or holiday state applies. **Recorded time:** the database clock when it was stored (`recorded_at`, never client-supplied), with the actor.
- **The answer for a date `d`:** the active week version with the greatest `effective_from ≤ d`, plus the active holiday on `d`. Before the School's first version, the answer is **not recorded**.
- **Ordinary changes are prospective** (today or later), so past dates keep their answer. **A past-dated correction** (D6-2) deliberately changes past answers. It needs a reason and fresh MFA, is refused for any locked month it touches, and its recorded time and actor stay on the row.
- **Conflicts are never resolved by "latest timestamp wins":** at most one active week version per effective date (database-unique), and at most one active holiday per date.

## 5. D14-08, D14-09 and R1-03 (Deliverable C)

| Row | Reference requirement | Today | After WO-5, on evidence | Tests that decide it |
|---|---|---|---|---|
| D14-08 Effective publication | "draft → published, with recalculation notice" | MISS: changes are immediate | **ALT** (D6-4): effective-dated and schedulable, withdrawable until effective, with a notice and no recalculation | Future version not applied before its date; withdrawal; notice content; nothing recalculated |
| D14-09 Modifications | "versioned change, never delete" | PART: remove + add, hard delete (S-16) | **IMP**: versions and holiday lifecycle; runtime DELETE revoked | Raw-role DELETE refused; past answers stable after a prospective change; correction provenance |
| R1-03 (calendar, policy) | "calendar … policy … answer an as-of-date query" | Open | Calendar and policy answer from each School's history start. Earlier dates truthfully answer "not recorded". Whether that satisfies R1-03 is a closure-time reading; with no live School, nothing real precedes it | As-of tests per concept, boundary dates |

The rows stay ACTIVE until WO-5 ships and is verified. A decision alone changes no status.

**S-16** (gate register: "`staff_holidays` remains") is an implementation gate: it closes when runtime DELETE on `staff_holidays` is revoked and the lifecycle ships. **LVA-3** is decided, pending WO-5. **R1-08's** "reproduce" for past runs depends on the as-of calendar, again from the history start.

## 6. WO-5 implementation contract (Deliverable D)

### 6.1 Scope and ownership

- **Owner:** Leave, which owns the staff calendar (`StaffCalendarService`, `LeaveLocks::calendar`). There is no second calendar master. Staff Attendance and Payroll read through the new reader; the WO-5 docket row lists them as consumers.
- **In scope:** working-week versions, the holiday lifecycle, the as-of reader, `LeavePolicyAsOf`, the consumer switch (Leave counting, the Staff Attendance writer check, register and month view, Payroll capture), the configuration UI, and the "not recorded" presentation.
- **Out of scope:** WorkSchedule, shifts, clock times, Campus or department calendars (D14-02/03/04 stay DEF), optional holidays (D14-07 DEF), a recorded-time query, historical import, School timezone history, and any change to Leave or Payroll formulas.

### 6.2 Data model (design; names follow existing conventions)

**`staff_working_week_versions`** (new):

- **Columns:**
  - `id`, `school_id`;
  - `effective_from date`;
  - seven `weekday_N` portions, each `full | first_half | off`, CHECKed;
  - `status`: `active`, `withdrawn` (a future version withdrawn before its date) or `superseded` (replaced by a past-dated correction for the same date);
  - `supersedes_id`, a same-School composite key;
  - `reason_code` (closed; required for a past-dated correction);
  - `recorded_by_user_id` (RESTRICT);
  - `recorded_at` (database clock);
  - status-change actor and time.
- **Keys:** unique `(school_id, effective_from) WHERE status = 'active'`.

**`staff_holidays`** (existing) gains a lifecycle:

- **Columns:** `status` (`active`, `withdrawn`), withdrawn by/at, a closed withdrawal reason, and `recorded_at`.
- **Keys:** the unique key becomes `(school_id, holiday_on) WHERE status = 'active'`.
- **Runtime role:** DELETE revoked (S-16).

**`staff_working_weekdays`** stops being written. It is kept read-only until a later forward migration retires it (ADR 0066 §15.1: no published migration is rewritten).

### 6.3 Migration and cutover

1. **Schema:** forced RLS (`TenantRls::enable`), same-School composite keys, the triggers below, runtime privileges (INSERT, plus UPDATE of the status columns only; no DELETE), and the PAY-15 rollback guard. A `down()` refuses once any version exists beyond the cutover rows.
2. **First version:** for each School with a complete seven-day pattern, one version from that School's local date on the migration day, with `reason_code = 'cutover'`, recorded by the migration. A School without a pattern gets none, and stays "not configured" as today.
3. **Existing holiday rows** become `active` with `recorded_at` = their `created_at`. Rows dated before the cutover stay in the table as preserved records, but the reader answers "not recorded" for every date before the School's first version (D6-3).
4. **No backfill.** Today's calendar is never copied to earlier dates, and no import is authorized. A future verified import of older calendars would need its own owner decision, with provenance, review and a separate evidence class.

### 6.4 Database integrity (triggers, runtime-role backstops)

- **Terms immutable:** `effective_from`, the weekday portions, the holiday date and portion, the actors and `recorded_at`.
- **Status changes:**
  - `active → withdrawn` only while `effective_from` (or `holiday_on`) is after the School's local date on the database clock;
  - `active → superseded` only together with the insert of its replacement.
- **Past-dated change** (a version or holiday dated before the School's local today, or the withdrawal of a past holiday):
  - requires a non-cutover `reason_code`;
  - takes each affected month's Staff Attendance key shared, and is refused if any of those months is locked (`staff_attendance_month_is_locked`).
  - The database cannot see MFA; that stays an application boundary (rule 102), as for WO-6.
- **No version before the history start** (`effective_from ≥` the School's first version date), and only the migration writes `cutover`.
- **Isolation:** READ COMMITTED only, as rule 147's guards.
- **Verifier:** `platform:verify-database` pins all of this (C20 pattern).

### 6.5 Services and locks

- **Writer:** `StaffCalendarService` only.
  - `recordWeek(school, effectiveFrom, pattern, reason?)`.
  - `withdrawWeek(versionId)`: future versions only.
  - `addHoliday(date, portion, name, reason?)`.
  - `withdrawHoliday(id, reason?)`.
  - Past-dated forms need a reason and a `FreshMfaProof` (D6-5), and re-read `hr.leave.configure` with `ApprovalDecisionAuthority::requireCurrent` under the calendar lock.
- **Lock order:**
  - **Calendar change:** School `FOR SHARE` → Staff Attendance month keys shared, in ascending order (past-dated only) → `LeaveLocks::calendar` exclusive → rows.
  - **Staff Attendance writers:** School → month key → employment → Leave locks → calendar shared, as today. A calendar change takes no employment or `hrx.staff_employment` lock.
  - **Leave approval and Payroll capture:** take the calendar shared after their own locks, as today.
  - The month key always comes before the calendar key, so no cycle exists.
- **Notice (D14-08):** before saving, the page shows what the change touches in its range: pending leave requests, recorded attendance days, and calculated, unposted Payroll runs. Counts only, nothing recalculated.

### 6.6 Readers

- **`StaffCalendarHistory::day(school, date)`** answers:
  - `status`: `RECORDED`, `NOT_RECORDED` or `NOT_CONFIGURED`;
  - the working portion;
  - the holiday (id, name, portion) or none;
  - `versionId`.
- **`range(school, from, to)`:** one query for the versions and one for the holidays (bounded).
- **`calculator(school, from, to)`:** the same `WorkingDayCalculator` formula. It refuses `LEAVE_CALENDAR_NOT_RECORDED` if any day in range is not recorded.
- **`LeavePolicyAsOf::forEmployment(school, employment, leaveType, date)`:** the assigned immutable version or none; read-only, `hr.leave.view`.
- **No cache:** the readers are request-scoped, with no cross-request cache to invalidate.
- **Campus:** none (a School calendar; D14-02 DEF).

### 6.7 Consumers

| Consumer | Change |
|---|---|
| Leave submission (`LeaveRequestService`) | Counts with the as-of calendar. A range touching a not-recorded day is refused (`LEAVE_CALENDAR_NOT_RECORDED`) |
| Staff Attendance writer check | As-of calendar. A not-recorded day refuses new evidence (fails closed) |
| Register and month view | As-of calendar; pre-history days read "Calendar not recorded", never "working" or "off" |
| Payroll HRX capture | As-of calendar. A not-recorded day makes the evidence `input_incomplete` with a reason; the fingerprint includes the calendar version, so a later correction is detected. No Payroll formula, approval, posting or reversal change |

### 6.8 Audit and retention

- **Audit:** events for version recorded, version withdrawn, superseded, holiday added and holiday withdrawn. Ids, dates, portions and closed codes only (holiday names are institutional).
- **Retention:** versions and holidays are School configuration (the existing `leave_configuration` unit, kept with the School). No new period.

### 6.9 UI and accessibility

- **Leave configuration:** the week editor gains an effective-from date (default today), the version list with status, and Withdraw for future versions. Holidays get Withdraw instead of Delete. A past date reveals the reason and the MFA field, and the notice is shown before saving.
- **Register and month view:** a "Calendar not recorded" state.
- **Standards:** rule 136 tokens; labelled date inputs and status words, never colour alone; axe on every changed page; the manual screen-reader pass as for C18-A12.

### 6.10 Tests (WO-5 acceptance)

- **Functional:** current calendar; a future version applies only from its date; withdraw before effect; withdrawal refused once effective; holiday lifecycle; past-dated correction (reason, fresh MFA) changes the past answer, with provenance; refused into a locked month; not recorded before the history start; the notice; no recalculation.
- **Temporal:** the day before, on and after each effective date; first and last day of a month and year; a leap day; School-local midnight (`SchoolTimezone::today()`, rule 141); a timezone change leaves stored dates' answers unchanged.
- **Consumers:**
  - Leave counting with the as-of calendar, and pre-history refusal;
  - approved leave unchanged after a correction;
  - Staff Attendance locked months untouched, with a correction into them refused;
  - Payroll capture `input_incomplete` before the history start, a correction detected, and the snapshot never changed.
- **Policy as-of:** an assignment boundary, a superseded version, and none.
- **Database (runtime role):**
  - no DELETE or term edit;
  - withdrawal refused once effective;
  - a past-dated change into a locked month refused;
  - no version before the history start, and no `cutover` written at runtime;
  - cross-School refused and RLS empty;
  - the verifier with negative proofs.
- **Concurrency (two processes):** a calendar correction against a month lock, both orders; against a Leave approval; against a Payroll capture.
- **Rollback proof:** schema identical after rollback and re-apply.
- **Review:** an independent final-state review, then the full exact-SHA regression.

## 7. Carry-forward (Deliverable E)

| Item | Owner | State | Readiness consequence |
|---|---|---|---|
| C18-A12 manual screen-reader pass | HR, accessibility reviewer | PENDING | WO-6 not certified for screen readers |
| C20-N1 audit-log scroll region | Compliance UI | Open | A keyboard user cannot scroll that table |
| C20-N2 month summary, Payroll capture timings | HR, Payroll | Open, no target | None until a target is set (D-7) |
| C19-N1, C19-N2 | Communications, Platform | Open | As recorded |
| Current-month locking | HR owner | **Decided CM-1: Option A** | None; matches the code |
| S-16, LVA-3, D14-08, D14-09, R1-03 | Leave (WO-5) | Decided, not built | HR.R1 §2 stays open until WO-5 ships |
| D-2, D-3, D-4, D-7, D-9, D-10, R1-01, R1-09, §1, §4, baselines | Their owners | Pending | As recorded |
| Same-person identity | Identity, Security | Open MEDIUM | Real-Payroll blocker |
| E21-D9, E45/ESI-12, E47, HRX-L1–L4, S-4, S-7, X-1 | Legal, Security | Unchanged | Production NO-GO |

The local DDEV October 2026 lock (C20) is untouched. WO-5's tests use isolated synthetic fixtures only.
