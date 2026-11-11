# HR.R1-C12: owner decision docket (2026-11-07)

**This docket decides nothing.** Every item is PENDING unless §13 records an approval with its authority and date, and §13 records none. A recommendation is not an approval, an approval is not an implementation, and an implementation is not production authorization.

Baseline `0b77270c`, the tip of `main`, with `CI gate` green on main run 42372422936. That run is a documentation lane and ran no suite. The last full suite is `eec7bb26`, and the one commit between changed documents only. `HR-R1-CLOSURE-CONTRACT.md` stays the authority, and this record does not reword it.

| Companion | Holds |
|---|---|
| `HR-R1-C12-HR4-8-DISPOSITION.md` | The 82-row HR.4–8 table |
| `HR-R1-C12-EVIDENCE-APPENDIX.md` | Every Payroll reference, foreign key and SQL function; all 87 models; runtime privileges; the School-check writers |
| `HR-R1-C10-ARCHITECTURE-INVENTORY.md`, `HR-R1-C10-EVIDENCE-INVENTORY.md`, `HR-R1-C11-HARDENING.md` | Earlier evidence, not repeated here |

**Statuses used:** PROPOSED, RECOMMENDED, APPROVED, REJECTED, PENDING CLARIFICATION, GATED, IMPLEMENTED, VERIFIED. Each is a separate state.

## 1. Authority register

The repository names roles, not people. Earlier records say "the owner". If one person holds several of these roles, each decision should say which role decided it.

| Authority | Decides here | Cannot decide |
|---|---|---|
| HR owner | D-1, D-3, D-6, HRC-4, HRC-8, TRR-8, S-6, C11-N4, §1, §4, R1-09, baselines | Legal gates |
| Architecture | D-2, R1-01, TRR-6, closure-level definitions | Product scope |
| Security | D-4, the generic upload (with HR), S-6 assurance (with HR), S-7, same-person (with Identity) | Legal gates |
| Identity | Same-person assurance | Payroll policy |
| Product | D-7, D-9 (with the ADR 0040 owner) | Legal gates |
| Legal, DPO | E21-D9, E45, E47, HRX-L1–L4, D-10, OD-HR-25 legal review | Nothing here is theirs to delegate |
| Platform | Per-commit publication | Product scope |

**Nothing in this checkpoint can approve a Legal, DPO or Security determination.** Sending a legal question is a real external communication (rule 16), so it is the owner's own act.

## 2. Decision record

Selected option, approving authority, approval date and source of authorization are **none** for every row. No owner decision changed after C11: the last change to the gate register is `0b77270c` itself, which recorded no approval.

| Id | Question | Authority | Scope if approved | Conditions | Development | Staging | Production | Re-review trigger | Related |
|---|---|---|---|---|---|---|---|---|---|
| D-1 | How §2 is met for HR.4–8 | HR owner | The 82 rows | Gates stay shut | A map section, a roadmap amendment, no code | None | NO-GO | OD-HR-14 decided; HRX-L2b decided | S-6, S-16, D8-10, GOV-1 |
| D-2 | Resolve or except Payroll's coupling | Architecture | Payroll and Statutory | No HR write; protected functions untouched | ADR 0034 amendment, pin, reader moves | None | NO-GO | A new Payroll HR reference; a change to `employment_records` | R1-02, C10-N5 |
| R1-01 | What "exactly one writer" means | Architecture | HR tables, usable elsewhere | §5's refinements | ADR, register entries | None | NO-GO | A new table with two writers | C10-N2, C12-N2 |
| D-3 | Accrual manual or scheduled | HR owner | Leave accrual | No automatic posting | None if manual | An operator step | Operator procedure | First real School month | LVA-1, TRR-2 |
| D-4 | Which Leave writes take fresh MFA | Security | Allocation | Existing mechanism only | Web, API, OpenAPI, tests | Enrolled factor needed | Enrolled factor needed | HRX-L4 activation | S-7, LVA-4 |
| D-6 | Calendar history | HR owner | Staff calendar | No invented history, no WorkSchedule | Its own slice | Harmless on synthetic data | Must precede real data | First real data; HRX-L4 | LVA-3, S-16, R1-03, R1-08 |

## 3. D-1: HR.4–8 scope

**Question.** Record HR.4–8 as DEFERRED in `HR-REFERENCE-CAPABILITY-MAP.md`, as contract §2 requires, or say how else §2 is met. A row in the gate register alone does not satisfy the sentence.

- **Current implementation.** Not built, design-only. No owner DEFERRED record exists. Daily half-day attendance is built and is not part of this.
- **Existing architecture.** HR.4 needs OD-HR-14 (L2a) and an ADR 0065 reopening. HR.8 needs HRX-L2b. HRX-L2 is **not split**: OD-HR-14 is direction, not a decision.
- **Problem.** §2 needs each slice published or deferred by the owner.
- **Options.** A: blanket deferral. B: partial, deferring gated rows and keeping calendar history and the attendance lock open. C: decide OD-HR-14 and build L2a. D: amend §2 with a "gate-held" state.
- **Recommendation.** **B.** A blanket deferral silently drops D8-10, S-6 and S-16.
- **Consequences.** A map section, and a roadmap amendment splitting HR.4 and HR.7 and fixing the RM:315 dependency. No code. Row-level proposal: HELD 61, ACTIVE 4, DEFERRED 10, KEEP 5, KEEP (DEF) 2.
- **Risks.** A deferral could be read as opening or closing a gate. It does neither.
- **Authority.** HR owner. Legal and Security stay on L2b.
- **Acceptance.** The eight conditions below.
- **Readiness.** Gives the 61 HELD and 10 DEFERRED rows their record. **Option B does not satisfy §2 on approval alone:** the 4 ACTIVE rows have no gate and no deferral, so HR.4 and HR.7 stay unresolved until WO-5 and WO-6 ship or the owner defers them. Does not touch R1-10.

**The count needs care.** Of 82 rows, 61 are GATE and 21 are not: 20 have no gate, and D8-06 is held by ADR 0065 §7. "12 ungated" is the HR.4 share only. HR.7 has 7 more and HR.6 has 1.

**HR-011 and HR-004 are located.** The map cites them on D8-03 and D8-14, and they are not in `docs/hr`. They are rows of `docs/product/reference-parity/functions.csv`, both `OWNER_DECISION_REQUIRED`, and neither carries a legal gate. So the owner is being asked a real open question, not one lost in the records. HR-011 would be a rule 102 workflow. HR-004 would reopen the ADR 0065 attendance evidence model.

**Preserved by every option.** HRX-L2. OD-HR-14 stays direction only. Staff Attendance stays date and half-day. Teacher attendance stays the E33 distinction: it concerns Student attendance, never staff. Academic ownership stays with TeachingAssignments. Approved absence and Leave controls do not change. **No generic deferral authorizes biometric, clock-time, shift or WorkSchedule processing.**

