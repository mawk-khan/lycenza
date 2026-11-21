# HR.R1-C35 WO-26: every HR table, report and export proven School-isolated (C38-N1; 2026-11-21)

**Test-only work order** ordered by HR.R1-C38 (C38-D2: C38-N1 LOW, corrected by WO-26; C38-D3: the 19-importer exception pinned). It closes R1-06's evidence gap. No application, migration, schema, RLS policy, grant, capability or report logic changed. HR.R1 stays **OPEN**: development closure needs the bounded re-run (C38-D4), a separate checkpoint.

| | |
|---|---|
| Authorization | The owner's WO-26 dispatch, 2026-11-21: "GO — test-only correction" |
| Source | Code `e33a1e1e` on `2ae991f9`. Diff from the C36-selected source `5dcc35ef`: **7 test files only** (6 new, 1 modified), +1,516 / −2 lines, plus documents |
| Publication authority | E16 OWNER-0O-E16-2026-11-01, valid through 2026-11-28 |
| Owner decisions applied | The owner's standing instruction of 2026-11-21 ("always proceed with recommended selections"). Two choices were made under it, each the recommended option: the retired weekday table's admin-seeded committing test, rather than widening the rule 93 seam; and the source-baseline proposal in §8. Neither was an answer to a question put for that choice |

## 1. What R1-06 asked, and what was missing

R1-06 (closure contract §3): "Raw-SQL / RLS tests for every HR table, report and export", by rule 28's pattern. C38 §5 found the following gaps.

**Tables:**
- 10 of the 57 HR-family tables had no behavioural test that could fail (a loop over empty tables, or no test at all).
- 14 more were missing a leg.

**Reports:** 6 of the 10 rendered reports had no meaningful cross-School test.

**Exports:** C38 called the export leg vacuous. That holds only for HR, Leave, Staff Attendance and Tasks. Payroll's statutory exports (ECR, ESI worksheet, TDS draft) do exist, and before WO-26 they had only deny-without-capability tests. WO-26 treats them as exports and proves their isolation (§3).

## 2. Tables: all 57

**One checker, one manifest.**
- `tests/Concerns/AssertsHrFamilyIsolation.php` probes one table on the raw runtime connection (`pgsql` as `school_os_app`), with no Eloquent and no SchoolScope. It returns every violation, each naming the table, context, operation, expected result and actual result.
- `HrFamilyTenantIsolationTest`'s manifest equals `DatabaseRoleVerifier::HR_FAMILY_RUNTIME_PRIVILEGES` and asserts 57 entries. A table added to or removed from the verifier fails the test until it is classified.

**Legs per table.** Each leg runs in both directions (A→B and B→A).

| Leg | What must hold |
|---|---|
| Role | The connection is `school_os_app`: not superuser, not BYPASSRLS |
| Positive control | School A's rows are visible in A's context, and only A's. An empty table is a violation, never a pass |
| Foreign read | In B's context: 0 of A's rows by count, and 0 by A's id |
| No context | 0 rows with the setting RESET, and 0 with it empty |
| Foreign insert | A copy of A's row with a new id is refused in B's context (details below) |
| Foreign update | An UPDATE from B aimed at A's rows touches 0 rows; moving A's row to B is refused |
| Delete | "Permission denied" in both contexts: no family table grants DELETE (R1-01) |
| Integrity | A's rows are byte-identical afterwards (md5) |
| Policy | RLS enabled and forced; exactly one PERMISSIVE ALL policy to PUBLIC; USING and WITH CHECK both exactly `TenantRls::enable()`'s expression as PostgreSQL 16 deparses it |

The foreign insert copies A's row with a new id, leaving out generated and identity columns. Where the table needs it, the copy is first reset to the state the table accepts at insert (`born`).

