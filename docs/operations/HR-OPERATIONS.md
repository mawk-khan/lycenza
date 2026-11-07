# HR operations runbook

This page covers the HR checks an operator needs after a deployment and
when HR background work fails. It sits beside `FAILED-JOBS.md`,
`BACKUP-AND-RESTORE.md` and `MAINTENANCE-WINDOW-RELEASE.md`.

## 1. After a deployment

1. Run `php artisan platform:verify-database`. Expect `failed=0`. These
   checks must PASS:
   - `hr_workforce_invariants`, `hr_profile_invariants`,
     `hr_reporting_invariants`;
   - `hr_task_invariants`, `hr_separation_invariants`;
   - `leave_policy_invariants`, `tenant_tables_force_rls`.
2. Confirm the scheduler and a queue worker are running, then check that the
   scheduled-task heartbeats are fresh (`platform:operations-status`):
   - `workforce-tasks-generate` runs every minute;
   - the leave accrual and eligibility runs.
3. Sign in as an HR administrator and check:
   - **HR reports** opens;
   - **Staff attendance** shows "Showing 1–N of TOTAL staff", and TOTAL
     equals the number of current employments.

## 2. Staff attendance at large Schools

The register and the month view are server-paged: 100 staff per page, up to
200 through the API (`page`, `per_page`), and `data.page.total` gives the
whole population. Nothing is capped.

- The month view's totals compose every employment's month. Measured at
  5,000 staff: about 4.3 s on one local machine.
- If it is slow, look at the database first. Never add a cap.

## 3. Recurring staff tasks

`workforce-tasks:generate` creates **today's** occurrences only, every
minute, for each active School.

**A missed day** is never generated later. That is an owner decision
(R-HR13-3).

**Detecting a missed day:**
- the `workforce-tasks-generate` heartbeat alert, or
- **HR reports → Recurring task generation**, which lists every day and shows
  "No run" for a day without a run. A "No run" day never counts as
  completed, missed or overdue.

**Recovery:**
- Fix the scheduler and confirm today's run is "Completed".
- An administrator may also use "Generate now" on the catalogue, which
  generates today only.
- Earlier days stay without occurrences. Re-creating them needs a business
  decision about eligibility, versions and due dates.

**Recurrence ignores holidays** (R-HR13-2) until WorkSchedule exists.

## 4. Leave accrual and eligibility runs

Runs resume from their cursor and never post twice. A failed batch rolls back
on its own.
- Re-run the same month from the accrual workspace.
- Never edit `leave_ledger_entries`. A correction is a new adjustment.

Leave balances in **HR reports** are **as posted**: an entry counts from the
School-local day it was recorded.

## 5. Separation completion and School access

Completion takes Identity's School access lock before it reads the
subject's access (R-HR15-1). While a grant, revocation, invitation
acceptance or off-boarding is running in the same School, completion waits
for it. The reverse also holds.

A case that refuses with `SEPARATION_OBLIGATIONS_OPEN` because of School
access needs one of two actions:
- off-board the account under **Settings → Staff accounts**, or
- record that the access is kept (RETAINED).

Completion never revokes anything. A grant made after completion shows as
OPEN on the completed case.

## 6. Database statistics after a bulk load

A School loaded in bulk (an import, a migration or the demo) has no planner
statistics until autovacuum analyzes its tables. Report pages can be very
slow in that window: one absentee page took 75 s before `ANALYZE` and 49 ms
after.

**Normal case:** autovacuum fixes this by itself within minutes.

**To fix it immediately after a large import, in an authorized maintenance
action:**
- Connect as the table owner (the admin or migration role; the runtime role
  cannot ANALYZE) and run:

  ```sql
  ANALYZE employees, employment_records, employee_assignments, staff_attendance_records, workforce_tasks, leave_ledger_entries;
  ```

**Never** disable autovacuum, run `VACUUM FULL` as a fix, or add indexes for
this.

## 7. Rolling back an HR release

- **HR.14 and HR.16** are code-only (no migration). Revert the deployment.
- **Migrations with HR evidence** (HR.1A to HR.13): never roll back once real
  evidence exists. Fix forward, or restore from backup as in
  `BACKUP-AND-RESTORE.md`. HR.2A's rollback can lose archive and
  reference-code evidence. Each migration's `down()` refuses when evidence
  would be destroyed.

## 8. Naming a Payroll checker (HR.R1-C1, PAY-9)

No default role can approve a payroll run on its own: approving needs
`payroll.runs.approve` AND `payroll.compensation.sensitive.view`, and no
default role holds both (School Admin holds the first only). Until a School
names a checker, every calculated run waits.

