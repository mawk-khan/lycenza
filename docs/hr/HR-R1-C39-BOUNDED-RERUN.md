# HR.R1-C39: bounded development-closure re-run after WO-26 (C38-D4; 2026-11-21)

| | |
|---|---|
| Authorization | The HR owner's C39 dispatch (2026-11-21): "GO — bounded re-run only". Not authorized and not done: application, schema or fixture change; ordinary DDEV mutation; destructive UAT; accessibility sign-off; Legal/DPO gate change; staging; production |
| Governing decision | C38-D4 (`HR-R1-C38-FORMAL-ASSESSMENT.md` §13): PASS WITH CORRECTION; development closure after WO-26 and a bounded re-run of R1-06 and what WO-26 touched, plus the canonical regression on the corrected source |
| Contract | `HR-R1-CLOSURE-CONTRACT.md` §3 R1-06 ("No cross-tenant leakage. Raw-SQL / RLS tests for every HR table, report and export", `RawIsolationTest` pattern) and §4; C27-C (P1/HIGH = BLOCKER, P2/MEDIUM = MAJOR); RF2 §8 (D-RF2-3, clean complete run); rule 28 |
| Source | **`e33a1e1e427c16ee64883acbf7bb471896e59474`** (WO-26 code), selected by the owner for this re-run (C39-D1, §2) |
| Verdict | **HR.R1 RE-RUN INCOMPLETE — REQUIRED EVIDENCE MISSING. R1-06 and every WO-26-affected check pass; the clean complete local regression (RF2 §8.7, C27 §9; no composite under §8.3) was not obtained: three complete runs each had one intermittent failure outside WO-26 (§5). No development HIGH or MEDIUM. HR.R1 stays OPEN** |
| Addendum (C39-EQ1, owner decision dated 2026-11-23) | **C39-EQ1 EQUIVALENT EXECUTION — PASS. HR.R1 DEVELOPMENT-SCOPE PASS — PRODUCTION NOT ADDRESSED** (§10). The verdict above is kept as recorded on 2026-11-21 |

## 1. Preflight

| Check | Result |
|---|---|
| `origin/main` | `a7179df5` (fetched 2026-11-21), = local `main`, clean tree, one worktree, no newer publication |
| Ancestry | `a7179df5` → `e33a1e1e` → `2ae991f9` (C38) → … → `5dcc35ef` (C36) |
| Preserved | `integration/e16-r2` (remote), `stash@{0}` (phase-5d3-preflight) |
| Protection | ruleset 24909660 `main-protection`: active; deletion, non_fast_forward, required_linear_history, required_status_checks; 0 bypass actors |
| CI | 44902029782 (candidate, `e33a1e1e`), 44904079811 (main, `e33a1e1e`), 44904539991 (main, `a7179df5`): success, attempt 1 each |
| E16 | `OWNER-0O-E16-2026-11-01`, valid through 2026-11-28 (exceptions expire 2026-11-29) |
| Counter | REMOTE CHECKPOINT `e33a1e1e`, 0/5 |
| Test isolation | detached snapshot worktree of `e33a1e1e`, Compose project `school-os-test-471586962` (database `school_os_test`, no published host port); ordinary DDEV not addressed by the runner |

**Test-only source change.** `git diff --name-only 5dcc35ef e33a1e1e`: the 7 WO-26 test files (`tests/Concerns/AssertsHrFamilyIsolation.php`, `HrFamilyTenantIsolationTest`, `HrFamilyRetiredWeekdayIsolationTest`, `HrFamilyIsolationCheckerSelfTest`, `HrReportSchoolIsolationTest`, `HrCrossDomainImportRegisterTest`, `StatutoryExportsTest`) and 9 files under `docs/hr/`. Nothing under `app/`, `routes/`, `database/`, `config/`, `resources/`, `infrastructure/`, `phpunit.xml` or `tests/bootstrap.php`. `e33a1e1e..a7179df5` changes three documents only. No production code, migration, route, environment, RLS policy, grant, trigger, requirement or report logic changed. The narrow supersession condition holds.

## 2. Baselines

**C39-D1 (HR owner, 2026-11-21, in the C39 dispatch; recorded today, not backdated).** The closure source is substituted narrowly: **`5dcc35ef` → `e33a1e1e427c16ee64883acbf7bb471896e59474`**. This confirms explicitly what WO-26 §8 applied under the standing instruction. C36's record is not rewritten: `5dcc35ef` stays the historical selection.

| Dimension | Required | Verified today (read-only) | State |
|---|---|---|---|
| Source | `e33a1e1e`, owner-approved for the bounded re-run | test-only delta from `5dcc35ef` (§1) | **Superseded (C39-D1)** |
| Database | C36: DDEV `db` 402/402, schema SHA-256 `2d581b70…877b`, verifier failed=0, 256 forced, grants `71668564…`/934 | ledger 402, last `2027_02_27_090000_bind_separation_access_to_the_person`; C36 recipe (pg_dump 16.14) reproduces `2d581b7042d1f75ca63bda0a4a3eda94c38b8ebc99d63c74bcfdda2ef143877b`; `platform:verify-database` failed=0 (one operator-evidence row, connection encryption); 256 forced; grants `71668564a2a3f67e81f35eb1997ff2d8`/934; October 2026 month lock `md5(to_jsonb(row))` `851693a2c1a63b4b81b88d75d0a63b63`; `UAT-HR-SYN` present | **Intact** |
| Reference pack | C16 `40ed6a83…1ac3` | Not recomputed: the pack is owner-held and not on this host's working paths (as in C38). No repository change touches it | **Intact as selected** |
| Legal/Security | blobs at `eb3d6dde` + C36 §1, plus later approved decisions | gate register `8f8d227fc03b`, `vulnerability-exceptions.json` `23688c539c3c`, RF2 `c6a3cb41a804`, audit register `adc64b547fa8`, domain ownership map `d4b7c4f525b4`: identical at `eb3d6dde`, `e33a1e1e` and `a7179df5`. Later approved decisions: C38-D1..D4, C39-D1 | **Intact** |
| Local environment | snapshot `lycenza-c36-environment-baseline` | snapshot file SHA-256 `bc4e0855298d3ab8f5b9452baa28c7565a7ab2c5deedbea48308ac3eb7e3a50d`; frontend manifest `7b9e6f1bba440e2800adf9da385772bb348d8f68f046f95c42d7f66dc7990fce`; neither restored nor rebuilt | **Intact** |

