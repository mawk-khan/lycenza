# HR.R1-C23: D-2 Payroll to HR boundary, decided (2026-11-11)

**HR.R1 stays OPEN, and this is not the closure audit.** This record holds what Architecture decided on D-2, what the HR owner decided on the four C22 deviations, and the reconciled evidence behind both. **Nothing was built.** No PHP, migration, grant, function or test changed. Implementation is WO-3, a separate checkpoint that does not start automatically.

| | |
|---|---|
| Baseline | `a3f36cd2` = `main` = `origin/main`, clean tree. `337631b7` (C22 code) is an ancestor |
| Last full suite | `337631b7`, CI gate run 42586811898: 11,592 cases, 0 failures, 1 skip (ESI-12), 466,313 assertions. Regression counter 0/5 |
| Local database | DDEV `db`, 390 migrations, last `2027_02_15_090100_admit_hrx_payroll_input_v2`. Read-only catalogue queries only |
| Decided here | D-2 (Architecture); the four C22 deviations (HR owner) |
| Not decided here, not inferred | R1-01, R1-09, §1, §4, D-3, D-4, D-7, D-9, D-10, C14-N1, C14-N2, C11-N4, the generic Employee document classification, every Legal, DPO and Security determination, the five closure baselines |

## 1. Decisions

The owner answered the decision intake in the HR.R1-C23 session on 2026-11-11. The docket assigns D-2 to **Architecture** alone (`HR-R1-C12-DECISION-DOCKET.md` §1, §2) and the C22 deviations to the **HR owner**. If one person holds both roles, each decision below states which role made it.

| Id | Role | Selected | Date | Source |
|---|---|---|---|---|
| D2-1 | Architecture | **D-2 Option B, exactly as docketed** (`HR-R1-C12-DECISION-DOCKET.md` §4): governed readers for 22 sites, narrow named exceptions for 27, no change for 13; all 9 foreign keys approved as structural; all 8 Payroll SQL functions approved as narrow exceptions; a guard pins the list; Payroll never writes HR | 2026-11-11 | Owner answer, C23 decision intake |
| D2-2 | Architecture | **Implementation is a separate checkpoint, WO-3** (the docket's pending "new checkpoint" condition) | 2026-11-11 | Same answer: "readers move later in WO-3, a separate checkpoint" |
| D2-3 | Architecture | **The 9 API existence sites keep the 404 status and drop the HR class name from the body** | 2026-11-11 | Owner answer, 404 body question |
| D2-4 | HR owner | **All four C22 deviations accepted** as recorded alternatives (§7). C22-N4 stays a LOW, with no work order queued | 2026-11-11 | Owner answer, C22 deviations question |

**What these approvals are not.**

- They are not an implementation. Every reader move is still unbuilt, and R1-02 is not closed (§9).
- No Security or Legal/DPO approval was given or needed (§8). None is recorded, and none should be inferred.
- None of them is a Payroll owner sign-off on new financial behaviour. The docket names no Payroll role for D-2, and no Payroll behaviour changes.
- They grant no new HR mutation authority, database grant or SECURITY DEFINER function.

**Completion state: D-2 APPROVED — IMPLEMENTATION CONTRACT READY.**

## 2. Reconciled inventory (current main)

Re-run at `a3f36cd2` and compared with `HR-R1-C12-EVIDENCE-APPENDIX.md` (baseline `0b77270c`).

| Measure | C12 | C23 | Change |
|---|---:|---:|---|
| Executing PHP sites | 62 | **62** | None. Line numbers moved only (C14 capability-first lines, C15 School-guard lines) |
| Payroll files importing `HR\Infrastructure` | 25 + 3 App controllers | **25 + 3** | None, same files |
| `Employee::query()` / `EmploymentRecord::query()` outside HR | 47 (32 Payroll + 15 other) | **47 (32 + 15)** | None. "Other" excludes HR's own `App/HR` controllers, as C12 did |
| Files outside HR and Payroll importing `HR\Infrastructure` | 24 | **24** | None (X-1, XRP-3: not D-2) |
| Foreign keys, Payroll to HR (live catalogue) | 9 | **9** | None |
| Payroll-owned SQL functions reading HR (live `pg_proc.prosrc`) | 8 | **8** | None |
| All functions reading `employment_records`, `employees`, `employee_assignments`, `employee_account_links` | 31 | **32** | +1, `staff_attendance_is_employee` (C18, Staff Attendance). Not Payroll |
| Payroll writes to HR | 0 | **0** | None; `HrWriterBoundaryTest` |
| Payroll as-of (historical) reads of HR | 0 | **0** | None; Payroll's history is its own result rows |

**Why nothing moved (C13 to C22).**

- **C13, C16, C17, C19, C20, C21:** no Payroll file changed.
- **C14 (C12-N3):** capability-first on `CompensationController:100` and `StatutoryEmployeeController:85` and on two sites in `PayrollRunController`; the search terms became literal (`%` and `_` escaped) with a stable order by id. Same reads, new lines.
- **C15 (C14-N6):** `SchoolOperationalGuard` lines in thirteen Payroll files. No HR reference added.
- **C18:** Staff Attendance month locks. Payroll is untouched (rule 147).
- **C22:** `hrx_payroll_input.v2`. Payroll's only HR consumer on this path is the governed `EmploymentRoster`, already compliant (`PayrollHrxInputReadService:45`, through `PayrollAbsenceEvidenceReader`, which is Staff Attendance's). The one migration touching a Payroll table (`2027_02_15_090100`) rewrites a CHECK and adds no FK or function.

