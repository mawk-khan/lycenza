# HR.R1-C25: R1-01, what "exactly one writer" means, decided (2026-11-11)

**HR.R1 stays OPEN, and this is not the closure audit.** This record holds what Architecture decided on R1-01, the re-verified evidence behind it, the writer exception register it adopts, and the work it leaves. **Nothing was built.** No PHP, migration, grant, function or test changed.

| | |
|---|---|
| Baseline | `2f5227ec` = `main` = `origin/main`, clean tree; `ede8af12` (C24 code) is an ancestor. One worktree; the historical stash and `integration/e16-r2` preserved |
| Last full suite | `ede8af12`, CI gate run 43100706287: 11,625 cases, 0 failures, 1 skip (ESI-12). Regression counter 0/5 |
| Local database | DDEV `db`, 390 migrations. Read-only catalogue queries only |
| Decided here | R1-01's definition, point 4 (C12-N2), the primary-correction home, the C24 export query deviation. All by Architecture |
| Not decided here, not inferred | R1-09, §1, §4, D-3, D-4, D-7, D-9, D-10, C10-N2, C14-N1, C14-N2, C11-N4, every Legal, DPO and Security determination, the five closure baselines |

## 1. Decisions

The owner answered the decision intake in the HR.R1-C25 session on 2026-11-11. The docket assigns R1-01 to **Architecture** alone (`HR-R1-C12-DECISION-DOCKET.md` §1, §2).

| Id | Role | Selected | Source |
|---|---|---|---|
| R1-01-1 | Architecture | **Adopt the ten points with the docket's refinements** (`HR-R1-C12-DECISION-DOCKET.md` §5). The shared runtime role is a stated limitation. Per-module database credentials are **not** adopted, not even as a planned project | Owner answer, R1-01 question |
| R1-01-2 | Architecture | **Point 4 stands.** The runtime role must hold only the privileges the named operations need. The unused DELETE on four tables (C12-N2) is revoked by a later work order (WO-11, §9), not now | Owner answer, point 4 question |
| R1-01-3 | Architecture | **`EmployeeAssignmentService::setPrimary()` stays the named writer of the `primary_correction` placement change** (rule 130 sanctions it). C10-N2 stays a separate open finding for the HR owner | Owner answer, placement question |
| R1-01-4 | Architecture | **The C24 export query deviation is accepted:** +3 constant statements per statutory export, caused by HR's unchanged reader; bytes identical, no per-row growth | Owner answer, exports question |

**What these approvals are not.**

- **Not an implementation.** WO-11 and WO-12 (§9) are needed before R1-01 can pass on the closure baseline.
- **Not a Security approval.** No privilege is widened, and WO-11 narrows one. No Security, Legal, DPO, Payroll or Platform approval was given or needed, and none is recorded.
- **Not a decision on R1-09, §1, §4 or C10-N2.**
- **No new mutation authority.** The decision grants none to any domain.

**Completion state: R1-01 APPROVED — IMPLEMENTATION/EVIDENCE WORK REMAINS.**

## 2. The adopted definition

**Exact criterion** (`HR-R1-CLOSURE-CONTRACT.md` §3, R1-01): "No duplicate source of truth. Every row of `HR-DOMAIN-OWNERSHIP-MAP.md` §1 has exactly one writer", method "grep writers per table; architecture guards".

**Principle (refinement 1).** *Every business fact has one canonical domain owner and one governed mutation contract. Several explicitly authorized service operations may change that fact, always through its owner.*

A tenant table satisfies one-writer when all of these hold. Points 1 to 10 are C10's proposal word for word (`HR-R1-C10-ARCHITECTURE-INVENTORY.md` §1.8). The refinements adopted with them are marked **R2** to **R7**.

