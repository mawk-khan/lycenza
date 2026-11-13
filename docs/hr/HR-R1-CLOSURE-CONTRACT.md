# HR.R1 — Final Cross-ERP Reconciliation & Closure Audit (reserved)

Status: **RESERVED by HR.0A** (2026-11-03). The final assessment has not
started; the HR.R1-0 audit and checkpoints C1 to C27 have. **Never
auto-start.** The audit's own findings are in `HR-R1-0-AUDIT-REGISTER.md`,
recovered by C9.

## 1. Purpose and timing

HR.R1 runs only after the wider ERP programme has connected HR with each
of these:
- Organization;
- SIS;
- Finance / Payroll;
- Inventory;
- Transport;
- Timetable / Academic;
- IAM / security;
- retention / audit.

**Closure states:**
- **Implementation-complete:** HR may reach this at the end of HR.16.
- **Permanently closed:** HR is declared closed **only when HR.R1
  passes**.

## 2. Preconditions

- HR.1–HR.16 are each published, or each explicitly DEFERRED with an owner
  decision recorded in `HR-REFERENCE-CAPABILITY-MAP.md`.
- The HR reference pack fingerprint is unchanged
  (`HR-REFERENCE-CAPABILITY-MAP.md` §1), or a re-capture has been mapped.
- A green full regression exists on the HR.R1 baseline SHA.

## 3. Mandatory verifications (each PASS / FINDING with evidence)

| # | Verification | Method |
|---|---|---|
| R1-01 | **No duplicate source of truth.** Every row of `HR-DOMAIN-OWNERSHIP-MAP.md` §1 has exactly one writer | grep writers per table; architecture guards |
| R1-02 | **No orphaned integration.** Every cross-domain consumer reaches HR only through an Application reader; X-1 is closed; Payroll's `EmploymentRecord` coupling is resolved or recorded as an owner-approved exception | import scan (`use App\Domain\HR\Infrastructure` outside HR) |
| R1-03 | **No missing temporal relationship.** Assignment, category, employment type, manager, shift, calendar, policy and entitlement each answer an as-of-date query | as-of-date tests per concept |
| R1-04 | **No authorization gap.** Every HR route has a coverage contract (rule 98); manager relationship ≠ data visibility; export and column authority match the screen | `CoverageGuard`, deny tests |
| R1-05 | **No stale reporting contract.** Every HR report is in the registry with pinned filters, sorts and columns; none counts people outside the Analytics gate | registry pin tests |
| R1-06 | **No cross-tenant leakage.** Raw-SQL / RLS tests for every HR table, report and export | `RawIsolationTest` pattern |
| R1-07 | **No unresolved lifecycle issue.** Separation closes assignments, teaching ownership, reporting lines, shifts, Transport driver assignments and Timetable entries per each owner's contract | lifecycle integration tests |
| R1-08 | **Payroll / attendance / leave reconciliation.** Every payroll run's HR inputs reproduce from Leave and StaffAttendance evidence; post-approval source changes surface (`differenceState`); no LOP money effect without HRX-L4 | reconciliation tests |
| R1-09 | **Offboarding dependencies.** Inventory custody, Library loans, Finance clearance, Timetable substitution and Transport each answer the clearance question | clearance integration tests |
| R1-10 | **Gates intact.** HRX-L1–L4, ADR 0036, the ADR 0040 person-count gate and D-11: each still refused, or opened only by a recorded decision | gate register re-verification |
| R1-11 | **Retention.** Every HR table has a unit; purge and hold paths are tested | retention catalogue tests |
| R1-12 | **Security findings.** S-1…S-16 and X-1…X-3 are each FIXED, or a recorded residual with an owner decision | finding register |

**Evidence recorded (HR.14, 2026-11-05) for R1-05:** `HrReportRegistry` is the
closed catalogue (`Tests\Feature\HR\Reports\HrReportsTest`: 18 entries, owners,
authority, reference ids, no prohibited column, no headcount/birthday/biometric/
salary/staff-child/Inventory entry); counts are task occurrences by status only;
the ADR 0040 rows stay GATE. Integrations authoritative today: HR ↔ Leave
(balances), HR ↔ Staff Attendance (absentees, corrections), HR ↔ WorkforceTasks;
still blocked: HR ↔ SIS (staff children), Inventory, Finance, Payroll salary logs,
Timetable substitution, Analytics (headcount, rates).

**Evidence recorded (HR.16, 2026-11-05):** `HR-16-READINESS.md` -- R-HR15-1 closed (two-process proof), Staff Attendance paging (2,100), temporal semantics of every HR report, browser walkthrough (53 steps) and axe (22 pages, 0 violations), measured report performance, readiness by dimension. Still open for HR.R1: OD-HR-1/2, HRX-L2/OD-HR-14, ADR 0040 cohort size, D-11, HR.11B-D, HR.15B-D, staging evidence.

**Evidence recorded (OD-HR-1/2, 2026-11-05):** S-1 and S-2 closed for development (approval needs sight of pay, review packet, evidence binding, compensation authorship); Payroll production still gated (HRX-L4, E45, statutory).

**HR.R1-0 audit (2026-11-05):** verdict PARTIALLY READY -- REMEDIATION REQUIRED. P1 findings PAY-2 (a compensation author could approve the run that first paid the change), HRC-1, HRC-2; R1-02, R1-03, R1-07, R1-09 and R1-12 open.

**Evidence recorded (HR.R1-C1, 2026-11-05):** PAY-2 CLOSED for development (reproduced against `3254e1a0`; calculation compensation provenance, freshness and first-paid author exclusion in the service AND `payroll_runs_approval_evidence_guard`; two-process races); S-5 and S-13 CLOSED for development (service-level fresh-MFA proof; posting core re-reads current capability); PAY-1, PAY-3, PAY-4, PAY-5, PAY-6, PAY-7, PAY-9, PAY-11 closed; PAY-12 measured (`docs/modules/PAYROLL.md`). For R1-12 this removes S-5 and S-13; S-4, S-7 (partial) and HRC-3 remain. HRC-1, HRC-2, XRP-1 and every other HR.R1-0 finding stay open. HR.R1 remains OPEN.