**Already compliant: no change, not counted in the 62.** These Payroll uses of HR go through HR Application readers:

- `EmploymentRoster`: `PayrollHrxInputReadService:45` and `PayrollApprovalReviewService:58`;
- `ActingEmployeeResolver`: `PayslipReadService:63`;
- `HrSelfAdministrationGuard::isEmployeeOf()`: in the two administration services (rule 145);
- `EmployeeRetentionEligibility` / `EmployeeSeparation`: both retention services.

**One dead import remains:** `StatutoryPayrollCalculationService.php:5`, mentioned only in docblocks. It is removed with WO-3.

## 3. The approved register

IDs are new, assigned here so a guard can pin them. "Line" is the current line at `a3f36cd2`; C12's line is shown where it differs. App = `app/Http/Controllers/App/Payroll/…`, API = `app/Domain/Payroll/…/Http/Controllers/…`.

### 3.1 Governed reader (22): moves in WO-3

The readers already exist in `App\Domain\HR\Application\EmploymentRoster`, so Option B adds none.

| Id | Site | Reader | Note |
|---|---|---|---|
| D2-R01 | App `PayrollRunController:279` (C12 277) | `labels()` | Unresolved list |
| D2-R02 | App `PayrollRunController:296` (294) | `labels()` | Result names |
| D2-R03 | App `PayrollRunController:314` (312) | `labels()` | Correction candidates |
| D2-R04 | App `CompensationController:100` (95) | `labels()` | **Depends on** `listAssignments()` taking an id (§3.5) |
| D2-R05 | App `StatutoryEmployeeController:85` (79) | `labels()` | Services already take an id |
| D2-R06 | `PayslipReadService:178` | `labels()` | Shows the current name, as today |
| D2-R07 | `StatutoryEcrExportService:96, 128` | `labels()` | Built once before the row loop; `CsvCell::safe()` and C10-N7 unchanged |
| D2-R08 | `StatutoryEsiContributionWorksheetExportService:59, 108` | `labels()` | Same |
| D2-R09 | `StatutoryTdsDraftStatementExportService:75, 113` | `labels()` | Same |
| D2-R10 – R11 | API `StatutoryPfStatusController:24, 32` | `span() !== null` | 404 per D2-3 |
| D2-R12 – R13 | API `StatutoryEsiCoverageController:25, 39` | `span() !== null` | 404 per D2-3 |
| D2-R14 – R15 | API `StatutoryTaxProfileController:23, 31` | `span() !== null` | 404 per D2-3 |
| D2-R16 – R18 | API `StatutoryIdentifierController:26, 34, 42` | `span() !== null` | 404 per D2-3 |
| D2-R19 | `EmployeePfStatusAdminService:103` (99) | `span() !== null` | Replaces `exists()` |
| D2-R20 | `EmployeeEsiCoverageAdminService:122` (118) | `span() !== null` | Replaces `exists()` |
| D2-R21 | `EmployeeTaxProfileAdminService:97` (93) | `span() !== null` | Replaces `exists()` |
| D2-R22 | `StatutoryIdentifierAdminService:131` (127) | `span() !== null` | Replaces `exists()` |