**Fixtures.**
- 56 tables get rows in two Schools from the real services as the runtime role: separation cases and approval, account-link correction by two linked staff, year close and reconciliation, allocation and accrual runs, month lock and assured correction, placement change, credential verification, and the rest.
- `staff_working_weekdays` is retired since HR.R1-C22. The runtime role can only read it and nothing in the application writes it. It is seeded on the admin connection in its own committing test (`HrFamilyRetiredWeekdayIsolationTest`), and still probed as the runtime role.

**How each foreign write was refused.** 168 legs across 56 tables, plus the weekday table's three. Each class is **pinned per table** in the manifest, so drift fails the test.

| Class | Legs | Meaning |
|---|---:|---|
| RLS | 50 | `42501 new row violates row-level security policy` |
| Guard reading under RLS | 12 | A guard refused in B's context; the same insert in A's own context was accepted, or refused for another reason. So the refusal comes from the School boundary: the guard cannot see A's parent rows under B's RLS |
| Declared evidence guard | 3 | `leave_request_days`, `leave_accrual_run_items`, `staff_attendance_corrections`: no direct insert is admitted in any School. For these, the refusal is proven behaviourally and the WITH CHECK by the policy pin |
| Declared immutability guard (move) | 25 | The table's own guard refuses moving a row between Schools before RLS is asked (C10 precedent). WITH CHECK is proven by the pin |
| No INSERT, UPDATE or DELETE privilege | 1 + 21 + 56 | "Permission denied", recorded as a privilege refusal, never mistaken for RLS |

**The checker can fail.** `HrFamilyIsolationCheckerSelfTest` is a committing test. It builds scratch tables, each broken one way, with real rows of two Schools, and drops them in tearDown. The checker must report each break by name:

| Planted break | What the checker reports |
|---|---|
| RLS not forced | The policy pin |
| RLS disabled | The policy pin, read leaks and no-context leaks |
| Open USING | Read leak, delete leak and the policy pin |
| Open WITH CHECK | Insert accepted, plus the policy pin |
| No School predicate | Read leak |
| Empty table | "Vacuous fixture" |
| A guard refusing in every School | "Independent of the School" (the differential control) |
| A policy failing open only without context | Both no-context legs, but not the foreign read |
| INSERT revoked | Recorded as a privilege refusal |
| Privileged connection | Refused: "expected the runtime role" |

A correct control table reports nothing.

## 3. Reports and exports

**Reports.** `HrReportSchoolIsolationTest` gives two Schools the same synthetic world under different prefixes:
- two staff, a reporting line and a transfer;
- an allocation, an absence and a correction;
- a task and a generation run, with different examined counts per School.

Every one of the 10 rendered reports is read both ways:
- a positive control: the School's own records are present;
- a negative control: the other School's never are;
- for generation runs: exactly one row for the day, and never the other School's count.

The manifest equals the registry's rendered set, and the 8 governed pages are classified. A forged id from the other School in a filter is refused, or matches nothing. An in-School id is the positive control. HTTP authorization is a separate protection (`HrReportsTest`, rule 98), and no 403 is counted as isolation.

**Exports.**
- **Payroll statutory exports** (`StatutoryExportsTest::wo26_…`): each School's run is calculated for real.
  - School B naming School A's run gets a 404 on all seven routes: the web exports page, three web downloads and three API downloads.
  - Naming School A directly over the API is the private 404.
  - Each School's worksheet lists only its own staff.
- **HR document download:** Alpha's HR gets the file. Beta's HR, fully authorized in Beta, gets a 404 with no bytes and no attachment header.
- **Export pin:** every export-shaped route (export, csv, print, download, pdf, xlsx) under the HR family's and Payroll's controller namespaces must be classified with its isolation test. A new export fails until it is. The report catalogue classes must stay free of export members.

## 4. The C38-D3 import register

`HrCrossDomainImportRegisterTest` pins exactly 24 HR Infrastructure importers outside HR and Payroll, each with exactly its model:
- C38-D3's 19, each importing `Employee`, read only;
- Documents' 3 (the C10 port);
- the HR family's 2 outside `Domain/HR`, each importing `EmploymentRecord`.