**Evidence recorded (HR.R1-C2, 2026-11-05):** HRC-1, HRC-2, XRP-1, XRP-2 and LVA-2 CLOSED for development, HRC-12 PARTIAL (deceased closed; archived open as an owner decision), each reproduced against `852fb06a` (`WorkforceLifecycleIntegrityTest`, `TimetableEmploymentEligibilityTest`, `LeaveSeparationCreditBoundaryTest`, `WorkforceLifecycleConcurrencyTest`; ADR 0028 HR.R1-C2 amendment). R1-07 (lifecycle) loses its two verified defects; R1-09 keeps the owner-deferred Library/Inventory/Finance clearance. Still open: HRC-12 (archived identity), HRC-3, HRC-4 to HRC-11 (except HRC-9's empty-interval case), LVA-1, LVA-3 to LVA-9, XRP-3 to XRP-7, the HR.4-HR.8 deferral record, R1-02, S-4, S-7. HR.R1 remains OPEN.

**Evidence recorded (HR.R1-C2 gap closure, 2026-11-05):** re-verification found that the published C2 did not fully meet its own closure: a whole-School assignment could still land on an ended employment, the never-effective guard could be bypassed through `starts_on`, and the XRP-2 web test could pass without reaching the service. Each was reproduced against `cc5c14a8` and corrected (ADR 0028 "HR.R1-C2 gap closure"). The first independent review of the C1 and C2 changes found no HIGH. Its C2 findings are corrected, except the policy half of the back-dated end (OD-HR-21). Its Payroll findings are new and OPEN, not changed here (brief section 70):

| Id | Severity | Finding | Status |
|---|---|---|---|
| PAY-13 | MEDIUM | (corrected by HR.R1-C3, see below) A reversed posted run still counts as having paid a compensation change: `reverse()` leaves the run `posted`, so `payroll_run_first_paid_compensation` excludes the change from the next run and its author may approve that run. The review packet does not list it. No test covers reversal. | Closed at approval (HR.R1-C3); see PAY-16 and PAY-18 for later boundaries |
| PAY-14 | LOW | The runtime role can change `assigned_by_user_id` or `salary_structure_id` on a compensation assignment: no trigger freezes them, so raw SQL could defeat author exclusion or reprice unseen. No application path. | CLOSED for development (HR.R1-C3: `trg_employee_compensation_assignments_identity`) |
| PAY-15 | LOW, conditional | The HR.R1-C1 `down()` guard reads FORCE-RLS tables with no tenant context. If a production migration role lacks BYPASSRLS, it could see no rows and drop provenance. Not exercised. | CLOSED for development (HR.R1-C5, see below; its severity reasoning was corrected) |

## 4. Output

- An HR.R1 record in `docs/hr/` with a verdict of **PASS**, **PASS WITH
  CORRECTION** or **BLOCKED**.
- Rule for the verdict:
  - any BLOCKER or MAJOR finding means **not closed**;
  - corrections are their own published units, then HR.R1 is re-run.

**Evidence recorded (HR.R1-C3, 2026-11-06):** PAY-13 reproduced against `a5370575` (after the paying run's reversal the next run's author set was empty, so the author approved it) and corrected at approval: `payroll_run_first_paid_compensation` no longer counts a reversed run or one its author approved, a reversal takes the compensation lock exclusively, and an approval names the reviewed attribution (ADR 0034 items 11-14, `docs/modules/PAYROLL.md`). PAY-13 is PARTIAL, not closed: the final-candidate review found PAY-16 below. PAY-14 CLOSED for development. PAY-15 stays OPEN. HR.R1 remains OPEN.

| Id | Severity | Finding | Status |
|---|---|---|---|
| PAY-16 | MEDIUM | Approve-then-reverse-then-post. Y approves and posts October, paying X's change. X approves November, which is allowed because October stands. October is reversed. `post()` on November still succeeds, so the change is paid with no standing independent approval, with no warning or audit marker. Related: an approval whose packet omitted a change (a predecessor discharged it) later discharges that change once the predecessor is reversed. | CLOSED for development: every order up to posting by HR.R1-C4, and after posting by HR.R1-C6 (PAY-18). Was PARTIAL (HR.R1-C4): closed for every order up to posting; reversal after posting is PAY-18 |
| PAY-17 | LOW | Corrections on a reversed run: the target check in `createCorrectionRun()` is unlocked and outside the transaction, the database trigger checks status only, and a correction can be approved and posted after its target is reversed. Pre-existing; not an attribution defect. | CLOSED for development by HR.R1-C8. Was OPEN |

The final-candidate reviews also found raw-SQL routes around the corrected rule, all fixed in HR.R1-C3: a run could be reclassified to skip the guard's author and freshness checks, INSERTed already approved, or have its creator changed (`trg_payroll_runs_identity`), and a decided run could have no approver, counting as independent of every author (`payroll_runs_decided_has_approver`). Who acted stays an application boundary (rule 102).

**PAY-15 evidence (2026-11-06), CORRECTED by HR.R1-C5.** This paragraph first said the documented migration role is `NOBYPASSRLS`. That was wrong: the line describes `school_os_app`. See the HR.R1-C5 evidence below.

### Findings registered from the HR.R1-C2 review (2026-11-06)

Registered, not implemented, at the time. Severity is the reviewer's. All three
are dispositioned by HR.R1-C9, below.

| Finding | Evidence and confidence | Severity | Domain, owner | Reproduction needed | Recommended checkpoint | Acceptance | HR.R1 impact |
|---|---|---|---|---|---|---|---|
| Placement guard scope | Static review of `a5370575`, confirmed by reading: `trg_employee_assignments_never_effective` does not fire on `employment_record_id`, and `is_primary` on a never-effective placement is refused only in the service. High confidence. | LOW | HR workforce, HR architecture owner | A runtime-role UPDATE of each column on a never-effective or open placement | A lifecycle hardening slice | Both refused by the database for the runtime role; `DatabaseRoleVerifier` column pin updated | Open backstop gap; no application path |
| Leave credit netting | Static review, confirmed by reading: `LeaveSeparationObligations` sums debit adjustments only, so a later credit naming the same accrual still reads clear. High confidence. | LOW | Leave, Leave owner | A debit then a credit naming the same accrual | With LVA or R-HR10-1 work | The obligation nets through the one sign convention (`LeaveLedgerEntry::signedUnitsSql()`, rule 134) | Misleads a reviewer; the state never blocks completion |
| Possible reporting-line deadlock | Reviewer's static trace only: `ReportingLine::resolve()` locks the line, then the manager's employment, while `end()` holds that employment and waits on the line in `closeForEmploymentEnd()`. NOT reproduced and NOT confirmed. `TaskAuthority` has the same shape. Low to medium confidence. | LOW, unverified | HR reporting, HR architecture owner | A two-process race of a non-manager leave decision against the manager's employment end | Reproduction first, in a lifecycle slice | Reproduced and ordered, or recorded as not reachable | None until reproduced |

**Evidence recorded (HR.R1-C4, 2026-11-06):** PAY-16 reproduced at `5995e853` (the pinned test proved the run posted silently). OD-HR-22 decided as mandatory independent attestation (`docs/hr/HR-LEGAL-SECURITY-GATE-REGISTER.md`). A run whose approver is now an author of what it pays first posts only with an independent poster's attestation to the current evidence, enforced in the service and the database. HR.R1 remains OPEN.

| Id | Severity | Finding | Status |
|---|---|---|---|
| PAY-19 | LOW | A beneficiary can attest. A user who holds `payroll.runs.post` and sensitive view, made no part of the run, and whose own pay is in the implicated changes passes the service and database checks, then states they are independent. Approval has the same gap. A conflict-of-interest rule is new policy (rule 102 keeps no generic framework), so it needs an owner decision. | CLOSED for development (HR.R1-C6, OD-HR-24; limits below) |
| PAY-18 | MEDIUM | Reverse-after-posting. The run is posted while independence stands; a later reversal removes it. No posting-time control can reach this. The reversal is not blocked; `payroll.run.independence_changed` and a notice on the run record it. Nobody is required to acknowledge it, and the record is not surfaced beyond the run page and the audit log. | CLOSED for development (HR.R1-C6, OD-HR-23) |

| PAY-20 | LOW | Over-recording. `recordAffectedBy()` only asks whether the run has an open exception, so a reversal can write `payroll.run.independence_changed` for a run whose evidence it did not change: run A and run C discharge change X, run B pays X and already has an open exception on change Y, and A is reversed. It adds an audit row and never loses one. Found by the last review of `76a60fdd`, by reading only, not reproduced. Fix: compare the version before and after the reversal. | CLOSED for development (HR.R1-C6: detection now compares the reversal with the exception itself) |

The final-candidate reviews of HR.R1-C4 found no HIGH and no way to post an affected run without an attestation. Fixed before publication: unaudited evidence reads, an attestation hiding later exposure, a guard race surfacing as a 500, and evidence that disagreed with the audit events. All reviews read code only and executed nothing. `withinGuards()` has no deterministic test, and approval vs correction and posting vs capability revocation have no two-process test.

**PAY-15 update.** Superseded by the HR.R1-C5 evidence below. PAY-17 and the three HR.R1-C2 LOW findings are unchanged.

**Evidence recorded (HR.R1-C5, 2026-11-06):** PAY-15 CLOSED for development.
- **Correction.** Earlier evidence here said the documented migration role lacks `BYPASSRLS`, and that 27 of 47 guards fail open on it. The role requirement was never documented, but three migrations refuse to run without RLS bypass, so a role that applied them all sees every row. The real trigger is narrower: a role that lost bypass or stopped part way. The true guard count is in `docs/hr/PAY-15-ROLLBACK-GUARD-INVENTORY.md`.
- **Reproduced for every guard** on a production-shaped database built by the real bootstrap, as a real non-superuser login role, with the real `migrate:rollback`, each guard at its true rollback position. On `main`'s stock migrator with no bypass, hidden rows of two Schools were destroyed through 25 real guards (20 tables, 5 columns), and 10 more were blind and stopped only by a later constraint. All 27 guards HR.R1-C4 flagged measured blind; the 8 others came from the 18 entries it missed. Per guard: `docs/hr/PAY-15-ROLLBACK-GUARD-INVENTORY.md`.
- **Fixed centrally,** because published migrations are not rewritten (ADR 0066 §15.1): `RollbackSafeMigrator` refuses every `down()` before it starts unless the role sees every row, because a DROP is never filtered, and runs the rest with `row_security = off`. Every environment except local and testing now refuses every destructive migration command, on the real `APP_ENV`, enforcing ADR 0074 §26.7. Bootstrap refuses a migration role without bypass, and `platform:verify-database` checks both.
- **Proven:** on the fixed code, with no bypass, all 65 entries were refused before they started, with hidden rows and with empty tables, rows and schema unchanged. A real non-superuser role with `BYPASSRLS` installed all 376 migrations from the real bootstrap, rolled back all 77 reachable ones, was refused by the fence with nothing changed, and reapplied them with a byte-identical `pg_dump -s`. With bypass, 63 of the 65 roll back on empty tables at their true position and the 2 fences refuse.
- **Kept in CI:** `RollbackProductionShapeTest` repeats the install, the whole rollback and reapply, and 15 real guards across Payroll, Leave, Employment, Attendance, SIS, Finance, Inventory, Documents and Retention, plus the audit ledger's unguarded `down()`, as a real non-superuser role on a scratch database, on every candidate in the platform lane. Removing the role check or wiring the stock migrator fails it (mutation-checked locally). The 65-guard run was local and is not in CI.
- **Not solved:** a guard is still not atomic with the DDL after it unless it lifts FORCE first, so a concurrent write in the gap is possible on a disposable database. Hardened environments refuse the five commands, so the gap is not reachable through them. Arbitrary code run with the admin credentials is outside this control: the gate stops a mistake, not a credential holder.
- **Reviews.** The final-candidate reviews read code only and found no HIGH. Fixed before publication: `--env=local` defeating the hardened refusal, a `down()` that reads nothing not being refused, and `platform:test-db-reset` dropping every function and trigger before the framework's gate refused it. The reset now wipes first, so that gate decides before anything changes. The last review also found, already in the code, that `TestDatabaseGuard` checked a connection's database name while a connection URL overrides it, so an ambient `DB_ADMIN_URL` could have sent a reset to any database. Nothing in the repository sets one. The guard now refuses a URL on any guarded connection, and both runners clear all three. The `db:seed` failure branch has no test: nothing can reach it today.
- **Final-state review (HR.R1-C5, read-only, no HIGH).** Fixed: the `rollback-proof` skill reported a refused rollback as IDENTICAL, because it discarded the exit code, and the test for `row_security` could not fail, because the refusal came before the setting. Registered, not fixed, all LOW: `DB_TESTING_DATABASE` is read from the environment the guard polices, so a deliberate operator could approve another name (predates this work); `--env=local` makes Laravel load `.env.local`, so a file-based host with that stray file and `APP_ENV` only in `.env` would look local (the image excludes `.env.*`); `migration_owner_sees_every_row` reads the owner of `migrations`, not the role acting, though the migrator checks the acting role itself; nothing at runtime verifies the destructive-command gate, which sits on the commands and not the migrator; `RollbackGuardInventoryTest` accepts a `throw` in one nested branch as handling an error; `schema()` in `RollbackProductionShapeTest` ignores column order and seeded rows, which the local `pg_dump` comparison covered; two worktrees sharing one cluster would collide on that test's role, failing the test with no data harm; `a_refused_down_leaves_that_connection_as_it_found_it` cannot catch `SET` for `SET LOCAL` (the test beside it does).

**Evidence recorded (HR.R1-C6, 2026-11-06):** PAY-18, PAY-19 and PAY-20 CLOSED for development. PAY-16 closes with PAY-18. HR.R1 stays OPEN.
- **Decisions.** OD-HR-23 and OD-HR-24 decided by the owner, with full records in `docs/hr/HR-LEGAL-SECURITY-GATE-REGISTER.md`. The brief's Option B was not the register's: the owner chose the OPEN obligation explicitly.
- **Reproduced before any change** on `cd52ea4b`: PAY-18 by its pinned test, PAY-19 by a throwaway test in which a non-author approved a run paying their own change first.
- **PAY-18.** A reversal completes, and in its own transaction opens one obligation per affected posted run. "Affected" is one SQL function over the provenance tables, with no cap. A deferred trigger refuses the commit without the obligation, and refuses a commit that can no longer see the reversal. Two append-only tables and no status column: open means no review row, so nothing can be updated into looking closed. An obligation snapshots what the run paid first, what was implicated and the reversal's facts, so a later approval cannot empty it and the reversed run's retention cannot take it.
- **PAY-19.** One reader and one SQL function serve approval, the C4 attestation and the C6 review, each backed by a database guard. Link writers take the compensation lock before the Employee row, so a link change is ordered against every decision. An approver who becomes the Employee of a first-paid change through a reversal loses independence like an author (owner decision, 2026-11-06), so only an approval independent of both the author and the Employee discharges a change.
- **PAY-20,** fixed because an obligation cannot be opened on a false positive. A reversal is now tested against the exception itself.
- **Found by measuring.** The first form of impact discovery was quadratic: 116 s at 5,000 entries, under the exclusive lock. It is now about 52 ms, and 117 ms at 10,000. `PayrollReconciliationLargePopulationTest` pins the bounds.
- **Found by the final-state reviews.** The first candidate read independence and evidence from the live first-paid set, so an ordinary later approval let the affected Employee review their own change against an empty table (HIGH). The second found that two runs an Employee approved discharged the change for each other, so reversing the real approval flagged nothing (HIGH): the first-paid rule itself now requires independence of the Employee. Also fixed: another run's retention could delete an OPEN obligation, the deferred check failed open with no tenant context, a lock inversion between a link change and a calculation, attestors not shown the unlinked count, and implicated readers seeing evidence on a posted run. The inversion's fix has no deterministic test: the deadlock needs an interleaving no harness here can force.
- **Proven.** 66 new test methods, 13 of them on committed data, 9 of those two-process races with the contender observed blocked, and a real logged-in walkthrough with real TOTP and axe, including a 404 for another School.
- **Limits.** `employees.user_id` is the current link only and is read live, in both directions. An unlink at any time removes the person from every first-paid reading, so a pending attestation can clear, or a reversal can open no obligation where two runs that person approved discharge each other. It needs someone with `hr.employees.manage`, and is audited. A link reclassifies history the safe way: past runs the person approved stop discharging that Employee's changes. Closing this needs link history (MEDIUM). It is recorded as a limit by engineering and awaits the owner's acceptance; it is a re-review trigger of OD-HR-24. The `reversals` listing stays author-only, so a run the Employee approved, which never discharged the change, can appear in the version and on the page: stale evidence, in the safe direction. A review has no correction path. A refused review is not audited. Capability revocation against a review has no two-process test: revocation takes no lock, so the fresh read decides, and a single-process test proves that read. A reversed run with no recorded provenance is reported as impact unknown; a downstream run with no provenance is not examined at all, because it records nothing it paid. A correction run is out of scope: it pays no compensation change. A link change alone creates the exception: before posting it needs the attestation, but after posting it shows a notice and opens no obligation, because an obligation needs a reversal. Snapshot ids carry no foreign key, so if retention removes an assignment while its run stays, the evidence shrinks, and the page says so; the conflict check cannot see that person any more. The retention test exercises the key on a direct delete, not `retention_expire_payroll_run` itself. Manual screen-reader review is pending.
- **Operational consequence, the owner's explicit choice.** A School's first payroll, and any School-wide salary revision, needs a decider not paid by it.

**Evidence recorded (HR.R1-C7, 2026-11-06):** the C6 live-link MEDIUM is CLOSED for development under OD-HR-25, for every link the system can see. A new MEDIUM replaces part of it: one person with a second, never-linked HR account can correct their own link (OD-HR-25 known limits, owner decision needed before production). PAY-19 is CLOSED for development under the approved contract, with the legacy limits below. PAY-16, PAY-18 and PAY-20 hold. PAY-17 and the HR.R1-C2 LOW findings are unchanged. HR.R1 stays OPEN. The finding as published in C6 stands in the record above: until this checkpoint an unlink could erase a known conflict.

- **Reproduced before any change** on `d23a210d`, 5 of 5: approval refused while linked and allowed after an unlink, attestation after an unlink, review after an unlink, a reversal opening no obligation for runs the Employee approved after an unlink, and a relink hiding the first person.
- **Link history.** `employee_account_links`, written only by a trigger on `employees.user_id` and one correction function. The runtime role reads it. Payroll's functions read it, so approval, attestation, review and their database guards agree. An unlink no longer changes what any of them read.
- **Scope.** Only a run paying that Employee's change first. An old link does not block Payroll generally, and receiving a salary stays no conflict.
- **Correction.** Marked, never removed, with a closed reason and fresh MFA, by an account never linked to that Employee.
- **Assignment retention, measured.** C6 reported from a direct delete that an implicated assignment could go before its decision evidence. The real services do not do this: `platform:employee-retention-prune` counts the Employee as dependency-blocked while a calculation source names the assignment. C7 proved that end to end, and changed the FK from CASCADE to RESTRICT so the database says the same.
- **Measured, no SLA set.** At 10,000 entries with 20,000 link rows, with planner statistics, on the local DDEV database: current link and as-of lookup 0.4 ms, first-paid set 28 ms, self-beneficiary 104 ms, attestation version 42 ms, reconciliation version 68 ms, affected runs 90 ms, a correction 24 ms, and a reversal recording two obligations 589 ms under the exclusive lock.
- **A defect published in `fc7fcffe`, found and fixed by HR.R1-C7-R1.** Re-measuring showed `payroll_reversal_affected_runs` taking 184 s per call and a reversal 560 s, under the exclusive compensation lock. The link check C7 added sat inside a join, and a generic plan estimated one row after it and scanned every assignment per row. The first measurement of the same code had read 184 ms and 1.4 s. Why those two runs differed was not established. An independent run of the old body took 330 ms with no statistics, 5.6 s with all but the links analyzed, and over 60 s fully analyzed, so a database with normal statistics is the exposed state. One fast measurement of a generic plan is not evidence. No real data was affected, and Payroll is not in production. `2027_02_09_090000` materializes the join first, and `PayrollReconciliationLargePopulationTest` now runs 10,000 entries with link history under a 30 s statement timeout. It fails on the published function and passes in 6 s with the fix. Discovery still grows with the number of affected runs: 2.6 s at 24 runs in that independent run. Recorded, with no SLA.
- **Link against calculation.** Tested in both orders. Provenance reads compensation, never identity, so sources and the attribution version do not move, and the attribution is read at the decision.
- **Legacy.** Cutover rows state only what was known at cutover, and an Employee with no row stays unknown. An unlink before cutover cannot be recovered. PARTIAL by nature, recorded in OD-HR-25 row 19.
- **Found by the final-state review, and fixed.** One person with two linked accounts could correct one using the other, so a correction is now refused to any account ever linked to that Employee, in the service and the database. A second account that was never linked cannot be detected: recorded as a MEDIUM in OD-HR-25's known limits, with its readiness consequence. Pay authoring still read the current link, so after an unlink a person could record their own raise, override or delta: `HrSelfAdministrationGuard::isEmployeeOf()` now decides those three writes. This applies OD-HR-25 to rule 95, and is called out for the owner because the owner's choice named the decisions. The cutover now locks `employees`, the correction function takes the compensation lock, and a decision records `deciderHadCorrectedLink`.
- **Proven and published.** `fc7fcffe`: CI gate run 42288793688, four shards, 10,911 cases, 0 failures, 1 skip (ESI-12), 456,708 assertions. 93 new cases from 87 test methods, 15 two-process races with the contender observed blocked, 2 more against User erasure, 9 through the real prune and erasure commands. Rollback and re-apply identical, on the superuser proof and the production-shaped role. Mutations of the identity clauses failed 18 and 4 tests. A real logged-in walkthrough on the final code passed 25 of 25, with axe clean on 7 pages. Screen-reader testing by a person stays an open evidence gap.
- **Publication history.** `a1c32780` (HR.R1-C6) does not parse: a duplicate import, corrected by `355f8de8`. It reached `main` inside a fast-forward range. The exact-SHA gate proves each published tip, never each commit inside a range. No CI run, tag or release names it, and every published tip was green. History is not rewritten. A cherry-pick of `a1c32780` must take `355f8de8` with it. Proposal for the Platform owner, not built here: `publication-check` lints each commit of `main..candidate`, or refuses a range that has one that does not parse.
- **Assertion count.** C6 reported 107 fewer assertions than C5 with 68 more tests. Compared per test across both CI JUnit sets: no test was removed, and no test file behind the drop changed. −2,251 comes from 11 classes whose counts depend on timing or on rows present: ten polling concurrency tests and `PlatformAuditLogReviewTest` (a loop over the audit rows in its shard). +2,144 is the new tests. No security assertion was weakened.

**Evidence recorded (HR.R1-C8, 2026-11-07):** PAY-17 is CLOSED for development. The C7 second-account path is CLOSED; the wider risk it belonged to is governed, never closed. HR.R1 stays OPEN.

- **PAY-17, reproduced before any change** on `c4ea0307`: a calculated correction was approved and posted after its target was reversed, an approved one was posted, and each left 500.00 in the ledger with nothing under it. A raw runtime INSERT on a reversed target was accepted.
- **PAY-17, the contract.** It was already written: a correction of a reversed run is "a delta with no economic basis" (`CorrectionTargetAlreadyReversedException`), and a reversal is never blocked (ADR 0034). The owner confirmed both readings.
- **PAY-17, corrected.** Creation, approval and posting hold the target FOR SHARE and refuse a reversed one, in the service and the database. A reversal holds it FOR UPDATE, so each is ordered wholly before or after the other. A correction already posted stands, is named on the reversal's audit event and shown on the run, and an authorized person decides. Nothing reverses automatically.
- **A consequence the refusal created, fixed.** Retention waited for every run of a group to post, so a correction that can never post would have held its target and Employees forever. It now ages from its own creation, proven through the real prune commands, and a hold still beats it.
- **Found by the real retention tests.** My first version restated those two retention functions from their original migration, which silently removed the retention identity, hold and anchor checks that later migrations had patched in. It was never committed. They are now patched in their live definition, and a test pins those checks.
- **Second account, reproduced** on `c4ea0307`: a never-linked HR account cleared its owner's link, and that person approved their own change. Such an account now takes no part. `employee_account_link_void()` no longer exists: an approved row is the only thing that voids a link.
- **Found by the final-state review, and fixed.** A known alias of the corrected account could decide, because the same-person check compared only requester and reviewer. The database now sets its own decision times, and its qualification has the School status and employment dates. A dead correction can no longer be recalculated or given deltas. A decision is bound to the Employee in its route.
- **Recorded, not fixed.** Approving a correction holds it and then the target, while retention locks the target then the correction, so the two can deadlock. PostgreSQL aborts one, safely, and it needs an approval on an emptied group eight years old. A dead correction with statutory results would fail retention's posted-statutory check and stay held, but nothing creates those: `StatutoryPayrollCalculationService` has no route. Statutory posting now refuses a reversed target anyway. The refusals of recalculation, deltas and statutory posting on a dead correction are in the service only.
- **Performance.** See `docs/hr/PAY-REVERSAL-PERFORMANCE-EVIDENCE.md`.
- **Proven and published.** `28901d75`: CI gate run 42324557543, four shards, 10,960 cases, 0 failures, 1 skip (ESI-12), 456,835 assertions. Rollback and re-apply identical on the superuser proof, and the production-shaped role in CI. 4 mutations of the new controls failed 2, 5, 4 and 1 tests. A real logged-in walkthrough of 29 steps passed with axe clean on 9 pages. It ran one commit earlier; the last commit changed only an approval re-check. Screen-reader testing by a person stays an open evidence gap.

**Evidence recorded (HR.R1-C9, 2026-11-07):** HRC-12 and the three HR.R1-C2 LOW findings are dispositioned. HR.R1 stays OPEN. Production stays NO-GO.

| Finding | Reproduced on `d67b3b1b` | Disposition |
|---|---|---|
| HRC-12, archived | Service, API and raw runtime INSERT all admitted an archived identity | **CLOSED for development** under the owner's decision (gate register §2d-1, Option A) |
| Placement guard scope | Raw UPDATE reparented open and ended placements and set `is_primary` on a never-effective one | **CLOSED for development.** Narrowed, with the reason recorded: `is_primary` on a running employment stays a service write |
| Leave credit netting | By reading, then by test: a later credit left an accrual reading clear | **CLOSED for development.** R-HR10-1 stays **PARTIAL**: a closed year cannot be corrected |
| Possible reporting-line deadlock | Two-process, 40P01 on each run, and a second cycle the register did not name | **CONFIRMED AND CORRECTED,** with two more cycles the reviews found inside C9, each reproduced first: one the first correction introduced, one it widened |
| Correction approval vs retention (C8) | Not reproduced in C9 | **Registered, separate.** Another pair of rows on another path (a Payroll correction run and its target, in `payroll_runs`, against retention), so not this root cause and untouched by this change. PostgreSQL aborts one side and it needs an approval on an emptied group eight years old |
| Own-pay pre-lock read (C7) | Not re-examined | Unchanged, LOW |

- **Why C9 is not eligible for closure.** The preflight below. It found that the HR.R1-0 audit's findings were never committed. They are now in `HR-R1-0-AUDIT-REGISTER.md`, and most of the P3 hardening is still open.
- **Found by the independent review, and fixed.** The first candidate introduced a real `40P01` between a manager's approval and a placement change, reproduced on 2 of 2 runs before the fix. `setPrimary()` could promote a placement that was over, leaving a running employment with no current primary. A primary could be cleared in the statement that closed it. The verifier matched triggers by name only, and two tests proved less than they claimed.
- **Found by CI, and fixed.** The first candidate failed shard 1: nine `StaffAudienceTest` cases built an already-archived Employee and then employed them. That was fixture order, not a defect. The other fixtures of this shape were corrected the same way. Archiving an Employee who is still employed stays allowed, so the state those tests need is real.
- **Found by the delta review, and fixed.** A Campus line writer took two Employees in id order while a decider holds the manager's. That cycle existed before C9, and the correction widened it from approvals to rejections and task decisions, which the first version of this evidence wrongly denied. Reproduced as `40P01` on 2 of 2 runs. Both now take the manager's Employee first. The trigger's employment read now holds FOR KEY SHARE, and "ended" means terminal status, so a legacy running employment with a stored end still takes a named change.
- **Registered from the reviews, not fixed (LOW):**
  - `campus_id`, `department_id` and `position_id` stay writable by the runtime role on a closed or never-effective placement, and `ends_on` can be cleared on a running employment. No application path does either, and those keys restrict deletes, so nothing prevents freezing them. It was outside the registered finding.
  - `setPrimary()` to a placement that has not started demotes the current primary, leaving a running employment with none until it starts. Audited and reversible. It belongs with HRC-7.
  - Line writers with crossed roles can deadlock, which id order used to prevent in some of these cases. A Campus `assign(sub=M1, mgr=M2)` against `correct(S: M1→M2)`, or a reciprocal `remove` and `assign`, can abort one side safely with a 500. A deliberate trade for decisions, which are far more frequent.
- **A 500 on a deadlock.** Neither `app/Domain/HR` nor `app/Domain/Leave` translates `40P01`. The orders corrected here no longer reach it; any remaining one is an uncontrolled error with nothing written.
- **An unexplained single failure.** `EmployeeAccountLinkCorrectionTest::the_reason_list_is_closed` failed once with a `QueryException` in a local run that was not captured. Its body touches no database, so the error came from setup or teardown. It did not recur in a full HR run or six targeted reruns. No cause is known.

- **Proven and published.** `415f7bbb`: CI gate run 42348887333, four shards, 11,007 cases, 0 failures, 1 skip (ESI-12), 455,406 assertions; `main` CI run 42350275241 green. 47 more cases than C8. Rollback and re-apply identical on the superuser proof, and the production-shaped role in CI; `platform:verify-database` failed=0; forced RLS 252. 17 distinct mutations of the new controls, 19 runs in all, each failed a test. A real logged-in walkthrough on `4e616bdb`, the same behaviour as `415f7bbb`, passed 31 of 31 steps with axe clean on 10 pages. It covers former rehire, archived restore then rehire, deceased refusal, forged writes, placement changes, Leave on the separation case and another School. It is not a screen-reader pass, and its scripts stay outside the repository.
- **Assertion count.** 1,429 fewer than C8 with 47 more tests. Compared per test across both CI JUnit sets: no test was lost. The two names that disappeared are the two deliberately rewritten, and 49 are new. The drop comes from polling concurrency tests, chiefly `ServiceAssertionReplayConcurrencyTest` (−1,152) and `CrossHostHandoffConcurrencyTest` (−720), and from `StaffNavigationCatalogueTest::navigation_never_names_a_role` (−115), which asserts per role row in the database. None of those files changed.
- **Measured, no SLA set.** On the local DDEV database: an employment insert with the new guard 1.35 ms at p50, `holdManagerOf()` 3.06 ms against `managerOf()` 1.75 ms. Leave obligations took 2.20 ms on an empty account only, so the chain query has no load measurement.

**Closure preflight (HR.R1-C9, 2026-11-07).** Evidence-based, not the closure audit. "Recorded" means earlier evidence that was not re-run here.

| Item | State | Remaining |
|---|---|---|
| §2 slices | **Finding** | HR.4–HR.8 have neither publication nor an owner DEFERRED record (audit D-1) |
| §2 fingerprint | Not evidenced | The reference pack is not in Git, so only the owner can check it |
| §2 baseline | Not yet | No baseline SHA chosen for the final assessment |
| R1-01 one writer | **Finding; definition decided by C25** | Architecture adopted the ten-point definition with refinements (HR.R1-C25). Re-verified: 0 foreign, 0 controller, 0 duplicate writers; four named multi-writer HR tables pinned. Still failing: point 4 on four tables with unused runtime DELETE (WO-11), and point 3's Payroll `employee_esi_coverage` entry unpinned (WO-12) |
| R1-02 integration | **Finding; Payroll part implemented by C24** | Architecture approved D-2 Option B (HR.R1-C23); WO-3 shipped it (HR.R1-C24): 22 Payroll sites read through HR readers, 26 narrow exceptions, 12 no change, 9 structural FKs, 8 functions, pinned by `PayrollHrBoundaryRegisterTest`. Still open: direct `Employee::query()` in Timetable, Transport and Visitor controllers (X-1) and the 24 non-Payroll files |
| R1-03 temporal | **Finding, narrowed by C22** | Calendar answers as of a date from each School's history start (WO-5); earlier dates are "not recorded" by owner decision D6-3, a closure-time reading. Policy answers through `LeavePolicyAsOf`. Shifts are absent by design |
| R1-04 authorization | Recorded | Re-run on the baseline |
| R1-05 reports | Recorded | Re-run on the baseline |
| R1-06 isolation | **Finding** at C9; ten tables closed by C10 | No raw-role test named `employment_separation_cases`, its events, the clearance tables or the six `workforce_task*` tables. Forced RLS itself is checked globally |
| R1-07 lifecycle | Recorded, except shifts | Timetable and Transport answer as obligations and are never closed by HR, by rule 137 |
| R1-08 reconciliation | Recorded | Re-run on the baseline |
| R1-09 offboarding | **Finding** | Library, Inventory and Finance are NOT_INTEGRATED by owner decision. Timetable substitution does not exist. §1 of this contract requires those connections, so the owner must say how the verdict reads them |
| R1-10 gates | Intact | Refused by absence or by guard tests |
| R1-11 retention | Mapped | 85 of 92 HR-prefixed tables are catalogued; the other 7 are platform rule tables. E21 is unratified |
| R1-12 security | **Finding** | S-4 latent, S-7 partial, S-16 for `staff_holidays`, X-1 partial, and the owner record behind S-6 not located |

**Whether legal and environment items block a development-scope verdict is not stated in §4.** The owner needs to define it.

**Evidence recorded (HR.R1-C10, 2026-11-07):** the recovered audit is reconciled, HRC-3 is closed, and the ten R1-06 tables have raw-role evidence. HR.R1 stays OPEN. `HR-R1-C10-CLOSURE-READINESS.md` holds the matrix, decision packages and sequence. `HR-R1-0-AUDIT-REGISTER.md` holds all 61 findings with the audit's own text.

- **HRC-3 was real, and the C9 record of it was wrong.** C9 read the HR register routes and recorded "not reproduced". The generic Documents API reached the same tier: on `82e70a8a`, with no code, an upload answered 201 and an archive 204, the archive left the HR record active, and nothing refused a person's own record. It needed the capability, and no other School was reachable. S-3 was marked FIXED while this stood. Corrected against the existing contract (ADR 0071 §26.7, rule 95), with 17 tests, through a port HR implements.
- **R1-06.** The ten tables are `employment_separation_cases`, `employment_separation_events`, `separation_clearance_items`, `separation_clearance_requirements` and the six `workforce_task*` tables. `HrSeparationAndTaskRawIsolationTest`: 78 cases as the real runtime role. No bypass was found. It failed when RLS was really switched off on one table, and when DELETE was really granted on another. The catalogue was restored afterwards and rechecked.
- **7 of the 17 named findings closed:** HRC-9, LVA-5, LVA-6, LVA-8, XRP-7, TRR-1, TRR-7. HRC-7 is documented. 9 stay open, each with an owner and next action.
- **R0-19 corrected,** row by row in the register, including rule 93 (20 roles), rule 140, and the false accrual-heartbeat claims.
- **S-6.** No owner determination exists. The "recorded decision" was an audit classification about approval only, carried forward without its citation.
- **Found by the independent review, and fixed before publication.** The first candidate imported HR into Documents, which made the dependency bidirectional (rule 4); it is now a port. It marked TRR-1 closed while a PAN cell could still carry a formula. It let the generic route take file types the register refuses. Two raw-role cases passed without reaching their key. A forged id spent a recovery code. Several tests could pass for the wrong reason.
- **Found by C10, open:** `is_paid` can change on an untracked leave type with approved requests (C10-N1, reproduced), and eight smaller items and a recorded limit in the register.

- **Proven and published.** `eb45539e`: CI gate run 42357034567, four shards, 11,110 cases, 0 failures, 1 skip (ESI-12), 457,956 assertions; `main` CI run 42358638933 green. 103 more cases than C9. `platform:verify-database` failed=0; forced RLS 252, with no new table. 22 mutation runs of the new controls each failed a test, two of them real catalogue changes on the test database, restored and rechecked. The independent reviews found no HIGH, and their four MEDIUM findings were fixed before publication. No new screen changed, so no browser walkthrough was run: the HR and Leave changes here are API or read paths with tests. A screen-reader pass stays outstanding.

**Evidence recorded (HR.R1-C11, 2026-11-07):** HRC-10, HRC-11 and C10-N1 are closed, GOV-6 is partial, and R1-01's boundaries are pinned while its definition stays with Architecture. No owner decision was taken: D-1 to D-10 stay pending. HR.R1 stays OPEN. Production stays NO-GO. `HR-R1-C11-HARDENING.md` holds the scope, the writer proposal, the HRC-3 boundary table, the generic-upload decision and the decision register.

- **Reproduced.** With the guards removed, 26 of 27 `HrExecutionTimeSchoolCheckTest` cases fail: 22 writers and the Campus actor raise nothing, 2 cases refuse for another reason and so prove the School comes first, and the import row is created. The 27th is the positive control. C10-N1 was reproduced on `33ac0b6b` through the service and raw SQL.
- **Regression only.** The field-injection tests and edit-against-end races passed on the baseline too. HRC-10 was a structural weakness, not reachable from a request, and what proves its correction is the model test.
- **Found by the first independent review, and fixed.** My test handler stopped Eloquent's own throw on every totally guarded model outside the nine, which hid failures in test runs (MEDIUM, test control). Clearance requirements and link correction requests had no guard, while the rule said they did. Completion took the School before Identity's lock, against rule 141: no cycle existed, but the documented order is restored. Import reported a suspended School as a validation failure. Unused fillable entries are gone, and `down()` restores the original function text exactly.
- **Found by the delta review, and fixed.** Two claims about "stop refusing", a wrong test pointer, the import vocabulary, and an import message that disclosed what the 404 does not. It found no HIGH or MEDIUM, and no mass assignment reaching the models that now throw.
- **Found by a mutation, and fixed.** Nothing pinned rule 141's order. `completion_waits_on_identitys_lock_before_it_touches_the_school` now does: while completion waits, the School row takes a `FOR UPDATE NOWAIT`.
- **Found by CI, and fixed.** The first candidate failed shard 1: `EmploymentEndArchitectureGuardTest` pins the literal `$locked->update(['ends_on'`, which C11 changed. My focused runs had not covered that class. Shard 2 failed in `PayrollUiTest` on a random email containing `pf`, unrelated to C11 (C11-N3).
- **Registered, not fixed (LOW).** C11-N1, the profile and reference-data writers; C11-N2, an idempotent 404 stored in the gap; C11-N3, the `PayrollUiTest` flake. C11-N4 records that any leave request freezes its type, for the owner.
- **Not done.** No browser walkthrough: C11 has no screen change. The guard's cost was not measured: it is one FOR SHARE row read per write.

- **Proven and published.** `eec7bb26`: CI gate run 42370545237, four shards, 11,176 cases, 0 failures, 1 skip (ESI-12), 461,795 assertions. 66 more cases than C10, and 3,839 more assertions. 16 valid mutations each failed a test, one only after the proof above was added; a 17th was invalid, because it weakened nothing. Rollback and re-apply identical on the superuser proof, and the production-shaped role in CI; `platform:verify-database` failed=0; forced RLS 252, with no new table. `ddev demo-reset` rebuilt the demo with every lifecycle state correct and no missing actor. The capability map recounts to 361 with no row changed.
- **Publication.** The reviewed work was published as one commit with an identical tree, so `2f56d46c`, whose architecture test failed, never reached `main`.

**Evidence recorded (HR.R1-C12, 2026-11-07):** the decision packages are ready and **no decision is approved**. HR.R1 stays OPEN, final assessment is not eligible, staging stays blocked, and production stays NO-GO. `HR-R1-C12-DECISION-DOCKET.md` holds the docket, `HR-R1-C12-HR4-8-DISPOSITION.md` the 82 rows, and `HR-R1-C12-EVIDENCE-APPENDIX.md` every Payroll reference, all 87 models and the runtime privileges. No code, schema, test or capability row changed.

- **Recounted, unchanged:** 361 capability rows, 61 audit identifiers, 82 HR.4–8 rows, 62 Payroll sites, 9 foreign keys, 8 Payroll SQL functions (from the live catalogue), 47 direct reads.
- **Corrected against the source:** the HR.4–8 rows were not the whole of §2 (C12-N5), and Option B would not satisfy §2 on approval alone, because four rows stay ACTIVE. HR-011 and HR-004 are located, in `docs/product/reference-parity/functions.csv`. The "25 non-Payroll files" is 24. Four HR tables need a writer register entry, not five. `0b77270c`'s green `CI gate` ran no suite: the last full suite is `eec7bb26`, which §2 will need answered when a baseline is chosen.
- **Found by applying the proposed one-writer definition to the code:** it needs named exceptions for rules 76 and 86 to work outside HR, and its point 4 fails five tables (C12-N2).
- **Found by an independent fact-check before publication, and fixed.** My first closure matrix said "Architecture agreement: Yes" on three rows with no record behind it. C12-N1 overstated the WorkforceTasks gap, C12-N3 undercounted its sites, the D-2 moves hid a 404 body that names an HR class, and the server version and one citation were wrong. It found no invented approval.
- **Not verified, and not verifiable here:** the reference-pack fingerprint.

**Evidence recorded (HR.R1-C13, 2026-11-07):** C11-N1 and C12-N1 are closed for development, and GOV-6 stays partial with every model dispositioned. No owner decision was taken: D-1 to D-10 and R1-01 stay pending. HR.R1 stays OPEN. Production stays NO-GO. `HR-R1-C13-HARDENING.md` holds the writer inventory, the 87-model matrix and the review dispositions.

- **Reproduced on `ceff7576`:** all 52 writer cases reached the write on a suspended School, and a suspended School took a document.
- **Found by the strict handler, and fixed before review.** The first model change dropped the verification reset of an edited qualification or certification, with no error in production. Nothing on `main` ever carried it.
- **Found by the independent reviews, and fixed.** The generic Documents route still wrote an Employee file on a suspended School (MEDIUM), closed in HR's port with no Documents file changed. The generation run row was written before its guard. Pins saw only `school_id`. No HIGH.
- **Found by a mutation, and fixed.** A model could leave the strict list unnoticed. The lists must now partition the discovered namespace.
- **Proven and published.** `060743c4`: CI gate run 42398199405, four shards, 11,410 cases, 0 failures, 1 skip (ESI-12), 462,457 assertions; `main` CI run 42400347210 green. 234 more cases than C11. 13 mutations each failed a test. Eight two-process races pass in both commit orders. No migration, so no rollback proof applies, and forced RLS stays 252. `platform:verify-database` read failed=0 at the start of the checkpoint. The capability map recounts to 361 with no row changed.
- **Not done.** No browser walkthrough: no screen changed. `ddev demo-reset` was not run, so the local School database was left as it was. A screen-reader pass stays outstanding.

**Publication-history recommendation to the Platform owner (not built).** `lycenza-ci publication-check` proves the candidate tip. A narrow addition would run `php -l` over the PHP files each commit in `main..candidate` changes, and refuse a range containing one that does not parse. That is the whole of it: no CI redesign. Disposition is the Platform owner's.

**Evidence recorded (HR.R1-C14, 2026-11-07):** C11-N2, C11-N3 and C12-N3 are closed for development. C14-N1, C14-N2, C14-N4, C14-N5 (partial, MEDIUM) and C14-N6 (P3, Payroll's execution-time School check) stay open with named owners. The `php -l` proposal stays a proposal. No owner decision was taken: D-1 to D-10 and R1-01 stay pending. HR.R1 stays OPEN. Production stays NO-GO. Record: `HR-R1-C14-RESIDUAL-HARDENING.md`.

**Evidence recorded (HR.R1-C15, 2026-11-08):** C14-N6 is closed for development: every Payroll writer asks the School when it runs, with rule 142 unchanged (`8132f9c7`). C15-N1, an unreproduced CI failure of a retention test outside this change, is open with Retention/Platform. C14-N1, C14-N2 and C14-N5 stay open. No owner decision was taken: D-1 to D-10 and R1-01 stay pending. HR.R1 stays OPEN. Production stays NO-GO. Record: `HR-R1-C15-PAYROLL-SCHOOL-GUARD.md`.

**Evidence recorded (HR.R1-C16, 2026-11-08): D-1 decided, §2 read, the fingerprint verified.** The HR owner chose D-1 Option B, kept the four ACTIVE rows ACTIVE, deferred the nine rows outside HR.4–8, and adopted this reading of §2's first precondition: **a slice is published when what it delivered is published and each unresolved row of its phase carries a gate or an owner's DEFERRED record in `HR-REFERENCE-CAPABILITY-MAP.md`.** The text of §2 is not edited. Under that reading the first precondition is still **not met**: HR.4 (D14-08, D14-09) and HR.7 (D8-10, D8-13) hold ACTIVE rows. The second precondition is met as of today: the pack fingerprint was recomputed exactly (map §1). The third is not: no HR.R1 baseline SHA is selected, so no full regression on one exists. D-2 to D-10 and R1-01 stay pending. HR.R1 stays OPEN. Production stays NO-GO. Record: `HR-R1-C16-D1-DECISION.md`.

**Evidence recorded (HR.R1-C17, 2026-11-08): S-6 decided, WO-6 specified, nothing built.** The HR owner set the lock (School-local calendar month, `hr.staff_attendance.lock`, no reopening, assured corrections only) and Security, with HR, set its assurance (fresh MFA to lock and approve, a second lock-key holder, authority re-read under the lock, and a fresh re-read on open-month corrections). D8-10 and D8-13 stay ACTIVE until WO-6 ships, so §2's first precondition is still not met. No baseline is selected. HR.R1 stays OPEN. Production stays NO-GO. Record: `HR-R1-C17-S6-DECISION.md`.

**Evidence recorded (HR.R1-C18, 2026-11-08): WO-6 built, D8-10 and D8-13 resolved for development.** On the HR owner's authorization (requester = `hr.staff_attendance.manage` holders; a one-holder School fails closed), the month lock shipped exactly as S-6 decided: an ended School-local month locked once by `hr.staff_attendance.lock` with fresh MFA, never reopened; locked-month changes only by another lock-key holder's approval, applied by the database (rule 147). D8-10 moved to ALT and D8-13 to IMP in the map, so HR.7 holds no ACTIVE row. §2's first precondition is **still not met**: HR.4's D14-08 and D14-09 stay ACTIVE (WO-5, after D-6). Payroll calculation admission and every retention period are unchanged. Independent review: no HIGH; four MEDIUM fixed. Published `26ace7b3`: CI gate run 42480517599, 11,520 cases, 0 failures, 1 skip (ESI-12), 464,002 assertions. C15-N1 recurred twice and is raised to MEDIUM with Retention/Platform. No baseline is selected. HR.R1 stays OPEN. Production stays NO-GO. Record: `HR-R1-C18-WO6-MONTH-LOCK.md`.

**Evidence recorded (HR.R1-C19, 2026-11-08): C15-N1 closed for development.** It was a real Retention defect, not a flaky test: 14 batched retention functions selected their batch with a `LIMIT` subquery that PostgreSQL could rescan, so one call deleted more than its batch and `RetentionExpiry::run()` stopped with eligible rows left (under-deletion only; never an ineligible, held or cross-School row). Each batch is now evaluated once, patched in place (ADR 0066 §16, rule 148). The failing assertion is unchanged; `RetentionBatchBoundTest` reproduces CI's exact output on the old functions. No retention period, hold or E21 determination changed. Published `53b32960`: CI gate run 42490957488, 11,535 cases, 0 failures, 1 skip (ESI-12), 461,215 assertions, every test shard green on the first attempt; the supply-chain job alone failed once on its Python lock step and passed on re-run (C19-N2, Platform). C19-N1 (LOW) is open with Communications/Retention. The C18 acceptance debt is tracked as C18-A1 to C18-A14, and the ended-month-only rule awaits the HR owner. D14-08 and D14-09 stay ACTIVE. No baseline is selected. HR.R1 stays OPEN. Production stays NO-GO. Record: `HR-R1-C19-RETENTION-BATCH-BOUND.md`.

**Evidence recorded (HR.R1-C20, 2026-11-08): WO-6 acceptance evidence.** Of C18-A1 to A14, 12 are closed: the three races (register versus lock, decision versus a later write, Payroll capture versus a locked-month correction, each both orders in two processes), stale/reused/borrowed MFA, REPEATABLE READ on the requests table, the database-clock branch, the API refusal, the malformed-id policy, a real browser walkthrough and axe in local DDEV (owner-authorized; October 2026 stays locked there), and measured performance and contention with no approved target. `platform:verify-database` now pins the month-lock backstops (`staff_attendance_month_lock_invariants`). One axe defect on the month page was fixed. A12, the manual screen-reader pass, is PENDING. No S-6 invariant was found broken. The current-month policy still awaits the HR owner. Published `d8ca71ae`: CI gate run 42503131458, green on the first attempt, 11,550 cases, 0 failures, 1 skip (ESI-12), 464,301 assertions. C20-N1 (Compliance UI) and C20-N2 (observation) are open. D14-08 and D14-09 stay ACTIVE. No baseline is selected. HR.R1 stays OPEN. Production stays NO-GO. Record: `HR-R1-C20-WO6-ACCEPTANCE.md`.

**Evidence recorded (HR.R1-C21, 2026-11-08): D-6 decided, nothing built.** The HR owner chose effective-date calendar history: an append-only working-week version and a holiday lifecycle, history starting the day WO-5 ships (earlier dates read "not recorded", never today's calendar), past-dated changes only into months whose Staff Attendance is not locked with a closed reason and fresh MFA (Security with HR), effective-dated publication with no drafts, and a read-only Leave policy as-of reader. WO-5 is authorized as the next checkpoint and specified (`HR-R1-C21-D6-DECISION.md` §6). The owner also confirmed current-month locking Option A, which matches the code. D14-08 and D14-09 stay ACTIVE, and S-16, LVA-3 and R1-03 stay open until WO-5 ships; whether "not recorded" before the history start satisfies R1-03 is a closure-time reading. No historical import is authorized, and no Legal or DPO matter changed. No baseline is selected. HR.R1 stays OPEN. Production stays NO-GO. Record: `HR-R1-C21-D6-DECISION.md`.

**Evidence recorded (HR.R1-C22, 2026-11-09): WO-5 built, D14-08 and D14-09 resolved for development.** On the HR owner's authorization D6-7, the staff calendar became effective-dated history (rule 149, ADR 0065 §33).

- **What shipped:** an append-only working-week version; a holiday lifecycle (withdrawn, never deleted; runtime DELETE revoked, S-16 closed); one as-of reader that every consumer uses; `LeavePolicyAsOf`.
- **Before the history start**, every date reads "not recorded". Leave refuses it, Staff Attendance refuses and labels it, and Payroll's capture is `input_incomplete` (contract v2).
- **Past-dated changes** are corrections: capability, closed reason and fresh MFA, never into a locked month. Nothing is backfilled.
- **Map:** D14-08 moved to ALT and D14-09 to IMP, so no row of the map is ACTIVE or MISSING. LVA-3 is fixed.
- **R1-03** is answered for the calendar and the policy from each School's history start. Whether "not recorded" before it satisfies R1-03 is a closure-time reading (C21 §5).
- **Independent review:** no HIGH. One MEDIUM (a first-week backfill) and four LOW were fixed. A targeted review of the fixed state found no HIGH or MEDIUM and four LOWs, C22-N1 to N4, carried in the record.
- **Published `337631b7`:** CI gate run 42586811898, 11,592 cases, 0 failures, 1 skip (ESI-12), 466,313 assertions.
- **DDEV:** forward-migrated, its October 2026 lock and Staff Attendance history byte-identical.
- **Still open:** C18-A12 (screen reader) and the same-person Identity MEDIUM. No baseline is selected. HR.R1 stays OPEN. Production stays NO-GO.

**Evidence recorded (HR.R1-C23, 2026-11-11): D-2 decided, nothing built.** Architecture chose D-2 Option B as docketed (`HR-R1-C23-D2-DECISION.md`).

- **Re-verified at `a3f36cd2`:** 62 PHP sites, 9 FKs and 8 Payroll SQL functions, the same as C12; only line numbers moved. Payroll writes no HR table.
- **Approved:** a per-site register: 22 reader moves, 27 narrow exceptions, 13 no change, 9 structural FKs, 8 functions (one SECURITY DEFINER retention function the runtime role cannot execute). The 404 stays, without the HR class name.
- **WO-3 implements it**, as a separate checkpoint. R1-02 is not closed: WO-3, X-1 and the 24 non-Payroll files remain.
- **The HR owner accepted the four C22 deviations.** C22-N4 was re-examined: approval never reads HRX evidence, so no stale calculation is approved and there is no financial-integrity defect. It stays LOW.
- **Unchanged:** R1-01, R1-09, §1, §4, every Legal, DPO and Security gate, the capability map (361 rows), the 61 audit identifiers, C18-A12, C14-N5 and the same-person Identity MEDIUM. No baseline is selected. HR.R1 stays OPEN.

Record: `HR-R1-C22-WO5-CALENDAR-HISTORY.md`.

**Evidence recorded (HR.R1-C24, 2026-11-11): WO-3 implemented for development.** The Payroll part of D-2 ships (`HR-R1-C24-WO3-READER-MIGRATION.md`).

- **Moved:** the 22 register sites read through `EmploymentRoster::labels()`/`span()`. `listAssignments()` takes an id (§3.5), so the split is 22 / 26 / 12, D2-N09 removed.
- **The 404:** the nine statutory API sites answer one fixed body for an absent and another School's id; no HR class, table or id.
- **Pinned:** `PayrollHrBoundaryRegisterTest` (register per file, 9 keys, 8 functions with security mode, raw-role isolation) and `HrWriterBoundaryTest::payroll_never_writes_an_hr_table()`. Planted violations failed both.
- **Unchanged:** every calculation, approval, posting, reversal, correction and retention path; no migration, grant or function. Export bytes identical before and after.
- **R1-02 stays open** for X-1 and the 24 non-Payroll files. R1-01, R1-09, §1, §4, every Legal, DPO and Security gate, the capability map (361 rows) and the 61 audit identifiers are unchanged. No baseline is selected. HR.R1 stays OPEN.

**Evidence recorded (HR.R1-C25, 2026-11-11): R1-01 decided, nothing built.** Architecture adopted the ten-point one-writer definition with the docket's refinements (`HR-R1-C25-R1-01-DECISION.md`).

- **Re-verified at `2f5227ec`:** 0 foreign-domain, 0 controller and 0 duplicate writers to the HR family; four named multi-writer tables, each pinned; one shared runtime role, stated as a limitation, with no per-module credentials adopted.
- **Decided:** point 4 stands (WO-11 revokes four unused DELETE grants); `setPrimary()` stays the named primary-correction writer, with C10-N2 left open; the C24 export query deviation is accepted.
- **Register:** writer categories, rules 76 and 86 as the only cross-domain writers, triggers and retention definers by name.
- **Delta review:** the C24 post-review fixes were independently reviewed. No HIGH or MEDIUM; new LOW C25-N1 (guard blind spots).
- **R1-01 enforcement is PARTIAL** until WO-11 and WO-12 ship. R1-02 stays PARTIAL. R1-09, §1, §4, every Legal, DPO and Security gate, the capability map (361 rows) and the 61 audit identifiers are unchanged. No baseline is selected. HR.R1 stays OPEN.

**Evidence recorded (HR.R1-C35 WO-19, 2026-11-13): LVA-4 and S-7 closed for development on publication; D-4 built; no open MAJOR.**
- **Built:** fresh MFA on Leave allocation and allocation runs (web, API and in the service; adjustment and opening now service-required too); a fresh authority read inside every Leave ledger and accrual write transaction (allocate, run, adjust, opening, accrual start and batch, year close), once per transaction.
- **Proof:** reproduced at `5cbc6abd` (seven entry points wrote behind a revoked grant; allocation and run wrote without a code); allow and deny for each loss of authority; two-process races; six planted bypasses detected.
- **Reviewed:** independent Security review, no HIGH or MEDIUM; LOWs WO19-N1 to N4 recorded.
- **Still open:** WO-20 to WO-22 and the other P3/LOW dispositions before baseline selection. HR.R1 stays OPEN (`HR-R1-C35-WO19-LEAVE-WRITE-ASSURANCE.md`).

**Evidence recorded (HR.R1-C35 WO17-N1, 2026-11-13): WO17-N1 and its author side closed for development on publication; no open MAJOR.**
- **Built:** after a decision, an approver known to be the paid Employee (D-WO18-1) or the change's author (D-WO17N1-1) is treated like their own account: attestation before posting, an obligation once posted, never a discharge.
- **Scale:** per approver, never per row: 414 ms at 10,000 entries against over 30 s per row.
- **Reviewed:** two independent reviews (no HIGH; the first's MEDIUMs fixed, the second's LOWs fixed).
- **Still open:** WO-19 to WO-22 and the other P3/LOW dispositions before baseline selection. HR.R1 stays OPEN (`HR-R1-C35-WO17-N1-POST-APPROVAL-INDEPENDENCE.md`).

**Evidence recorded (HR.R1-C35 WO-18, 2026-11-13): C34-N3, C34-N5 and C34-N2 closed for development on publication; WO17-N1 rated MAJOR.**
- **Built:** "never one's own" is person-level for every separation write and administrative leave withdraw/cancel, in service and database (OD-HR-25 amended for HR).
- **Reproduced first:** 8 of 8 new behaviour tests failed before the fix.
- **Reviewed:** independent review, no HIGH or MEDIUM; its LOWs fixed.
- **WO17-N1:** reproduced (a run that, after a reversal, pays its approver's own raise first posts without attestation when a second account approved it); the owner rated it MEDIUM and authorized Option A as a separate unit. **HR.R1 is NOT CLOSED until it publishes** (`HR-R1-C35-WO18-OWN-CASE.md`).

**Evidence recorded (HR.R1-C35 WO-17, 2026-11-13): C34-N1 and C34-N4 closed for development on publication.**
- **Reproduced:** 4 of 8 new tests failed before the fix.
- **Fixed:** the self-beneficiary refusal now matches a known-same account at approval, attestation and review, in service and database, at no extra query. Nine guard triggers are pinned by definition, and the pinned functions by body, mode and path.
- **Reviewed:** independent review, no HIGH or MEDIUM. New LOW WO17-N1 (post-decision independence by known-same accounts, for an owner decision) and WO17-N2.
- **No open MAJOR remains.** WO-18 to WO-22 and the other P3/LOW dispositions still stand before baseline selection. HR.R1 stays OPEN (`HR-R1-C35-WO17-KNOWN-SAME-BENEFICIARY.md`).

**Decisions recorded (HR.R1-C35, 2026-11-13): the seven P3s dispositioned; C34-N1 rated MAJOR. Nothing built.**
- **Seven P3s:** HRC-4, HRC-8, LVA-4 (D-4) and TRR-6 approved for implementation (WO-20, WO-21, WO-19, WO-22); XRP-3 deferred to a TeachingAssignments checkpoint; XRP-4 and TRR-8 accepted (documented).
- **C34-N1** reproduced (a known-same account of a paid Employee approves the run that pays that Employee's change first) and rated **MEDIUM by the owner: MAJOR under C27-C, so HR.R1 is NOT CLOSED** until WO-17 publishes.
- **C34-N3:** extend "never one's own" person-level (WO-18, amends OD-HR-25 for HR).
- **Other open P3/LOW findings:** each dispositioned before baseline selection.
- **Independent review:** no HIGH; its MEDIUMs acted on before the decisions; new LOW C34-N4, C34-N5.
- **Unchanged:** RG-ID-1, C33-H1 to H5, R1-02 PARTIAL, every gate, 361 capability rows, 61 audit identifiers. No baseline selected (`HR-R1-C35-P3-DISPOSITIONS.md`).

**Evidence recorded (HR.R1-C34, 2026-11-12): WO-16 built; the Identity MEDIUM's development part closes on publication.**
- **Reproduced before the fix:** 14 of the 16 new tests failed because each prohibited decision was accepted, including a requester's own replaced account approving their own leave and separation.
- **Built:** one relation (`HrSelfAdministrationGuard::knownSamePersonAccounts()` and the SQL `employee_account_link_same_person_accounts()`); refusals in service and database for Payroll approval, posting attestation and reconciliation review, Leave approve/reject and separation approve/reject; registry declarations; `known_same_person_guards` in `platform:verify-database` with whole-body hashes.
- **Reviewed:** independent Security review, no HIGH or MEDIUM; L-1 and I-3 fixed, L-2 fixed in part.
- **Recorded:** C34-N1 (LOW), C34-N2, C34-N3 (INFO).
- **Unchanged:** RG-ID-1 open; C33-H1 to H5; the seven P3 findings; R1-02 PARTIAL; every Legal, DPO and Security gate; the capability map (361 rows); the 61 audit identifiers. No baseline is selected. HR.R1 stays OPEN (`HR-R1-C34-WO16-KNOWN-SAME-PERSON.md`).

**Decision recorded (HR.R1-C33, 2026-11-12): person-level identity decided, nothing built.**
- **Reproduced on `69ef41c0`:** one person's second, known-same account approved a run their first account prepared, because the shared maker/checker compares account ids only.
- **Decided by the Identity and Security owner:**
  - known-same-person refusals in the HR family and Payroll, specified as WO-16 (not built), with the five other domains' workflows handed to their owners;
  - the unknown residual recorded, with a School's out-of-band one-account-per-person assertion before real Payroll (RG-ID-1);
  - evaluation at the decision, never retroactive.
- **Still blocking under C27-C:** the Identity MEDIUM's development part, until WO-16 is published. Unchanged: R1-02 PARTIAL, every Legal, DPO and Security gate, the capability map (361 rows), the 61 audit identifiers. No baseline is selected. HR.R1 stays OPEN (`HR-R1-C33-IDENTITY-INDEPENDENCE-DECISION.md`).

**Evidence recorded (HR.R1-C32, 2026-11-12): WO-9 built; LVA-1 and TRR-2 closed for development.** A read-only status per leave type and month, from the recorded runs and the canonical evaluation, in the accrual workspace and `leave:accrual-status`; accrual stays manual (`HR-R1-C32-WO9-ACCRUAL-STATUS.md`).

- **Proven:** two real sessions (a held first run is invisible and reads `missed`; a committed `processing` run reads `running` without waiting), a backdated employment reads `incomplete`, School-local boundaries for both timings, and reading writes nothing.
- **Reviewed:** an independent review (no HIGH; MEDIUM-1, the report losing the previous unclosed year at rollover, fixed) and a delta review.
- **Blocking under C27-C after C32:** the person-level Identity MEDIUM. R1-02 stays PARTIAL. Every Legal, DPO and Security gate, the capability map (361 rows) and the 61 audit identifiers are unchanged. No baseline is selected. HR.R1 stays OPEN.

**Decision recorded (HR.R1-C31, 2026-11-12): D-3 decided, nothing built.** The HR owner chose option 3: accrual stays manual, and a read-only status (WO-9) will show a missed, incomplete, failed or running month from the run records and the canonical read-only evaluation, in the accrual workspace and a console report, with no alerts (`HR-R1-C31-D3-ACCRUAL-DETECTION-DECISION.md`).

- **LVA-1/TRR-2** stay MAJOR under C27-C until WO-9 is published. With the person-level Identity MEDIUM, they are the remaining development-scope blockers.
- Unchanged: R1-02 PARTIAL, every Legal, DPO and Security gate, the capability map (361 rows), the 61 audit identifiers. No baseline is selected. HR.R1 stays OPEN.

**Evidence recorded (HR.R1-C30, 2026-11-12): C14-N2 closed for development.** The Platform owner decided C30-A/B: a rendered 5xx on an idempotent route is released only when the request provably committed nothing; after a committed effect it stays remembered and is never re-executed (`HR-R1-C30-IDEMPOTENCY-SERVER-ERRORS.md`).

- **Reproduced first** on `506c6c62`, then proven on real routes with real server-side failures and with two real processes under a deterministic barrier.
- **Reviewed:** an independent review (no HIGH; MEDIUM-1, a lost acknowledgement read as "nothing committed", fixed), a delta review and a final-state recheck.
- **Blocking under C27-C after C30:** LVA-1/TRR-2 (D-3) and the person-level Identity MEDIUM. R1-02 stays PARTIAL. Every Legal, DPO and Security gate, the capability map (361 rows) and the 61 audit identifiers are unchanged. No baseline is selected. HR.R1 stays OPEN.

**Evidence recorded (HR.R1-C29, 2026-11-12): C14-N5 closed for development.** The owner decided C29-A/B/C (HR with Security): a verification names a database-maintained revision and is refused for any other; every decision is an append-only row whose insert alone sets the status; legacy statuses are shown unbound (`HR-R1-C29-CREDENTIAL-VERIFICATION.md`).

- **Reproduced first** on `5be4f9ad` (the C14 race pin and a stale page), then refused in both commit orders with two real sessions.
- **Reviewed:** an independent Security review (no HIGH; MEDIUM-1, a supplied decision order, fixed) and a delta review of the fixes.
- **Blocking under C27-C after C29:** C14-N2, LVA-1/TRR-2 (D-3), the person-level Identity MEDIUM. R1-02 stays PARTIAL. Every Legal, DPO and Security gate, the capability map (361 rows) and the 61 audit identifiers are unchanged. No baseline is selected. HR.R1 stays OPEN.

**Readings recorded (HR.R1-C27, 2026-11-12; `HR-R1-C27-CLOSURE-INTERPRETATIONS.md` §2).** The text above is unchanged; these are the HR owner's readings of it.
- **§1 (C27-B):** met when each of the eight connections has one recorded status: INTEGRATED (with a test), DEFERRED BY OWNER, or NOT BUILT (with a named owner). At C27: Organization, Transport, Timetable/Academic, IAM/security and retention/audit INTEGRATED (development); Finance/Payroll INTEGRATED (Payroll) and DEFERRED (Finance staff dues); Inventory DEFERRED; SIS NOT BUILT (owners SIS + HR, Fees).
- **§4 (C27-C):** P1/HIGH = BLOCKER, P2/MEDIUM = MAJOR, for development and architecture findings. Legal, staging and production items, and owner-DEFERRED items, do not fail the verdict if recorded, unchanged and refused; the verdict then reads "PASS for development scope; production not addressed". Blocking at C27: C14-N2, C14-N5, LVA-1/TRR-2 (D-3), the person-level Identity MEDIUM.
- **R1-09 (C27-A):** each owner gives one recorded answer; NOT_INTEGRATED is shown, never CLEAR and never blocking. Library, Inventory and Finance are owner-deferred residuals. "Timetable substitution" is read as Timetable's answer about its entries: an amendment, since no substitution concept exists.
- **Prerequisite (C27-D, Architecture):** WO-13 (C22-N1) and C26-N1, with C27-N1/N3, before the final assessment.
