# HR.R1-C26: R1-01 enforcement for the HR family (WO-11, WO-12, review M1 to M5), 2026-11-12

**HR.R1 stays OPEN, and this is not the closure audit.** This record holds what C26 built to make the adopted one-writer definition (`HR-R1-C25-R1-01-DECISION.md` §2, rule 151) executable for the HR family, the independent review of that contract, the owner decisions taken on it, and the evidence. Publication evidence (CI runs, final SHAs) follows in a separate evidence commit.

| | |
|---|---|
| Baseline | `b7204210` = `main` = `origin/main`, clean tree, one worktree; `ede8af12` (last runtime code) an ancestor. The historical stash and `integration/e16-r2` preserved. `main-protection` ruleset active (deletion, non-fast-forward, linear history, required `CI gate`), 0 bypass actors |
| Last full suite before C26 | `ede8af12`, CI gate run 43100706287: 11,625 cases, 0 failures, 1 skip (ESI-12). Counter 0/5 |
| Local databases | DDEV: `db` and `school_os_test`, 390 migrations each before C26, 255 forced-RLS tables each. `school_os_app`: not superuser, no BYPASSRLS, NOINHERIT, member of no role |
| Authorized | Owner, 2026-11-12, in the C26 session: implement WO-11 and WO-12 and publish; then, on the review, widen C26 to close M1 to M3, and (Architecture) decide M5 |
| Not decided here | R1-09, HR.R1 §1, §4, D-3, D-4, D-7, D-9, D-10, C10-N2, C14-N1, C14-N2, C14-N5, the person-level Identity MEDIUM, every Legal, DPO and Security determination, the five closure baselines. WO-13, WO-14 and WO-15 not started |

## 1. Authorization and decisions

| Id | Role | Decision | Source |
|---|---|---|---|
| C26-A1 | Owner | Implement WO-11 + WO-12 (C25 §9) only if the independent review finds no HIGH contradiction; full exact-SHA CI; publish through `integration/*` and `publication-check` | Owner answer, C26 go-ahead question |
| C26-A2 | Owner | Widen C26 to close review M1 to M3 in the same test and verifier layer, with no new business behaviour | Owner answer, M1 to M3 scope question |
| C26-A3 | Architecture | **M5: add a rule 76 category to point 2.** A controller's short inline create-then-audit-then-event for a genuinely simple entity of its own domain (rule 76) satisfies point 2, so R7 (the definition is general) stands. `SchoolSettingsController::update()` is a rule 76 controller write and a rule 76/75 cross-domain write. HR-family tables never use the shape | Owner answer, M5 question |

No Security, Legal, DPO, Payroll or Platform approval was given or needed. Every privilege change narrows.

## 2. Independent review of the C25 contract

A read-only reviewer with no part in C25 examined the decision, the C12 docket §5 and C10 §1.8, `HrWriterBoundaryTest`, `PayrollHrBoundaryRegisterTest` and `DatabaseRoleVerifier` at `b7204210`, every HR-family model and its static write sites (its own per-model scan), cross-domain writes, the relevant migrations, triggers and grants, all 31 scheduled commands and the demo guards. It did not read the live catalogue (C26 did, §4), runtime behaviour, Payroll's writers beyond `employee_esi_coverage`, or dynamic SQL in depth. The earlier C25 review covered C24's code delta only; this is the first independent review of the contract itself.

**Verdict: sound, with corrections.** No HIGH. No unaccounted writer, no exploitable bypass.