| Decision-complete when | Today |
|---|---|
| The owner explicitly selects a permitted option | **Pending** |
| All affected rows have a proposed disposition | Ready |
| The 12 ungated HR.4 rows are individually addressed | Ready |
| S-6, S-16 and D8-10 stay visible | Ready |
| Existing implementation stays accurately classified | Ready: every status matches the map |
| Future phases are identified | Ready |
| No legal gate is waived | Ready |
| Recorded with date and authority | **Pending** |

**Status: PENDING.** No capability status changes before approval.

**Decided 2026-11-08 (HR.R1-C16), by the HR owner:** Option B; the four ACTIVE rows stay ACTIVE (WO-5, WO-6); the nine rows outside HR.4–8 are DEFERRED by owner record; the proposed reading of "published" below is adopted. `HR-R1-C16-D1-DECISION.md`. Legal and Security stay on L2b, and no gate opened.

### D-1 alone does not satisfy §2

§2 covers HR.1–HR.16, and D-1 covers HR.4–8. HR.11, HR.12 and HR.15 are recorded as PARTIAL, and HR.1A, HR.2A, HR.3A, HR.9 and HR.10 carry their own "not built" lists in the roadmap. Whether such a slice counts as "published" is not stated. **17 PART or MISS rows sit outside HR.4–8.** This was not in the C10 or C11 packages.

| Rows | Count | State |
|---|---:|---|
| D7-15 (HRX-L4), D10-16 (OD-HR-15), D10-19, D12-03 (OD-HR-17), D11-06 (OD-HR-16), D18-15 (rule 105, HR.15 deferral), D20-11, D20-26 (ADR 0040) | 8 | Each carries a gate or a recorded decision |
| D4-07 dotted-line manager (HR.3) | 1 | **No gate and no deferral** |
| D1-07, D18-03, D18-04 probation and confirmation (HR.12). HR-090 is `OWNER_DECISION_REQUIRED` in `functions.csv` | 3 | **No gate and no deferral** |
| D18-17 relieving and clearance documents (HR.15) | 1 | **No gate.** The clearance half is deferred, the document half is not |
| D19-07, D19-14, D19-15, D19-16 task relationships and targets, phase "later" | 4 | **No gate and no deferral** |

**Proposed reading:** a slice is published when what it delivered is published and each unresolved row of its phase carries a gate or an owner's DEFERRED record in the map. The HR owner then decides those nine rows. Comp-off, permissions and special windows need nothing more: the map already records them as DEF under OD-HR-18 to 20.

## 4. D-2: Payroll to HR boundary

**Question.** Resolve Payroll's `EmploymentRecord` coupling, or record an owner-approved exception (R1-02, third conjunct).

- **Current implementation.** 62 executing PHP sites: direct read 25, eligibility 14, calculation 7, relationship 12, infrastructure 4, **write 0, historical 0**. 9 foreign keys. 8 Payroll-owned SQL functions. All re-verified at the baseline, the functions from the live catalogue. Imports are 25 under `app/Domain/Payroll` plus 3 App controllers.
- **Existing architecture.** ADR 0034:43 and `PAYROLL.md:97` say Payroll reads HR through its Application layer. Most reads do not (C10-N5). ADR 0034:96,106 does approve the key and the lock.
- **Problem.** The decision was assigned to HR.15 and never taken.
- **Options.** A: exception for all. B: narrow exception plus readers. C: a new HR reader for all PHP. FKs and SQL functions need an exception under every option.
- **Recommendation.** **B.** HR owns Employee and Employment. Payroll owns calculation, approval, posting and financial evidence. Payroll consumes stable HR facts through contracted readers or narrowly approved structures. **Payroll never writes HR.**
- **Consequences.** An ADR 0034 amendment, a guard pinning the excepted list, and 22 reader moves. No schema change and no new reader.
- **Risks.** Touching calculation paths could disturb PAY-2, PAY-13, PAY-16, PAY-18, PAY-19 or C7. The contract below leaves them alone.
- **Authority.** Architecture.
- **Acceptance.** The nine conditions below.
- **Readiness.** Answers only R1-02's third conjunct. X-1 and the 24 non-Payroll files still fail the first two.

**"47 direct Employee reads" needs a correction.** It counts `Employee::query()` **and** `EmploymentRecord::query()` outside HR: 32 Payroll plus 15 others, re-verified. It is not Employee only, and "25" has meant three different things in earlier records.

### Proposed boundary

Every site is classified in `HR-R1-C12-EVIDENCE-APPENDIX.md` §A, and the rows below sum to 62.

| Reference | Sites | Proposed | Reason |
|---|---:|---|---|
| Existence | 13 | **Governed reader:** `EmploymentRoster::span() !== null` | Exists today, any status, School explicit |
| Labels on pages and exports | 9 | **Governed reader:** `labels()` | Exists today, chunked since LVA-8. One depends on a signature change, below |
| Calculation facts | 7 | **Narrow exception** | No single reader gives `id`, `employee_id` and dates. `span()`, `spans()` and `labels()` together could. The reason is risk: this is the provenance path |
| Compensation lock | 1 | **Narrow exception** | ADR 0034:106 approves it. Readers are FOR SHARE and judge status |
| Search with ended staff, own employments | 3 | **Narrow exception** | No reader, and `hr.employees.view` is the wrong authority |
| Sorted paged names | 2 | **Narrow exception** | `paged()` would change the visible order |
| Id-only reads | 14 | **Narrow exception** | 7 queries and 7 type hints, using `id` and nothing else, on a key the FK binds. Changing them all touches 12 signatures for little minimization |
| Model relations | 9 | **No change**, listed | 8 are unused in production |
| Retention | 4 | **No change** | Runs as the retention identity with column grants |
| **PHP total** | **62** | Reader 22, exception 27, no change 13. The split can shift at implementation, below | |
| Foreign keys | 9 | **Approved structural FK** | 5 RESTRICT, 4 CASCADE. History depends on the RESTRICT keys |
| SQL functions | 8 | **Narrow exception** | A PHP reader cannot run in a trigger |
| Separately authorized migration | 0 | | None is needed |
| Writes | 0 | **Prohibited** | Pinned by `HrWriterBoundaryTest` |

**The moves are not all independent.** `CompensationController:95` passes its model to `listAssignments()`, so it moves only if that method takes an id. That change would also remove the id-only site `PayrollCompensationReadService:50` and the need for the model at `CompensationAssignmentController:35`, so the totals would shift. If the signature stays, the site joins the id-only row: 21, 28 and 13.

**The 404 is the other catch.** By reading, not by a request: the 9 API existence sites answer with `No query results for model [App\Domain\HR\Infrastructure\EmploymentRecord] <id>`, through `bootstrap/app.php:299`. An `abort(404)` changes that body, and keeping it keeps the HR class name in Payroll. **Architecture should say which it accepts.** Recommended: keep the status, drop the class name.