**Capability map.** The C36 evidence blob was `c4f33615c813`; the current blob is `a9d4c0fc39e5`, carrying C38-D1's six Gate-column owner annotations and a change-log entry (361 rows; every status and total unchanged). This is an approved post-selection evidence change, not a byte-identical file; both references are kept.

**DDEV preservation.** Every row of every public table was hashed (`md5(to_jsonb(row))`, 5,254 rows) before the runs and compared with C37's after-state: only 15 `scheduler_heartbeats` rows differed. After the runs (23:4x UTC) it was hashed again: besides heartbeats, DDEV's own scheduler had created each School's daily tasks at 18:30:02 UTC, the School-local midnight (`workforce-tasks:generate`: 11 generation runs, 14 tasks, 14 task events), and `platform:staff-account-credentials-prune` (hourly) had removed one ended activation credential older than 24 hours. None of it came from the test runner, which used its own project and database. Schema, verifier, forced RLS, grants, October lock and `UAT-HR-SYN` re-read unchanged after the runs. The read-only outputs are preserved with the run evidence (`database-after.txt`, `verify-database-after.txt`, `environment-hashes.txt`, `c39-ddev-rows*.txt`).

## 3. R1-06 re-run

R1-06 is evaluated as a complete verification against the contract and rule 28, not as "WO-26 added tests". Evidence is from the source at `e33a1e1e`, runs 1–3 (§5) and the independent review (§7).

### 3.1 Tables (57)

- **Inventory.** `HrFamilyTenantIsolationTest::the_manifest_is_the_verifiers_family_list` asserts the manifest's sorted keys equal `DatabaseRoleVerifier::HR_FAMILY_RUNTIME_PRIVILEGES` (`app/Support/Operations/DatabaseRoleVerifier.php:52-79`): 57 tables. Drift fails.
- **Policy (57).** `every_family_table_is_forced_with_exactly_the_tenant_policy`: RLS enabled and forced, `school_id` NOT NULL, exactly one PERMISSIVE ALL policy to `public`, USING and WITH CHECK each equal to the canonical `TenantRls` expression.
- **Runtime role.** Every probe runs on `pgsql` as `school_os_app`; `roleViolations()` refuses a superuser, BYPASSRLS or any other role, and the self-test proves the privileged `pgsql_admin` connection is refused.
- **Legs (56 tables, both directions A→B and B→A).** A real School A row built through the real services; positive read under A (an empty table is a violation, never a pass); B sees none by count and by id; no context sees none, both `RESET` and empty string; foreign insert, update, move and delete refused; md5 snapshot of A's rows unchanged.
- **Retired table.** `staff_working_weekdays` has no runtime INSERT or UPDATE (asserted with `has_table_privilege`), so `HrFamilyRetiredWeekdayIsolationTest` (committing) seeds one row per School through the admin connection and probes as the runtime role. Read legs are RLS's; its write legs are recorded as "no INSERT privilege" and "no UPDATE privilege", never as RLS.

**Refusal classes (168 legs over 56 tables), from the manifest source and from the run's evidence file (identical):**

| Leg | RLS | Guard reading under RLS | Declared evidence guard | Immutability guard | No privilege | Total |
|---|---:|---:|---:|---:|---:|---:|
| Insert | 40 | 12 | 3 | — | 1 | 56 |
| Move (update to the other School) | 10 | — | — | 25 | 21 | 56 |
| Delete | — | — | — | — | 56 | 56 |
| **Total** | **50** | **12** | **3** | **25** | **78** | **168** |

Not all 168 prove RLS directly. Each table's class is pinned per direction in the manifest, so a changed class fails. A privilege refusal is recognised only by "permission denied". A guard refusal counts only when declared, or when the differential control (the same write in A's own context is accepted, or fails differently) shows it depends on the School. For the 15 guard inserts and 25 guard moves, the table's own WITH CHECK is proven by the exact policy pin, not by behaviour (C39-N1, §6).

**Fail-capable.** `HrFamilyIsolationCheckerSelfTest` (committing scratch tables, granted SELECT/INSERT/UPDATE/DELETE) proves the checker reports: not forced, disabled, open read (and its DELETE leak), open write, no School predicate, empty table, an always-raising guard, open without context, INSERT revoked, and a privileged connection; the control table reports nothing.

**Result: every one of the 57 tables has effective isolation evidence.**

### 3.2 Reports (10)

`HrReportSchoolIsolationTest`: the rendered set equals `HrReportRegistry` (10), the 8 governed pages are classified. Two Schools carry the same synthetic world under different prefixes. Each report is read both ways by an authorized reader: own rows present (non-empty positive control), the other School's prefix absent; the generation-run report by its examined counts (3 / 7). A forged foreign id (`leave_year_id`, `department_id`) matches nothing or is refused, with an in-School positive control. The six previously weak reports now have real foreign records. These are HTTP reads with SchoolScope and RLS both active, over tables proven at the raw layer in §3.1; that is C38's stated correction ("cross-School tests with real foreign rows"), not "RLS alone".

### 3.3 Exports and document delivery

**Dated clarification (2026-11-21).** C38 recorded that no applicable export exists (C38 §5 "Exports", R1-04 row). That holds for HR, Leave, Staff Attendance and Tasks only. WO-26 found the Payroll statutory exports; C38's text is kept and annotated, not rewritten.