| Id | Severity | Finding | Disposition |
|---|---|---|---|
| M1 | MEDIUM | The foreign-domain and controller guards covered 19 of the 56 family tables; §11's "pinned" overstated | **Closed** (§5) |
| M2 | MEDIUM | Point 3 had no pin for single-writer tables: a second class writing `Employee` or `LeaveLedgerEntry` passed | **Closed** (§5) |
| M3 | MEDIUM | The verifier was a denylist: default privileges give every new table DELETE (how C12-N2 happened), and revokes already made for Leave and Staff Attendance were not pinned at deploy time | **Closed** (§4) |
| M4 | MEDIUM | WO-11 would break four tests that DELETE as the runtime role (`HrRawIsolationTest` :536, :629, :1367; `TenantCompositeSetNullTest` :99) | **Closed** (§4.4); the in-progress work had missed :629, the review caught it |
| M5 | MEDIUM | R7 (general) conflicts with rule 76 controller writes in about 20 controllers outside HR | **Decided** (C26-A3) |
| L1 | LOW | `employee_esi_coverage`: the two operations were not stated, and its retention writer was not named | **Closed** (§5.3) |
| L2 | LOW | The rule 86 entry understated the service (it also suspends earlier memberships and revokes their grants) | **Closed**: verified at `SchoolBootstrapAdministrationService.php`, C25 record amended |
| L3 | LOW | C25-N1 is mostly about readers (R1-02); only relation-chain writes concern R1-01 | Recorded; both done (§5.4) |
| I1 | INFO | §5.4 missed two trigger writes of their parent operation: `staff_attendance_locked_corrections_apply` inserts the `staff_attendance_corrections` row (`2027_02_13_090000`:304); the INVOKER `staff_working_week_versions_guard` updates the version it supersedes (`2027_02_15_090000`:132) | **Closed**: verified, C25 record amended; point 7 |
| I2 | INFO | The shared runtime role is described honestly everywhere | — |

