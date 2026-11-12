# HR.R1-C31: D-3 decided. Accrual stays manual; a missed month becomes detectable (WO-9) (2026-11-12)

**Decision only. Nothing is built here.** HR.R1 stays OPEN. LVA-1 and TRR-2 stay a MAJOR blocker under C27-C until WO-9 is built and published.

| | |
|---|---|
| Baseline | `af650d2d` = `main` = `origin/main`; `8eda4cc7` (C30 code) an ancestor; clean tree; the historical stash and `integration/e16-r2` preserved; nothing published since C30 |
| Last full suite | `8eda4cc7`: 11,764 cases, 468,257 assertions, 0 failures, 1 skip (ESI-12); counter 0/5. This checkpoint changes documentation only and runs no new runtime regression |
| Authority | HR owner, 2026-11-12 (D-3 is the HR owner's: `HR-R1-C12-DECISION-DOCKET.md` §6) |
| Not changed | Accrual formulas, eligibility, timing, the ledger and its constraints, runs and run items, Payroll, the Staff calendar, every capability, the schema |

## 1. The question and the findings, verbatim

**D-3** (`HR-R1-C12-DECISION-DOCKET.md` §6): "Keep accrual manual, or schedule it."
- **Options:** "Manual; scheduled with a system actor; manual plus 'month not run' detection."
- **Recommendation:** "Manual now, detection as a separately specified option. A system actor is a new authority concept."
- **Acceptance:** "No scheduler exists. If detection is chosen, it posts nothing and tests both a missed and a completed month."

**LVA-1** (P2, CONTRADICTED): "`HR-16-READINESS.md` §5 'Monitoring' says scheduled-task heartbeats and alerts exist for accrual. `HR-OPERATIONS.md` §1.2 tells operators to check heartbeats for 'the leave accrual and eligibility runs'. Neither exists. Accrual is a manual web or API action by a User: 20 batches per web request, `max_batches` on the API, `started_by_user_id` required. Nothing detects a month that was never run; the grid only shows `null`."
- **Disposition then:** "Correct both docs now (doc-only). Owner decision whether accrual should be scheduled."

**TRR-2** (P2, CONTRADICTED): "the docs claim scheduled heartbeats and alerts exist for leave accrual and eligibility runs ... There is none: the only HR schedule entry is `workforce-tasks-generate`; accrual is staff-triggered; no Leave code records a heartbeat."
- **Disposition then:** "correct both documents, or decide to add monitoring."

**Reconciled:**
- **Same root issue.** Both report one gap: a false monitoring claim, plus no way to see a month that was never run.
- **TRR-2** adds the eligibility run to the documentation claim.
- **LVA-1** names the operational consequence: a silent missed month.

**Classification.** C27-C: "MAJOR, blocks until D-3 is decided and any consequence published" (`HR-R1-C27-CLOSURE-INTERPRETATIONS.md`).

## 2. What exists (verified on `af650d2d`)

| Fact | Evidence | Status |
|---|---|---|
| The false claims | `HR-16-READINESS.md` §5 now reads "Leave accrual and eligibility are manual and have none (corrected by HR.R1-C10, LVA-1; the choice is D-3)". `HR-OPERATIONS.md` §1 says they "are not scheduled and have no heartbeat ... so a missed month is silent". No other document claims accrual monitoring | **Corrected (C10)**. Still true |
| Schedule | `routes/console.php` has no Leave accrual or eligibility entry. The HR entry is `workforce-tasks:generate` | **Absent** (manual by design, ADR 0065 §29) |
| Trigger | Web `POST /app/leave/accrual-runs` and API `POST …/leave/accrual-runs`, both `hr.leave.manage`, through `LeaveAccrualService::execute()`. A User is required (`started_by_user_id`) | Implemented |
| Run record | `leave_accrual_runs`: School, leave type, year, `period_start` (the 1st), status `processing`, `completed` or `failed`, `failure_code`, cursor, batch count, `started_at`, `completed_at`. One open run per month (`leave_accrual_runs_one_open`); a failed run resumes from its cursor | Implemented |
| Per-employment outcome | `leave_accrual_run_items`: one per examined employment, with eight closed, disjoint outcomes (`credited`, `already_credited`, `not_due`, `partial_period`, `no_policy`, `assignment_not_whole_period`, `not_monthly_policy`, `annual_allocation_exists`). Examined = the sum of the outcomes | Implemented |
| Ledger | `leave_ledger_entries` of kind `accrual`, with `accrual_period` and `accrual_run_id`. One per account and month, enforced by trigger (rule 134) | Implemented. The single source of truth |
| Canonical evaluation without writing | `LeaveAccrualService::preview()`. It is read-only and evaluates every employment through `LeaveAccrualCalculator` for a given month, returning counts and rows (`hr.leave.manage`) | Implemented |
| Grid | `LeaveStatementReader::grid()` shows each employment's accrued units per month, or `null`. `null` cannot tell "never run" from "not eligible", "not due" or "failed" | Implemented. **Not detection** |
| Metrics, heartbeat, alert, dashboard for accrual | None | **Absent** |
| Detection of a never-run, incomplete, failed or stuck month | None | **Absent**: what WO-9 adds |

## 3. The decision (HR owner, 2026-11-12)

| | Decided |
|---|---|
| **Option** | **Docket option 3: manual plus "month not run" detection.** Accrual stays a manual action of an `hr.leave.manage` holder. Nothing is scheduled, and there is no system actor |
| **Completeness** | **Runs plus a canonical recheck.** Detection reads the run records, and re-asks the existing read-only evaluation (the `preview()` path, `LeaveAccrualCalculator`) whether the month would still credit anyone. No second formula, and no ledger write |
| **Surface** | **A status per leave type and month in the existing accrual workspace**, for `hr.leave.view` or `hr.leave.manage` holders of that School, **plus a read-only artisan report** for operators. **No email, SMS or third-party alert** |
| **Ownership and cadence** | **School HR (`hr.leave.manage`)** reviews the status after each School month becomes due, investigates, and re-runs through the existing workspace. No fixed SLA |
| **This checkpoint** | Publishes the decision and the WO-9 work order. **WO-9 is not started**, and needs its own go-ahead |

**Rejected:**
- **Option 1, manual only:** a silent missed month would stay an accepted risk.
- **Option 2, scheduled with a system actor:** a new authority concept, plus Platform scheduling and Security work.

**Also not chosen:**
- run records only, which is blind to an employment added after a month was run;
- workspace only;
- an email alert.

**Approvals.** No other authority is needed for this decision. No Legal, DPO or Security approval is claimed. WO-9 adds no personal-data destination, so no Platform notification or privacy review is triggered by these choices.

## 4. WO-9 work order (bounded, not started)

**Goal.** For a School, a leave type with a monthly-accrual policy, and a Leave Year, answer for each month exactly one closed status, from canonical evidence only.

| Status | Condition |
|---|---|
| `not_due` | The month's postable date (`LeaveAccrualTiming`, from the School-local today) has not arrived for any assigned monthly version |
| `running` | A run for the month is `processing`. Its `started_at` is shown, and no stall threshold is invented |
| `failed` | The month's latest run is `failed`. Its `failure_code` is shown; it resumes through the existing workspace |
| `missed` | Due, no `completed` run, and the canonical recheck would credit at least one employment |
| `incomplete` | A `completed` run exists, and the canonical recheck would still credit at least one employment (for example, one added or backdated after the run) |
| `complete` | A `completed` run exists, and the recheck would credit nobody |
| `nothing_to_credit` | Due, no completed run, and the recheck would credit nobody (no monthly policy assigned, everyone ineligible) |
| `unknown` | The recheck could not be evaluated (for example, an unreadable input). **Never shown as complete** |

**Rules:**
- **Read-only.** Detection never posts, adjusts, allocates, re-runs, approves or locks anything. It never claims a run happened; it reads completed runs only. Remediation stays the existing manual run (`LeaveAccrualService::execute()`), which is idempotent per account and month, with no automatic retry.
- **One source of truth.** Facts come from `leave_accrual_runs`, `leave_ledger_entries` and the existing calculator through a Leave-owned reader, for example `LeaveAccrualCompletenessReader`. Never a second formula, a second ledger, a stored status or a status table. **No migration**, so no new retention unit: run and ledger retention are unchanged.
- **Authority.** Whole-School `hr.leave.view` or `hr.leave.manage`, re-checked by the reader, in the School's `TenantContext`. The private 404 applies to another School. No Campus adoption, since accrual is whole-School. No new capability.
- **Privacy.** The status and the counts per outcome only. The workspace may list the employments a recheck would credit, using the existing workspace's directory fields for `hr.leave.manage` holders. The console report prints School id, leave type code, year, month, status and counts, never names (rule 63).
- **Console report.** It walks Schools one at a time under `withSchool()`, reads only, and writes no audit. As a School-walking command it states its suspended-School behaviour (rule 86): a read-only report includes suspended Schools and marks them.
- **Rule 86.** Detection is not a business effect, so it takes no `SchoolOperationalGuard`. Re-runs keep theirs.
- **Time.** School-local today (`SchoolTimezone::today()`), the version's timing and the Leave Year bounds. Accrual does not read the staff calendar, so D-6 history is not an input.
- **Bounded cost.** One query for the runs per type and year. The recheck pages the roster by keyset, as `preview()` does, one month at a time on request. It is never a polling loop or a scheduled scan.
- **Performance budget.** Recorded at implementation against `preview()`'s own cost at 1,000 employments.

**Tests WO-9 must have:**

| Case | Expected |
|---|---|
| A due month never run, with an eligible employment | `missed` |
| The same month after a completed run | `complete` |
| A completed run, then an employment backdated into the month | `incomplete`; `complete` after a re-run, with no double credit |
| A month not yet postable (both timings, at the School-local midnight boundary) | `not_due` |
| A failed run | `failed`; `complete` after resuming |
| A run in progress (held open by a real second session, deterministic barrier) | `running`; the reader takes no lock that blocks the run |
| No monthly policy; all employments ineligible (each `LeaveAccrualOutcome`) | `nothing_to_credit`, never `missed` |
| A Leave Year boundary; a policy version boundary inside the year; separation and rehire within the year | As the calculator answers |
| The recheck raises | `unknown`, never `complete` |
| Another School's ids | Private 404 |
| No `hr.leave.view` | 403 |
| No School context | Refused |
| A viewer without `hr.leave.manage` | Counts only, no employee list |
| Ledger row counts and balances before and after any read | Unchanged |
| The runtime role on the reader's queries | Reads only |
| The console report | Per School, no names, a suspended School marked |

**Plus the standard regression:**
- Leave (accrual, policy as-of, ledger), Payroll HRX source and Staff Attendance non-regression;
- architecture guards (rule 151 writer register, HR family);
- `platform:verify-database`;
- independent review;
- full exact-SHA CI and protected-main publication.

**Done means:**
- LVA-1 and TRR-2 close for development when WO-9 is published with these tests.
- `HR-OPERATIONS.md` §4 then tells operators to check the status after each month becomes due.
- Operational acceptance is a synthetic DDEV walk-through: miss a month, see `missed`, run it, see `complete`.

## 5. Effects

| Item | Effect |
|---|---|
| D-3 | **DECIDED 2026-11-12** (option 3) |
| LVA-1, TRR-2 | Decided; **still MAJOR under C27-C until WO-9 is published** ("any consequence published") |
| HR.R1 §4 | Not eligible. Blocking: LVA-1/TRR-2 (WO-9) and the person-level Identity MEDIUM |
| C14-N2, C14-N5 | Closed for development (C30, C29). Unchanged |
| Seven P3 (HRC-4, HRC-8, LVA-4, XRP-3, XRP-4, TRR-6, TRR-8) | Unchanged. None is closed by D-3. LVA-4 belongs to D-4 (Leave assurance), not to this decision |
| C30-N1, C30-N2, C29-N1/N2, C28-N1/N3/N4 | Unchanged. WO-9 is read-only, uses no idempotency key and no long-running write, so C30-N2 does not apply |
| R1-02, D-2, R1-01, C27-A to C27-D, D-1, D-6 | Unchanged |
| Legal, DPO, Security gates | Unchanged. No new processing purpose or destination |
| Capability map (361 rows), 61 audit identifiers | Unchanged |
| Five closure baselines | Unselected |
| Accessibility | WO-9's workspace status needs its own automated and manual acceptance. C18-A12, WO-5 and C20-N1 are unchanged |
| Local UAT | Eligible. Staging paused; production **NO-GO** |

**Re-review trigger.** Any proposal to schedule accrual, to add alerts, or to make detection write anything.