- **Seven Payroll statutory export routes** (web exports page, ECR, ESI worksheet, TDS draft; API ECR, ESI worksheet, TDS draft): `wo26_every_statutory_export_answers_only_its_own_schools_run_on_web_and_api`, where School B naming School A's run gets 404 with no bytes on all seven, and the API naming School A directly gets 404. Positive controls: the ESI worksheet (web and API) and the exports page. ECR and TDS share the same School-bound lookup (`PayrollRun::query()->where('school_id', …)->findOrFail()`) but have no positive two-School control of their own (C39-N3). Authorization: API routes carry `capability:payroll.statutory.exports.generate`; web routes have rule 98 coverage entries (`tests/Support/Authorization/SchoolRouteCoverage.php:1407-1410`); deny tests exist (`ecr_export_is_denied_without_the_identifiers_capability`). R1-04 stays consistent: run 2 re-ran its tests (§5).
- **HR document download** (`an_hr_document_download_never_crosses_schools`): own download first, then the other School's id gives 404 with no bytes and no Content-Disposition.
- **Generic Documents API content route (WO26-N1).** `GET /api/v1/schools/{school}/documents/{document}/content` (`routes/api.php:978`) serves Employee-owned files. It belongs to the Documents domain, outside WO-26's namespace-based export pin. Documents' own tests cover it: `DocumentHttpDirectAccessTest::a_cross_school_document_id_is_a_safe_404_on_both_routes` (Employee-owned document, School A actor, 404 on metadata and content), with the positive `content_streams_the_exact_bytes_with_safe_headers`; `DocumentRawIsolationTest` (RLS forced, no context, cross-School read and write as the runtime role, through an Employee owner). No HR delivery path with an untested cross-School exposure was found, and no disclosure was reproduced. LOW stands, as a Documents-domain handoff.
- **Export pin (WO26-N3).** A keyword scan (`/export|csv|print|download|pdf|xlsx|spreadsheet/i`) over named HR-family controller namespaces. It is not exhaustive: a route whose URI and name avoid those words, or that lives elsewhere, is not seen; for example the printable payslip routes (cross-School tested through `PayslipReadService`: `PayrollApiTest`, `MyPayslipTest`). Any new export or download endpoint needs its own Security and School-isolation review.

### 3.4 R1-06 result

| Criterion | Result |
|---|---|
| All 57 tables valid isolation evidence | Yes |
| All 10 rendered reports valid evidence | Yes (HTTP layer, over raw-proven tables) |
| Applicable exports tested or governed by their owner | Yes: 7 Payroll routes and the HR download tested; Documents content route governed and tested by Documents |
| No vacuous fixture | Yes: empty table = violation; non-empty report positive controls |
| Runtime role authenticated by connection metadata | Yes: `current_user`, `rolsuper`, `rolbypassrls` |
| Policy checks consistent | Yes: 57 identical canonical policies |
| No new material isolation finding | Yes: review LOWs only (§6) |

**R1-06: PASS** (all three runs; the evidence file is byte-identical across them).

## 4. WO-26-affected architecture tests