### 3.2 Narrow exception (27): approved, stays direct

Each exception covers its own site only, for reads of the named columns of `employment_records` (and, where stated, `employees`). None authorizes a write, a new column, a new caller or any later Payroll code. A new site needs a new Architecture decision (re-review trigger, §10).

| Id | Site | Reads | Justification | Date semantics |
|---|---|---|---|---|
| D2-X01 | `PayrollRunService:400` (392) | `findOrFail`: model for calculation | Provenance path (HR.R1-C1). Touching it risks PAY-2, PAY-13 and C1 sources | The row's own dates |
| D2-X02 | `PayrollRunService:496` (488) | Same, correction run | Same; PAY-17 | Same |
| D2-X03 | `PayrollRunService:675` (666) | `starts_on`, `ends_on` | Decides a partial period | Same |
| D2-X04 | `PayrollRunService:710` (701) | `id` | Manual override calculation | None |
| D2-X05 | `PayrollRunService:745` (736) | `id` | Correction delta calculation | None |
| D2-X06 | `PayrollRunService:818` (809) | `id`, `employee_id` stored on the result | Frozen financial evidence; the FK binds it | None |
| D2-X07 | `CompensationService:178` (174) | `id`, dates | Resolves the effective compensation | As of the period |
| D2-X08 | `CompensationService:108` (104) | `FOR UPDATE` on one row | ADR 0034:106 approves the lock; readers are FOR SHARE and judge status. Lock order: rule 144 | None |
| D2-X09 | App `CompensationController:69` (67) | Search including ended staff | No reader; `hr.employees.view` is the wrong authority. Literal term, bounded, ordered by id (C14) | Any status |
| D2-X10 | App `StatutoryEmployeeController:52` (50) | Same | Same | Any status |
| D2-X11 | `PayslipReadService:172` | Every employment of the acting Employee | Own payslips; no reader, and no HR capability applies | Any status |
| D2-X12 | `PayrollPostingIndependence:304` | SQL join, ordered by number, paged | `paged()` would change the visible order | None |
| D2-X13 | `PayrollPostingIndependence:305` | Same, `employees` | Same | None |
| D2-X14 | App `PayrollRunController:122` (121) | `id` for a typed service | Id-only, on a key the FK binds | None |
| D2-X15 | App `PayrollRunController:144` (142) | Same | Same | None |
| D2-X16 | App `CompensationController:161` (154) | `id` for `assign()` | Same. Lookup still precedes the capability check (C14-N1, open) | None |
| D2-X17 – X18 | API `PayrollRunController:84, 104` | `id` for a typed service | Same | None |
| D2-X19 – X20 | API `CompensationAssignmentController:35, 54` | `id` for a typed service | Same. X19 can leave the list (§3.5) | None |
| D2-X21 | `PayrollRunService:210` (205) | Type hint, `id` only | 12 signatures for little minimization | None |
| D2-X22 | `PayrollRunService:264` (258) | Same | Same | None |
| D2-X23 | `CompensationService:74` (72) | Same | Same | None |
| D2-X24 | `PayrollCompensationReadService:50` | Same | Same. Can leave the list (§3.5) | None |
| D2-X25 | `PayrollCompensationAdministrationService:45` | `id` for `isEmployeeOf()` | Same | None |
| D2-X26 – X27 | `PayrollRunAdministrationService:72, 86` | Same | Same | None |

### 3.3 No change (13)