### Readers used

Option B adds no reader. These two exist.

| Property | `span(School, id)` | `labels(School, ids)` |
|---|---|---|
| Fields | `startsOn`, `endsOn` | Employment id, Employee id, number, name, status, current, active |
| School | Explicit argument, `withSchool` | Same |
| Date | None: the row's own dates | None: current facts |
| History | Current row. Payroll's frozen results never change because a reader did | Same. A past payslip shows the current name, as today |
| Errors | Null when absent. Payroll keeps deciding the 404. Its body is the question above | Absent ids are absent |
| Transactions | Reads only, no lock. Lock order stays with its owner | Same |
| Retention | Not used on a retention path | Same |
| Privacy | Dates only | Directory tier. No personal, bank or statutory field |
| Performance | One row | Batched by 2,000 ids, never one query per row. Sorted by name, so callers key by id |
| Compatibility | Additive. No second employment state machine and no shadow master | Same |

**Protected, not touched by any move:** `payroll_run_first_paid_compensation`, `payroll_calculation_compensation_fresh`, `payroll_run_calculation_sources`, the reversal and reconciliation functions, `employee_account_links`, and every calculation path. So HR.R1-C1 provenance, PAY-2, PAY-13, PAY-16, PAY-18, PAY-19, correction and reversal integrity and the C7 history stay as they are.

| Decision-complete when | Today |
|---|---|
| Architecture approves the boundary | **Pending** |
| Every exception category has a rationale | Ready |
| Required readers have a contract | Ready |
| No shadow HR master | Ready |
| No unauthorized Payroll HR writer | Ready: zero, pinned |
| Snapshots and retention stay valid | Ready: not touched |
| Migration and regression needs are documented | Ready: no migration; Payroll suites and the C1–C8 proofs |
| XRP-3 and X-1 stay separate | Ready: §15 |
| The owner says whether implementation is a new checkpoint | **Pending.** Recommended: yes, WO-3 |

**Status: DECIDED 2026-11-11 (HR.R1-C23), Architecture: Option B as docketed; implementation is WO-3, a separate checkpoint; the 9 API existence sites keep the 404 and drop the HR class name.** `HR-R1-C23-D2-DECISION.md` holds the reconciled register (still 62 / 9 / 8). Not built: R1-02 stays open until WO-3 ships, and X-1 and the 24 non-Payroll files are not part of it.

## 5. R1-01: one writer

**Exact criterion.** "No duplicate source of truth. Every row of `HR-DOMAIN-OWNERSHIP-MAP.md` §1 has exactly one writer", method "grep writers per table; architecture guards".

**The ten-point proposal, exact** (`HR-R1-C10-ARCHITECTURE-INVENTORY.md` §1.8). A tenant table satisfies one-writer when all of these hold:

1. **Canonical domain owner.** Exactly one bounded context owns the table. No other domain's code issues a write to it.
2. **Entry points invoking one service.** Controllers, API, console, imports and other services may trigger a write only by calling the owner's Application service. Any number of entry points is allowed. A controller, job or import never writes the table itself.
3. **Named operations.** Normally one Application class writes a table. A second class is allowed only if the table's register names it and the operation it alone performs. The same operation in two classes is a duplicate writer.
4. **Raw SQL privileges.** `school_os_app` holds only the privileges the named operations need, enforced by revoke plus `DatabaseRoleVerifier`. A raw-SQL write inside the named class is permitted; it is still that class's write.
5. **Migration-only writes.** Schema changes and one-time backfills in a migration are not writers.
6. **Projections.** A column maintained by a trigger from another table is not a second writer. It belongs to the source table's writer, and a direct write is refused by the database (the `employment_records` terms columns).
7. **Audit and history writers.** An append-only row written in the same transaction as its parent operation, or by a trigger on the parent, belongs to the parent's writer. It needs append-only privileges, not a separate register entry.
8. **Retention.** Deletion by the retention identity or a retention function is a platform mechanism, recorded in the retention catalogue. It is not a business writer.
9. **Fixtures.** Seeders that bypass the service are excluded only if guarded to local or testing.
10. **Rows without tables.** Mechanisms, readers and unbuilt concepts are marked not applicable. They do not pass or fail.

- **Current implementation.** Foreign writes 0, controller writes 0, true duplicates 0.
- **Existing architecture.** The term has two senses. ADR 0002 says "single writer per record" for the system: Laravel writes, the AI service does not. That is a different level and stays as it is. Inside Laravel, rules 100, 105, 110, 121, 131 and 137 say "one writer" for one service, ADR 0063 pins "one writer per ownership table", and rule 128 says "one catalogue writer, two membership writers". So named operations are an accepted pattern. None of these defines the term.
- **Problem.** R1-01 cannot be evaluated literally: many §1 rows are not tables.
- **Options.** Adopt as written; adopt with the refinements below; keep the literal one-class reading, which would fail five tables that have no defect.
- **Recommendation.** **Adopt with refinements.** Principle: *every business fact has one canonical domain owner and one governed mutation contract, although several explicitly authorized service operations may change that fact through its owner.*
- **Consequences.** A short ADR, register entries for four HR tables and Payroll's `employee_esi_coverage`, a decision on C12-N2.
- **Risks.** A permissive reading could excuse a real duplicate, so point 3's "the same operation in two classes" must stay.
- **Authority.** Architecture.
- **Acceptance.** The eight conditions below. Thirteen passing tests do not close it.
- **Readiness.** R1-01 becomes evaluable, not closed.

### Testing the definition against the code

Applying the ten points to the baseline shows where they need refining.

| Case | Point | Result as written | Refinement |
|---|---|---|---|
| `workforce_task_events` | 7 vs the inventory's closing line | The inventory lists it for a register entry, but point 7 says history needs none. **Four** HR tables need entries, not five | Point 7 stands |
| `SchoolSettingsController.php:66` writes `schools` | 2 | **Fails**, though rule 76 accepts this shape for a simple entity | Name rule 76's case as an exception |
| `SchoolBootstrapAdministrationService.php:78` writes a role grant from Platform | 1 | **Fails**, though rule 86 sanctions it | Name approved cross-domain writers |
| `hr_departments`, `positions`, `employee_categories`, `hr_employee_number_counters`, `staff_working_weekdays` | 4 | **Fail:** the runtime role holds DELETE and nothing calls it (C12-N2) | Keep point 4 and revoke, or narrow it. Architecture's choice |
| `staff_holidays` | 4 | Passes as a privilege, because code deletes. S-16 and D-6 are about whether it should | None |
| `employee_account_links` | 2 | Point 2 speaks of a service, but only triggers write it | Add triggers as a category |
| `StatutoryPayrollCalculationService.php:327` writes `employee_esi_coverage` | 3 | Needs a register entry | Name it |

