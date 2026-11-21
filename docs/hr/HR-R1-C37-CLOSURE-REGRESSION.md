# HR.R1-C37: the closure regression on the selected baselines (C27 §9; 2026-11-21)

**A verification record. Nothing was built, changed or selected.** The full canonical suite and the four closure checks of the C12 docket §12 ran on the C36-selected source, `5dcc35ef`, from a clean snapshot worktree with `SAFE_TEST_ISOLATED=1`. **All passed on their first execution.** This is not the HR.R1 verdict: the formal assessment (closure contract §3, §4) is a separate checkpoint. **HR.R1 stays OPEN.**

| | |
|---|---|
| Authority | The owner's C37 dispatch (2026-11-21): the selected-baseline local closure regression and the four checks only |
| Contract | C27 §9 (`HR-R1-C27-CLOSURE-INTERPRETATIONS.md:147`), verbatim: "The closure regression runs on the selected SHA from a clean snapshot with `SAFE_TEST_ISOLATED=1`, plus the R1-04/05/07/08 re-runs of the docket §12; C26's suite does not substitute for it." The docket §12 (`HR-R1-C12-DECISION-DOCKET.md`): "re-evaluated on the selected SHA, from a clean snapshot worktree with `SAFE_TEST_ISOLATED=1`, never a moving tree. No fixture is needed beyond the suite's" |
| Baselines (C36, `2c924e37`, owner-selected 2026-11-20) | source `5dcc35ef9539eb94743af7fc6e402c40008f8a72`; database DDEV `db` 402/402, schema SHA-256 `2d581b7042d1f75ca63bda0a4a3eda94c38b8ebc99d63c74bcfdda2ef143877b`; reference pack `40ed6a836f5a99c65694118c0481bc468b1fb6ebb379a0275cc33839fa7a1ac3`; Legal/Security blobs at `eb3d6dde` plus C36 §1; environment snapshot `lycenza-c36-environment-baseline` (`bc4e0855298d3ab8f5b9452baa28c7565a7ab2c5deedbea48308ac3eb7e3a50d`) |
| Git | `main` = `origin/main` = `2c924e37`; `5dcc35ef..2c924e37` changes only documents (0 non-`docs/` files), so no selected baseline moved; clean tree; the stash and `integration/e16-r2` preserved |

## 1. Method

- **The source, pinned.** A detached worktree at exactly `5dcc35ef` (tree `c8d86cb53e2aa476c29604dae0950f71ea8aa3cb`, 0 status lines, 402 migration files), never the working tree.
  - It used a runner derived from the repository's `/full-regression` procedure (`.claude/skills/full-regression/run.sh`, not edited): the same detached snapshot worktree and isolated project, but pinned to the selected SHA instead of the current tree, run in four shards with CI's plan verification and coverage union, with the copy fix below. The skill itself runs the suite unsharded.
  - It ran in its own isolated Compose project, `school-os-test-471586962`, which publishes no host ports, so the run could not reach DDEV.
- **Shards, as CI runs them.** For each of the four canonical shards:
  1. a full `--reset-db` (as each CI runner starts fresh);
  2. `bin/safe-test --shard N/4 --log-junit …`;
  3. `lycenza-ci shard verify` (the shard ran exactly its plan).

  Then `lycenza-ci shard coverage` over all four (the union is the canonical inventory, exactly once).
