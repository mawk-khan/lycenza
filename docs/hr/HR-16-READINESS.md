# HR.16 — Security, data integrity, performance and readiness (2026-11-05)

Status: **implementation-complete for the HR.16 scope**. This is not HR
programme closure. HR.R1, the cross-ERP closure audit, stays open and
mandatory.

Owner decisions (2026-11-05):
- **Browser and axe tooling:** used temporarily in the session scratchpad,
  never added to the repository.
- **Local data:** `ddev demo-reset` on the local DDEV database is authorised
  for the walkthrough.
- **Leave balances:** shown "as posted" (posting date).
- **Staff details status:** the status on the chosen date, or "Employed
  (notice history not recorded)" where unknown.

## 1. Remediation matrix

| Finding | Class | Outcome | Evidence |
|---|---|---|---|
| R-HR14-2: Staff details showed today's status on a past date | FIX NOW | **CLOSED.** "Status on the date" comes from `SeparationNoticeHistory`: HR.12 approval starts notice and cancellation ends it the day before. "Status today" is a separate, labelled column. Where the source has no notice history, the column says so and never guesses. | `HrReportTemporalSemanticsTest::staff_details_shows_the_status_on_the_date_from_separation_evidence` covers four cases: later separation, cancelled notice, a legacy end, and a rehire's two employments. |
| R-HR14-1: leave "as at" was ambiguous | OWNER DECISION, then FIX NOW | **CLOSED.** The report is now "Leave balances as posted" with a `posted_by` filter and the "Recorded (posted) by a date" basis. The ledger has no effective date for allocations or adjustments, so no effective-date mode is offered. | `HrReportTemporalSemanticsTest::leave_balances_are_as_posted_and_cut_at_the_school_local_day_end` covers a backdated adjustment and an exact cutoff in Asia/Kolkata and America/New_York. |
| Other HR.14 fields labelled as history | VERIFY | Work email and phone are labelled "(today)". Department and designation show "on the date" or "(when assigned)". Names are today's labels of historical rows, the same convention as SIS. | Code review; `HrReportsTest`. |
| R-HR15-1: a role grant could race separation completion | FIX NOW | **CLOSED.** Completion first takes Identity's School access lock, the one every grant, revocation, reactivation, invitation acceptance and off-boarding takes first (`StaffAccessState::holdForCertification`). This also covers a new grant on a membership that holds no role. | `SeparationAccessCertificationTest`: two real processes with the contender observed blocked. Grant versus completion refuses with `SEPARATION_OBLIGATIONS_OPEN`; revocation versus completion completes; a later grant is shown as OPEN on the completed case; another School is isolated; authority is still required. With the lock removed, the grant test fails because the contender is never blocked. |
| Staff Attendance register 2,000-row cap | FIX NOW | **CLOSED.** The register and the month view are paged on the server through `EmploymentRoster::paged()`. Page size is at most 200, the page reports the whole total, and nothing in the population is capped. Month totals count everyone in id-ordered batches. `EmploymentRoster::on()` and `overlapping()` are removed. The API gains optional `page` and `per_page` parameters and a `data.page` object in OpenAPI. | `StaffAttendanceRegisterPagingTest` with 2,100 employments: all reachable, none twice, records on their own rows, month totals = 2,100 × 6 half-days. |
| `LeaveEligibilityConcurrencyTest` used the UTC date | FIX NOW | **CLOSED.** Root cause: the child processes use the real clock, but the services judge dates against the School-local day. The test now uses `SchoolTimezone::today()`. | Exact boundary tests (local 23:59:59 versus 00:00:00 for the ledger cutoff and task overdue) in `HrReportTemporalSemanticsTest`. |
| R-HR14-4: slow pages on a freshly bulk-loaded School | VERIFY + HARDEN | **MEASURED.** Without planner statistics, absentees took 75 s and other reports up to 1 s. After `ANALYZE` every report page takes under 50 ms (§3). Autovacuum gathers the statistics; operators can run `ANALYZE` after a bulk import (runbook). No index added. | §3 |
| R-HR14-3: no exports (D-11) | OWNER DECISION | **Kept:** browser-only. | — |
| R-HR14-5: headcount and rates (ADR 0040) | LEGAL GATE | **Kept** as GATE. | — |
| R-HR13-1: task remarks are free text | VERIFY | Remarks are rendered as escaped text (no `v-html`), never sent to any HR.14 report, and hidden from a former assigner (HR.13 tests). | `HrReportsTest`; `WorkforceTaskTest`. |
| R-HR13-2: recurrence ignores holidays | OWNER DECISION | **Kept** until WorkSchedule (OD-HR-14). Labelled on the catalogue page. | — |
| R-HR13-3: missed days are not generated | VERIFY | **Kept** (no back-fill without a decision). Detection: the `workforce-tasks-generate` heartbeat alert, plus "No run" in `hr.task_generation_runs`. | HR.13 observability; `HrReportsTest`. |
| R-HR12-3: retained School access | VERIFY | Unchanged by owner decision. RETAINED stays separate from CLEAR, and a grant after completion shows as OPEN. | `SeparationAccessCertificationTest`. |
| R-HR10-1: backdated separation after an accrual credit | DEFERRED | Unchanged: controlled reconciliation (HR.10). HR.R1-C9 corrected detection only (net of the adjustment chain). A closed year still cannot be corrected. | `LeaveSeparationCreditBoundaryTest` |
| HR.2A-1: legacy path-only documents | VERIFY | Local DDEV: **0** rows (`employee_documents` where `document_id` is null). The staging and production count is unknown and is a pre-staging inventory step. | DDEV query, 2026-11-05. |
| HR.2A-2: rollback can lose data | VERIFY | Unchanged: forward-fix only once evidence exists. Restoring from backup is the rollback. | `BACKUP-AND-RESTORE.md` |
| HR.2A-3: Highly Sensitive documents need MFA-assured API tokens | VERIFY | Unchanged and enforced (HR.2A tests). The API compatibility note is a pre-staging item. | HR.2A suites. |
| HR.2A-4 and HR.9/10: no axe audit | VERIFY | **CLOSED.** Axe ran on 22 rendered pages, including the Staff profile and the Leave and Staff Attendance pages, with no WCAG 2.1 A/AA violations. | §4 |
| Browser walkthrough not done | VERIFY | **CLOSED** for the flows in §4. | §4 |
| New: forged report parameters redirected back with no visible error | FIX NOW | **CLOSED.** The report now renders with the bad parameter dropped and the message shown on the page. | `HrReportsTest` (`assertReportError`); browser step "Forged column salary refused". |
| New: the register made the page scroll sideways at 375 px | FIX NOW | **CLOSED.** The table scrolls inside its own region. | Browser responsive check. |
| New: the month view takes about 4.3 s at 5,000 staff | HARDEN (later) | **RECORDED** as R-HR16-1, because totals compose the whole population. No target exists, and one is recommended for the owner to set. | §3 |
| OD-HR-1/2: Payroll approver visibility | OWNER DECISION | **OPEN** at HR.16; since DECIDED and implemented for development (OD-HR-1/2, §6), corrected by HR.R1-C1 (PAY-2). Payroll production stays blocked by HRX-L4, E45 and the statutory gates. | Gate register. |
| HRX-L2 / OD-HR-14: clock and biometric attendance | LEGAL GATE | Kept. | — |