| Id | Site | Why it is already compliant |
|---|---|---|
| D2-N01 – N08 | `employmentRecord()` relations on `EmployeeCompensationAssignment:66`, `PayrollRunResult:64`, `PayrollAdjustment:75`, `EmployeePfStatus:56`, `EmployeeEsiCoverage:46`, `EmployeeTaxProfile:48`, `EmployeeStatutoryIdentifier:43`, `PayrollLwfAnnualCharge:27` | Unused in production; a key the FK binds |
| D2-N09 | `PayrollRunResult:70` `employee()` | Used only by the three exports, D2-R07 to R09. Unused once they move; WO-3 may then remove it |
| D2-N10 – N12 | `PayrollEmployeeRetentionService:54, 61, 71` | Retention identity with column grants (E21); a platform mechanism, not a business read. `:71` names HR tables, so an HR schema change can need a Payroll edit |
| D2-N13 | `PayrollEvidenceRetentionService:90` | Same |

### 3.4 Database: foreign keys and functions

**Foreign keys (9), approved structural FKs (D2-1).** All are same-School composite keys, `(…, school_id) → (id, school_id)` (rule 70), read from the live catalogue.

| Id | Table → HR table | On delete | Role |
|---|---|---|---|
| D2-FK1 | `payroll_run_results` → `employees` | RESTRICT | Financial history |
| D2-FK2 | `payroll_run_results` → `employment_records` | RESTRICT | Financial history |
| D2-FK3 | `payroll_adjustments` → `employment_records` | RESTRICT | Financial history |
| D2-FK4 | `employee_compensation_assignments` → `employment_records` | RESTRICT | Calculation source (outlives its run, rule 145) |
| D2-FK5 | `payroll_lwf_annual_charges` → `employment_records` | RESTRICT | Financial history |
| D2-FK6 – FK9 | `employee_pf_status`, `employee_esi_coverage`, `employee_tax_profile`, `employee_statutory_identifiers` → `employment_records` | CASCADE | Per-employment statutory facts that leave with their employment |

**No FK may be removed or weakened to reduce coupling.** A later migration that changes one needs its own data-integrity argument and Architecture decision. A tidier dependency graph is not a reason (rule 97).

**SQL functions (8), narrow exceptions (D2-1).** A PHP reader cannot run inside a trigger or a set-returning predicate. Security mode and `search_path` were read live today; C12 did not record them.

| Id | Function | Reads | Mode | `search_path` | Volatility |
|---|---|---|---|---|---|
| D2-F1 | `compensation_assignments_reject_overlap()` (trigger) | `employment_records`, locks a row | INVOKER | not pinned | volatile |
| D2-F2 | `payroll_run_first_paid_compensation` | `employment_records`, `employee_account_links` | INVOKER | pinned | stable |
| D2-F3 | `payroll_run_first_paid_beneficiaries` | same | INVOKER | pinned | stable |
| D2-F4 | `payroll_run_independence_exception` | same | INVOKER | pinned | stable |
| D2-F5 | `payroll_reversal_reconciliation_subjects` | same | INVOKER | pinned | stable |
| D2-F6 | `payroll_reversal_affected_runs` | same | INVOKER | pinned | stable |
| D2-F7 | `retention_assert_payroll_employee_floor` | `employment_records`, `employees`, locks | INVOKER | `pg_catalog, pg_temp` | volatile |
| D2-F8 | `retention_expire_payroll_employee_evidence` | `employment_records` | **DEFINER** | `pg_catalog, pg_temp` | volatile |

- **D2-F1:** the unpinned path is not reachable by the runtime role, which has CREATE on no schema (`has_schema_privilege`, checked today). Pinning it is optional hardening, never a D-2 prerequisite.
- **D2-F8 is SECURITY DEFINER by the E21.3F retention design.** `school_os_app` cannot execute it (`has_function_privilege` = false); EXECUTE is held by the owner and `school_os_retention` only.
- **D2-F2 to F6 are the protected set** (HR.R1-C1, C3, C6, C7). WO-3 never touches them, and neither does any other D-2 work.

**Adjacent facts, approved as named (D2-1, from appendix A.4):**

