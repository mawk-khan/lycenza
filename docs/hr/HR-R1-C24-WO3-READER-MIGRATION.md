# HR.R1-C24: WO-3, Payroll reads HR through the D-2 register (2026-11-11)

**HR.R1 stays OPEN, and this is not the closure audit.** This record holds what WO-3 changed, the evidence that nothing else changed, and what stays open. D-2 was decided in HR.R1-C23 (`HR-R1-C23-D2-DECISION.md`); this checkpoint implements its Payroll part (§11 there).

| | |
|---|---|
| Baseline | `827749a7` = `main` = `origin/main`, clean tree; `337631b7` (C22 code) and `827749a7` (C23) are ancestors |
| Authority | D2-1 to D2-3 (Architecture, 2026-11-11). The owner dispatched WO-3 by sending the HR.R1-C24 brief in the session of 2026-11-11 |
| Scope | The 22 moves of C23 §3.1, the `listAssignments()` decision (§3.5), D2-A5, D2-N09, the register guard, the docs of C23 §11 |
| Not in scope, not touched | §3.2 to §3.4 sites; the protected functions; every calculation, approval, posting, reversal, correction and retention path; schema, grants, functions; X-1; XRP-3; C14-N1; R1-01 |
| Database | No migration, grant or function. 390 migrations before and after |
| Regression unit | Unit 1 of 5 since the last full-regression checkpoint (`337631b7`) |

## 1. What moved (the 22)

Every move reads `App\Domain\HR\Application\EmploymentRoster`, which exists unchanged since HRX.3/HRX.4. No reader was added or changed.

| Id | Site | Before | After |
|---|---|---|---|
| D2-R01 to R03 | App `PayrollRunController::renderShow()`: unresolved list, result names, correction candidates | `EmploymentRecord::query()->whereIn()->with('employee')` | `labels()`, once per list |
| D2-R04 | App `CompensationController::show()` | `findOrFail()`, then the model to `listAssignments()` | `labels([$id])[0] ?? abort(404)`; `listAssignments()` takes the id |
| D2-R05 | App `StatutoryEmployeeController::show()` | `findOrFail()->with('employee')` | `labels([$id])[0] ?? abort(404)` |
| D2-R06 | `PayslipReadService::assemble()` | `find()->with('employee')` | `labels([$id])[0] ?? null` (the current name, as before) |
| D2-R07 to R09 | ECR, ESI worksheet and TDS draft exports | `with('payrollRunResult.employee')`, then `$runResult->employee->full_name` per row | `labels()` once per export, keyed by employment; a missing name throws, as the eager-loaded relation did |
| D2-R10 to R18 | API `StatutoryPfStatus`, `EsiCoverage`, `TaxProfile`, `Identifier` controllers | `EmploymentRecord::query()->where('school_id')->findOrFail()` | `RequiresSchoolEmployment::requireEmployment()`: `span() === null` → one fixed 404 |
| D2-R19 to R22 | The four statutory admin services' `assertEmploymentRecordExists()` | `->exists()` | `span() !== null` |

Also removed: the dead import in `StatutoryPayrollCalculationService` (D2-A5) and `PayrollRunResult::employee()` (D2-N09), unused once the exports moved.

**§3.5, the dependent move:** `PayrollCompensationReadService::listAssignments()` now takes the employment id. D2-X24 is gone. D2-X19 stays: the API `index` still needs its lookup to answer 404 for an unknown id (an id-taking list would answer an empty 200). **Shipped split: 22 moved / 26 exceptions / 12 no change**, the 62 of C23 less X24 and N09.

## 2. What did not move

- **26 narrow exceptions** (D2-X01 to X27 less X24): unchanged code, unchanged lines apart from the files above. The register test names each by id.
- **12 no-change sites** (D2-N01 to N08, N10 to N13): untouched.
- **D2-FK1 to FK9:** read live, unchanged: 5 RESTRICT, 4 CASCADE, all composite with `school_id`.
- **D2-F1 to F8:** read live, unchanged. D2-F8 (`retention_expire_payroll_employee_evidence`) is the one SECURITY DEFINER, `search_path = pg_catalog, pg_temp`, not executable by `school_os_app`; D2-F7 is INVOKER and not executable by it either. D2-F1 keeps its unpinned `search_path` (C23 §3.4, optional hardening).
- **D2-A1 to A4:** unchanged.

