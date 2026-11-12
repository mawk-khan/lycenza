# HR.R1-C32: WO-9 built. A missed, incomplete or failed accrual month is visible (2026-11-12)

**HR.R1 stays OPEN, and this is not the closure audit.** C32 implements WO-9 exactly as D-3 option 3 specifies (`HR-R1-C31-D3-ACCRUAL-DETECTION-DECISION.md` §4). LVA-1 and TRR-2 close for development when this is published.

| | |
|---|---|
| Baseline | `60006318` = `main` = `origin/main`; `8eda4cc7` (C30 code) the last runtime ancestor; clean tree; the historical stash and `integration/e16-r2` preserved |
| Authorized | The owner's dispatch of the C32 work order, 2026-11-12, on the D-3 decision of the same day |
| Not changed | Accrual stays manual (`LeaveAccrualService::execute()`, `hr.leave.manage`). Unchanged: formulas, eligibility, timing, ledger, runs, run items, policies, Payroll, the staff calendar, capabilities, schema. No scheduler, no alert, no retry |

## 1. What was built

**`App\Domain\Leave\Application\Accrual\LeaveAccrualMonthStatusReader`.** A Leave-owned, read-only reader with one closed status per School, leave type, Leave Year and month.

**Evidence:**
- **The runs:** `leave_accrual_runs`, read once per year (or per month).
- **The canonical evaluation:** `LeaveAccrualCalculator::evaluate(lock: false)` over the whole roster by keyset, exactly as `preview()` counts it, at the same batch size.

**Precedence.** The first match wins, and it is pinned by `classify()` and an 11-case matrix:

| # | Evidence | Status |
|---|---|---|
| 1 | The month starts after the School-local today | `not_due` (no evaluation) |
| 2 | Its open run is `processing` | `running` |
| 3 | Its open run is `failed` | `failed` |
| 4 | The evaluation or the run history could not be read | `unknown`, never complete |
| 5 | The evaluation would credit someone | `incomplete` if a completed run exists, else `missed` |
| 6 | Credits exist with no completed run (a contradiction) | `unknown` |
| 7 | Someone's version is not yet postable (arrears) | `not_due` |
| 8 | A completed run exists | `complete` |
| 9 | Otherwise | `nothing_to_credit` |

**Why no false `complete`.** `complete` needs the evaluation itself to find nobody left to credit. A completed run is final (trigger), and the final batch commits its credits and `completed` together. So a run that commits between the two reads gives `unknown` or `incomplete`, never `complete`.

**`blockedBy`.** `year_closed`, `type_inactive` or `type_untracked` says why `execute()` would now refuse a run. The status itself stays what the evidence says.

**Surfaces:**
- **Workspace** (`/app/leave/accruals`, `hr.leave.view`):
  - the selected month's status;
  - **"Check every month of this year"**, a table of each month's status, with a glyph and a word (`StatusBadge`, never colour alone) and an explanation. `unknown` uses the attention family, never the positive one;
  - wording by role: a viewer is told who can run the month. Manage holders keep the existing preview, which lists the employments a month would credit.
- **Console** (`leave:accrual-status [--school=<id>]...`):
  - one School at a time, under `withSchool()`, for each leave type with a monthly policy and each Leave Year that has started and is not closed;
  - prints ids, codes, statuses, reasons and counts, never a name;
  - includes a suspended School and marks it (allowlisted with a reason in `SchoolLifecycleArchitectureGuardTest`);
  - one School's unreadable evidence prints an `unknown` line and never stops the report;
  - writes nothing, and its exit code is always 0. It is not an alert.

## 2. Evidence

| Proof | Test | Result |
|---|---|---|
| A due month never run → `missed` (1 to credit); after the existing manual run → `complete` | `LeaveAccrualMonthStatusTest` | pass |
| **C31's mandatory case:** a completed month gains an employment effective inside it → `incomplete`. The ledger is unchanged by reading; a re-run → `complete`, credited once each | same | pass |
| `not_due`: a month not started; an arrears month not yet postable | same | pass |
| The School-local midnight (Asia/Kolkata) decides it, at 18:29:59 vs 18:30:00 UTC, for both timings | same | pass |
| `nothing_to_credit`, never `missed`, for each non-credit answer: no policy, a part month (joiner), a separation inside the month, an annual policy | same | pass |
| `running` and `failed` before anything else; resuming a failed run through the existing workflow → `complete` | same | pass |
| An evaluation that throws → `unknown` (reason `evaluation_failed`, counts null), never `complete` or `nothing_to_credit` | same | pass |
| The precedence matrix, 11 cases | same | pass |
| `blockedBy` on an inactive type, with the evidence status kept | same | pass |
| A viewer reads it; no `hr.leave.view` → refused (403 over HTTP); another School's ids → nothing read; a month outside the year → refused | same | pass |
| **Read-only:**<br>• no statement it runs is a write (`CommittedEffectDetector::isWrite`);<br>• no `FOR UPDATE` / `FOR NO KEY UPDATE`;<br>• runs, run items, ledger, assignments, employments and audit counts unchanged;<br>• the same evidence gives the same answer. | same | pass |
| Workspace props, the year check and the error states, over HTTP | same | pass |
| **Two real sessions (a), first run:** a real manual run (`leave-op.php accrue`) held open before COMMIT in another process. Its uncommitted run and credits are invisible: the month reads `missed`, and the reader returns under a 3 s `lock_timeout`. After commit → `complete` | `LeaveAccrualMonthStatusConcurrencyTest` | pass |
| **Two real sessions (b), committed run:** a committed `processing` run whose batch is held open in another process reads `running` without waiting. After commit → `complete`, credited once each | same | pass |
| **Console report:**<br>• run on a day in the NEXT Leave Year: the previous, unclosed year is still reported (review MEDIUM-1);<br>• a suspended School is marked;<br>• `complete` and `missed` lines appear;<br>• no employee name in the output;<br>• no table changed. | same | pass |