| Id | Fact | Disposition |
|---|---|---|
| D2-A1 | Raw reads of `employee_account_links` in `PayrollSelfBeneficiaries.php:28, 31` | Narrow exception; rule 145 sanctions them |
| D2-A2 | `trg_employees_user_link_payroll_lock`, a Payroll-named lock trigger on an HR table | Narrow exception; rule 144 lock order |
| D2-A3 | HR takes the compensation lock through `App\Support\Concurrency\SchoolCompensationLock` | No change; HR imports no Payroll class |
| D2-A4 | `PayrollEmployeeRetentionService:71` names HR tables | No change (D2-N12) |
| D2-A5 | The dead import `StatutoryPayrollCalculationService.php:5` | Removed in WO-3 |

### 3.5 The one dependent move

`CompensationController:100` (D2-R04) passes its model to `PayrollCompensationReadService::listAssignments()`. It can move only if that method takes an id. If WO-3 changes the signature, D2-X24 disappears, and D2-X19 too unless another use of the model remains. Those sites are removed, not reclassified: **22 / 26 / 13**, or **22 / 25 / 13**. If the signature stays, D2-R04 becomes an id-only exception: **21 / 28 / 13**. **Both outcomes are inside D2-1.** WO-3 records which one shipped and updates this register in the same change.

## 4. Ownership

| Fact | Owner | Payroll's relationship |
|---|---|---|
| Employee identity, lifecycle, profile, archive/restore | HR | Reads through §3; never writes; keeps no copy |
| Employment, terms, dates, separation, rehire, overlap | HR (rules 130, 137) | Reads the row's own dates; never "currently employed" for a past period |
| Historical User–Employee association | HR (`employee_account_links`, rule 145) | Read only through D2-F2 to F6 and D2-A1, every uncorrected link at each decision |
| Runs, calculations, sources, approval, attestation, posting, reversal, reconciliation, corrections, retained evidence | Payroll (ADR 0034) | Owner. Frozen results are never rebuilt from today's HR state |
| HRX evidence snapshot (`payroll_run_hrx_inputs`) | Payroll stores it; Staff Attendance produces it (ADR 0065 §26) | Through `PayrollAbsenceEvidenceReader` only |
| Staff calendar | Leave (rule 149) | Never read directly by Payroll |

**There is no shadow HR master.** No Payroll-owned Employee or Employment copy exists or is permitted. `payroll_run_results.employee_id` and `employment_record_id` are evidence keys, bound by D2-FK1 and FK2.

**Historical identity.** Every reader in §3.1 returns current facts: a past payslip shows today's name, exactly as it does now. Nothing in D-2 makes a reader a historical source. Independence, beneficiary and approver identity keep reading `employee_account_links` through the protected functions, never `users` or `employees.user_id` alone. The person-level Identity MEDIUM, that two accounts are not two people, is unchanged and open.

## 5. Reader contract (WO-3)

The two readers exist and are not changed. For every §3.1 site the contract is:

| Property | `span(School, id)` | `labels(School, ids)` |
|---|---|---|
| Fields | `startsOn`, `endsOn` | employment id, Employee id, number, name, status, current, active |
| School | Explicit argument, `withSchool` | Same; another School's id yields nothing |
| Date | The row's own dates | Current facts |
| Consistency | Committed read, no lock. Approval, posting, reversal and correction lock order is untouched | Same |
| Errors | `null` when absent or in another School. Payroll keeps deciding the 404 (D2-3): same status, no HR class name. A cross-School id is indistinguishable from an absent one | Absent ids are absent |
| Privacy | Dates only | Directory tier: no personal, bank, statutory or protected field |
| Performance | One row | One query per 2,000 ids, sorted by name, keyed by id. Never one query per row |
| Caching | None | None |

**Exposure goes down, not up.** The sites in §3.1 now load the whole `Employee` model (`with('employee')`). After WO-3 they receive the directory tier only.

## 6. HRX v2 compatibility and C22-N4

**What `hrx_payroll_input.v2` hashes** (`PayrollAbsenceEvidenceReader::fingerprint()`, ADR 0065 §26.7, §33):

- the contract version, employment, period and coverage;
- whether the calendar is configured;
- per half: date, half, class, the calendar working flag (null before the history start) and the source facts.