A new importer, a removed one or another model fails the test. That is a C38-D3 re-review (an Architecture decision), never an edit to make the test pass.

**Read-only check.** For the 22 Employee importers, the test finds every name a file gives an Employee:
- typed and nullable parameters, and closure arguments;
- assignments from `Employee::`;
- `@var` names, read from the raw source;
- the models' Employee relations: `driver`, `employee`, `hostEmployee`, `teacher`.

It refuses any mutator through those names, including nullsafe and chained calls, and any write in a statement that starts at `Employee::`.

**Limits:** an untyped variable, a group `use {…}` import, a fully qualified inline reference, or a write built at run time is not visible to a source scan. `HrWriterBoundaryTest` still refuses table writes to HR.

## 5. Probes on real tables (isolated test database only; then reset)

The isolated stack (`school-os-test-…`, database `school_os_test`, 402 migrations; never DDEV).

| Planted break | Reported |
|---|---|
| `leave_settings` open USING | Read leaks (count, key, no context), update leg, policy pin |
| `staff_holidays` RLS disabled | Read leaks, no-context leaks, policy pin |
| `employees` NO FORCE | Policy pin (the runtime role is not the owner, so behaviour is unchanged; the pin catches it) |
| `hr_departments` WITH CHECK true | Policy pin; the insert leg failed closed (a unique key answered in both contexts) |
| `workforce_task_categories` no School predicate | Read, update and move leaks, policy pin |
| Report: a "Beta Planted" employee inside Alpha | `hr.staff_details in Alpha: Beta's records never appear` |
| Register, in source files restored by `git checkout`: an extra model, a new importer, an Employee mutator, a removed importer, `$teacher->save()`, `->hostEmployee()->update()` | Each reported |
| Export pin: a temporary `…/export` HR route | Reported |
| A flipped refusal-class pin | `insert refusal class: expected guard_under_rls; actual rls` |

Afterwards: `bin/safe-test --reset-db`; 256 forced tables and the three policies re-read canonical; `app/` and `routes/` clean.

## 6. Review

**Independent review of the candidate:** no HIGH; M1 and L1–L9, all corrected.

| # | Finding | Correction |
|---|---|---|
| M1 | The read-only check matched only `$…employee…` names, but the files use `$teacher`, `$driver` and `$host` | The per-file name and relation scan above; the reviewer's bypasses are now reported |
| L1 | The exports page was credited but never requested | Requested: 200 in its own School, 404 from the other |
| L2 | The download was classified without a two-School case | The case was added |
| L3 | The generation-run marker could miss a second School's row | Exactly one row, no foreign count |
| L4 | The differential, fail-open and privilege branches were never proven able to fail | Three probe tables |
| L5 | The working-week probe used a fixed date the database clock would overtake | Computed from `staff_calendar_local_today() + 7` |
| L6 | The classification was never asserted | Pinned per table |
| L7 | The forged-id test only checked a name | Refused or empty, with an in-School positive control |
| L8 | An unexplained filter in the self-test control | Removed |
| L9 | No DELETE leg | Added |

**Final-state review:** all ten fixes present and correct; no regression; nothing at MEDIUM or above. Four scanner LOWs (nullsafe, static-then-instance, `@var` after comment stripping, chains with arguments), the `driver` pin, the L7 positive control and a planted DELETE leak were then closed too.

**Recorded, not changed (LOW/INFO):**
- **WO26-N1:** the generic Documents API content route serves Employee files through HR's port. It is the Documents domain's route and is outside the export pin's namespaces (the limit is stated in the test).
- **WO26-N2:** the evidence JSON is keyed per table, not per direction. A failing leg always adds its own violation, so this can never cause a false pass.
- **WO26-N3:** the export pattern cannot see an export whose URI and name avoid its words (stated).

## 7. Results