### Refinements proposed

1. Add the principle above as the preamble.
2. Point 3: the register is `HrWriterBoundaryTest::NAMED_WRITERS` plus `WRITE_A_LOCKED_ROW` for HR, and a table's own rule elsewhere.
3. Point 4: a privilege is not a business authorization, and a permitted UPDATE is not thereby a legitimate mutation.
4. Add **imports:** authorized only through the owner's service.
5. Add **cross-domain writers:** never, unless a rule names the exception. Rules 76 and 86 are the two known cases.
6. Add **trigger-only tables:** a table whose only writer is a trusted trigger satisfies point 2.
7. State that the definition is general. It then has to carry refinements 5 and 6.

### Writer categories

| Category | Proposed answer | Pinned today by |
|---|---|---|
| Canonical domain service | Satisfies | `a_table_with_several_writers...` |
| Alternate authorized domain operation | Satisfies if named, one operation each | `NAMED_WRITERS` |
| Trusted database trigger | Satisfies for its projection or history | Catalogue test, rule 145 |
| Migration | Not a writer | Rule 54 |
| Retention service | Platform mechanism, never a duplicate | Retention catalogue |
| Authorized import | Satisfies through the canonical service only | `EmployeeImportService`, rule 122 |
| Local demo seeder | Excluded while guarded to local | `DemoEnvironmentGuard` |
| Test fixture | Excluded | Rule 93 |
| Projection writer | Belongs to the source table's writer | `only_the_terms_trigger_writes_the_projection` |
| Cross-domain consumer | Never a writer, unless a rule names the exception | `no_foreign_domain_writes_an_owned_table` |

### Disputed writers, proposed outcomes

| Table | Writers | Proposed |
|---|---|---|
| `employee_assignments` | `EmployeeAssignmentService`, `EmploymentService` (closing at an end), `PlacementChangeService` (closing the source, through a locked row), retention | Named operations. An end's closure belongs to the end. Retention is point 8, listed in the pin because a source scan sees it |
| `employee_placement_changes` | `PlacementChangeService`, `EmployeeAssignmentService` (primary correction) | Named. The map already says so, and a test pins it. **Open:** should the correction move into `PlacementChangeService`? |
| `workforce_tasks` | `TaskGenerationService`, `TaskAssignmentService`, `TaskWorkflowService` (through a locked row) | Named operations. Rule 139 names only the generator, and the other two are named only in `HrWriterBoundaryTest`, so point 3 needs them in the register |
| `leave_policy_assignments` | `LeavePolicyAssignmentService`, `LeaveEligibilityService` | Named operations. Both insert, one per request and one per run. Rule 133 names only the run, so point 3 needs the first in the register |

**C11's questions for Architecture stay open:** adopt per table; the primary-correction home; whether an end's closure is a second writer; whether `WRITE_A_LOCKED_ROW` is acceptable as a hand-kept list. **C10-N2 stays visible:** `end()`, `create()` and `setPrimary()` can compose a move outside rule 130's named path.

### Layers that must not be conflated

| Layer | Answers |
|---|---|
| Runtime privilege | Can the role issue this SQL at all |
| Tenant isolation | Which School's rows it sees |
| Service authorization | May this actor do this |
| Business ownership | Which domain's invariant governs the fact |
| Migration authority | Who changes schema: the admin role |
| Privileged maintenance | Retention and operator commands |

### Evidence

`HrWriterBoundaryTest`, 13 cases. Source pins show where the code writes, and a write through a variable or dynamic SQL escapes them. The privilege assertions and `DatabaseRoleVerifier` are the runtime boundary, and the appendix §C lists the family. **Neither closes R1-01.**

| Decision-complete when | Today |
|---|---|
| Architecture approves the full definition | **Pending** |
| Reconciled with existing ADRs and rules | Ready: this section, including ADR 0002 and ADR 0063. ADR 0028 sanctions writers without defining the term |
| Legitimate writer categories are explicit | Ready |
| Exceptions are documented | Ready: rules 76 and 86 |
| Runtime and source evidence are distinguished | Ready |
| Required executable invariants are mapped | Ready |
| Each disputed writer has an outcome | Proposed; one question open |
| Applies across HR and other domains | Ready only with refinements 5 and 6 |

**Status: DECIDED by Architecture, 2026-11-11 (HR.R1-C25): adopted with refinements, point 4 kept (WO-11), primary correction stays `setPrimary()`'s named operation** (`HR-R1-C25-R1-01-DECISION.md`). Enforcement is partial: WO-11 and WO-12.

## 6. D-3: leave accrual

**Question.** Keep accrual manual, or schedule it.

- **Current implementation.** Manual only, under `hr.leave.manage`. `routes/console.php` has no accrual or eligibility entry. C10 corrected the false heartbeat claims.
- **Existing architecture.** ADR 0065 §29. Every service takes a human `User`.
- **Problem.** A missed month is silent (LVA-1).
- **Options.** Manual; scheduled with a system actor; manual plus "month not run" detection.
- **Recommendation.** **Manual now, detection as a separately specified option.** A system actor is a new authority concept.
- **Consequences.** None for manual. Detection is a read-only check plus an operator procedure, with no ledger write. The Leave Year and ledger rules do not change.
- **Risks.** A detector must never claim that accrual ran, and must read completed runs, not planned ones.
- **Authority.** HR owner.
- **Acceptance.** No scheduler exists. If detection is chosen, it posts nothing and tests both a missed and a completed month.
- **Readiness.** Closes LVA-1's decision. Operations, not an R1 criterion.

**Status: PENDING.**

## 7. D-4: Leave allocation assurance

**Question.** Which Leave writes need fresh MFA.

- **Current implementation.** Adjustment and opening take it (`LeaveLedgerController.php:130,156`). Allocation, runs, assignment and year close do not. Every ledger write reads the cached capability, those two included (`LeaveLedgerService.php:63,114,176,218`).
- **Existing architecture.** Rule 95, ADR 0071 §26.7.
- **Problem.** S-7 is partial and LVA-4 is open.
- **Options.** Allocation and runs; allocation only; record no step-up as deliberate; keep S-7 open.
- **Recommendation.** **Allocation only, with `requireCurrent`.** A direct allocation names its own amount, up to 1,000 units on the API (`LeaveLedgerController.php:68`), while a run is bounded by policy units. This is a judgement for Security.
- **Consequences.** `FreshMfaRequirement::requireForAction()` on web and API, OpenAPI, tests.
- **Risks.** A School needs an enrolled factor first. Duplicate allocation must stay impossible.
- **Authority.** Security owner.
- **Acceptance.** Expired, missing and spent proofs refused; School scope and ledger authority unchanged; both surfaces.
- **Readiness.** Moves S-7, does not close it: runs and the cached check stay tracked. HRC-3 is a separate, closed matter.