## 3. Reader contract, as shipped

| | Before | After |
|---|---|---|
| Fields reaching Payroll | the whole `Employee` model (`with('employee')`) | directory tier: employment id, Employee id, number, name, status, current, active (C23 §5) |
| School | the request's `TenantContext` (SchoolScope + RLS), sometimes an explicit filter | always an explicit `School` argument under `withSchool` and forced RLS |
| Dates | the row as stored | the row as stored. Every status is found: active, ended, separated, both employments of a rehire |
| Absent / other School | `ModelNotFoundException`, or `exists() = false` | `null` / no label; Payroll still decides the 404 |
| Historical identity | not read | not read. Names are current, as before; `employee_account_links` is read only by D2-F2 to F6 and D2-A1 |

**Export name source.** The exports used to name a row by `payroll_run_results.employee_id`; they now use the employment's Employee. Both are written once (the employment at creation, the result from it at calculation), and nothing rewrites either, so they agree. The DDEV development database has 0 results where they differ.

## 4. The nine 404s (D2-3)

| Site | Route | Before (body `error.message`) | After |
|---|---|---|---|
| R10 | `GET payroll-statutory/pf-status/{id}` | `No query results for model [App\Domain\HR\Infrastructure\EmploymentRecord] <id>` | `No query results for the employment.` |
| R11 | `POST payroll-statutory/pf-status/{id}` | same | same |
| R12 | `GET payroll-statutory/esi-coverage/{id}` | same | same |
| R13 | `POST payroll-statutory/esi-coverage/{id}` | same | same |
| R14 | `GET payroll-statutory/tax-profile/{id}` | same | same |
| R15 | `POST payroll-statutory/tax-profile/{id}` | same | same |
| R16 | `GET payroll-statutory/identifiers/{id}` | same | same |
| R17 | `GET payroll-statutory/identifiers/{id}/{type}/reveal` | same | same |
| R18 | `POST payroll-statutory/identifiers/{id}` | same | same |

- **Status and envelope:** 404, `{message, status, code: null, requestId, errors: null}`, as before; only the message changed. The wording is the one Leave already uses for an employment.
- **No oracle:** an absent id and another School's id give the same body, which no longer echoes the id.
- **Order:** the route's `capability:` middleware still answers first (403 for a real and an unknown id alike), then the check, then the service.
- **Consumers:** no frontend, OpenAPI schema or test read the old message. OpenAPI's 404 references the shared `Error` schema, unchanged.

## 5. Guard

- **`PayrollHrBoundaryRegisterTest` (new).**
  - Scans `app/Domain/Payroll` and `app/Http/Controllers/App/Payroll` with comments stripped (PHP tokenizer).
  - Pins, per file, the HR imports and six counts: static HR model references, HR type hints, HR relation eager-loads and filters, HR table names in strings, HR reader calls, and fully qualified HR names. A new or moved site changes a count.
  - Pins the nine keys (table, HR table, delete rule, `school_id` in the key) and the eight functions (DEFINER, `search_path`, volatility, runtime EXECUTE).
  - Proves the runtime role cannot reach another School's employment through raw SQL or the readers.
- **`HrWriterBoundaryTest` (extended, not duplicated).** `payroll_never_writes_an_hr_table()` reuses its scanner over the Payroll paths, with `employee_account_links` added. It also refuses a mutator on a variable named for an HR row, which the older model scan cannot see.
- **Negative proof.** Four violations were planted, uncommitted, then restored by checksum. All were caught: a model update in `PayrollRunService`, a fully qualified `Employee::query()` in the ECR export, `$employmentRecord->forceFill()->save()` in `CompensationService`, and one API site back on `findOrFail`. Only the new Payroll check caught the variable write; the older foreign-domain scan passed it. Accepted shapes stay green: the readers, the D2-X08 lock, Payroll-owned writes, comments and URL strings.
- **Limits, stated honestly.**
  - A source scan cannot see a table or class name built at run time, or a write through a differently named variable.
  - One runtime role (`school_os_app`, INSERT, SELECT and UPDATE on `employees`, `employment_records` and `employee_assignments`) serves every domain, so PostgreSQL cannot tell a Payroll write from an HR one. The database still denies DELETE on `employment_records` and every write on `employee_account_links`, and HR's triggers bind every writer.
  - The function pin finds Payroll functions by name (`payroll` or `compensation`) and body; a differently named one would be missed.