Each half also stores `calendarVersionId`, as provenance only; it is not hashed. The shape CHECK admits v1 and v2 only. Snapshots are immutable: no runtime UPDATE or DELETE, and frozen once the run is approved or posted.

**The four cases.** The terms are the code's own (`comparable`, `sourceChanged`, `differenceState`, `completeness`).

| Case | Outcome |
|---|---|
| A: the answer changes and the version changes | Class or working flag changes, so the hash changes: `sourceChanged`. A calculated run shows `recalculate_to_refresh`; an approved or posted run shows `source_changed_after_approval`, and stays frozen |
| B: the version changes, the answer does not | Equal hash, no difference. The stored per-half `calendarVersionId` still names the old version. **This is deviation 1, accepted (D2-4)**: the pay-relevant answer is equal, and §26.7 defines equality by source state |
| C: a past-dated correction after calculation | If it changes a half's answer, it is case A. If it supersedes with the same answer, it is case B |
| D: a v1 snapshot with today's v2 reader | `comparable: false`, never `sourceChanged`, counted in `earlierContractInputs` |

**`input_incomplete`.** A half before the history start is `calendar_not_recorded` with a null working flag, which is never zero, never off and never absence. Units are counted per class, so unknown time cannot become a numeric zero. Payroll pay does not consume this evidence (`PAYROLL_POLICY = pending_hrx_l4`), so no calculation admission rule exists or was added.

**C22-N4: confirmed LOW, no financial-integrity defect.**

- **Approval does not depend on HRX evidence.** `PayrollRunService::approve()` (`:533`) binds the reviewed calculation id and `attributionVersion`. Through `assertMayDecide()` (`:650`) it also requires a complete calculation, every adjustment calculated, recorded sources and compensation freshness (`PayrollCompensationProvenance::isFresh()`, HR.R1-C1 and C3); otherwise `RunRecalculationRequiredException`. None of these reads `payroll_run_hrx_inputs`.
- **This is by contract.** ADR 0065 §26.10 makes recalculation the remedy for a changed source on a calculated run, not an approval gate. That was true under v1 before C22, so a calculated run whose HRX source changed could always be approved.
- **C22 changed what such a run displays, nothing else.** A calculated run holding only v1 snapshots says "not compared" (with the count) instead of "recalculate". The API's `differenceState` is then `none`. That is documented as "decided from comparable inputs" (OpenAPI), so `earlierContractInputs` is the field to read.
- **Pay and posting are unaffected.** No stale calculation can be approved contrary to ADR 0034 or rule 142, and no v1 snapshot is rewritten or upgraded.

The owner accepted N4 as a LOW (D2-4). The optional remedy is to also prompt recalculation for a draft or calculated run with earlier-contract snapshots. It is a small UI and API change, not queued.

**A raw v1 insert** is still admitted by the CHECK. The only writer always writes the current version, and the runtime role has no path to forge one through the service. This stays part of N4.

## 7. C22 deviations (D2-4: all accepted by the HR owner)

| # | C21 required | C22 did | Evidence | Security / readiness effect |
|---|---|---|---|---|
| 1 | Fingerprint includes the calendar version (§6.7) | Version is per-half provenance, not hashed; contract v2; comparison within a version only | `StaffCalendarConsumersTest`; §6 above | None on pay or approval (§6). An answer-preserving supersession is not flagged |
| 2 | The notice counts calculated, unposted Payroll runs (§6.5) | Notice counts pending leave and recorded attendance; Payroll's `checkDifferences` reports changed captures | `LeaveArchitectureGuardTest` forbids Payroll→Leave | Payroll impact is visible per run, not in the calendar notice |
| 3 | Runtime UPDATE of the status columns only (§6.3) | Table-level UPDATE; guard triggers freeze every other column for every role, pinned by the verifier | `staff_calendar_history_invariants` | Equivalent outcome. Its strength depends on the verifier, which C22-N1 and N2 harden |
| 4 | Service operation to withdraw a scheduled week (§6.5) | Web page only, no API endpoint | C22 §5 | Withdrawal is not scriptable; nothing is weakened |