1. **Canonical domain owner.** Exactly one bounded context owns the table. No other domain's code issues a write to it.
   - **R5, cross-domain writers:** never, unless a rule names the exception. The approved exceptions are rule 76 (`SchoolSettingsController::update()` on `schools`) and rule 86 (`SchoolBootstrapAdministrationService` granting the bootstrap administrator's role).
2. **Entry points invoking one service.** Controllers, API, console, imports and other services may trigger a write only by calling the owner's Application service. Any number of entry points is allowed. A controller, job or import never writes the table itself.
   - **R4, imports:** authorized only through the owner's service.
   - **R6, trigger-only tables:** a table whose only writer is a trusted trigger satisfies this point (`employee_account_links`, rule 145).
3. **Named operations.** Normally one Application class writes a table. A second class is allowed only if the table's register names it and the operation it alone performs. The same operation in two classes is a duplicate writer.
   - **R2, the register:** for HR it is `HrWriterBoundaryTest::NAMED_WRITERS` plus `WRITE_A_LOCKED_ROW`; elsewhere, a table's own rule. The hand-kept `WRITE_A_LOCKED_ROW` list is acceptable.
4. **Raw SQL privileges.** `school_os_app` holds only the privileges the named operations need, enforced by revoke plus `DatabaseRoleVerifier`. A raw-SQL write inside the named class is permitted; it is still that class's write.
   - **R3:** a privilege is not a business authorization, and a permitted UPDATE is not thereby a legitimate mutation.
5. **Migration-only writes.** Schema changes and one-time backfills in a migration are not writers.
6. **Projections.** A column maintained by a trigger from another table is not a second writer. It belongs to the source table's writer, and a direct write is refused by the database (the `employment_records` terms columns).
7. **Audit and history writers.** An append-only row written in the same transaction as its parent operation, or by a trigger on the parent, belongs to the parent's writer. It needs append-only privileges, not a separate register entry.
8. **Retention.** Deletion by the retention identity or a retention function is a platform mechanism, recorded in the retention catalogue. It is not a business writer.
9. **Fixtures.** Seeders that bypass the service are excluded only if guarded to local or testing.
10. **Rows without tables.** Mechanisms, readers and unbuilt concepts are marked not applicable. They do not pass or fail.

**R7, scope.** The definition is general: any domain may apply it. It carries R5 and R6 wherever it is used.

**What "writer" means here.** The definition keeps five things apart:

| Term | Meaning |
|---|---|
| Business-fact owner | The domain responsible for a fact's meaning and lifecycle (point 1) |
| Canonical mutation authority | The owner's named Application operations (points 2 and 3) |
| Physical database mutation | The PostgreSQL role that issues the statement: `school_os_app` for every domain at runtime (point 4 and §6) |
| Maintenance authority | Migration (`pgsql_admin`, point 5) and retention (`school_os_retention`, point 8) |
| Derived evidence | Projections, history and other domains' snapshots (points 6 and 7; §5.5) |

An endpoint, a PHP method and a database account are not owners.

**How it fits existing contracts.**

- **ADR 0002's "single writer per record"** is system level: Laravel writes and the AI service does not. It is unchanged.
- **Domain rules** that say "one writer" (rules 100, 105, 110, 121, 128, 131, 137) and ADR 0063's "one writer per ownership table" are instances of point 3.
- **D-2 (C23/C24)** is consistent: "Payroll never writes HR" is point 1, and Payroll's snapshots are point 7 and §5.5.

## 3. Re-verified evidence (current main)

Re-run at `2f5227ec` and compared with C10 (`82e70a8a`) and C12 (`0b77270c`).

| Measure | C12 | C25 | Change |
|---|---:|---:|---|
| Foreign-domain writes to HR, Leave, Staff Attendance, WorkforceTasks tables | 0 | **0** | Pinned by `HrWriterBoundaryTest::no_foreign_domain_writes_an_owned_table` and, for Payroll, `payroll_never_writes_an_hr_table` (C24) |
| Controller writes to those tables | 0 | **0** | `no_controller_writes_an_owned_table_itself` |
| True duplicate writers (same operation in two classes) | 0 | **0** | — |
| Tables with several named writing classes | 4 HR + Payroll `employee_esi_coverage` | **same** | §5.2 |
| Runtime DELETE with no caller (C12-N2) | 5 tables | **4** | `staff_working_weekdays` became SELECT-only when C22 retired it. Left: `hr_departments`, `positions`, `employee_categories`, `hr_employee_number_counters`. No delete anywhere in `app/` |
| `staff_holidays` runtime DELETE | held | **revoked** | S-16, C22 |
| Tables added since C12, with their one writer | — | 4 | `staff_working_week_versions` and `staff_holidays` lifecycle: `StaffCalendarService`. `staff_attendance_month_locks` and `staff_attendance_locked_corrections`: `StaffAttendanceService`. The approved correction is applied to `staff_attendance_records` by the trigger `staff_attendance_locked_corrections_apply` (§5.4) |
| R1-02 files outside HR and Payroll importing HR models | 24 | **24** | All reads; no write-shaped statement on an HR model |

**Runtime privileges** (`has_table_privilege('school_os_app', …)`, live catalogue, HR family):

| Class | Count | Tables |
|---|---:|---|
| SELECT only | 2 | `employee_account_links`, `staff_working_weekdays` |
| INSERT only | 20 | history and run tables, including `employment_terms`, `employee_placement_changes`, `leave_ledger_entries`, `staff_attendance_corrections`, `staff_attendance_month_locks` |
| INSERT, UPDATE | 36 | `employees`, `employment_records`, `employee_assignments`, the profile tables, the Leave configuration tables, `staff_attendance_records`, `staff_working_week_versions`, `staff_holidays`, the task tables, Payroll's statutory tables |
| INSERT, UPDATE, DELETE | 5 | the four C12-N2 tables and Identity's `staff_account_invitations` (outside HR; its pruning command deletes) |

## 4. Who owns what (business facts)

Code-level detail per table stays in `HR-R1-C10-ARCHITECTURE-INVENTORY.md` §1.3 and §1.4, which C25 re-checked for the rows below. "Backstop" is a database guarantee about the fact, never about which module wrote it.

| Fact | Owner | Canonical writer(s) | Other entry points | Database backstop | Status |
|---|---|---|---|---|---|
| Employee identity | HR | `EmployeeService` | onboarding, import, link correction, HR controllers | no runtime DELETE; archive triggers; account-link history by trigger | PASS |
| Employment | HR | `EmploymentService` | `EmployeeLifecycleService`, `SeparationCaseService`, onboarding, import (delegate) | no runtime DELETE; lifecycle and admission triggers; terms projection | PASS |
| Employment terms | HR | `EmploymentTermsService`; first row by trigger | — | append-only | PASS |
| Placement (assignments) | HR | `EmployeeAssignmentService`, `EmploymentService` (end), `PlacementChangeService` (move) | — | overlap and identity triggers | PASS: named operations (§5.2). C10-N2 open |
| Placement changes | HR | `PlacementChangeService`; `EmployeeAssignmentService::setPrimary()` (`primary_correction`, R1-01-3) | — | append-only | PASS: named |
| Reporting lines | HR | `ReportingRelationshipService` | `ReportingHierarchyService` delegates | guard trigger, advisory lock | PASS |
| Separation cases, clearance | HR | `SeparationCaseService`, `SeparationClearanceRequirementService` | — | decided terms frozen | PASS |
| Profile, reference codes, documents record | HR | one service per table | — | no runtime DELETE; archive frozen | PASS |
| Department, Position, Category, number counter | HR | `DepartmentService`, `PositionService`, `EmployeeCategoryService`, `EmployeeNumberAllocator` | — | **runtime DELETE held, unused** | **FINDING** (C12-N2 → WO-11) |
| Account-link history | HR (rule 145) | triggers only | correction workflow | runtime SELECT only | PASS |
| Leave types, years, policies, rules | Leave | one service each | — | version immutability | PASS |
| Leave ledger | Leave | `LeaveLedgerService` | requests, year close, accrual, admin (delegate) | append-only | PASS (pinned) |
| Leave policy assignments | Leave | `LeavePolicyAssignmentService`, `LeaveEligibilityService` (run) | — | — | PASS: named |
| Staff calendar | Leave (rule 149) | `StaffCalendarService` | — | append-only versions, verifier hashes | PASS |
| Staff attendance, corrections, month locks | Staff Attendance (rule 147) | `StaffAttendanceService`; approved locked corrections applied by trigger | — | month guards, append-only corrections | PASS |
| Workforce tasks | WorkforceTasks | `TaskGenerationService`, `TaskAssignmentService`, `TaskWorkflowService` | the scheduled `workforce-tasks:generate` (delegate) | no runtime DELETE; events append-only | PASS: named |
| Compensation, runs, calculations, approvals, postings, reversals, corrections, snapshots | Payroll (ADR 0034) | `CompensationService`, `PayrollRunService`, `PayrollPostingService`, reconciliation and correction services | administration services, controllers (delegate) | provenance, approval, posting and reversal guards | PASS (not re-censused beyond C10/C24) |
| Statutory ESI coverage | Payroll | admin service; `StatutoryPayrollCalculationService` (calculation-time row) | — | — | Needs its register entry (WO-12) |
| School, Campus, profile | Organization | `SchoolLifecycleService`, `SchoolProfileService`, `CampusAdministrationService`; `SchoolSettingsController` (rule 76) | — | lifecycle trigger | PASS by R5 |
| Login identity, memberships, grants | Identity | named paths (rules 86, 92–94) | `SchoolBootstrapAdministrationService` (rule 86) | grantor and scope triggers | PASS by R5 |
| Documents (bytes) | Documents | `DocumentService` (`createForEmployee()` through HR's `EmployeeDocumentPolicy`) | — | — | PASS: the HR record and the bytes are two facts with two owners |
| Timetable, Transport, Visitor duties | their domains | their own services; HR facts read only (`DutyCandidateReader`, `EmploymentCoverage::hold()`) | — | composite keys | PASS for writes; reads are R1-02 / X-1 |
| Audit and outbox | Platform | `AuditRecorder`, outbox writers | every domain, in its own transaction | append-only | Point 7: evidence of the operation, not an owner of its fact |

## 5. Writer exception register (adopted)

### 5.1 Categories

| Category | Answer | Pinned by |
|---|---|---|
| Canonical domain service | Satisfies | `a_table_with_several_writers_has_exactly_the_named_ones` |
| Second named operation | Satisfies if registered, one operation each | `NAMED_WRITERS` |
| Write through a locked row | Satisfies if listed | `WRITE_A_LOCKED_ROW` |
| Trusted database trigger | Satisfies for its projection or history (points 6 and 7, R6) | catalogue tests, verifier |
| Migration | Not a writer (point 5) | rule 54 |
| Retention service or function | Platform mechanism (point 8) | retention catalogue, `retention_role_*` verifier checks |
| Authorized import | Through the owner's service (R4) | `EmployeeImportService`, rule 122 |
| Local demo seeder | Excluded while guarded to local (point 9) | `DemoEnvironmentGuard` |
| Test fixture | Excluded | rule 93 |
| Audit and outbox | Evidence of the parent operation (point 7) | append-only privileges |
| Another domain's snapshot | Its own fact, not a write of the source (§5.5) | D-2 register |
| Cross-domain writer | Never, except rules 76 and 86 (R5) | `no_foreign_domain_writes_an_owned_table` |

### 5.2 Named multi-writer tables (point 3)

| Table | Named operations | State |
|---|---|---|
| `employee_assignments` | `EmployeeAssignmentService` (create, end, set primary), `EmploymentService` (close at the employment's end), `PlacementChangeService` (close a move's source through a locked row) | Approved; pinned in `NAMED_WRITERS` and `WRITE_A_LOCKED_ROW`. Retention is point 8, listed because the scan sees it |
| `employee_placement_changes` | `PlacementChangeService` (transfer, promotion, designation, department), `EmployeeAssignmentService::setPrimary()` (`initial_placement`, `primary_correction`) | Approved (R1-01-3); pinned |
| `workforce_tasks` | `TaskGenerationService` (generated occurrences), `TaskAssignmentService` (ad hoc task, reassignment cancel), `TaskWorkflowService` (transitions, through a locked row) | Approved; pinned. Rule 139 names only the generator; this register names the other two |
| `leave_policy_assignments` | `LeavePolicyAssignmentService` (per request), `LeaveEligibilityService` (run, adds only) | Approved; pinned. Rule 133 names only the run |
| `employee_esi_coverage` (Payroll) | `EmployeeEsiCoverageAdminService` (administration), `StatutoryPayrollCalculationService` (calculation-time row) | Approved here as named operations; **not yet pinned** (WO-12) |

### 5.3 Approved cross-domain writers (R5)

| Writer | Fact | Owner | Authority |
|---|---|---|---|
| `SchoolSettingsController::update()` | `schools` profile fields | Organization | rule 76 (simple entity), rule 75 (never `status`) |
| `SchoolBootstrapAdministrationService` | the bootstrap administrator's School membership and role grant | Identity | rule 86 (provisioning only), ADR 0047 |

No other cross-domain writer is approved. A new one needs a rule naming it.

### 5.4 Database writers (triggers and definers)

| Function | Writes | Mode | Runtime role may call it directly | Category |
|---|---|---|---|---|
| `hr_employment_records_initial_terms` | the first `employment_terms` row | trigger | — | history (point 7) |
| `hr_employment_terms_project` (on `employment_terms`) | `employment_records` type, staff type, category columns; a direct write is refused by `trg_employment_records_terms_projection_guard` | trigger | — | projection (point 6) |
| `employees_record_account_link` | `employee_account_links` | DEFINER, `search_path` pinned | no | history (R6, rule 145) |
| `employee_account_link_corrections_apply` | voids a link on approval | DEFINER, pinned | no | named operation of the correction workflow (rule 145) |
| `staff_attendance_locked_corrections_apply` | applies an approved locked-month correction to `staff_attendance_records` | DEFINER, pinned | no | named operation of `StaffAttendanceService`'s workflow (rule 147) |
| `retention_expire_leave_employee_evidence`, `_staff_attendance_employee_evidence`, `_payroll_employee_evidence` and the other `retention_expire_*` | lawful deletion | DEFINER, `pg_catalog, pg_temp` | no; `school_os_retention` only | retention (point 8) |

**`retention_expire_payroll_employee_evidence` (D2-F8):**

- **Owner and caller:** a Payroll retention unit (E21.3F), run by `school_os_retention` through `PayrollEvidenceRetentionService` and the scheduled `platform:payroll-retention-prune`.
- **Execution:** SECURITY DEFINER, owner `db`, `search_path = pg_catalog, pg_temp`. EXECUTE is held by `school_os_retention`, not `school_os_app`.
- **Effect:** it deletes evidence past its period after hold and anchor checks (rule 146: never restated). It authorizes no ordinary Payroll write to HR.

### 5.5 Snapshots and projections

- **Payroll's frozen evidence is Payroll's fact.** Result rows, calculation sources, HRX snapshots, attestations and reconciliation snapshots are immutable Payroll records, and they hold HR keys by composite FKs (D2-FK1 to FK9). Writing a frozen copy is not writing the HR source: the source row is never changed, and the copy is never rebuilt from today's HR state (ADR 0034).
- **No writable duplicate exists.** No domain keeps a writable copy of an HR fact. The terms projection is the only projection, and it is database-maintained (point 6).

### 5.6 Permanence

| Exception | Kind |
|---|---|
| Rules 76 and 86 cross-domain writers | Permanent, rule-bound |
| Named multi-writer operations (§5.2) | Permanent, register-bound |
| Trigger writers (§5.4) | Permanent |
| Retention functions | Permanent, E21-bound |
| Migrations, backfills | One-time (point 5) |
| Demo seeders | Local only (point 9) |
| Runtime DELETE on the C12-N2 tables | **Not an exception.** A finding, to be revoked by WO-11 |

## 6. Enforcement, layer by layer

| Layer | What it proves | What it does not |
|---|---|---|
| Domain contracts (rules, ADRs, this record) | The intended owner and operations | Anything at runtime |
| Module dependency rules (rule 4) | No foreign import of another domain's internals, where pinned | Writes through a shared table name |
| Canonical services | Invariants, locks and audit inside the operation | That nobody else writes |
| Capability authorization, fresh MFA | Who may ask for an operation | Which code ran |
| `SchoolOperationalGuard` | The School is operational at execution (rule 86) | Ownership |
| Source writer guards (`HrWriterBoundaryTest`, `PayrollHrBoundaryRegisterTest`, domain guards) | No static write or reference outside the register | Dynamic SQL, runtime-built names, writes through other variables, lazy relation access (C25-N1) |
| Runtime privileges (`school_os_app`) | What the role can never issue (no DELETE, append-only, SELECT-only) | Which module issued a permitted statement |
| Forced RLS, composite keys | Same-School integrity | Ownership |
| Triggers, immutable evidence | The fact's own invariants, for every role | Who acted (rule 102) |
| Migration and retention roles | Schema and lawful deletion stay separate identities | — |
| CI gate (exact SHA) | All of the above ran green on that code | Anything not asserted |
| Independent review | A second reading of a change | Future code |

**The shared role, stated plainly.** Every domain's runtime code connects as `school_os_app`. PostgreSQL cannot tell a Payroll statement from an HR one. Ownership is therefore enforced by the application, the source guards and the fact-level database invariants, never by a per-module database identity. R1-01-1 keeps it that way.

## 7. Reconciliations

- **D-2:** consistent and untouched. 22 reader sites, 26 exceptions, 12 no-change, 9 keys, 8 functions. Payroll writes no HR table (point 1), and its snapshots are its own facts (§5.5).
- **R1-02:** still **PARTIAL**. R1-01 is about writers, and R1-02 about readers. X-1 (Timetable, Transport and Visitor controllers) and the 24 non-Payroll files are reads, so none of them is a writer under this definition. Their reader migration or exception is a separate decision.
- **R1-03:** unaffected. Dates before a School's calendar history start stay "not recorded" (D6-3).
- **R1-09:** unaffected and pending. Clearance answers are reads of other domains' obligations (rule 137); this definition changes nothing about them.
- **§1** requires the wider ERP connections before HR.R1 runs. The definition is general (R7) but does not connect anything, so it neither satisfies nor conflicts with §1.
- **§4** sets the verdict rule. R1-01's verdict can now be evaluated: it passes or fails on the baseline against §2's definition. It will fail while WO-11 is open (point 4) and while §5.2's Payroll entry is unpinned (point 3). No amendment to §1 or §4 is needed or made.
- **Original findings:** HRC-4, HRC-8, LVA-4, XRP-3, XRP-4, TRR-6 and TRR-8 are not about writers. R1-01 contributes nothing to closing them, and they stay open. All 61 identifiers are preserved.

## 8. Carried forward and new findings

| Item | State |
|---|---|
| C22-N1 (guards not pinned as INVOKER with their `proconfig`; hashed by name, not `tgfoid`) | Open, LOW. A specified work order, WO-13 (§9) |
| C22-N2, C22-N3 | Open, LOW |
| C22-N4 | Accepted LOW |
| C24-N1 (malformed API id → 500) | Open, LOW, Platform. A specified work order, WO-14 (§9). No disclosure without debug mode; no availability effect beyond one request |
| C24 export name source | Recorded (`HR-R1-C24-WO3-READER-MIGRATION.md` §3, R-2). Results are frozen by `payroll_run_results_reject_rewrite`, but nothing in the database freezes `employment_records.employee_id` or ties the two. Application invariant only: `employee_id` is set once by `EmploymentService` and is not fillable. Proposed work order WO-15, needing a Payroll/HR integrity decision first. No FK invented, no result rewritten |
| C24 export query deviation | **Accepted** (R1-01-4) |
| C24 post-review fixes | **Delta-reviewed in C25** by an independent reviewer (read-only, at `ede8af12`). No HIGH or MEDIUM; the null-name fix, the < 500 assertion and the stated limits are correct. One LOW below, and two INFO: the throw path is untested (unreachable), and the rehire export test is weaker than its name |
| **C25-N1** | **New, LOW.** `PayrollHrBoundaryRegisterTest`'s docblock says a new site "fails here", but lazy relation access (`$result->employmentRecord->employee->full_name`), array-form eager loads (`->with(['…employee'])`) and `new`/`instanceof`/return types are not counted, and the docblock does not name them. The variable-write heuristic also misses relation-chain writes. Folded into WO-12 |
| C24 assertion count | 33 more cases, 2,156 fewer assertions than C22, attributed to row-dependent tests. Not traced test by test; no test removed |
| C10-N2 | Open, P3, HR owner (R1-01-3 keeps it separate) |
| C18-A12, C20-N1, C20-N2, C19-N1, C19-N2, C14-N1, C14-N2, C14-N5, person-level Identity MEDIUM | Open as recorded |

## 9. Work orders (none started)

| WO | Owner | Scope | Database | Tests | Readiness effect |
|---|---|---|---|---|---|
| **WO-11** C12-N2 revoke | HR, Platform | Revoke runtime DELETE on `hr_departments`, `positions`, `employee_categories`, `hr_employee_number_counters`; pin in `DatabaseRoleVerifier`; `down()` restores | One migration through `pgsql_admin`, rollback-proved (rule 143) | raw role cannot DELETE; services unchanged; verifier pin fails when re-granted | R1-01 point 4 |
| **WO-12** R1-01 register pins | HR, Payroll | Pin §5.2's Payroll entry; name the C25-N1 blind spots in the docblocks and, where cheap, extend the scan to array-form eager loads and relation-chain writes; keep the register in one place | None | planted violations for each new shape; no false positive on the canonical writers | R1-01 point 3 |
| **WO-13** C22-N1 verifier | Platform, Security | Resolve each trigger's `tgfoid`, pin INVOKER/DEFINER, owner, grants and `search_path`; fail on an unexpected DEFINER | None (verifier only) | negative proof: an `ALTER FUNCTION … SECURITY DEFINER` on a guard fails the verifier | Before final architecture acceptance |
| **WO-14** C24-N1 API ids | Platform | UUID constraint on the API's `{employmentRecord}` (and peers) so a malformed id is the private 404 before any query; capability order and body unchanged | None | malformed, absent, foreign: one 404; no query reaches PostgreSQL | None on HR.R1 |
| **WO-15** export identity (proposed) | Payroll, HR | Decide whether `employment_records.employee_id` is database-frozen, or `(employment_record_id, employee_id)` is tied on results | Possibly a migration (needs that decision) | — | None until decided |

Each needs the owner's go-ahead. WO-11 and WO-12 are code-lane units with the full exact-SHA suite. **Not adopted:** module-specific database credentials (R1-01-1).

**Negative tests a future R1-01 evaluation needs:** a planted foreign write, controller write, duplicate operation, unnamed second writer and raw-SQL write each fail; the canonical writers, delegated entry points, triggers, retention, migration and demo paths pass; the raw role refuses DELETE on each WO-11 table; a re-granted DELETE fails the verifier.

## 10. Re-review triggers

Architecture re-opens R1-01 when:

- a table gains a second writing class not in a register;
- a cross-domain write appears outside rules 76 and 86;
- a runtime privilege is widened on an HR-family table;
- a trigger starts writing another domain's table;
- a module-specific database identity is proposed.

## 11. Readiness

| Question | Answer |
|---|---|
| R1-01 architecture | **APPROVED** (Architecture, 2026-11-11) |
| R1-01 enforcement | **PARTIAL.** Points 1, 2, 5 to 10 hold today and are pinned or categorical; point 3 lacks the Payroll entry's pin (WO-12); point 4 fails on four tables (WO-11) |
| R1-02 | **PARTIAL** (unchanged) |
| HR.R1 | **OPEN.** Not eligible for the final architecture assessment |
| Capability map | Unchanged at 361 rows: IMP 163, ALT 22, PART 8, MISS 0, XDOM 7, DEF 68, GATE 87, NR 6 |
| Local synthetic UAT | Eligible for implemented, governed functionality |
| Staging | Paused (owner, 2026-10-17); AWS not authenticated |
| Production | **NO-GO** |

**Next.** WO-11 together with WO-12: the smallest work that makes R1-01 pass. Neither starts without the owner's go-ahead.