- **The four checks.** After a fresh reset (its log ends "reset complete"; the runner did not check that step's exit code), each with its docket command as a `--filter` and its own JUnit report.
- **Ignored inputs** were copied in: `vendor` (identical `composer/installed.json`), the C36-rebuilt `public/build`, and the env files Compose names. The worktree and the project's containers were removed afterwards.

**Harness failure before any test (recorded, C37-N1).**
- The first attempt (06:57 UTC) stopped at its first `--reset-db`: the snapshot's platform container exited at once.
- **Cause:** 2,158 files under `apps/platform/vendor` (among them `laravel/framework`) are owned by `root`, and the kernel's `protected_hardlinks=1` refuses to hard-link them. The procedure's `cp -al` therefore left an incomplete `vendor`.
- **Fix:** a real copy (`cp -r --preserve=mode,timestamps`) with an `installed.json` comparison, failing loudly.
- **Evidence:** the first attempt's own log was not retained (the runner clears its evidence directory at start); its failure was read from the attempt's output and reproduced by hand (`c37-debug.log` in the bundle, §4), which showed the `cp -al` refusals.
- **Classification:** no test ran, so this is a harness failure, not a failed regression. The rerun is the first and only execution of the suite (D-RF2-3's complete-run rule is not engaged). The repository skill would abort the same way; the owner of the local tooling may wish to fix it. File ownership was not changed.

## 2. The canonical regression

| Shard | Tests | Result | Assertions | Plan verified | Duration |
|---|---|---|---|---|---|
| 1/4 | 3,000 | OK | 107,808 | Exactly its plan | 1,158 s |
| 2/4 | 3,000 | OK | 155,120 | Exactly its plan | 1,126 s |
| 3/4 | 2,999 | OK, 1 skipped | 103,248 | Exactly its plan | 1,239 s |
| 4/4 | 2,999 | OK | 99,402 | Exactly its plan | 1,191 s |

- **Coverage union** (`lycenza-ci shard coverage`): "4 shards cover the canonical inventory exactly once: 1419 files, 11998 cases (cases per shard [3000, 3000, 2999, 2999]), 465578 assertions, outcomes {'passed': 11997, 'skipped': 1}, union selection sha256 `0d8fd41c9fefcd9704ef187452d55c504a00d46ffb3d8fa9bd862533041292e1`."
- **11,998 tests, 0 failures, 0 errors, 1 skip.** The selection sha256 is **identical** to the accepted CI leg (run 44781452652 on the same SHA): the same inventory ran. No test was omitted, added, renamed or excluded.
- **Assertions:** 465,578 locally against CI's 468,263, a difference of −2,685, all in 12 concurrency classes:
  - `CrossHostHandoffConcurrencyTest` −2,592;
  - `ServiceAssertionReplayConcurrencyTest` −315;
  - `PartnerApiClient…` −31;
  - `FinancialPeriod…` −7;
  - small positives elsewhere.

  These are polling loops whose assertion count depends on timing: RF2 §8 records this ("no monotonic assertion total is required"; the inventory and union are what must hold). Both hold: `phpunit-list.xml` is identical to CI's, as is the union.
- **The one skip** is `EsiCalculationServiceTest::esi_12_disability_special_threshold_is_legally_deferred`: "ESI-12 … DEFERRED -- ADDITIONAL LEGAL CLARIFICATION REQUIRED (ADR 0036 correction addendum §1.9)". Its authority is the gate register's E45 / PAY-L1 row (legal request drafted, not sent, unanswered). It is a recorded legal gate that does not fail a development verdict (C27-C); it remains a Payroll release condition. No other skip.
- **The corrections behind every closed MAJOR passed in it:**
  - WO-23: `WorkforceTaskTransitionEvidenceTest` 21/21, `…CommitTest` 4/4;
  - WO-24: `SeparationPersonLevelAccessTest` 20/20, `…ConcurrencyTest` 10/10, and the updated `SeparationRetainedAccessBindingTest` 13/13 and `SeparationOwnCaseTest` 5/5;
  - WO-25: `IdempotencyServerErrorRealRouteTest` 4/4, `BacklogSignalsTest` 8/8.
- **The CI leg** (C36 §3): run 44781452652 on `5dcc35ef`, attempt 1, every required job green, 11,998 tests and the same union. It stands; no new code CI was run.

## 3. The four closure checks (C12 docket §12)

| Check | Command (docket) | Passes when (docket, verbatim) | Classes run (all named, none extra) | Result |
|---|---|---|---|---|
| R1-04 | `AuthorizationCoverageGuardTest`, `SchoolRouteRefusalSweepTest`, `HrDefaultDenyTest`, `HrMutationAuthorizationTest` | "Every route contracted, deny cases pass. It does not prove assurance" | 10 + 2 + 8 + 14 = **34 passed** (3,741 assertions) | **PASS** |
| R1-05 | `HrReportsTest`, `HrReportTemporalSemanticsTest` | "18 entries pinned, none counting people. D-9 answered" | 16 + 3 = **19 passed** (406 assertions). `HrReportsTest` pins exactly 18 definitions (`assertCount(18, …)`, lines 138 and 163). "None counting people": the `PROHIBITED` columns (line 148), the forbidden word "headcount" (line 152), `hr.headcount` answering 404 (line 179), and no rates in task reports (line 485). D-9 is answered by D-RF2-5 (accepted as operational reconciliation) | **PASS** |
| R1-07 | `WorkforceLifecycleIntegrityTest`, `EmploymentEndTeachingOwnershipTest`, `SeparationCaseServiceTest` | "Each owner's contract holds. Shifts absent by design" | 19 + 5 + 21 = **45 passed** (343 assertions) | **PASS** |
| R1-08 | `PayrollHrxSnapshotTest`, `PayrollHrxArchitectureGuardTest` | "Snapshots sound, differences surface, no LOP money. 'Reproduce' read with D-6" | 5 + 3 = **8 passed** (1,661 assertions) | **PASS** |

R1-04 proves the authorization contracts and the deny cases. As the docket says, it does not prove assurance (MFA), which is evidenced by each work order's own tests in the suite.

## 4. Baselines and environment preserved

**Ordinary DDEV** was never a test target. A hash of every row of every public table before and after the run (5,252 rows) showed:
- 15 scheduler heartbeats, as always;
- **two platform audit events from an interactive browser sign-in at 07:53 UTC** by an existing demo persona (`auth.login_succeeded`, `school_context.activated`). This is ordinary use of DDEV during the run, not caused by the regression, which signed into nothing there;
- no other row added, changed or removed.

| Check | After the run |
|---|---|
| Migrations | 402/402 |
| `platform:verify-database` | failed=0 (the connection-encryption operator row only) |
| October 2026 month lock, forced RLS (256), runtime roles, runtime grants | Unchanged: the combined hash `f650a804…` is identical to C36's |
| Frontend manifest | `7b9e6f1bba440e28…`, as selected |
| `lycenza-c36-environment-baseline` | `bc4e0855298d3ab8…`, untouched (not restored, not overwritten) |
| `UAT-HR-SYN` | `active`, its fixtures unchanged |
| `apps/platform/storage/app/private/uat0/secrets.json` | Never read or copied by this checkpoint |

**Evidence bundle.** Kept in `.claude/regression/c37-evidence/` (git-ignored, local).
- **Contents:**
  - the run's identity, shard and R1 logs and JUnit reports, the verify and coverage logs;
  - the runner, the hand reproduction of the harness failure;
  - the DDEV row hashes before and after, the DDEV aggregate and verifier output after, and the environment hashes.
- **SHA-256:**
  - `c37-run.sh` `1686687f15ddea314a55e0918d0ed8f034ffbb3fab3acdb737b5ff0d9ce85f2f`;
  - `identity.txt` `adf13c3e1f5766835e4539cf9cfb666adadae1a669633b9a301e5052b6f5c2f7`;
  - `coverage.log` `070571c210e725d34023751d9a347d9c2500f94a7c7d2ff203ea2b8da15383b7`;
  - DDEV rows before `06ffd2f90188a942758517d0678bebf1850a0fa55d520442cb4b4f1118497179`, after `4dbe5f82dcb75122944c98541d3abdeca631dd01b4024e7e27c88336f8f8efac`.

**Reference pack and Legal/Security:** unchanged (no commit touches them since C36). Registers: 361 capability rows, 61 audit identifiers, RF2's 81 decisions, C36's five dispositions (WO24-N1..N3, WO25-N1/N2), all as recorded.

## 5. Findings

| Id | Severity | Finding | Owner |
|---|---|---|---|
| C37-N1 | INFO | Local tooling. `/full-regression`'s `cp -al vendor` fails when vendor files are root-owned (2,158 here) and `protected_hardlinks=1`; the skill aborts. A copy works (§1). Not a product or test defect | Platform (local tooling) |

**No development HIGH or MEDIUM is open.** Nothing found here changes a C36 disposition.

## 6. Independent review

An independent reviewer read the raw evidence and this record, read only. It is not an owner approval. **The PASS is supported.** It confirmed, from the run's own files:
- the selected SHA in an isolated project;
- every shard green and plan-verified;
- the union sha256 identical to CI run 44781452652's coverage log (fetched through the API);
- 0 failures and 0 errors in the parsed JUnit;
- the single ESI-12 skip and its authority;
- each R1 report holding exactly the docket's classes, with counts equal to the full suite's;
- no secret or personal data.

| # | Rating | Finding | Treatment |
|---|---|---|---|
| R-1 | MEDIUM | §7 overclaimed: "eligible", and "settles two preconditions", while §2's first precondition and §1's timing are undetermined, and the fingerprint is C36's | **Corrected** (§7) |
| R-2 | LOW | §4's DDEV claims had no artifact | **Corrected:** evidence bundle with hashes (§4) |
| R-3 | LOW | The first attempt's log was not retained | **Corrected:** stated, with the reproduction named (§1) |
| R-4 | LOW | The assertion difference was attributed generically | **Corrected:** classes named (§2) |
| R-5 | LOW | The runner was described as the skill itself | **Corrected** (§1); hashes recorded (§4) |
| R-6 | INFO | "None counting people" was not mapped | **Corrected** (§3) |
| R-7 | INFO | `reset-r1`'s exit code was not checked | Stated (§1); its log ends "reset complete" |

## 7. Verdict and next

**CLOSURE REGRESSION PASS — SELECTED BASELINE VERIFIED.**
- The full canonical regression passed on the selected source, in the approved isolated environment, on its first execution: 11,998 tests, the canonical union.
- All four docket checks pass under their own criteria.
- The accepted CI leg stands, every baseline is preserved, and no new development defect is open.

**What this establishes for the formal assessment** (closure contract §3 R1-01 to R1-12 and §4):
- §2's green-regression precondition is now evidenced, in CI (44781452652) and locally on the selected SHA;
- the pack fingerprint stands from C36.

**Left for the assessment to determine:**
- §2's first precondition (HR.1 to HR.16 each published or recorded DEFERRED, under C16's reading);
- §1's timing (HR connected with each listed domain; Inventory, Library and Finance clearance remain owner-deferred NOT_INTEGRATED).

The closure regression no longer stands in its way.

The assessment is not started and needs the owner's go-ahead. It must keep apart:
- the development verdict;
- UAT sign-off (browser, axe, keyboard and the manual screen-reader pass, C18-A12: none run; the UAT School administrator still needs an MFA factor);
- RG-ID-1 (open), R1-02 (PARTIAL), C33-H1..H5, XRP-3 and the Legal/DPO gates;
- staging (PAUSED) and production (NO-GO).

E16 is valid through 2026-11-28; any publication from 2026-11-29 needs its successor first.