Accepting a deviation is not acceptance of C22-N1 to N4. Those stay as recorded (§11).

## 8. Security, privacy and Legal

- **No new privileged read.** Option B adds no reader, grant, function or DEFINER. §3.1 moves narrow the data each site receives (§5).
- **School isolation is unchanged.** Every reader takes the School explicitly and runs under `withSchool` and forced RLS. Every exception query either filters `school_id` or runs under the School context. A cross-School id answers like an absent one.
- **Campus.** Payroll is whole-School. No `payroll.*` key is Campus-adopted, and nothing here adopts one (rule 101).
- **MFA and private resources.** Unchanged. WO-3 keeps every existing capability check, its order (C14) and the 404.
- **Runtime role limitation, recorded and not new.** `school_os_app` holds INSERT, SELECT and UPDATE on `employees`, `employment_records` and `employee_assignments`, and SELECT on `employee_account_links`. One runtime role serves every domain, so the database cannot tell a Payroll write from an HR one. "Payroll never writes HR" is an application boundary, pinned by `HrWriterBoundaryTest` and, after WO-3, the register guard. Trigger-level HR invariants still bind every writer.
- **Security approval:** not required by the docket for D-2, and none recorded.
- **Legal and DPO:** no purpose or recipient expands. The same Payroll surfaces read the same facts, or fewer. No retention period changes (E21-D9 stays unratified).

## 9. D-2, R1-01 and R1-02

- **R1-02** has three conjuncts. D-2 decides the third (an owner-approved Payroll exception) for **architecture** only. It is **not closed**:
  - WO-3 must ship and pass;
  - X-1 (Timetable, Transport and Visitor controllers) and the 24 non-Payroll files still fail the first two conjuncts.
- **R1-01** (one writer) is separate and **PENDING**. D-2's "Payroll never writes HR" is consistent with R1-01's proposed point 1, but adopts none of its ten points.

## 10. Re-review triggers

D-2 is re-opened by Architecture when any of these happens:

- a new Payroll reference to an HR model, table or function, outside §3;
- a change to `employment_records`' columns or keys that a §3 site reads;
- a change to any FK in §3.4, or to a function's security mode;
- a reader in §5 changing its fields, School handling or error behaviour;
- a Payroll write to an HR table (prohibited outright).

## 11. WO-3: the authorized work order (not started)

| Field | Content |
|---|---|
| Owner | Payroll, with HR for the readers (unchanged) |
| Authority | D2-1 to D2-3. Starting it still needs the owner's go-ahead, as every checkpoint does |
| Scope | The 22 moves in §3.1; the `listAssignments()` decision (§3.5); remove D2-A5; optionally remove D2-N09 once unused |
| Not in scope | §3.2 to §3.4; the protected functions; any calculation, approval, posting, reversal or correction path; a schema change; a new reader; X-1; XRP-3; C14-N1 |
| Database | None. No migration, grant or function. If one seems needed, stop and return to Architecture |
| Authorization | Unchanged checks in unchanged order. The 9 API sites keep the 404 with no HR class name (D2-3), and a cross-School id stays indistinguishable from an absent one |
| Dates | `span()` uses the row's own dates. `labels()` gives current facts, as today. No as-of change |
| Financial non-regression | Frozen results, sources, attribution, attestation, reversal and reconciliation are byte-for-byte unaffected. Exports produce the same rows and bytes for the same data |
| Guard | A new architecture test pins the full §3 register: the exception and no-change site set per file, no Payroll write to HR, no new HR import in Payroll, the 9 FKs and the 8 functions with their security mode (fail on a new one) |
| Tests | Below |
| Performance | `labels()` batched once per page or export, never per row. `span()` is one row. Expected query count equal or lower; measure the three exports and the run page before and after |
| Rollback | Code only; revert the commit |
| CI | Rule 82 local gates, then the exact-SHA `CI gate` (full sharded suite) on the candidate. Counts as unit 1 of 5 since the last regression checkpoint |
| Docs | This register (final split), ADR 0034 item 20, `PAYROLL.md` dependency position, ownership map §3, closure contract R1-02, CLAUDE.md rule for the pin |