## 2. Authorization boundary (repository capabilities)

| Persona (seeded role) | HR reports | Placement columns | Task reports | Separation |
|---|---|---|---|---|
| `school_admin` | all | yes | yes | view, manage, approve |
| `hr_officer` | all | yes | yes | view, manage, configure |
| `principal` | Staff details, anniversaries, leave, attendance, tasks; **not** movements or reporting lines | no (no `hr.employees.assignments.view`) | yes | view, approve |
| `line_manager` (`hr.leave.approve` only) | none | — | none | — |
| `team_task_manager` (`hr.tasks.team` only) | none | — | none (team page only) | — |
| `teacher`, `accountant`, staff self-service | none | — | none | — |
| Platform admin, auditor, Group or elevation | none (no School capability; rule 83) | — | — | — |

Evidence:
- `HrReportsTest` covers discovery, direct URLs, a Campus grant and team or
  leave-approval keys.
- The browser walkthrough (§4) covers the principal, teacher, accountant and
  platform admin.
- `SeparationAccessCertificationTest` covers authority on completion.
- Earlier suites still cover employment-dependent duty eligibility (HR.15),
  self-administration (HR.2A, HR.12, HR.13) and the Campus scope catalogue.

## 3. Performance (local DDEV, synthetic, 2026-11-05)