**Running, truthfully.** A run's work is only visible once committed. A run opened by the workspace commits its `processing` row first, then each batch, so an interrupted or in-flight run reads `running`. A run whose very first transaction has not committed is invisible to every other session, so its month reads `missed` until that commit. That is the truth about committed evidence, and the reader never claims to see an uncommitted row.

**Suites:**
- Leave 200 (before the review fixes) and the final-state rerun in §5;
- Payroll 542, Staff Attendance 76;
- guards 115: School lifecycle, HR writer boundary, Leave architecture, authorization coverage, design tokens, Payroll register, HR School guard.

**Static checks:** Pint, Larastan, vue-tsc, ESLint and Prettier are clean.

**Performance (DDEV, 300 employments, one leave type):**

| Read | Time | Queries |
|---|---|---|
| `preview()` of one month | 185 ms | 44 |
| One month's status | 161 ms | 46 |
| The whole year, seven due months evaluated | 934 ms | 286 |

Cost is linear in the roster and in the months due; there is no N+1 per employment. The 1,000-employment figure is extrapolated, not measured.

**Not performed: browser walkthrough and axe.** The DDEV Lycenza Demo School has no Leave types, years or policies. A walkthrough would have needed new Leave configuration and ledger entries in the development database, which the D-3 and C32 briefs say not to create. The workspace was verified over HTTP through Inertia props instead. No manual screen-reader review was performed.

## 3. Reviews

1. **Independent final-state review. No HIGH.** Core correctness confirmed: no false `complete` or `nothing_to_credit`, counting identical to `preview()`, the `not_due` rule right for both timings, no write and no blocking lock, and the concurrency tests valid.
   - **MEDIUM-1, fixed:** the console report read only the Leave Year containing today, so an arrears final month, and any missed month of the previous year, disappeared at rollover. It now covers every started Leave Year that is not closed.
   - **LOWs, all fixed:**
     - LOW-1: a runs-query failure escaped (now `unknown`, per School in the console).
     - LOW-2: no signal when a run would be refused (`blockedBy`).
     - LOW-3: the year check also re-ran the selected month's preview, and runs were read per month (now once per year).
     - LOW-4: wording for viewers and raw timestamps.
     - LOW-5: `--school` input validation.
     - LOW-6: the contradiction check now precedes `not_due`.
     - LOW-7: the tests above.
2. **Independent delta review of those fixes:** §5.

## 4. Dispositions

| Finding | Status |
|---|---|
| LVA-1 | **CLOSED for development on publication.** Its acceptance: accrual stays manual with no false heartbeat claim (C10); "nothing detects a month that was never run" → `missed`, with the workspace and the console report; the grid's `null` is no longer the only signal |
| TRR-2 | **CLOSED for development on publication.** The documentation now matches the code: there is still no heartbeat and none is claimed. Detection is the read-only status, and the runbook (`HR-OPERATIONS.md` §4) points to it |
| D-3 | Decided (C31) and built |
| C32-N1 (INFO, HR) | The browser walkthrough and axe check of the status section were not run, because the DDEV demo has no Leave configuration. Run them when synthetic Leave data exists |
| C32-N2 (INFO, HR) | The whole-year check costs one canonical evaluation per due month (about 0.9 s for 300 employments); measured at 300, not 1,000 |
| C32-N3 (INFO, HR) | Untested paths: unreadable runs, a School's unreadable evidence in the report, and `blockedBy` year closed / untracked type |
| C32-N4 (INFO, HR) | The operator report evaluates every started, unclosed Leave Year; a School that never closes years costs more each year |

## 5. Final-state delta review and publication

**Independent delta review of the fixes. No HIGH or MEDIUM.**
- **Confirmed:**
  - the read-order argument (runs first, evaluation second, no false `complete` in any interleaving);
  - timestamps (UTC `timestamp` columns, ISO in the props);
  - `blockedBy` mirroring `execute()`'s refusals;
  - the batch size, the Vue wording and the report's School scope and privacy.
- **LOWs, fixed:**
  - LOW-1: the month path now also turns a runs failure into `unknown`.
  - LOW-2: a year can be closed the day after it ends, the day its last arrears month becomes due. The report now also lists the most recently ended closed year's months that need attention, marked `year_closed`.
  - LOW-3: the command's description.
  - LOW-4: a dedicated `AccrualMonthNotInYearException`.
  - INFO: separate log events for an unreadable evaluation and unreadable runs; a School's failure line names the leave type and year it was on.
- **Recorded, not tested:** `runs_unreadable`, the per-School `unknown` line, and `blockedBy` = `year_closed`/`type_untracked`. Each is a direct code path; the closed-year case needs a full year-close fixture (C32-N3, INFO).
- **Recorded:** the report's cost grows with the unclosed years of a School that never closes one (C32-N4, INFO).
- **Malformed `--school` id:** now tested; the report refuses it.

Final local state: the WO-9 tests and the School-lifecycle guard pass (36); Leave 203; Pint and Larastan clean.

Publication evidence is recorded in the evidence commit.