**Scheduled commands (31).** Delegate: `workforce-tasks:generate` (each School to `TaskGenerationService`). Retention: `platform:employee-retention-prune` (HR, Payroll, Leave and Staff Attendance retention services, as the retention identity) and `platform:payroll-retention-prune` (the DEFINER function). Read-only on HR: `platform:storage-orphans-prune`. All the others touch no family table (`staff-account-credentials-prune` is Identity's invitations). Unresolved: none.

### 2.1 Final-state review of the candidate

A second independent read-only reviewer examined the candidate diff itself (the working tree against `b7204210`): the migration, every allowlist entry against every migration's revokes, the verifier check, both guards (re-running their scan logic and `fingerprint()` standalone over the real sources: `WRITERS` matched all 57 entries, all 36 register entries matched), the new and edited tests, and the documents. It did not run the suite, read the live catalogue or see CI.

**Verdict: pass with corrections.** No HIGH, no MEDIUM. All five LOWs were fixed before the candidate was committed, and the affected tests re-run green:

| Id | Finding | Fix |
|---|---|---|
| F-L1 | `has_table_privilege` misses a column-level grant and REFERENCES/TRIGGER, so "exactly" overstated | SELECT, INSERT, UPDATE read with `has_any_column_privilege`; REFERENCES and TRIGGER fail; drift cases for `UPDATE (voided_at)` and REFERENCES added A column-level REFERENCES still passed: C27-N1 (HR.R1-C27), with WO-13 |
| F-L2 | `assignment` is Payroll's own `CompensationAssignmentValue` relation, so the `lazy` and chain-write patterns over-matched (fail closed) | `assignment` removed from both lists; `employeeAssignment` kept |
| F-L3 | Two unstated scanner limits: the array form stops at its first `]`; a ternary's `: Employee::` counts as a type | Both named in the register docblock |
| F-L4 | The ADR status said "verified" without the CI condition | Conditioned on the exact-SHA `CI gate` and publication |
| F-L5 | "A new family table fails CI" held only for tables with a model | A sweep of every table named like the family's (`FAMILY_TABLE_NAMES`): it must be in the allowlist or in `NOT_FAMILY_TABLES` (Payroll's five, Identity's two). A planted model-less `hr_c26_probe` failed it |
| INFO | The new verifier method sat under an older, orphaned HR.R1-C1 docblock | Moved above it |

## 3. The contract as amended

Rule 151 and ADR 0028 (HR.R1-C26 amendment) carry the changes. Point 2 gains the rule 76 category (C26-A3); §5.3's rule 86 entry reads "grants the bootstrap administrator's role and suspends earlier memberships, revoking their grants, while the School is provisioning"; §5.4 gains the two trigger writes of I1 under point 7. Nothing else in C25 §2 changes.

## 4. WO-11 and the family allowlist (point 4)

### 4.1 Baseline (live catalogue, both DDEV databases)

`school_os_app` held DELETE on `hr_departments`, `positions`, `employee_categories` and `hr_employee_number_counters` by a direct ACL entry from the schema's default privileges (`infrastructure/docker/postgres/init/01-roles.sql`: `GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES`). No grant to PUBLIC, no role membership. Nothing in `app/`, the seeders, the console or a retention function deletes these tables; their rows leave with their School (`school_id` cascades, run as the table owner; the runtime role can never delete a School, rule 86). `TenantRetentionCatalog`: `hr_configuration`, tenant lifetime.

`staff_account_invitations` (Identity) keeps its DELETE: `staff-account-credentials-prune` deletes from it under Identity's own contract. Not part of WO-11 and not a family table.

### 4.2 Change

- Migration `2027_02_16_090000_revoke_unused_runtime_delete_on_hr_configuration`: `REVOKE DELETE` on exactly the four tables; `down()` restores the grant it removed. SELECT, INSERT and UPDATE unchanged.
- `DatabaseRoleVerifier::NO_RUNTIME_DELETE` lists the four.
- **The allowlist (M3).** `DatabaseRoleVerifier::HR_FAMILY_RUNTIME_PRIVILEGES` names every one of the 56 family tables with exactly its runtime privileges (S, I, U). Check `hr_family_runtime_privileges`: the effective privilege (`has_table_privilege`, which counts PUBLIC) must equal the entry, in both directions; SELECT, INSERT and UPDATE also count when granted on one column, and REFERENCES or TRIGGER fail; a missing table fails. No family table holds DELETE or TRUNCATE after C26.
- `HrWriterBoundaryTest::the_writer_register_and_the_privilege_allowlist_cover_exactly_the_family`: the family's models (56) equal the allowlist's tables, and every table named like the family's is classified or named as another domain's, so a new family model's table, or a model-less table whose name matches `FAMILY_TABLE_NAMES`, fails CI until it is classified (C27-N2: a model-less family table named otherwise is not seen).

| Class | Tables |
|---|---:|
| S | 2 (`employee_account_links`, `staff_working_weekdays`) |
| SI | 19 (history, runs, ledgers, terms, decisions, Leave years, rules) |
| SIU | 35 |
| with D or T | **0** |

### 4.3 Negative proof

| Control | Result |
|---|---|
| Runtime role DELETEs a row of its own School, in context, unreferenced, on each of the four | "permission denied for table …"; the row survives; UPDATE still affects 1 row; another School's row 0 (`HrConfigurationDeleteRevokedTest`) |
| Same test with DELETE granted back on `positions` (test database, then revoked) | Fails ("The runtime role must not delete") |
| Verifier, DELETE re-granted directly on each of the four, and to PUBLIC on one | `runtime_destructive_privileges_restricted` FAIL each time, PASS after restore |
| Allowlist drift: DELETE on `leave_types` (never on the denylist), UPDATE on `leave_ledger_entries`, TRUNCATE on `employees`, INSERT on `employee_account_links`, UPDATE on its one column `voided_at`, REFERENCES on `employees`, UPDATE removed from `staff_attendance_records` | `hr_family_runtime_privileges` FAIL naming the table each time, PASS after restore |

Every break was committed through the owner connection on the isolated test database and restored in `finally`. Nothing ran against DDEV's `db`.

### 4.4 Tests adjusted (M4)

The three `HrRawIsolationTest` cases named "raw delete … is revoked for the runtime role" asserted only "0 rows" under RLS; they now assert the privilege refusal (`AssertsRuntimeDeleteRevoked`), which is stronger. `TenantCompositeSetNullTest` splits its campus and parent-department cases; the parent delete, which only the owner can now perform, runs on the owner connection in a rolled-back transaction, like the file's other owner-side cases.

### 4.5 Rollback

`/rollback-proof 1` on the isolated Compose test database: IDENTICAL schema after rollback and re-apply (4 schema lines, the four grants, changed by the rollback); `platform:verify-database` failed=0. A schema rolled back past this migration fails `runtime_destructive_privileges_restricted` and the allowlist until it migrates forward, which is the intended signal: rollback is local and testing only (rule 143).

## 5. WO-12 and the writer register (points 1 to 3)

### 5.1 Family coverage (M1)

The family is derived from the models of the HR, Leave, Staff Attendance and WorkforceTasks Infrastructure directories: 56 models, one table each, every model name unique in `app/`. `no_foreign_domain_writes_an_owned_table`, `no_controller_writes_an_owned_table_itself` and `payroll_never_writes_an_hr_table` now scan all 56.

### 5.2 Single-writer pins (M2)

`HrWriterBoundaryTest::WRITERS` (replacing `NAMED_WRITERS`) names the writing classes of every family table, checked by `every_family_table_has_exactly_its_named_writers`. The register, from the code as it stands:

- **One class** for 47 family tables, as C25 §4 states; `employees` and `employment_records` have one class plus retention (below).
- **Named operations** (§5.2 of C25): `employee_assignments`, `employee_placement_changes`, `leave_policy_assignments`, `workforce_tasks` (plus `TaskWorkflowService` through a locked row, `WRITE_A_LOCKED_ROW`).
- **Point 7, history with its parent:** `workforce_task_events`, appended by `TaskAssignmentService`, `TaskGenerationService` and `TaskWorkflowService`, each for its own operation.
- **Point 8, retention seen by the scan:** `EmployeeRecordRetentionService` on `employees`, `employment_records` and `employee_assignments`.
- **No class** (empty entry): `employee_account_links` (trigger only, R6) and `staff_working_weekdays` (read only since C22).

### 5.3 `employee_esi_coverage` (WO-12, L1)

Pinned to exactly `StatutoryPayrollCalculationService` (decides a period's coverage once, automatically, when none is recorded: insert only, a concurrent duplicate re-read) and `EmployeeEsiCoverageAdminService` (records or corrects it, audited, refused once a calculation result has consumed it). Two operations, not a duplicate. `PayrollEmployeeRetentionService` deletes it by retention (point 8) through `DB::table($table)`, which the scan cannot see.

### 5.4 C25-N1

- `PayrollHrBoundaryRegisterTest` now also counts array-form eager loads (each HR relation named, single or double quotes), `lazy` (an HR relation reached through a loaded row; not `$this->` properties, which are injected services) and `type` (`new`, `instanceof`, a return type naming an HR model). Two register entries gained `lazy => 2`: `CompensationController` (X09) and `StatutoryEmployeeController` (X10), whose search results read `$er->employee?->full_name` and `employee_number`. These are the existing approved references counted for the first time, not new sites; the D-2 classification (22 / 26 / 12, 9 keys, 8 functions) is unchanged.
- `HrWriterBoundaryTest::payrollWritesThroughAnHrVariable` refuses a write through an HR relation (`$result->employmentRecord->update()`, nullsafe included); a relation read stays allowed.
- Both docblocks name what is still not seen.

### 5.5 The scan itself

Comments are stripped with the PHP tokenizer before scanning, so prose ("a new Employee") is not code, and `new Model` is seen in any form (`new Model;`, `(new Model)->forceFill(...)`). The proof cases sit in `the_scan_really_sees_a_writer` and `the_scanner_sees_what_it_pins_and_ignores_comments`.

### 5.6 Planted violations

Each was planted in a copied file, the guard run, the file restored and its checksum compared; all 14 failed for the intended reason, and every file returned byte-identical.

| # | Planted | Failed with |
|---|---|---|
| 1 | Payroll `EmploymentRecord::query()->update()` | foreign-domain, Payroll and point 3 guards |
| 2 | `Department::query()->create()` in an HR controller | controller guard and point 3 |
| 3 | `LeavePolicyAssignment` written by `LeaveTypeService` | point 3 |
| 4 | `EmployeeEsiCoverage` written by `EmployeePfStatusAdminService` | point 3 |
| 5 | `$employmentRecord->update()` in Payroll | variable-write guard |
| 6a | `$result->employmentRecord->forceFill()->save()` in Payroll | variable-write guard (relation chain) |
| 6b | `$result->employmentRecord->employee?->full_name` in Payroll | register (`lazy`) |
| 6c | `->with(["run", "employmentRecord.employee"])` in Payroll | register (`relation`); first missed with double quotes, then fixed |
| 6d | `fn (): ?Employee => null` in Payroll | register (`type`) |
| 7 | A fully qualified HR model in Payroll | register |
| 8 | `StaffHoliday` written by `StaffAttendanceService` | point 3 |
| 8b | Raw `UPDATE leave_years` in `StaffAttendanceService` | point 3 |
| 9 | A new model in `Domain/HR/Infrastructure` | family coverage (56) |
| 10 | A model-less table `hr_c26_probe` (test database, dropped after) | family table-name sweep |

### 5.7 What the guards still cannot see

**Corrected by HR.R1-C27 (C27-N2, C27-N3):** a model-less family table whose name does not match `FAMILY_TABLE_NAMES` is not seen, and the register's `lazy` count skips `$this->employmentRecord->...` inside a Payroll model.

`DB::table($name)` and other names built at run time; writes through a variable whose name is not an HR row name, or a relation not named in the lists; code outside `app/`; dynamic SQL. The `Support/Retention/` path is exempt as a whole (point 8). One runtime role serves every domain: PostgreSQL cannot tell which module wrote (§6 of C25 stands). These are stated limits, backed at runtime by the privilege allowlist, forced RLS, composite keys and the fact-level triggers.

## 6. Evidence

| Check | Result |
|---|---|
| `HrConfigurationDeleteRevokedTest` (new) | 4 passed, 56 assertions |
| `HrWriterBoundaryTest` | 70 passed |
| `PayrollHrBoundaryRegisterTest` | 6 passed |
| `HrRawIsolationTest`, `TenantCompositeSetNullTest` | 88 and 5 passed |
| Verifier suites (`StaffAttendanceMonthLockVerifierTest`, `StaffCalendarVerifierTest`, `OperatorVerificationCommandsTest`, `PayrollLwfRuntimePrivilegeTest`, `UserReferenceCatalogTest`) | all passed |
| `tests/Feature/HR` (DDEV, local) | 1,471 passed, 23,305 assertions, 0 failures |
| `tests/Feature/Postgres` (DDEV, local) | 1,242 passed, 4,128 assertions, 0 failures |
| `tests/Feature/Identity` (DDEV, local; checklist) | 199 passed, 42,442 assertions, 0 failures |
| Local gates (`/publish-gate`) | Pint, Larastan, whitespace (untracked included), gitleaks on changed files: PASS |
| Rollback | §4.5 |
| Full suite, CI, publication | §10 |

## 7. C16 reference pack, E16, historical checks

- **Reference pack.** Unchanged and not re-captured. The verified fingerprint stays as recorded in `HR-R1-C16-D1-DECISION.md` §5: the owner-held pack (602 PNG files, no sub-folders, at the location `HR-REFERENCE-CAPABILITY-MAP.md` §1 names, on the owner's machine), fingerprint `40ed6a836f5a99c65694118c0481bc468b1fb6ebb379a0275cc33839fa7a1ac3`, recomputed 2026-11-08 under the convention pinned there. Not copied or republished.
- **E16.** `infrastructure/release/vulnerability-exceptions.json`: 119 records under `OWNER-0O-E16-2026-11-01`, all expiring 2026-11-29, so valid through 2026-11-28. C25's publication on 2026-11-11 was inside that window, and `publication-check`, which refuses an expired decision, passed; the C25 record did not say so. C26 publishes inside the same window. Nothing here extends E16.
- **C25's local checks.** Which local documentation guards and which local gitleaks run C25 performed is not recorded and stays UNKNOWN. What is known: C25's candidate and main `CI gate` runs were green. C26's own checks are listed in §6 and in the evidence commit.

## 8. Findings

| Item | State |
|---|---|
| C12-N2 | **CLOSED** (§4) |
| C25-N1 | **CLOSED** (§5.4) |
| C26-R1 (review M1 to M5) | **CLOSED** |
| C26-R2 (review L1 to L3, I1) | **CLOSED** |
| Final-state review F-L1 to F-L5 | **CLOSED** before commit (§2.1) |
| **C26-N1** | **New, LOW, Platform.** The allowlist answers the runtime role's own effective privileges (it is NOINHERIT). A role it could `SET ROLE` into is checked only for the schema owner (`runtime_role_not_owner_member`). Today the runtime role is a member of no role. Proposed: a verifier check that the runtime role belongs to no role |
| **C26-N2** | **Observation, Platform.** `BacklogSignalsTest::an_automation_execution_waiting_for_its_backoff_is_not_overdue` asserts an exact age (230 s) across a second boundary and failed once with 231 on attempt 1 of C26's candidate run; the same flake was seen locally in the E16 cycle. Not a product defect; the assertion needs a tolerance or a frozen database clock |
| C22-N1 to N4, C24-N1, C24 export identity, C10-N2, C14-N1, C14-N2, C14-N5, C18-A12, C19-N1, C19-N2, C20-N1, C20-N2, the person-level Identity MEDIUM | Unchanged, open as recorded |
| HRC-4, HRC-8, LVA-4, XRP-3, XRP-4, TRR-6, TRR-8 | Unchanged, open. All 61 original identifiers preserved |

## 9. Readiness

| Question | Answer |
|---|---|
| WO-11 | **IMPLEMENTED** for development |
| WO-12 | **IMPLEMENTED** for development |
| R1-01, the ten points | 1 foreign writes: pinned, 56 tables. 2 entry points: pinned, 56 tables, rule 76 category (C26-A3). 3 named operations: pinned per table (`WRITERS`). 4 privileges: allowlist, verifier and CI. 5 migrations, 6 projections, 7 history, 8 retention, 9 fixtures, 10 rows without tables: categorical, as C25 |
| R1-01 enforcement | **VERIFIED for development for the HR family**, on publication of this candidate with a green exact-SHA full suite. Not a claim about other domains: R7 makes the definition general, but only the HR family is pinned |
| R1-02 | **PARTIAL** (unchanged): the 24 non-Payroll files and X-1 are reads |
| WO-13, WO-14, WO-15 | Pending, not started |
| Capability map | Unchanged at 361 rows |
| HR.R1 | **OPEN.** Not eligible for the final architecture assessment: R1-09, §1, §4, the remaining R1-02 obligations, the closure baselines, D-3/D-4/D-7/D-9/D-10, WO-13 and the closure regression remain |
| Local synthetic UAT | Eligible for implemented, governed functionality |
| Staging | Paused (owner, 2026-10-17); AWS not authenticated |
| Production | **NO-GO** |

## 10. Publication evidence

| | |
|---|---|
| Candidate | `3efcfcdb` (one commit on `b7204210`), pushed to `integration/hr-r1-c26` |
| Committed-range gitleaks | `b7204210..3efcfcdb`, pinned `zricethezav/gitleaks:v8.28.0@sha256:cdbb7c95…`, repository config: no leaks |
| Candidate `CI gate` | Run 43194294858. **Attempt 1 failed** on one test in shard 1, `BacklogSignalsTest::an_automation_execution_waiting_for_its_backoff_is_not_overdue` ("the running lease expired 230 s ago": 231 vs 230), a known pre-existing timing flake of a second boundary in an Observability test that C26 does not touch. Shards 2 to 4 green on attempt 1. **Attempt 2** re-ran the failed job only, on the same SHA: green; `CI gate` green (check run 131122027749) |
| Full suite on the exact SHA | 4 shards, 2,922 + 2,921 × 3 = **11,685 cases**, **466,978 assertions** (shard 1 from attempt 2), **0 failures, 1 skip (ESI-12)**. Coverage union = canonical suite: success. +60 cases on C24 (the per-table writer cases replacing four, the new test file, the split SET NULL case) |
| Publication | `lycenza-ci publication-check --candidate 3efcfcdb --push`: fast-forward, no problems; remote `main` = `3efcfcdb`. Main `CI` run 43200554612: success |
| E16 | Valid (`OWNER-0O-E16-2026-11-01`, through 2026-11-28) |
| DDEV development database | The one pending migration applied forward on `db` through `pgsql_admin` (APP_ENV local, `current_database()` = `db` checked first); `platform:verify-database` failed=0, `hr_family_runtime_privileges` PASS; 391 migrations, 255 forced-RLS tables; the October 2026 Staff Attendance month lock intact. No data changed |
| Regression counter | 0/5: the exact-SHA full suite is this unit's regression (rule 82, ADR 0072 §28.8) |

**Next.** R1-09 and HR.R1 §1/§4, the closure-contract interpretations, with WO-13 (C22-N1) resolved before the final architecture acceptance. Nothing starts without the owner's go-ahead.
