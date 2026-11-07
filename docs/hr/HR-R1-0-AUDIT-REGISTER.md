# HR.R1-0 audit register (recovered)

**Recovered by HR.R1-C9, 2026-11-07. Current status verified against `d67b3b1b`
plus the C9 candidate.**

**Why this file exists.** The HR.R1-0 audit of 2026-11-05 (verdict: PARTIALLY
READY, REMEDIATION REQUIRED) was delivered as a session report and never
committed. Only a summary reached `HR-R1-CLOSURE-CONTRACT.md`, which then named
findings by range (HRC-3…11, LVA-3…9, XRP-3…7) with no definition anywhere in
the repository. Checkpoints C1 to C8 worked through the P1 items and the Payroll
items, so the rest stayed open with nothing in the repository to show it. C9
recovered the audit text from that session's record. The definitions below are the audit's
own. The Current column is new, and each entry was re-checked by reading the
code. A code reference here is where the check was made.

**Limits.** A status here is a reading of the code, not a test run, unless a test
is named. The audit gave no per-row verdict for the 361 capabilities. TRR-9 and
the full text of GOV-5 are named in the backlog with no recoverable definition.

**Classes:** closed, open defect, open owner decision, legally gated,
cross-domain dependency, operational-readiness condition, intentionally deferred,
not required for the defined release scope.

## Remediation backlog

| Id | Sev | Findings | Current | Class |
|---|---|---|---|---|
| R0-01 | P1 | PAY-2 | Closed for development (C1) | closed |
| R0-02 | P1 | HRC-2 | Closed for development (C2) | closed |
| R0-03 | P1 | HRC-1 | Closed for development (C2) | closed |
| R0-04 | P1 for staging | No environment, restore or encryption evidence | Open. See "Staging" in `HR-R1-CLOSURE-CONTRACT.md` | operational-readiness condition |
| R0-05 | P2 | XRP-1, XRP-2 | Closed for development (C2) | closed |
| R0-06 | P2 | PAY-1 | Closed for development (C1) | closed |
| R0-07 | P2 | LVA-1/TRR-2 | **Open.** `HR-16-READINESS.md` §5 still claims heartbeats and alerts for accrual. `routes/console.php` schedules no accrual or eligibility command, and `HR-OPERATIONS.md` §4 describes a manual re-run. Needs D-3 | open owner decision |
| R0-08 | P2 | HRC-3 | **Not reproduced on reading.** Web `store`/`archive` and API `store`/`update`/`archive` all call `HighlySensitiveDocumentStepUp` (SR.4). Either the audit missed it or it meant another write. Not proven by a test here | open: re-verify with a test |
| R0-09 | P2 | LVA-3 | Open. `StaffCalendarService` overwrites the weekly pattern and deletes a holiday (`:88`). Needs D-6 | open owner decision |
| R0-10 | P2, latent | TRR-1 | Open. Statutory CSV exports do not use `CsvCell::safe()`. Unreachable until a statutory role exists | legally gated |
| R0-11 | P2 | PAY-9 | Closed for development (C1, `HR-OPERATIONS.md`) | closed |
| R0-12 | P2 | PAY-12, R-HR16-1, LVA-7 | Measured, no approved target. Needs D-7 | open owner decision |
| R0-13 | P2 | GOV-11, TRR-10, R-HR16-3 | Partly reduced: C6 to C9 ran real logged-in walkthroughs. High-assurance Leave decisions, separation MFA and a screen-reader pass have no evidence. No browser evidence is committed | operational-readiness condition |
| R0-14 | P3, blocks closure | S-5, S-13 (GOV-2) | Closed for development (C1) | closed |
| R0-15 | P3 | LVA-8/TRR-4 | **Open defect.** `EmploymentRoster::labels()` reaches `rows()`, which still limits to 2,000 (`:163`), so Payroll HRX labels go blank beyond 2,000 staff | open defect |
| R0-16 | P3 | LVA-2, LVA-4, LVA-5, LVA-6 | LVA-2 closed (C2, C9). LVA-4/S-7 open: `allocate` and `executeRun` take no fresh MFA, needs D-4. LVA-5 open: `LeaveBalanceReport:110` and `LeaveReadService:208` restate the credit rule. LVA-6 open: `LeaveAdminController:71,186` use the UTC date | open defect |
| R0-17 | P3 | HRC-4, 7, 8, 9, 10, 11, 12; XRP-4 | HRC-12 closed (C9). The rest are listed below | open defect |
| R0-18 | P3 | PAY-3, 4, 5, 6, 7, 11 | Closed for development (C1) | closed |
| R0-19 | P3 | TRR-3, GOV-3, GOV-4, PAY-10, LVA-9, TRR-9, HRC-5, HRC-6, XRP-5, XRP-6, GOV-5 | Open documentation unit, listed below | open defect (docs) |
| R0-20 | P3 | TRR-6, TRR-7, TRR-8 | Open. Task review is not in `ApprovalWorkflowRegistry`, no mid-run resume test, and tasks of separated staff stay overdue | open defect |
| R0-21 | P3 | XRP-3, XRP-7 | Open. `TeachingAssignmentController:163` and `ElectiveTeachingAssignmentController:71` list by identity flag. No guard pins that StaffType grants nothing | open defect |