**Status: PENDING.**

## 8. D-6: calendar history

**Question.** Accept a residual, or build calendar history.

- **Current implementation.** The weekly pattern is overwritten and holidays are hard-deleted. Approved leave and Payroll HRX snapshots are sound. Past holiday states, month views and policy as-of are not.
- **Existing architecture.** No contract makes today's calendar authoritative for past dates.
- **Problem.** R1-03 has no as-of answer, S-16 stays open, and R1-08's "reproduce" fails for past runs once a calendar changes.
- **Options.** Residual; recorded-time history; effective-date history; both. Separately, what past-date pages show and whether a change may be retroactive.
- **Recommendation.** **Acknowledge the missing history now, keep the reliable snapshots, and prepare an effective-dated contract as its own slice before real data.** It needs no timing data, so OD-HR-14 should not hold it.
- **Consequences.** Marked holidays, an append-only week version, one as-of reader. No WorkSchedule engine.
- **Risks.** History starts on the day a School goes live. What is lost before that cannot be recovered, and it must never be invented.
- **Authority.** HR owner.
- **Acceptance.** Past dates keep their answer after a change; missing history reads "not recorded", never today's value; runtime DELETE revoked on `staff_holidays`.
- **Readiness.** S-16 and R1-03 stay open until built.

**Status: DECIDED 2026-11-08 (HR.R1-C21), HR owner: effective-date history; past-dated changes only into open months, with a reason and fresh MFA; history from the day WO-5 ships; effective-dated publication, no drafts; policy as-of reader in WO-5; WO-5 authorized next.** `HR-R1-C21-D6-DECISION.md`. **Built for development by HR.R1-C22 (WO-5, 2026-11-09):** S-16 closed, LVA-3 fixed, D14-08 ALT and D14-09 IMP; R1-03 answered from the history start (`HR-R1-C22-WO5-CALENDAR-HISTORY.md`).

## 9. Secondary decision register

Pending unless the row says otherwise, and this checkpoint records no approval. OD-HR-14 to 21 keep the states in the gate register: OD-HR-18, 19 and 20 are owner decisions of 2026-11-04, and §3 relies on them.

| Id | Question | Recommendation | Owner | Effect | Status |
|---|---|---|---|---|---|
| D-7 | Performance targets | Set a School size first, then measure on staging | Product | None until a target fails | Pending |
| D-9 | "Employments examined" against ADR 0040 | Accept as operational with a reason, or "reconciled yes/no" | Product, ADR 0040 owner | **Dropping the column does not reliably erase the number.** The report's own docblock says `examined × occurring templates = created + existing + not matched`. With one template and one pass the three sum to it. The template count is not shown, and repeat passes accumulate, so it is not always exact. Column removal is not erasure | Pending |
| D-10 | Extend E47 | Names plus a leave and task data set | Legal | Docs only. Preparing is not sending | Pending; **not sent** |
| HRC-4 | RETAINED is a one-off boolean (`SeparationObligations.php:127`) | Bind it to the grant set at recording | HR, Identity | Small change | Pending |
| HRC-8 | `staff_type` NULL skips staff-type clearance (`SeparationCaseService.php:426`) | Show "unclassified" on the case | HR | Small change | Pending |
| TRR-6 | Task review outside the approval registry | Declare it in `ApprovalWorkflowRegistry::NOT_WORKFLOWS` with a reason, or register it | Architecture | One entry | Pending |
| TRR-8 | Open tasks of a separated employment | Accept as documented | HR, Tasks | None | Pending |
| LVA-4 | S-7 wording and allocation MFA | With D-4 | Security | With D-4 | Pending |
| S-6 | Staff attendance correction: no window, MFA or second person | A real determination. Candidate: record today's controls as deliberate for development, with re-review at HRX-L4 activation. **No authority exists today.** ADR 0076 §64.25 is a handoff list, and `functions.csv` HR-010's `KEEP_EXISTING` concerns approval only | HR owner, Security | D8-13 | **Decided 2026-11-08 (HR.R1-C17)**, `HR-R1-C17-S6-DECISION.md`; control not built |
| Generic upload | `HR-R1-C11-HARDENING.md` §5 | Refuse Employee uploads there | Security, HR | API change | Pending |
| Same-person | Two accounts are not two people | §11 | Identity, Security | Real-Payroll blocker | Pending, MEDIUM |
| C11-N4 | §10 | Preserve the broad freeze | HR owner | None if kept | Pending |
| §2 remainder | §3: is a partial slice "published", and nine ungated rows | The proposed reading, then decide the nine rows | HR owner | Map records, no code | Pending |
| Fingerprint | §12 | Owner recomputes | Owner | §2 | Pending |
| Baselines | §12 | Select after the next checkpoint | Owner | §2 | Pending |
| Per-commit publication | A range can hide a non-parsing commit | A `php -l` check in `publication-check` | Platform | Tooling | Pending |
| OD-HR-14 to 21 | Register | As recorded there | HR owner | As recorded | Unchanged |

## 10. Contract interpretation (proposals)

**§1, exact.** "HR.R1 runs only after the wider ERP programme has connected HR with each of these", listing Organization, SIS, Finance / Payroll, Inventory, Transport, Timetable / Academic, IAM / security, retention / audit.

"Connected" is undefined, and Inventory, Library staff borrowing and Finance staff dues are deferred by owner decision, so it cannot be met literally. **Proposed reading:** each connection is INTEGRATED with a test, DEFERRED BY OWNER, or NOT BUILT with a named owner. This does not weaken the sentence: nothing is reported as connected that is not.

**§4, exact.** "any BLOCKER or MAJOR finding means **not closed**". Whether legal and environment items block a development-scope verdict is not stated. **Proposed reading:** it applies to development and architecture findings. Legal, staging and production items do not fail the verdict if each is recorded, unchanged and refused, and the verdict names itself "PASS for development scope; production not addressed". "Permanently closed" would then need a later second assessment, or narrowing.

**R1-09.** Each owner gives one recorded answer from the same three. **NOT_INTEGRATED is never CLEAR,** and the code already refuses that (`SeparationObligations.php:33,73-74`). Library, Inventory and Finance stay NOT_INTEGRATED. Timetable answers about entries, and substitution does not exist.

**Closure levels, proposed.**

| Level | Meaning | Today |
|---|---|---|
| Development checkpoint complete | Authorized work and tests passed | C11 yes |
| HR.R1 architecture eligible | Contracts, decisions and evidence suffice for a real final assessment | **No** |
| Staging executable | Infrastructure, authorization and operations exist | **No** |
| Production GO | Every legal, security, staging, operational and functional requirement | **No: NO-GO** |

A lower level never implies a higher one. Architecture approves the levels, and the HR owner approves the readings.

**C11-N4 options.** A leave request freezes `is_paid`, `tracks_balance` and `allows_half_day`. The code counts **any** request, whatever its status, not only an approved one.