- **Focused:** the 26 new or changed tests pass (298 assertions). Together with the related suites (verifier commands, writer boundary, forced-RLS count, raw isolation, Leave RLS, staff calendar, HR reports, statutory exports): 329 tests pass. Pint is clean; whitespace and gitleaks are clean.
- **Canonical:** 12,015 cases, 0 failures, 1 skip (ESI-12), on the exact candidate `e33a1e1e` (§9).

## 8. Source-baseline supersession (proposal, recommended)

C36 selected source `5dcc35ef`. WO-26 is a different commit, so the source dimension needs a narrow re-selection. C36's own rule: "a change to source … is a new selection".

| Dimension | C36 selection | After WO-26 |
|---|---|---|
| Source | `5dcc35ef` | **`e33a1e1e`**: +7 test files only; no application, migration, route or config change |
| Database | DDEV 402/402, schema `2d581b70…` | Unchanged: no migration |
| Reference pack | C16 `40ed6a83…` | Unchanged. The capability map carries C38's six Gate annotations (an approved post-selection evidence delta, blob `a9d4c0fc`, not byte-identical to C36's `c4f33615`) |
| Legal/Security | Blobs at `eb3d6dde` + C36 §1 | Unchanged |
| Local environment | Snapshot `lycenza-c36-environment-baseline` | Unchanged: no frontend or runtime change, so the bundle still matches |

**Recommended:** select `e33a1e1e` as the closure source, with its own exact-SHA green CI (§9), keeping the other four dimensions. This is applied under the owner's standing instruction of 2026-11-21 and recorded as such; the owner may override it. C36's record is not rewritten: `5dcc35ef` stays the historical selection.

## 9. CI and publication

- **Candidate CI** 44902029782 on `e33a1e1e`: green on the **first attempt**, every required job (supply chain, gitleaks full history, static checks incl. Pint and Larastan, four shards, images, repository integrity, coverage, CI gate). No rerun.
- **Canonical suite on the exact SHA** (ADR 0072 §28.8, the coverage job): "4 shards cover the canonical inventory exactly once: 1424 files, **12,015 cases** (3004 / 3004 / 3004 / 3003), **469,345 assertions**, outcomes passed 12,014, **skipped 1**" (ESI-12, the Legal deferral), **0 failures**. Union selection sha256 **`ef26bd17c386c4ba0d55f98f8209924eba6ce7bb49846405130da576108b7ad9`**.
  - **12,015 = 11,998 + 17 new cases.** The new cases are HrFamilyTenantIsolationTest 3, HrFamilyIsolationCheckerSelfTest 4, HrFamilyRetiredWeekdayIsolationTest 1, HrReportSchoolIsolationTest 5, HrCrossDomainImportRegisterTest 3 and StatutoryExportsTest 1.
  - The selection hash differs from C37's `0d8fd41c…` because the inventory grew, as expected.
- **Published** with `lycenza-ci publication-check --candidate e33a1e1e… --push` (fast-forward from `2ae991f9`); remote `main` = `e33a1e1e`; the integration branch deleted. Main CI 44904079811 on `e33a1e1e`: green, first attempt.
- Regression counter: a code-lane publication whose exact-SHA CI ran the full suite, so it is a remote checkpoint (0/5).

## 10. Status

- **C38-N1:** **closed for development** on publication (`e33a1e1e`).
- **C38-N2:** recorded exception, now machine-pinned.
- **Unchanged:**
  - RG-ID-1 (open), C33-H1..H5 and the Legal/DPO gates;
  - RF2's 81 decisions, C36's five dispositions, the 361 capability rows and the 61 audit identifiers;
  - UAT (not signed off); staging (PAUSED); production (NO-GO).
- **Next:** the bounded HR.R1 re-run (C38-D4), a separate checkpoint. It covers:
  - R1-06 under its contract;
  - the tests WO-26 touches;
  - a complete canonical suite green on the corrected source;
  - source and baseline compatibility.