- **C38-D3 import register** (`HrCrossDomainImportRegisterTest`): exactly 19 C38-D3 importers (Timetable 4, Transport 4, Visitor 4, Attendance 1, TeachingAssignments 6) importing only `Employee`; 3 Documents-port files; 2 HR-family files outside `Domain/HR` importing only `EmploymentRecord`. A new importer, a removed one, another model or a write through an Employee variable or relation fails it. The exception covers the literal importers only; its re-review triggers stand (a new importer, a new model, a write through the imported model, the owning domain's checkpoint). Passing is never permission for new cross-domain access. Scanner limits are C39-N4.
- **Boundary guards:** `HrWriterBoundaryTest`, `PayrollHrBoundaryRegisterTest` (R1-02, with the C38-D3 exception).
- **Verifier tests:** `HrFamilyTriggerFunctionsVerifierTest`, `HrCredentialVerificationVerifierTest`, `HrConfigurationDeleteRevokedTest`, `StaffAttendanceMonthLockVerifierTest`, `StaffCalendarVerifierTest`, `GuardSearchPathShadowTest`, `KnownSamePersonCoverageTest`.
- No planted-policy experiment was repeated; the permanent self-tests are the failure probes. Nothing touched ordinary DDEV.

## 5. Canonical regression

**Accepted CI leg:** 44902029782 on `e33a1e1e`, first attempt, every required job green: 12,015 cases, 469,345 assertions, 0 failures, 1 skip (ESI-12), four shards, union `ef26bd17c386c4ba0d55f98f8209924eba6ce7bb49846405130da576108b7ad9`. Not duplicated.

**Local isolated regression.** The `/full-regression` procedure pinned to the SHA (runner `c39-run.sh`, git-ignored, from C37's): a detached snapshot worktree of `e33a1e1e` (tree `d222460e…`, clean), vendor and build copied (`cp -r --preserve=mode,timestamps`, C37-N1; never hard-linked, no ownership change; `installed.json` compared), project `school-os-test-471586962`, each shard after `--reset-db`, `lycenza-ci shard verify` per shard and `shard coverage` over all four. `/full-regression` tooling was not modified.

**Run 1 (2026-11-21 18:28–19:56 UTC): FAILED, preserved.**

| Shard | Cases | Assertions | Outcome |
|---|---:|---:|---|
| 1 | 3,004 | 55,420 | **1 failure** |
| 2 | 3,004 | 114,503 | passed, plan exact |
| 3 | 3,004 | 126,358 | 3,003 passed + 1 skip (ESI-12), plan exact |
| 4 | 3,003 | 169,527 | passed, plan exact |

The failure: `SeparationPersonLevelAccessTest::the_unlink_routes_refuse_a_known_same_actor_and_allow_an_independent_one` (WO-24's test, not WO-26's), a 500 from `employee_account_links_ended_check` (`ended_at >= linked_at`). The failing row had `linked_at` 18:38:53.638844 and `ended_at` 18:38:52.677161, both written by the database's `clock_timestamp()` in the trigger `employees_record_account_link()`, the end in a later statement than the link. The host journal shows the WSL clock stepped (`systemd-resolved: Clock change detected`) at 18:38:51, 18:38:52 and 18:38:53, and 178 times during the run (`run1/clock-changes.txt`). **Mechanism: a backward wall-clock step on the test host; a harness failure, not a product defect.** The source is unchanged since C37, where this test passed, and CI 44902029782 passed it. A latent robustness note is C39-N7. Coverage could not complete (shard 1 wrote no report). RF2 §8.3 refuses a failed-jobs-only or composite result, and §8.7 with C27 §9 requires the clean `SAFE_TEST_ISOLATED` snapshot run; the failed test alone was not rerun as acceptance.

**Run 2 (complete rerun, all four shards, coverage, focused suites): FAILED, preserved. Run 3 (a further complete run): FAILED, preserved.**

| Run | Window (UTC) | Shard 1 | Shard 2 | Shard 3 | Shard 4 | Cases / assertions | Clock steps in window |
|---|---|---|---|---|---|---|---|
| 1 | 18:28–19:56 | **1 failure** | pass, plan exact | pass + 1 skip, plan exact | pass, plan exact | 12,015 / 465,808 | 178 |
| 2 | 19:57–21:43 | pass, plan exact | pass, plan exact | pass + 1 skip, plan exact | **1 failure** | 12,015 / 468,227 | 225 |
| 3 | 21:45–23:32 | pass, plan exact | pass, plan exact | pass + 1 skip, plan exact | **1 failure** | 12,015 / 465,978 | 233 (+2 backward-jump notices) |

Each run used a fresh snapshot of `e33a1e1e` (tree `d222460e…`, 402 migrations, clean status) and a reset before each shard. The skip is ESI-12 every time. Every failure is a different pre-existing test outside WO-26's files, and each passed in the other two runs and in CI 44902029782:

1. **Run 1, shard 1:** `SeparationPersonLevelAccessTest::the_unlink_routes_refuse_a_known_same_actor_and_allow_an_independent_one` (above). Mechanism: a backward wall-clock step between two `clock_timestamp()` reads; harness.
2. **Run 2, shard 4:** `GuardianFeePortalTest::anything_not_this_guardians_student_or_payment_is_the_same_404` got 401 instead of 404 midway. The test stores `mfa_verified_at = now()` (wall clock); `AssuranceFreshness::isFresh()` correctly treats a future verification time (negative age) as never valid, so a backward clock step of a second after sign-in turns the page into the MFA 401. Mechanism: most likely a backward clock step (no timestamp ties this failure to one, unlike run 1); the product behaviour is correct.
3. **Run 3, shard 4:** `LedgerAccountStatementTest::opening_running_and_closing_balances_follow_posting_order`: a refund and its reversal, posted at the same frozen instant, came back reversed. `posted_at` is frozen by `travelTo`, so the statement's order (`ORDER BY e.posted_at, e.id, l.id`) falls to the entry id. Symfony's UuidV7 (`vendor/symfony/uid/UuidV7.php`) is monotonic per millisecond only: it reads the sub-millisecond field fresh from `microtime()` (:61-63), reuses the last millisecond when the clock steps back (:75, :127), corrects the sub-millisecond field only on a random-part carry (:112), and writes it ahead of the random bits (:135). A backward step can therefore give the reversal a smaller id than the refund. The failure is **consistent with a clock step, but not proven**: its own time was not captured (journald logged backward jumps at 23:19:30 and 23:24:58, inside shard 4's window). The test then passed 16 of 16 times alone on identical code (a diagnostic, not acceptance; output not preserved), and in runs 1 and 2 and CI.

**Not counted as acceptance (RF2 §8.3, "a failed-jobs-only rerun does not count"):** combining run 2's shards 1–3 with run 1's shard 4 covers the canonical inventory exactly once (1,424 files, 12,015 cases, 12,014 passed + 1 skip, union `ef26bd17c386c4ba0d55f98f8209924eba6ce7bb49846405130da576108b7ad9`, identical to CI; `composite-info/coverage.log`). This only shows the selection is complete; it is a composite, not a clean complete run.

**Result:** no clean complete local run was obtained. The host clock was stepped about every 35 seconds throughout (`systemd-resolved: Clock change detected`, plus `journald: Time jumped backwards`), most likely by the WSL2 Hyper-V time sync; `systemd-timesyncd` logged nothing. It cannot be corrected from inside this checkpoint.

**Focused re-runs (each run, after a reset):**

| Set | Tests | Run 1 | Runs 2 and 3 |
|---|---:|---|---|
| R1-06 tables (`HrFamily*`, `HrRawIsolationTest`, `HrSeparationAndTaskRawIsolationTest`, `LeaveRlsIsolationTest`, `StaffAttendanceRlsIsolationTest`, `PayrollHrxInputRlsIsolationTest`, `PayrollStatutoryRawIsolationTest`, the `*RawIsolationTest` family) | 193 | pass | pass (193); run 3 pass |
| R1-06 reports and exports (`HrReportSchoolIsolationTest`, `StatutoryExportsTest`, `HrReportsTest`) | 31 | pass | pass (31); run 3 pass |
| WO26-N1 documents (`DocumentHttpDirectAccessTest`, `DocumentRawIsolationTest`, `DocumentReadServiceContentTest`) | 27 | pass | pass (27); run 3 pass |
| R1-02 boundary (`HrCrossDomainImportRegisterTest`, `HrWriterBoundaryTest`, `PayrollHrBoundaryRegisterTest`) | 80 | pass | pass (80); run 3 pass |
| Verifier tests (§4) | 21 | pass | pass (21); run 3 pass |
| R1-04 (`AuthorizationCoverageGuardTest`, `SchoolRouteRefusalSweepTest`, `HrDefaultDenyTest`, `HrMutationAuthorizationTest`) | 34 | pass | pass (34); run 3 pass |

**ESI-12** is the one Legal-deferred skip (E45, PAY-L1), still recorded in the gate register; it is a skip, not a pass. No new skip.

## 6. Findings and dispositions

**Development HIGH/MEDIUM inventory.** WO22-N1, RF1-N1, WO23-N1 (WO-23 `d11a4537`), WO20-N1, RF1-N2 (WO-24 `e7d7ee02`) and C38-N1 (WO-26 `e33a1e1e`, rated LOW by C38-D2) are closed for development. This re-run raised none. **No development HIGH or MEDIUM is open.** R-HR12-2 (MEDIUM) stays owner-deferred; GOV-8, GOV-9, PAY-12 stay release and staging items (C27-C).

**WO-26 LOWs:**

| Id | Disposition | Owner | Re-review |
|---|---|---|---|
| WO26-N1 | **LOW — Documents domain handoff.** The generic content route is outside the WO-26 pin, but Documents' own tests cover Employee-file delivery across Schools (§3.3) | Documents | A new Documents delivery route, an owner type, or a reproduced disclosure (then Security triage) |
| WO26-N2 | **LOW — accepted evidence-format limitation.** Violations accumulate per direction, and the class is asserted inside each direction, so per-table evidence cannot suppress a failure (review confirmed) | Platform (tests) | Any change to evidence serialization or aggregation |
| WO26-N3 | **LOW — accepted export-discovery limitation.** Keyword scan, not exhaustive (§3.3) | HR / Security | Any new export or download endpoint: explicit registration and isolation review |

**New LOWs from this re-run (recorded; none is a demonstrated MAJOR; recommended disposition applied under the owner's standing instruction):**

| Id | Finding | Owner | Re-review |
|---|---|---|---|
| C39-N1 | For 15 inserts and 25 moves, the table's own WITH CHECK rests on the exact policy pin, not behaviour; an RLS refusal is matched by message substring without the table name (every RLS entry in the run's evidence does name its table) | Platform (tests) | Any change to the policy pin or `TenantRls` |
| C39-N2 | Report forged-id probe covers `leave_year_id` and `department_id` only; `department_id` has no own positive control | HR | A new report filter or a report-query change |
| C39-N3 | ECR and TDS (web and API) lack a positive two-School HTTP control; mitigated by one shared School-bound lookup | Payroll | A change to the export controllers |
| C39-N4 | The import scanner misses multi-line chains, chained arguments with nested parentheses, and `insert`/`upsert`/`attach`/`sync`; none exists in the 19 files today; WO-26 §4's limits list omits it | Architecture | A new importer or a write in a listed file |
| C39-N5 | The `e33a1e1e` commit subject says reports and exports are proven "at the raw/RLS layer"; only the tables are; reports and exports are at the HTTP layer (§3.2). The commit is not rewritten; this record is the correction | HR | — |
| C39-N6 | Test hygiene: `HrFamilyTenantIsolationTest` writes its evidence JSON into the working tree; a root-owned copy can make a later non-root run fail; nothing ties the weekday test's existence to the main test; `isolationViolations()` relies on callers for the role check | Platform (tests) | A new caller of the checker |
| C39-N7 | `employee_account_links_ended_check` compares two `clock_timestamp()` values, so a backward wall-clock step between link and unlink refuses the unlink (500, fails closed, nothing written). Observed only on the WSL test host (§5) | Platform, HR | A recurrence in CI, or a production time source that steps rather than slews |
| C39-N8 | `GuardianFeePortalTest` (and any test storing `mfa_verified_at = now()` then reading it) fails when the wall clock steps back after sign-in; the product's refusal of a future verification time is correct. Observed in run 2 (§5) | Platform (tests) | A recurrence in CI |
| C39-N9 | `LedgerAccountStatementTest::opening_running_and_closing_balances_follow_posting_order` returned a refund and its reversal in reversed order once (run 3); mechanism not determined, consistent with a backward clock step through UuidV7's sub-millisecond field (§5); 16/16 alone, and passing in CI and runs 1–2. Not shown to be a product or a harness defect | Finance | Any recurrence: investigate the statement order before treating it as a flake |

**Preserved unchanged:** WO24-N1/N2/N3; WO25-N1/N2; C38-N2 and C38-D3; RF2's 81 decisions (blob unchanged); C38-D1 deferrals; the Platform and Documents handoffs. Counts: 361 capability rows, 61 audit identifiers (blob unchanged), 81 RF2 decisions, 5 C36 dispositions. No accepted finding became implemented and no gate cleared.

**Gates, unchanged:** RG-ID-1 OPEN; C33-H1..H5; R1-02's C38-D3 exception; E21 Legal ratification; E45/ESI-12; E47; HRX-L1..L4; S-4; ADR 0036; ADR 0040; D-11; M-1; every Legal/DPO restriction. Inventory, Library and Finance clearances stay NOT_INTEGRATED under their development-scope deferrals.

## 7. Independent review

An independent reviewer read the source, the WO-26 record and the isolation evidence file (a main-tree copy, byte-identical to the three runs' files, md5 `8772f538…`), read-only (no edits, no tests, no writes). It confirmed: the test-only diff; the 57-table inventory tie; the checker's role, empty-table, no-context, read and write legs and policy pin; the self-test classes; the 168-leg reconciliation recomputed from source; the 10-report registry tie and two-School reads; the 7 export routes' cross-School 404s and authorization coverage; that WO26-N1's LOW is supported, with no untested HR cross-School delivery path; WO26-N3's non-exhaustiveness; the import register's counts. **No HIGH or MEDIUM.** Its six LOWs are C39-N1 to C39-N6; C39-N7 to C39-N9 are this record's. **Final-state check** of this record against the preserved evidence (runs 1–3, composite, focused sets, DDEV hashes): figures, failure blocks and verdict confirmed; it corrected one error (the ledger failure's UuidV7 reasoning, now §5 item 3) and asked for preserved baseline, composite and post-run DDEV evidence, all now saved; every point is applied. A reviewer grants no Legal, DPO or production approval.

## 8. Verdict

**HR.R1 RE-RUN INCOMPLETE — REQUIRED EVIDENCE MISSING.**

- **R1-06: PASS** (§3.4), on the corrected source, in all three runs.
- **WO-26-affected verification: PASS** (§4; the focused sets passed in all three runs).
- **Accepted CI leg:** 44902029782, clean and complete on `e33a1e1e`.
- **Baselines:** source superseded by C39-D1; the other four intact (§2).
- **Independent review:** no HIGH or MEDIUM.
- **Missing:** the clean complete isolated local regression that C38-D4 (with C27 §9 and RF2 §8.3 and §8.7) requires. Three complete runs each had one intermittent failure in a different test outside WO-26; two are explained by host clock steps, one is not determined. Under the brief and RF2, PASS is not issued on CI alone or on a composite.
- **C38-D4's correction condition is therefore not yet satisfied.** HR.R1 stays **OPEN** at C38's verdict, PASS WITH CORRECTION (development scope; production not addressed). No development HIGH or MEDIUM is open, and nothing here demonstrates a MAJOR.

**What completes it:** one clean complete isolated run of all four shards on `e33a1e1e`, with coverage union `ef26bd17…`, on a host whose wall clock is not being stepped (or after the owner decides how a stepped clock is handled). If it is clean, a short addendum to this record can issue HR.R1 DEVELOPMENT-SCOPE PASS — PRODUCTION NOT ADDRESSED; nothing else in this re-run needs repeating unless the source changes. Any CI leg or publication on or after 2026-11-29 needs an E16 successor first.

**Not claimed:** operational UAT (browser walkthroughs, axe, keyboard, manual screen reader, C18-A12; the synthetic School Administrator's MFA enrolment remains a prerequisite); every ERP integration; Identity or Legal gates; staging (PAUSED); production (NO-GO); permanent HR.R1 release closure.

**Before operational UAT sign-off and release:** UAT execution on `UAT-HR-SYN` with C18-A12; RG-ID-1's School assertion before real Payroll; C33-H1..H5 owner decisions; the Legal/DPO gates (E21, E45, E47, HRX-L1..L4, S-4); the deferred integrations if wanted; staging authorization (PPR); an E16 successor before any publication on or after 2026-11-29.

## 9. Publication

Documentation only: this record, dated annotations in C38 (§5 export clarification), WO-26 (§8 owner confirmation) and the closure contract (status and C39 entry). No code, test, migration, configuration or fixture changed. Published through `integration/hr-r1-c39-rerun`, its exact-SHA `CI gate`, and `infrastructure/ci/lycenza-ci publication-check --push` (protected main, ruleset 24909660), under E16 `OWNER-0O-E16-2026-11-01`, valid through 2026-11-28. Pre-publication: whitespace check and the pinned gitleaks image over the changed documents, no leaks. A documentation-lane green CI is not a substitute for the local regression in §5. The run evidence (runners, logs, JUnit, coverage, clock logs, DDEV hashes) is kept git-ignored under `.claude/regression/c39-evidence/`.

**Regression counter:** unchanged at REMOTE CHECKPOINT `e33a1e1e` (0/5); this checkpoint adds no code unit.

**Next recommended checkpoint (never started automatically):** one clean complete isolated local regression on `e33a1e1e` on a host whose clock is not being stepped, then an addendum to this record issuing the development-scope verdict. Then UAT execution (C18-A12 included) under its own authorization.

## 10. Addendum: C39-EQ1 equivalent hosted execution (owner decision dated 2026-11-23; executed 2026-11-22 UTC)

**Nothing above is rewritten.** §5's three failed local runs, §8's verdict and §9's publication stand as recorded on 2026-11-21. This section adds the missing leg under a narrow owner exception.

### 10.1 Decisions (HR owner, acting within the Architecture authority established by C27)

| Id | Decision |
|---|---|
| **C39-EQ1-A** | Preserve every available attempt-1 artifact and job log of run 44902029782 before anything else (read-only retrieval). |
| **C39-EQ1-B** | **APPROVED — GITHUB-HOSTED EQUIVALENT EXECUTION.** One new complete "Re-run all jobs" attempt of run 44902029782 on `e33a1e1e`, on GitHub-hosted Ubuntu 24.04 runners, stands in for the clean `SAFE_TEST_ISOLATED=1` local snapshot run that RF2 §8.7 and C27 §9 require. It is an exception for HR.R1-C39 only: RF2, C27 and future checkpoints are not amended. Attempt 1 stays the accepted CI leg and cannot satisfy both legs. Budget: one attempt, with no retry and no failed-jobs rerun; stop on any failure. |
| **C39-EQ1-B (final confirmation)** | The owner accepted that the re-run makes attempt 1's GitHub-hosted artifacts inaccessible. The digest-verified local archive (§10.3) is the authoritative retained evidence for attempt 1. No manual deletion, overwrite or edit of that evidence is authorized. |

**Accepted differences, and only these:**
- four independent hosted runners instead of one WSL host;
- a fresh Compose stack and test database per shard (an ephemeral VM, `-p school-os`) instead of a deterministically named isolated project;
- versitygw instead of MinIO (the declared CI substitution, TEST-ENVIRONMENT §"S3 server substitution");
- the CI runtime stack (PHP 8.3.37, PostgreSQL 16.16) instead of the C36 DDEV stack (PHP 8.3.33, PostgreSQL 16.14);
- the extra lanes from strict classification on re-run.

**Why the exception was needed.** §5: the WSL host clock was stepped about every 35 seconds, and three complete local runs each had one intermittent failure outside WO-26. That cannot be corrected inside the checkpoint, and no local WSL or Docker change was authorized.

### 10.2 Execution

| Item | Value |
|---|---|
| Trigger | `POST /repos/mawk-khan/lycenza/actions/runs/44902029782/rerun` (Re-run all jobs), 2026-11-22T21:59:16Z, HTTP 201; sent exactly once |
| Preflight (immediately before) | `origin/main` `d1538d99`, clean; `e33a1e1e` an ancestor, with only `docs/hr/` changed since; workflow active; no other run in progress; ruleset 24909660 active with 0 bypass actors; default token read-only; E16 `OWNER-0O-E16-2026-11-01` valid through 2026-11-28; attempt-1 archive hashes re-verified |
| Run | 44902029782, **attempt 2**, event `push`, ref `integration/hr-r1-c35-wo26` (deleted; the pinned `actions/checkout@11d5960a` fetched the commit by SHA, `src/ref-helper.ts` `getRefSpec`), SHA **`e33a1e1e427c16ee64883acbf7bb471896e59474`**, 21:59:18–22:14:12Z, **success** |
| Classifier | merge base = head, so empty diff, STRICT: secrets, supply_chain, integrity, images, platform, gateway, shared_types, mobile (attempt 1: base `2ae991f9`, without gateway, shared_types, mobile). A superset |
| Jobs (16, all attempt 2, all on `e33a1e1e`) | success: Classify, Secrets scan, Supply chain (audits evaluated on the run date), Integrity, Production images, Platform static, shards 1–4, Platform coverage, AI Gateway, shared-types, Flutter, **CI gate: PASS**. Skipped: Documentation guards (excluded by STRICT, `classify.py:38`). Job and runner ids are disjoint from attempt 1's |
| Runtime identity (all four shards) | PHP 8.3.37, tz=UTC, PostgreSQL 16.16, Valkey 8.1.12, `versity/versitygw:v1.8.0@sha256:30292fc2…`, image ubuntu-24.04 build 20261116.133.1; identical notices to attempt 1 |
| Isolation | One ephemeral VM per shard; inert `.env.example`; `bin/safe-test --reset-db` (`platform:env-diagnostic`, all three connections on `school_os_test`, `TestDatabaseGuard`, full seed set); the shard ran through `bin/safe-test --shard N/4`. No route to DDEV, `UAT-HR-SYN` or any real service |
| Timing | Log timestamps monotonic in all 15 logs; no clock, retry or timeout line. Shard durations 11:42, 10:03, 12:37, 11:27 |

### 10.3 Evidence (git-ignored, `.claude/regression/c39-evidence/`; never committed)

**Attempt 1 (`ci-44902029782-a1/`, retrieved 2026-11-22T21:53Z, before the trigger).** Four artifact archives, each local SHA-256 equal to GitHub's recorded digest. The 12 executed jobs' logs plus the attempt's log bundle. The union recomputed with `lycenza-ci shard coverage`. `MANIFEST.md` holds 35 file hashes, and its own hash is recorded. After the trigger the four ids return 404 (the expected consequence, accepted in §10.1). GitHub's own "Expected Digest" lines in the archived attempt-1 coverage log corroborate the archive hashes.

| Artifact (attempt 1) | Id | SHA-256 (= GitHub digest) |
|---|---|---|
| phpunit-shard-1 | 14707081739 | `26a1b7e75e19e8a989a85b3e00ec5bb43fed4e75ef1410d8228b4451f104e950` |
| phpunit-shard-2 | 14706811997 | `8e150fc5fbe9c07ac8a6e002afdb6fcbf3c4983f060553911bf3b459839492d4` |
| phpunit-shard-3 | 14706584602 | `90e5fa591d1f7e9db9bb858a14b4225edc3aec76b5b0823aee6fb6cca99a07c8` |
| phpunit-shard-4 | 14706758766 | `f7d40062823bc9cfae1b0d6d94a6853ef8d6acc9da4b9ec70aff52677d7a206d` |

**Attempt 2 (`ci-44902029782-a2/`).** Same layout, `MANIFEST.md` with 38 file hashes. Each artifact was created inside attempt 2's window, seconds before its own shard finished. The coverage job downloaded exactly these four ids after the last upload.

| Artifact (attempt 2) | Id | Created (UTC) | SHA-256 (= GitHub digest) |
|---|---|---|---|
| phpunit-shard-1 | 14775332376 | 22:12:44 | `71ac5933431fdcec823fa67d62495aec419f7a8f01c8c92624e240c581a6919e` |
| phpunit-shard-2 | 14775337001 | 22:11:15 | `8e472860ce477e9a6b13d1edb9350b67a3d344cdc76d93a4cb5b3ad464843170` |
| phpunit-shard-3 | 14775800482 | 22:13:45 | `e9b8f841af9348cd232634ab03cb17174140e46e21a1a1610fac470974d6a009` |
| phpunit-shard-4 | 14775068000 | 22:12:43 | `9e3164d69e75705f68044ce9cf7303fd8f3220455cb35305b4d076ac8f51bcc9` |

Both archives were scanned for secrets: no token, key or credential pattern, and masked values only. The only email-shaped strings are public PHP and curl release-key uids from the image build. §5's 144 run files are byte-identical to before this addendum.

### 10.4 Results

| | Attempt 1 (CI leg) | Attempt 2 (substitute leg) |
|---|---|---|
| Files / cases | 1,424 / 12,015 | 1,424 / 12,015 |
| Per shard | 3004 / 3004 / 3004 / 3003 | 3004 / 3004 / 3004 / 3003 |
| Outcomes | 12,014 passed, 1 skipped, 0 failures, 0 errors | 12,014 passed, 1 skipped, 0 failures, 0 errors |
| Skip | ESI-12 (`EsiCalculationServiceTest::esi_12_disability_special_threshold_is_legally_deferred`, shard 3) | the same, and only that |
| Union selection SHA-256 | `ef26bd17c386c4ba0d55f98f8209924eba6ce7bb49846405130da576108b7ad9` | **identical** (the CI coverage job and two independent recomputations) |
| Assertions | 469,345 | 467,315 |

**Independence.** The four JUnit reports differ from attempt 1's, and only 1 of 12,015 case times coincides. Every shard report names the same plan (`cdc3c7e5…`) and inventory (`10b49ea5…`). Nothing from attempt 1 was consumed.

**Assertion drift (RF2 §8, explained).** −2,030, all in 13 multi-process classes. The largest are ServiceAssertionReplayConcurrency −1,116, CrossHostHandoffConcurrency −279, FinanceRetentionConcurrency −202 and ReportingLineEmploymentEndConcurrency −127. Eleven of the 13 poll; two (ElectiveGroupConfigurationConcurrency −4, CanteenOrderConcurrency −1) branch on which side wins a real race. The test count and the union are unchanged.

### 10.5 What stands from §1–§9

- **R1-06: PASS** (§3), unchanged. Attempt 2 re-ran every R1-06 test as part of the full inventory, all passing.
- **WO-26-affected verification: PASS** (§4), unchanged, likewise inside the inventory.
- **Baselines:**
  - **Source:** `e33a1e1e` (C39-D1).
  - **Legal/Security blobs:** identical at `e33a1e1e` and `d1538d99`: gate register `8f8d227fc03b`, exceptions `23688c539c3c`, RF2 `c6a3cb41a804`, audit register `adc64b547fa8`, capability map `a9d4c0fc39e5`.
  - **Local environment:** snapshot `bc4e0855…a50d` and frontend manifest `7b9e6f1b…0fce` re-hashed, identical.
  - **Database:** not re-observed. DDEV was stopped, starting it lets its scheduler write (§2), and nothing in this addendum touched it. Its §2 state stands.
  - **Reference pack:** owner-held, untouched.
- **The three failed local runs** (§5) stay recorded as failures. Their LOWs C39-N7..N9 are unchanged, and nothing here re-judges them.

### 10.6 Independent review

A separate read-only reviewer (no edits, tests, Docker or triggers) re-derived from the raw files and live GET calls:
- source identity in every job and in checkout;
- attempt 2's separateness (fresh job and runner ids on all 16 jobs);
- artifact provenance and digests, including the 404 of the attempt-1 ids;
- the coverage, computed two ways;
- the per-class assertion drift;
- the gate's lane table;
- runtime parity;
- monotonic log time;
- both archives' manifests;
- the failed local runs' files.

**No HIGH or MEDIUM.** Its opinion: attempt 2 meets RF2 §8.1–8.6 on its own, independent of attempt 1, and whether it may stand in for §8.7 / C27 §9 is the owner's C39-EQ1 decision, not a technical finding. It grants no owner, Architecture, Legal, DPO or production approval.

Its LOWs, recorded under the owner's standing instruction (recommended disposition):

| Id | Finding | Disposition |
|---|---|---|
| EQ1-N1 | Literal C27 §9 / rule 81 isolation (`SAFE_TEST_ISOLATED=1` on a local snapshot) and the C36 local stack are not what ran. The essential guarantees hold on the ephemeral runners | Accepted by C39-EQ1-B for C39 only (§10.1); not a precedent |
| EQ1-N2 | Attempt 2's lane set differs from attempt 1's because `e33a1e1e` was already on `main` (strict on an empty diff) | Accepted: a superset, so it adds proof |
| EQ1-N3 | Two of the 13 drifting classes are race-branch rather than polling tests | Accepted: explained concurrency drift; RF2 §8's wording is not amended |

The fourth review note recorded only that the manifests' figures matched the recomputation; it is not a finding.

### 10.7 Verdict

**C39-EQ1 EQUIVALENT EXECUTION — PASS.** Within C39-EQ1-B's scope, attempt 2 is the clean, complete substitute leg on exactly `e33a1e1e`. Attempt 1 remains the CI leg.

**C38-D4 is satisfied:** R1-06 PASS (§3), the WO-26-affected verification PASS (§4), and the complete canonical regression on the corrected source PASS in two independent executions (§10.4). The source and baselines are compatible (§10.5). No development HIGH or MEDIUM is open.

**HR.R1 DEVELOPMENT-SCOPE PASS — PRODUCTION NOT ADDRESSED.** This verdict exists only through C39-EQ1's exception and is limited to development scope.

**Not claimed and unchanged:**
- **Operational UAT:** not signed off. Browser walkthroughs, keyboard and axe acceptance, named-human screen-reader acceptance (C18-A12) and the `UAT-HR-SYN` administrator's MFA enrolment remain.
- **Gates:** RG-ID-1 OPEN; C33-H1..H5 open; every Legal/DPO gate (E21, E45/ESI-12, E47, HRX-L1..L4, S-4) unchanged.
- **Integrations:** Inventory, Library and Finance stay NOT_INTEGRATED.
- **Release:** staging stays PAUSED, production stays NO-GO, and there is no permanent HR.R1 release closure. No Jurimatter checkpoint is affected.

**Regression counter:** unchanged at REMOTE CHECKPOINT `e33a1e1e` (0/5); this addendum adds no code unit.

**Next recommended checkpoint (never started automatically):** operational UAT on `UAT-HR-SYN` under its own authorization, beginning with the School Administrator's MFA enrolment, then C18-A12. Any publication on or after 2026-11-29 needs an E16 successor first.