**Tests WO-3 must add or keep green:**

- per moved API site: allow; deny (capability); absent id = 404 with no HR class name; another School's id = the same 404;
- per moved page or export: names and numbers equal to today's for active, separated and rehired employments, and for an Employee with several employments;
- statutory admin `exists()` → `span()` for every employment status, including ended;
- the payslip of a separated Employee and of a rehired one;
- a replaced or unlinked User: the payslip and the run page are unchanged;
- a voided link (rule 145): independence and beneficiary results unchanged (protected functions untouched);
- a frozen, approved, posted and reversed run: results, sources, attestation and reconciliation unchanged;
- HRX v1 and v2 snapshots on one run: comparison unchanged;
- raw-role: the runtime role still cannot reach another School's employment through a moved site;
- the Payroll suites and the C1 to C8 proofs (`PayrollCompensationProvenanceDbTest`, `PayrollParticipantApprovalDbTest`, `PayrollReversalReconciliationTest`, `PayrollAccountLinkConcurrencyTest`, `PayrollCompensationIntegrityTest`) green and unchanged.

**No other D-2 work order exists.** No database exception needs building, because §3.4 is approved as it stands. No HRX compatibility fix is needed, because §6 found no defect.

## 12. Carried forward, unchanged

| Item | State | Owner |
|---|---|---|
| C22-N1, guard functions not pinned as INVOKER with their `proconfig`; hashed by name, not `tgfoid` | Open, LOW. No runtime bypass shown: it needs owner DDL (`ALTER FUNCTION … SECURITY DEFINER`), which the runtime role cannot issue | Platform / Security |
| C22-N2, the body hash strips comments | Open, LOW. Detects drift, not a hostile owner | Platform |
| C22-N3, a School with no history start can withdraw a past legacy holiday | Open, LOW. No answer changes (NOT_CONFIGURED) | HR owner |
| C22-N4 | Accepted LOW (§6) | HR owner |
| C18-A12, manual screen-reader pass (WO-6); WO-5 pages also not screen-reader certified | Pending | HR owner |
| C20-N1, C20-N2, C19-N1, C19-N2 | Open as recorded | Their owners |
| C14-N1, sibling lookups before capability, D2-X16 among them | Open | WO-10 / Payroll |
| C14-N5, version-bound credential verification | Open, MEDIUM | HR owner |
| Person-level Identity independence | Open, MEDIUM | Identity, Security |
| R1-01, R1-09, §1, §4, D-3, D-4, D-7, D-9, D-10, C14-N2, C11-N4 | Pending | As in the docket |
| HRC-4, HRC-8, LVA-4, XRP-3, XRP-4, TRR-6, TRR-8 | Open; D-2 closes none of them | As in the audit register |
| E21-D9, E45/ESI-12, E47, HRX-L1 to L4, S-4, S-7, X-1, ADR 0036, ADR 0040, D-11 | Unchanged; nothing determined | Legal, DPO, Security |
| Five closure baselines | Not selected; `a3f36cd2` is not chosen by this record | HR owner |

**The capability map is unchanged at 361 rows:** IMP 163, ALT 22, PART 8, MISS 0, XDOM 7, DEF 68, GATE 87, NR 6. All 61 original audit identifiers are preserved.

## 13. Readiness

| Question | Answer |
|---|---|
| D-2 | **APPROVED** (Architecture, 2026-11-11), not implemented |
| R1-02 | Open: architecture decided, implementation (WO-3), X-1 and the non-Payroll files outstanding |
| HR.R1 | **OPEN.** Not eligible for the final architecture assessment |
| Local synthetic UAT | Eligible for implemented, governed functionality, unchanged |
| Staging | Paused (owner, 2026-10-17); AWS not authenticated |
| Production | **NO-GO** |

**Next.** Either WO-3, the smallest checkpoint this decision authorizes, or R1-01, the next formal Architecture decision. WO-3 is recommended: it is bounded, needs no further decision, and is the only path to R1-02's third conjunct. Neither starts without the owner's go-ahead.