1. **Preserve the broad freeze (recommended).** One rule, and a School can add a new type.
2. Narrow it to requests that were ever approved or are pending. It needs care: a cancelled approved request has history.
3. Permit a governed correction: a reasoned, audited change. New contract.
4. Defer. The broad freeze stands meanwhile, which is also option 1 in effect.

C10-N1 itself is not reopened by any of these.

## 11. Same-person identity

**Question.** What assurance is required before real Payroll that two accounts are two natural persons?

Two accounts do not prove two people, and no record of natural persons exists. C7, C8 and rule 145 reduce the path but cannot close it. Options for Identity and Security: accept and record it, an out-of-band assertion by the School, or a verified identity process. **No biometric, no inferred matching, and no new personal identifier may come from this docket.** MEDIUM, open, and it blocks real Payroll.

## 12. Reference pack and baselines

**Fingerprint.** `40ed6a836f5a99c65694118c0481bc468b1fb6ebb379a0275cc33839fa7a1ac3` is recorded in full in the map, over 602 PNG, and the convention is described in words: SHA-256 over each file, in sorted file-name order, of its UTF-8 name followed by its hex SHA-256.

**Verified 2026-11-08 (HR.R1-C16):** the owner-held pack was found and the value recomputed exactly; the convention is pinned in the map §1. The paragraph below is kept as it was written.

**It is not verified and cannot be from here.** Missing: the separator between name and hash, hex case, the collation of "sorted", any hash of `HR.zip`, a per-file inventory, a version, and the script. The pack is owner-held and not in Git or this workspace. The SIS pack uses this convention. The Organisation pack uses another, so the two must not be mixed. **No checksum is invented here.**

**Owner action.** Run the computation on the pack, record the exact convention and the script's hash, and date it.

| Baseline | Technically observed | Selected |
|---|---|---|
| Source | `0b77270c`, `CI gate` green on a documentation lane, main run 42372422936, **no suite ran**. Last full suite `eec7bb26`, run 42370545237. The commit between changed three documents | **No** |
| Database and migrations | 385 migrations, 0 pending, last `2027_02_12_090000_a_leave_request_puts_a_leave_type_in_use`. Verifier `failed=0 operator_evidence_required=1`, and that one is `runtime_connection_encrypted`, which only a hardened environment can show. Forced RLS 252 of 300 tables. Schema hash `44ab7b8a3f1d3452e9d327ec0a839d76e48f6c392a6de513f23fffa8b6539176`: SHA-256 of `pg_dump -s --no-owner --no-privileges` of the local DDEV database, without `\restrict` lines, stable across two runs. It needs the same client: pg_dump 16.15, in the web container. The hash includes the version comment, so another client gives another hash | **No** |
| Reference pack | As above, unverified | **No** |
| Legal and security | Gate register blob `996c9b7d`. E16: 119 exceptions under `OWNER-0O-E16-2026-11-01`, last valid day 2026-11-28, records expiring 2026-11-29. No successor | **No** |
| Local environment | CI 42370545237 on `eec7bb26`, four shards, 11,176 cases, 461,795 assertions, ESI-12 skip. DDEV: PHP 8.3.33, PostgreSQL server 16.14, Laravel 13.30.0, one machine, synthetic data | **No** |

**Re-run prerequisites.** R1-04, R1-05, R1-07 and R1-08 are re-evaluated on the selected SHA, from a clean snapshot worktree with `SAFE_TEST_ISOLATED=1`, never a moving tree. No fixture is needed beyond the suite's.

| Criterion | Command | Passes when |
|---|---|---|
| R1-04 | `bin/safe-test --filter='AuthorizationCoverageGuardTest'`, `SchoolRouteRefusalSweepTest`, `HrDefaultDenyTest`, `HrMutationAuthorizationTest` | Every route contracted, deny cases pass. It does not prove assurance |
| R1-05 | `--filter='HrReportsTest'`, `HrReportTemporalSemanticsTest` | 18 entries pinned, none counting people. D-9 answered |
| R1-07 | `--filter='WorkforceLifecycleIntegrityTest'`, `EmploymentEndTeachingOwnershipTest`, `SeparationCaseServiceTest` | Each owner's contract holds. Shifts absent by design |
| R1-08 | `--filter='PayrollHrxSnapshotTest'`, `PayrollHrxArchitectureGuardTest` | Snapshots sound, differences surface, no LOP money. "Reproduce" read with D-6 |

**§2 needs a green full regression on the baseline SHA, and a green `CI gate` on a documentation-only SHA is not one.** So the owner selects a code-lane SHA, or records that a later documentation-only SHA may rest on it. This record does not decide which.

## 13. Decisions recorded by this checkpoint

**None.** No approval was supplied, so nothing is recorded, and no status in any register moved.

## 14. Residual engineering

### By verified impact

Numbering is not priority. This orders the open items by what they can do.

| Rank | Item | Why here | Needs |
|---:|---|---|---|
| 1 | LVA-3, S-16 calendar history | The first real calendar edit silently changes past month views, and the loss is unrecoverable | D-6 |
| 2 | Same-person identity | Blocks real Payroll | Identity, Security |
| 3 | S-6 and D8-10 | Corrections feed absence evidence, with no determination and no period lock | HR owner |
| 4 | XRP-3, C10-N3 | Offered, then refused. Beyond 1,000 staff some are unreachable, and on `LeavePageSupport` which 1,000 is undefined | Their owners |
| 5 | C10-N2 and the future primary-placement gap | A composed move escapes rule 130's record, and a primary can be demoted before its successor starts | Architecture |
| 6 | R-HR10-1 partial reconciliation | A closed year cannot be corrected | Leave owner |
| 7 | C11-N1, C12-N1 | A suspension race on ordinary writes. The request check still applies | Authorization only |
| 8 | GOV-6 remainder | No bypass exists. Structural only | Authorization only |
| 9 | Raw placement mutation | Runtime role only, no application path | With 5 |
| 10 | C12-N2, C12-N3 | Unused privilege; id existence observable by a same-School member | R1-01; authorization only |
| 11 | Crossed reporting-line 500, correction against retention deadlock, own-pay pre-lock read | Each aborts or reads safely, and writes nothing wrong | Recorded |
| 12 | C11-N2, C11-N3 | A replayed 404 with a new key available; a test flake | Authorization only |

### GOV-6 remainder

87 models scanned, 75 remaining. **No verified bypass:** every `update($attributes)` sits behind a strip or intersect list, and the one `forceFill($attributes)` (`EmployeeDocumentService`) sits behind a four-key allowlist. Per-model list: appendix §B.