## 6. Financial non-regression

- **Code:** no line of `PayrollRunService`, `CompensationService`, the approval, posting, reversal, reconciliation or correction services, the retention services or any migration changed.
- **Exports:** byte-identical SHA-256 before (`main`'s code) and after, for 1 and 5 employments, in all three exports (§7 probe).
- **Suites green on the candidate:** `tests/Feature/Payroll` (541 tests), with the C1 to C8 proofs (`PayrollCompensationIntegrityTest`, `PayrollReversalReconciliationTest`, `PayrollAccountLinkConcurrencyTest`, `PayrollReconciliationLargePopulationTest`), and the Postgres `PayrollCompensationProvenanceDbTest` and `PayrollParticipantApprovalDbTest`.
- **HRX v1/v2:** `PayrollHrxInputReadService` is not changed; its suite is green.
- **C22-N4:** unchanged, confirmed LOW (C23 §6): approval binds the reviewed calculation and freshness and never reads HRX evidence.
- **Retention and E21:** no retention period, hold, function or privilege changed.

## 7. Performance

Measured with a throwaway probe (never committed). It ran on this candidate twice, then on `main`'s versions of the same files, with deterministic fixtures:

| Path | `main` | WO-3 | Note |
|---|---:|---:|---|
| ECR export, 1 / 5 employments | 20 / 24 | 23 / 27 | +3, constant |
| ESI worksheet, 1 / 5 | 13 / 29 | 16 / 32 | +3, constant |
| TDS draft, 1 / 5 | 12 / 24 | 15 / 27 | +3, constant |
| Run page, 1 / 5 | 38 / 38 | 38 / 38 | equal |

The +3 is the reader's separate Employee eager load (one SELECT) plus its own `withSchool()`, which sets and resets the tenant variable: a constant per export, never per row. C23 §11 expected "equal or lower"; this is 3 statements higher, recorded rather than worked around, since the reader is HR's and out of scope. A span() check adds the same two context statements per call.

## 8. Tests added

- `StatutoryExportsTest`: a rehired Employee is one row, named from HR, and a rename shows (the name is current, as before).
- `StatutoryAdminApiTest`: for each of the nine API sites, the 404 body for an absent and a foreign id is fixed, the same for both, and names no class, table or id; deny is 403 for a real and an unknown id; active, ended and both employments of a rehire are found (never a 404 or a 500). Service-level: each admin service finds an ended employment and refuses absent and foreign ids alike.
- `PayrollUiTest`:
  - result names on the run page come from HR (with a rehire), unchanged after the Employee's account is unlinked and another is linked;
  - an unresolved row carries its four label fields;
  - correction candidates are named;
  - the compensation and statutory pages name active, ended, separated and both rehire employments;
  - an admin payslip keeps its name after the account is unlinked.
- `PayrollCompensationReadServiceTest`: updated for the id signature.
- The guard tests of §5.

## 9. Findings

| Id | Severity | Finding | State |
|---|---|---|---|
| C24-N1 | LOW | A malformed (non-UUID) id on a statutory API employment route reaches PostgreSQL (`22P02`) and fails as a 500 with the generic body. Identical on `main` (`findOrFail` hit the same cast); web routes are UUID-constrained (`WebRouteParameters`), API routes are not. No class name or data leaks | OPEN, Platform (API routing). Not WO-3 scope |

## 10. Independent review

An independent reviewer (a separate agent given the diff and C23, not this record's conclusions) read the final code and ran two focused test sets (29 and 82 tests, green). **No HIGH or MEDIUM finding.**

| # | Severity | Finding | Disposition |
|---|---|---|---|
| R-1 | LOW | The exports cast a null name to `''`, so a row could print an empty name instead of failing (unreachable: `full_name` is NOT NULL, RESTRICT key) | **Fixed:** `nameOf()` refuses a missing label and a null name |
| R-2 | LOW / INFO | Export names now come through `employment_records.employee_id`; nothing in the database ties it to `payroll_run_results.employee_id` (no shared key, no freeze trigger) | Recorded (§3). Equal by every writer, frozen results (`payroll_run_results_reject_rewrite`), 0 differing rows on DDEV. A database tie would be a schema change, outside WO-3 |
| R-3 | LOW | The register guard's unstated blind spots: other HR tables in raw SQL, name-selected functions, `reader` counts any object's method | **Fixed:** stated in the test docblock and §5 |
| R-4 | LOW | Ended/rehired API cases accepted a 500; no export test of a rehire | **Fixed:** the API test now requires < 500 and not 404; `StatutoryExportsTest` covers a rehired Employee and a rename. Separated paid employments in an export and a committed query-count test are not added (§7 holds the measurement) |
| R-5 | LOW | The variable-write heuristic is name-based | Recorded (§5), as its docblock says |
| R-6 | INFO | The unresolved list is now sorted by name, not database order | Accepted (the old order was unspecified) |
| R-7 | INFO | C24-N1, malformed id → 500; with debug on the body shows SQL | Recorded as C24-N1; debug is never on outside local |
| R-8 to R-10 | INFO | Extra constant queries per export; `app()` resolution in controllers; the split 22 / 26 / 12 must match the decision record | Measured (§7); kept (matches `renderShow()`'s existing style); C23 §3.5 updated |

The review covered the code before these fixes. The fixes were re-tested (106 focused tests, Larastan clean), not re-reviewed: they narrow behaviour (R-1), add tests (R-4) or change comments (R-3).

## 11. Publication

Published through the protected exact-SHA flow (rule 96): the candidate on `integration/hr-r1-c24-wo3`, its full sharded `CI gate`, then `publication-check --push`. The SHAs and CI runs are recorded in the evidence commit that follows this one (§13).

## 12. Readiness

| Question | Answer |
|---|---|
| WO-3 | Implemented for development (§1 to §8); publication in §11 |
| D-2 implementation contract | Satisfied for Payroll: 22 moved, exceptions and no-change sites pinned, keys and functions pinned, 404 sanitized |
| R1-02 | **PARTIAL.** Payroll's part done; X-1 (Timetable, Transport, Visitor controllers) and the 24 non-Payroll files remain |
| R1-01 | Pending, a separate Architecture decision. WO-3 adopts none of its proposed points |
| HR.R1 | **OPEN.** Not eligible for the final architecture assessment |
| Local synthetic UAT | Eligible for implemented, governed functionality |
| Staging | Paused (owner, 2026-10-17); AWS not authenticated |
| Production | **NO-GO** |

**Carried forward, unchanged:**

- C22-N1, N2 and N3 stay open LOWs; C22-N4 is an accepted LOW.
- C18-A12 (manual screen-reader pass) and the WO-5 pages' screen-reader certification are pending.
- C20-N1, C20-N2, C19-N1, C19-N2, C14-N1, C14-N2 are open as recorded.
- C14-N5 MEDIUM and the person-level Identity MEDIUM are open.
- R1-09, §1, §4, D-3, D-4, D-7, D-9, D-10 and C11-N4 are pending.
- HRC-4, HRC-8, LVA-4, XRP-3, XRP-4, TRR-6 and TRR-8 are open.
- E21-D9, E45/ESI-12, E47, HRX-L1 to L4, S-4, S-7, X-1, ADR 0036, ADR 0040 and D-11 are undetermined.
- The five closure baselines are unselected.
- The capability map is unchanged at 361 rows (IMP 163, ALT 22, PART 8, MISS 0, XDOM 7, DEF 68, GATE 87, NR 6), and all 61 audit identifiers are preserved.

**Next.** R1-01, the canonical-writer definition, is the next formal Architecture decision. It does not start without the owner's go-ahead.