## The HR findings

| Id | Sev | Finding | Current |
|---|---|---|---|
| HRC-4 | P3 | RETAINED is a one-off boolean, not bound to the grant set: a role granted after access was recorded as kept stays non-blocking at completion | Open. `access_retained_at` is still a single timestamp |
| HRC-5 | P3 | ADR 0028 HR.2A §5 says editable profile records carry a stale-form token; code, rule 132 and HR.md cover identity and personal edits only | Open, contradiction between documents |
| HRC-6 | P3 | `assertNoOverlap` says it is the "sole enforcement point"; the HR.1A trigger also enforces it | Open, stale comment (`EmploymentService.php:432`) |
| HRC-7 | P3 | `setPrimary` demotes an open primary in place, so past as-of answers change | Open. C9 freezes a CLOSED primary and leaves this in-place demotion, which is the service's own write |
| HRC-8 | P3 | `staff_type` can still be NULL on API create and rehire | Open (`EmploymentController:43`, `EmployeeLifecycleController:78`). The web requires it |
| HRC-9 | P3 | `primaryOnSql` does not match its comment | Partly closed: C2 added the empty-interval case. The comment was not re-read against the SQL here |
| HRC-10 | P3 | `EmploymentRecord::$fillable` includes `school_id`, `status` and the terms columns (GOV-6 is the same family across models) | Open, database triggers mitigate |
| HRC-11 | P3 | HR's synchronous writers (employment, placement, separation) never call `SchoolOperationalGuard` | Open. Only `ActingEmployeeResolver` does. **Rule 86 says every School business effect re-checks at execution time.** A suspended School is refused earlier by routing; the execution-time check is what is missing |
| XRP-4 | P3 | Timetable and Transport obligations are keyed by Employee, not employment | Open (`TimetableSeparationObligations`) |
| XRP-5 | P3 | The gate register has no StudentMark row | Open |
| XRP-6 | P3 | HR reads `school_memberships`, `roles` and `campuses` directly; the domain map omits Campuses | Open, documentation drift |
| XRP-7 | P3 | No guard test pins that StaffType grants nothing | Open |
| TRR-3 | P3 | Rule 140 says an out-of-range request is a 422; the page answers 200 with errors in place, a deliberate HR.16 change | Open, rule text |
| TRR-5 | P3 | The "employments examined" column in task-generation reports is close to an ADR 0040 time series | Open owner decision (D-9) |
| GOV-1 | P2 | HR.R1 is not closable | Still true, see the C9 preflight |
| GOV-3 | P3 | R-HR16-2 implies leave decisions, attendance corrections and task reviews need fresh MFA; they do not | Open, register wording |
| GOV-4 | P3 | "17 roles" is stale in rule 93, the gate register and `DDEV-DEMO-REVIEW.md` | Open |
| GOV-6 | P3 | `PayrollRun`, `LeaveRequest` and `EmploymentRecord` leave `status`, actor columns or `school_id` mass-assignable | Open, triggers mitigate |
| GOV-7 | P3 | The E47 question should name the new add-on roles | Open (D-10) |
| GOV-8 | P2 | E16 schedule risk | Valid through 2026-11-28; a successor is due about 2026-11-20 |
| GOV-9 | P2 | No staging, restore, encryption or legacy-document evidence | Open |
| GOV-10 | accepted | Records older than their controls | Accepted by the audit |
| LVA-9 | P3 | `LeaveEligibilityResolver` docblock cites the removed `EmploymentRoster::on()`; the register's "S-1 stays OPEN" contradicts its §5 | Open |
| PAY-8 | P3 | Gate-register items still open | Open, these are the S-series rows |
| PAY-10 | P3 | Documentation drift | Open, part of R0-19 |

## The audit's owner decisions

D-5, D-8, D-11, D-12 and D-13 are status carried in the gate register. These
need an answer before a final HR.R1 assessment can pass:

| Id | Question | Audit's recommendation (not an approval) | Owner |
|---|---|---|---|
| D-1 | HR.4–8 deferral record | Record DEFERRED, tied to HRX-L2 and OD-HR-14 | HR owner |
| D-2 | R1-02 Payroll to HR coupling | Exception with an exit plan | Architecture |
| D-3 | Accrual scheduling and monitoring | Keep manual and correct the docs, or schedule with a system actor | HR owner |
| D-4 | Fresh MFA on leave allocation (S-7), and HRC-3 once re-verified | Require for Highly Sensitive documents | Security |
| D-6 | Calendar as-of (LVA-3) | Record a residual until HR.4 | HR owner |
| D-7 | Performance targets (month view, Payroll packet) | Set a p95 target | Product |
| D-9 | TRR-5 classification | Accept as operational, or bound the column | Product |
| D-10 | Extend E47 to the add-on roles | Extend before sending | Legal |