Dataset: one School, 5,000 employments, 100,000 attendance records and
20,000 tasks. Each figure is one page of the read model, page 3.

| Report | Queries per page | No statistics | After `ANALYZE` |
|---|---|---|---|
| Staff details | 18 | 481 ms | **19 ms** |
| Reporting lines | 19 | 484 ms | **18 ms** |
| Staff movements | 11 | 966 ms | **44 ms** |
| Staff absentees (20,000 rows) | 13 | **75,235 ms** | **49 ms** |
| Task occurrences (20,000 rows) | 8 | 12 ms | 13 ms |
| Task status counts | 5 | 21 ms | 22 ms |
| Attendance register, page 10 | — | 21 ms | 22 ms |
| Attendance month view (totals over 5,000) | — | 4,080 ms | 4,356 ms |

- Queries per page do not grow with the School's size (`HrReportsTest`).
- No performance target exists in the project. Recommendation for the owner:
  an HR report page at p95 under 500 ms at 5,000 staff, with fresh statistics.
- These figures come from one local machine. They are no promise for any
  other environment.

## 4. Browser walkthrough and accessibility (local DDEV, 2026-11-05)

**Setup:**
- Build: the HR.16 working tree on top of `13cc4a15`, after
  `ddev demo-reset --build` (synthetic demo data).
- Browser: system Chromium (snap), driven by Playwright 1.48.2 with
  `@axe-core/playwright` 4.10.0, installed only in the session scratchpad.
- Axe tags: wcag2a, wcag2aa, wcag21a, wcag21aa.

**Result:** 53 steps, 53 PASS.

| Persona | Flows exercised (rendered pages) |
|---|---|
| HR & Payroll (`hr_officer` + `hr_sensitive_records` + `payroll_officer`) | HR hub; directory; a Staff profile; reports hub (18 listed, no gated entry); all 10 rendered reports; Staff details as of 2025-06-01 with "Status on the date"; column chooser opened by keyboard (`aria-expanded`); a chosen column appears; a forged `salary` column refused with a visible message; unknown report 404; report paging; **write:** staff working week saved under Leave configuration; attendance register paging text; month view; **write:** a half-day recorded on the register and shown after save; leave requests; month-wise leave; separations; task monitor; **write:** a task category added in the catalogue |
| School admin | reports hub, Staff details |
| Principal | Staff details (200, no Campus, Department or Designation columns); movements and reporting lines 403; hub hides both |
| Teacher | reports hub 403; a task report 403; task monitor 403 |
| Accountant | reports hub 403; attendance register 403 |
| Platform admin | no HR reports page (no School context or capability) |

**Accessibility:**
- **Axe:** 22 pages, **0 violations**. Pages: HR hub, directory, Staff
  profile, reports hub, 10 reports, attendance register and month view, leave
  requests, month-wise leave, separations, task monitor, task catalogue, and
  Leave configuration.
- **Keyboard:** the report filters can be reached in order (back link, From,
  To and so on), and the column chooser opens with Enter.
  - **Observation:** Chromium's native date input moves through its day,
    month and year segments, and one segment has no visible focus ring of its
    own. That is a native control and is recorded, not changed.
- **Responsive:** at 768 px and 375 px no page scrolls sideways (after the
  register fix). Tables scroll inside their own region.
- **Not done:** a screen-reader pass and the remaining manual keyboard review
  (dialogs, focus restoration) still need a person.