| Group | Models | Disposition |
|---|---:|---|
| Service-bound, strip or intersect list | 13 | **Harden first.** `Employee.user_id` leads: the link column, rule 145 |
| Stateful, fixed-array writers | 8 | Harden second |
| Statutory | 16 | Leave. Legally gated, unreachable without a grant. Decision-dependent |
| Append-only by privilege | 27 | Leave. The runtime role holds no UPDATE |
| Configuration and operational | 11 | Leave for now. `PayrollRunResult` and its lines are pay rows, and any change there is a Payroll review |

### C11-N1 and C12-N1

C11-N1 names twelve services by family. **It does not name `ArchivesProfileRecords`,** the trait carrying the archive path of six of them, so guarding those services' own methods alone would leave archive unguarded. **WorkforceTasks is partly covered:** self-service and the manager path reach the check through `ActingEmployeeResolver::hold()`, while the `administrative` path and `TaskCatalogueService` do not. Each writes a School business record, so each is a rule 86 effect. Leave and Staff Attendance have none outstanding. Other domains were not assessed. Appendix §D lists them.

**Lock order.** Nothing inside these transactions writes `schools`, so no upgrade exists. Take the guard at the start of the transaction, after any lock its own rule puts first. `DocumentService::store()` writes bytes before its transaction, so `EmployeeDocumentService::register()` needs the check in its first transaction as well as in the closure. Neither exists today.

**Negative tests.** One suspended-School case per writer, including an archive through the trait and the `administrative` task path, because a self-service case passes today. A positive control, and one two-process race. Read the existing protocol before adding anything: C11's pattern is `HrExecutionTimeSchoolCheckTest`.

### C11-N2, C11-N3

**C11-N2.** Stored 404 under an idempotency key, read but not executed. It is generic, shared with Leave. Recommendation: reproduce before any change, and if real, treat it in the middleware's own contract, not in HR.

**C11-N3.** The evidence is the failed page itself: `pf` appeared only in `gdapfw.varun.mistry@example.org`, and `PayrollUiTest.php:520-521` searches the whole page. Not a production defect, and no fixture change is justified. Recommendation: assert on payload keys. Same class: `esi` and `tds`.

### New in C12 (registered in `HR-R1-0-AUDIT-REGISTER.md`)

| Id | Severity | Finding |
|---|---|---|
| C12-N1 | P3 | The `ArchivesProfileRecords` archive path, `TaskCatalogueService` and the `administrative` task path lack the execution-time School check. By reading |
| C12-N2 | P3 | The runtime role holds DELETE on five HR-family tables with no caller. Catalogue plus search |
| C12-N3 | P3 | Five web sites look up before the capability check: `CompensationController:95,154`, `StatutoryEmployeeController:79`, `PayrollRunController:121,142`. Two search endpoints leave `%` and `_` unescaped with no ORDER BY. The C10 inventory named two of the sites and never registered them. Confirmed at the baseline |
| C12-N4 | Docs | The inventory's "25 files" does not reproduce (24), §1.8's register list contradicts its point 7, and ownership map §3 still says `7a4068fe` and "25 places". The map is left for D-2 |
| C12-N5 | Contract | D-1 does not cover all of §2: nine unresolved rows outside HR.4–8 have no gate and no deferral record, and "published" is undefined for a partial slice. §3 |

## 15. Cross-domain handoffs

**XRP-3, TeachingAssignments owns.** Both controllers list Employees with `limit(1000)`, including staff the save then refuses.

| Contract | |
|---|---|
| Reader | Extend `DutyCandidateReader::search()` with `?string $onDate`, null meaning today, same predicate as `hold()`, School-local. `:38` hard-codes today, which contradicts ADR 0063 for future starts |
| As-of date | The form's `starts_on` |
| Teacher to Employment | An active Employee with a covering planned or current employment on that date (ADR 0063) |
| Authorization | `teaching.assignments.manage`, whole-School, not Campus-adopted |
| Migration | None |
| Test matrix | The 15 acceptance tests in the architecture inventory §4.1 |
| Handoff | TeachingAssignments owns the pages and search route, HR owns the reader change. No second eligibility algorithm in Academic. A new route needs its `SchoolRouteCoverage` entry |

**XRP-4, Timetable and Transport own.** Obligations key on the Employee because `teacher_id` and `driver_employee_id` are FKs to `employees` and neither table has an employment column. Timetable entries are undated weekly rows. **Choices:** an accepted limit (a duty counts against any open case of that person) or an architecture decision. **No Employment column is added without one.** Current duty eligibility and historical service dates stay separate.

**X-1, remaining direct reads, confirmed at the baseline.** `Timetable` App `:141,241,430`, API `:321`; `TransportOperationsController:143`, `TransportRouteAssignmentController:50`; `VisitorVisitController` App `:139`, API `:87`. Most resolve an id and could pass it, and the services already re-check `hold()`. The Timetable list filter needs a labels-by-Employee reader or an exception. Rule 138's pickers are done.

None of these domains is changed by this checkpoint.

## 16. Work orders (not started)

Each is a separate checkpoint. Baseline: the then-current `main`. None is authorized by this docket.

| Id | Owner | Services | Database | Authorization | Concurrency | Audit, retention, privacy | API, UI | Tests | Needs |
|---|---|---|---|---|---|---|---|---|---|
| WO-1 | HR, Tasks | The twelve C11-N1 services, `ArchivesProfileRecords`, `TaskCatalogueService`, the `administrative` task path | None | Unchanged | Guard first in the transaction; twice for documents | Unchanged | 404 as today | Suspended School per writer, one race | Authorization only |
| WO-2 | HR, Leave | GOV-6 service-bound and stateful models, their writers | None | Unchanged | None | Unchanged | None | Model pin extended, strict handler | Authorization only |
| WO-3 | Payroll, HR | 22 sites; `listAssignments()`; ADR 0034, `PAYROLL.md:97`, map §3 | None | Unchanged | Reads only | No field widens | Still a 404; its body as Architecture decides | New pin of appendix §A, Payroll suites, C1–C8 proofs | D-2 |
| WO-4 | Leave | `LeaveLedgerController::allocate`, web equivalent | None | Fresh MFA after capability | Proof outside the transaction | Audit unchanged | OpenAPI field, form field | Allow, deny, expired, spent, both surfaces | D-4 |
| WO-5 | Leave | `StaffCalendarService`, attendance and Payroll readers, D14-08, D14-09 | Append-only week version, holiday lifecycle, RLS, DELETE revoked | Existing keys | Effective-date rules; `LeaveLocks::calendar` | `leave_configuration` retention unit | Past-date labels | As-of, raw role, rollback | D-6 |
| WO-6 | Staff Attendance, Payroll | D8-10 lock, D8-13 | A lock record | A new key needs a catalogue release | Lock against correction | Audit, retention unit | New control | Lock, reopen, Payroll interaction | D-1, S-6 |
| WO-7 | TeachingAssignments | §15 XRP-3 | None | `teaching.assignments.manage` | None | No new field | Search pickers | 15 cases | Its owner |
| WO-8 | Timetable, Transport, Visitor | X-1, XRP-4 outcome | None unless decided | Existing | Existing `hold()` | Unchanged | None | Per domain | Their owners |
| WO-9 | Leave | D-3 detection, if chosen | None | Read-only | None | Writes nothing | Operator surface | Missed and completed month | D-3 |
| WO-10 | Platform, Payroll | C11-N2 reproduction, C11-N3 assertion, `php -l` range check, C12-N3 | None | Capability first on five web sites | None | None | 403 before 404 | One each | Platform for the range check |
| WO-11 | HR, Leave | C12-N2 | Revoke five DELETE grants, verifier pin, `down()` | None | None | None | None | Raw role, rollback | R1-01 point 4 |

