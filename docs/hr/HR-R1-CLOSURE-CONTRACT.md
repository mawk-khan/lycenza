# HR.R1 — Final Cross-ERP Reconciliation & Closure Audit (reserved)

Status: **RESERVED by HR.0A** (2026-11-03). Not started. **Never
auto-start.**

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
| PAY-17 | LOW | Corrections on a reversed run: the target check in `createCorrectionRun()` is unlocked and outside the transaction, the database trigger checks status only, and a correction can be approved and posted after its target is reversed. Pre-existing; not an attribution defect. | OPEN |

The final-candidate reviews also found raw-SQL routes around the corrected rule, all fixed in HR.R1-C3: a run could be reclassified to skip the guard's author and freshness checks, INSERTed already approved, or have its creator changed (`trg_payroll_runs_identity`), and a decided run could have no approver, counting as independent of every author (`payroll_runs_decided_has_approver`). Who acted stays an application boundary (rule 102).

**PAY-15 evidence (2026-11-06), CORRECTED by HR.R1-C5.** This paragraph first said the documented migration role is `NOBYPASSRLS`. That was wrong: the line describes `school_os_app`. See the HR.R1-C5 evidence below.

### Findings registered from the HR.R1-C2 review (2026-11-06)

Registered, not implemented. Severity is the reviewer's.

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
- **Measured, no SLA set.** At 10,000 entries with 20,000 link rows, on the local DDEV database: current link and as-of lookup 0.4 ms, first-paid set 32 ms, self-beneficiary 50 ms, attestation version 50 ms, reconciliation version 125 ms, affected runs 184 ms, a correction 43 ms, and a reversal recording two obligations 1.4 s under the exclusive lock.
- **Link against calculation.** Tested in both orders. Provenance reads compensation, never identity, so sources and the attribution version do not move, and the attribution is read at the decision.
- **Legacy.** Cutover rows state only what was known at cutover, and an Employee with no row stays unknown. An unlink before cutover cannot be recovered. PARTIAL by nature, recorded in OD-HR-25 row 19.
- **Found by the final-state review, and fixed.** One person with two linked accounts could correct one using the other, so a correction is now refused to any account ever linked to that Employee, in the service and the database. A second account that was never linked cannot be detected: recorded as a MEDIUM in OD-HR-25's known limits, with its readiness consequence. Pay authoring still read the current link, so after an unlink a person could record their own raise, override or delta: `HrSelfAdministrationGuard::isEmployeeOf()` now decides those three writes. This applies OD-HR-25 to rule 95, and is called out for the owner because the owner's choice named the decisions. The cutover now locks `employees`, the correction function takes the compensation lock, and a decision records `deciderHadCorrectedLink`.
- **Proven and published.** `fc7fcffe`: CI gate run 42288793688, four shards, 10,911 cases, 0 failures, 1 skip (ESI-12), 456,708 assertions. 93 new cases from 87 test methods, 15 two-process races with the contender observed blocked, 2 more against User erasure, 9 through the real prune and erasure commands. Rollback and re-apply identical, on the superuser proof and the production-shaped role. Mutations of the identity clauses failed 18 and 4 tests. A real logged-in walkthrough on the final code passed 25 of 25, with axe clean on 7 pages. Screen-reader testing by a person stays an open evidence gap.
- **Publication history.** `a1c32780` (HR.R1-C6) does not parse: a duplicate import, corrected by `355f8de8`. It reached `main` inside a fast-forward range. The exact-SHA gate proves each published tip, never each commit inside a range. No CI run, tag or release names it, and every published tip was green. History is not rewritten. A cherry-pick of `a1c32780` must take `355f8de8` with it. Proposal for the Platform owner, not built here: `publication-check` lints each commit of `main..candidate`, or refuses a range that has one that does not parse.
- **Assertion count.** C6 reported 107 fewer assertions than C5 with 68 more tests. Compared per test across both CI JUnit sets: no test was removed, and no test file behind the drop changed. −2,251 comes from 11 classes whose counts depend on timing or on rows present: ten polling concurrency tests and `PlatformAuditLogReviewTest` (a loop over the audit rows in its shard). +2,144 is the new tests. No security assertion was weakened.