**Not exercised in the browser (covered by HTTP and service tests only):**
- leave request submission, approval and refusal;
- separation record, approve, finalize and complete (fresh MFA; the demo
  personas have no MFA factor enrolled);
- task assignment, submission and review;
- attendance correction.

## 5. Readiness determination

| Dimension | Determination |
|---|---|
| Implementation (HR.16 scope) | Complete |
| Functional tests | Green: focused suites plus the exact-SHA CI run (completion report) |
| Database integrity | `platform:verify-database` on DDEV: 0 failures; 246 forced-RLS tables; HR invariants PASS. One operator-evidence item: runtime connection encryption. |
| Authorization and security | No unresolved HIGH finding; R-HR15-1 closed |
| Privacy and legal gates | Preserved: HRX-L1/L2/L4, ADR 0040, D-11, HR-L8, OD-HR-14 to 20 (OD-HR-1/2 decided since) |
| Browser UAT | Done for the flows in §4; write flows that need MFA were not exercised in a browser |
| Accessibility | Axe clean on 22 pages; a screen-reader pass is still needed |
| Performance | Measured (§3); no agreed target; R-HR16-1 (month view) |
| Monitoring | A scheduled-task heartbeat and alert rule exist for task generation (HR.13). Leave accrual and eligibility are manual and have none (corrected by HR.R1-C10, LVA-1). D-3 decided 2026-11-12 (HR.R1-C31): accrual stays manual with a read-only month status (WO-9, **not built**), no alerts; runbook `docs/operations/HR-OPERATIONS.md` |
| Migration and rollback | HR.16 adds no migration. Rollback is a code revert. HR.2A's data-losing rollback stays forward-fix only. |
| **Local testing (DDEV)** | **GO** for the implemented HR scope with synthetic data |
| **Staging testing** | **CONDITIONAL.** It may test the implemented HR scope with synthetic data only, provided: the staging environment and its approvals exist; E16 is current (the 2026-11-01 decision runs out after 2026-11-28); the legacy-document inventory is done; the API client MFA note is published; and backup and restore evidence exists for staging (a documented policy is not a tested restore). Payroll, clock attendance, exports and person-count analytics stay disabled. |
| **Production** | **NO-GO.** Blocked by HRX-L4, E45 and the statutory gates (Payroll), HRX-L2/OD-HR-14, ADR 0040 cohort size, D-11, the legal gates, staging evidence and HR.R1 |
| HR.R1 | Open (mandatory) |

## 6. OD-HR-1 / OD-HR-2 update (2026-11-05)

- **Decided and implemented for development:** approval needs
  `payroll.runs.approve` + `payroll.compensation.sensitive.view`; a
  purpose-limited review packet; the approval binds to the reviewed calculation
  (stale refused, recorded, database-frozen); compensation authors join the
  maker set. Evidence: `PayrollApprovalReviewTest`, `PayrollApprovalReviewLargeRunTest`
  (2,100 entries), the two-process approval/recalculation races in
  `PayrollRunLifecycleConcurrencyTest`.
- **Readiness:** local GO for the Payroll review. Staging stays CONDITIONAL
  (prerequisites in §5 unchanged; E16 through 2026-11-28 only). Production
  stays NO-GO: HRX-L4, E45, statutory gates, D-11 and HR.R1 remain open.

## 7. HR.R1-C3 update (2026-11-06)

PAY-14 is closed for development. PAY-13 is corrected at approval. PAY-16 is closed
for every order up to posting (HR.R1-C4, OD-HR-22), and after posting by HR.R1-C6: a
reversal after posting opens an OPEN obligation for independent review (PAY-18,
OD-HR-23). PAY-19 and PAY-20 are closed for development (HR.R1-C6), and the live-link limit by HR.R1-C7 (OD-HR-25). PAY-17 is closed for development by HR.R1-C8. PAY-15 is closed
for development (HR.R1-C5), which removes one application blocker for staging. This changes no other
readiness determination: staging stays paused and infrastructure-blocked, and
production stays NO-GO.