1. An administrator with `school.roles.manage` and the grant right
   `school.roles.grant.payroll_sensitive` opens **Settings → Staff accounts**
   and grants the **Payroll Approval Reviewer** add-on
   (`payroll_approval_reviewer`) to a person who already holds
   `payroll.runs.approve` (for example the School Admin).
2. Never grant it to the person who prepares, calculates, adjusts or changes
   compensation for payroll: the decision refuses them for any run they made
   or whose compensation change it pays first, but naming an independent
   checker avoids blocked runs.
3. The checker enrols an MFA factor under **Account security**. The review
   needs current MFA; approving asks for a fresh code.
4. A run calculated before 2026-11-05 shows "recalculate the run before
   approval": its calculation did not record the compensation it used. Ask
   the preparer to recalculate; nothing is backfilled.
5. If the review says compensation for the period changed after the
   calculation, the preparer recalculates and the checker opens the review
   again. The earlier review can no longer be approved.

**Rolling back HR.R1-C1:** its migration refuses once any calculation has
recorded compensation provenance. Fix forward.

## 9. Payroll reversal and approval (HR.R1-C3)

- **"This payroll run changed after you reviewed it."** A reversal or another
  run's approval changed which compensation this run pays first. The figures
  have not changed, so do not recalculate. Open the review again, read the
  compensation changes it now lists, and approve from there.
- **The run compared with here was reversed.** The review still compares with
  the previous approved run, and the warning says those figures no longer stand.
- **After a reversal, the next run's approver may change.** The author of a
  compensation change the reversed run paid cannot approve the next run that
  pays it, so name another checker (section 8) before the run is due.
- **"The evidence behind this approval changed" (HR.R1-C4).** A reversal took this
  run's approval independence. Post it as someone who took no part in the run and
  holds `payroll.runs.post` and `payroll.compensation.sensitive.view`: read the
  evidence, tick the statement and enter a fresh code. If nobody qualifies, the run
  waits. An independent approval of the same change on another run also clears it.
- **A posted run says it was affected after posting.** That reversal came too late
  for any control. It is recorded; reconcile it by your normal correction route.
- **Unlinking does not clear a Payroll conflict (HR.R1-C7).** An Employee's account
  link can be removed as before, and the person is still that Employee for Payroll.
If a link was simply wrong, use **Request correction** in the Employee's Link
  history. Nothing changes until a second person approves. Both of you need
  `hr.employees.manage`, your own linked Employee record and a current employment, and
  neither of you may ever have been linked to that Employee. The reviewer gives a code,
  and the decision is final. If the Employee's links change meanwhile, the request can
  only be refused: request again. A School with one such person has to wait until it
  has two. There is no platform override. Never use this to free someone who really
  was that Employee: it is audited, the row stays, and Payroll shows it on the approval.
- **"The run this corrects has been reversed" (HR.R1-C8).** That correction can never
  be approved or posted. It stays as a record. To change pay, use the normal run.
- **"Corrections still standing" on a reversed run.** Reversing a run does not reverse
  its posted corrections, so their amounts stand alone in the ledger. Someone with
  `payroll.runs.reverse` decides whether to reverse each one.
- **"This run pays a compensation change of your own" (HR.R1-C6).** You are the
  Employee of a change this run pays for the first time. Someone whose pay it does
  not change must approve, attest or review. There is no override.
- **A School's first payroll, and any School-wide salary revision.** Every Employee
  is first-paid in those runs, so everyone paid by them is refused. Before running
  one, make sure a decider who is not paid by it holds `payroll.runs.approve` and
  `payroll.compensation.sensitive.view`, such as a trustee or manager with no Employee
  record. This is deliberate. Do not unlink an account to get past it.
- **"N Employee(s) have no linked account".** The system cannot tell whether you are
  one of them. You may proceed, and your decision records the count. It does not mean
  there is no conflict: if you know you are one of them, stop and ask someone else.
- **Payroll > Reconciliations (HR.R1-C6).** A reversal took independence from a run
  that had already posted. Someone independent must read what happened and record a
  review: not the run's approver or makers, not whoever reversed, and not anyone paid
  by a change in it. It needs `payroll.runs.approve` and sensitive view, and a fresh
  code. It blocks no other Payroll work and has no deadline, and it never closes on
  its own, even if a later approval covers the change, so check the open count on the
  Payroll page. A review is final, is not an
  approval, and moves no money.
- **A reversal is brief but School-wide.** Approvals and calculations of that
  School wait for it to commit.
- **A migration rollback** is refused outside local and testing, and on any role
  without `BYPASSRLS`: see `DATABASE-BOOTSTRAP.md`, "Rollback safety".