**Sequence.** WO-1 and WO-2 need no policy decision, only authorization to start. Then WO-3 after D-2, WO-5 after D-6, WO-4 after D-4, WO-6 after D-1 and S-6, WO-11 after R1-01. WO-7 and WO-8 follow their owners. WO-9 and WO-10 fit anywhere.

## 17. Closure matrix

### Contract, decision and evidence

| Criterion | State | Contract | Owner decision | Implementation evidence | Test evidence | Blocker | Next |
|---|---|---|---|---|---|---|---|
| §1 | Not met literally | Contract §1 | §1 reading | Real contracts for six of eight | Per domain | No reading | Owner decides §10 |
| §2 slices | Open | Contract §2 | D-1, §2 remainder | HR.1–3 and 9–16 published in part, as the roadmap records; HR.4–8 not built | Canonical suite | D-1, nine rows, four ACTIVE rows, "published" undefined | Decide |
| §2 fingerprint | Owner-held | Map §1 | None needed | Recorded, unverified | None possible here | Recompute | Owner |
| §2 baseline | Observed only | Contract §2 | Selection | §12 | Last full suite on `eec7bb26`, not the tip | Selection, and a full regression on it | Owner |
| R1-01 | Open, pinned | Ownership map §1 | R1-01 | 0 foreign writers, 0 duplicates, by source scan | `HrWriterBoundaryTest`, 13 cases | Definition | Architecture |
| R1-02 | Open | Contract R1-02 | D-2 | 62 sites, X-1 reads | Appendix §A | D-2, X-1, 24 files | Architecture |
| R1-03 | Open | Contract R1-03 | D-6 | Assignment, terms, manager answer; calendar and policy do not | As-of tests per concept | D-6 | HR owner |
| R1-04 | Recorded | Rule 98 | None | Coverage contracts | Guard and deny tests | Re-run | After baseline |
| R1-05 | Recorded | Rule 140 | D-9 | 18 entries | `HrReportsTest` | Re-run, D-9 | After baseline |
| R1-06 | Done for ten tables | Rule 28 | None | Forced RLS 252 | 78 raw-role cases | Others named, not re-audited | Keep |
| R1-07 | Recorded | Each owner's contract | XRP-4, TRR-8 | Rule 137 | Lifecycle tests | Re-run | After baseline |
| R1-08 | Recorded, limited | Contract R1-08 | D-6 | Snapshots sound | `PayrollHrxSnapshotTest` | Re-run, D-6 | After baseline |
| R1-09 | Open | Contract R1-09 | R1-09 reading | Transport answers; Library, Inventory, Finance NOT_INTEGRATED | `SeparationObligationTruthTest` | Reading | Owner decides §10 |
| R1-10 | Intact | Gate register | None | Refused by absence or guards | Guard tests | Held partly by absence | Keep |
| R1-11 | Mapped | E21 | Legal | 85 of 92 HR-prefixed tables, other 7 platform rules | Retention tests | E21 unratified | Legal |
| R1-12 | Open | Finding register | S-6, D-4, D-6 | S-1, 2, 3, 5, 8–15 closed | Per finding | S-4, S-6, S-7, S-16, X-1, M-1 | Security, HR |

### By level

**No Architecture agreement is recorded for any criterion,** so that column is the same on every row and is kept to show it. "Recorded" means earlier evidence not re-run here. Nothing here reaches staging or production, whose columns are one fact: not executed, and NO-GO.

| Criterion | Development evidence | Architecture agreement | Legal authority | Operational evidence |
|---|---|---|---|---|
| §1 | Partial | None recorded | n/a | n/a |
| §2 slices | Partial | None recorded | L2b undecided | n/a |
| §2 fingerprint | n/a | n/a | n/a | Owner's action |
| §2 baseline | Observed, none selected | None recorded | n/a | n/a |
| R1-01 | Pins only, no definition | None recorded | n/a | n/a |
| R1-02 | Partial | None recorded | n/a | n/a |
| R1-03 | Partial | None recorded | n/a | n/a |
| R1-04 | Recorded, re-run due | None recorded | n/a | n/a |
| R1-05 | Recorded, re-run due, D-9 open | None recorded | ADR 0040 gate held | n/a |
| R1-06 | Ten tables; the others not re-audited | None recorded | n/a | n/a |
| R1-07 | Recorded, re-run due | None recorded | n/a | n/a |
| R1-08 | Recorded, limited, re-run due | None recorded | HRX-L4 undecided | n/a |
| R1-09 | Partial | None recorded | n/a | n/a |
| R1-10 | Recorded: refused by absence or guards | None recorded | **Gates still shut** | n/a |
| R1-11 | Mapped | None recorded | **E21 unratified** | n/a |
| R1-12 | Partial | None recorded | Partial | n/a |

**Staging:** paused and infrastructure-blocked. None of AWS access, provider migration-role verification, restore proof, database TLS, legacy-document inventory, monitoring, staging MFA or screen-reader evidence exists. **Production: NO-GO.**

## 18. Inventories, reconciled

**Audit register.** 61 identifiers, none lost: HRC 12, LVA 9, XRP 7, PAY 12, TRR 10, GOV 11. The 17-finding subset stands at 9 closed, 1 documented, 7 open (HRC-4, HRC-8, LVA-4, XRP-3, XRP-4, TRR-6, TRR-8). Across all 61, 15 rows read OPEN, and GOV-6 is partial. TRR-2, GOV-1, PAY-12 and GOV-2's S-4 half are also unresolved, under other wording. No finding moved in C12.

**Capability map.** 361 rows, no row changed: IMP 161, ALT 20, PART 20, MISS 11, XDOM 7, DEF 49, GATE 87, NR 6. **It is reference coverage, not a readiness percentage.**

## 19. Next checkpoint

**Recommended: HR.R1-C13, WO-1 plus WO-2** (C11-N1, C12-N1, GOV-6 service-bound and stateful models), the smallest scope that needs no policy decision. It still needs the owner's authorization to start. If D-1, D-2 and R1-01 are approved first, WO-3 and the §2 record take priority instead. Nothing starts automatically.
