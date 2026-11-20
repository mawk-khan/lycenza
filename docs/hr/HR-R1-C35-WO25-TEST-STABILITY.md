# HR.R1-C35 WO-25: two timing-dependent tests made deterministic (WO22-N5, C26-N2; 2026-11-20)

**HR.R1 stays OPEN.** This implements WO-25, the test-only unit owner decision D-RF2-7 authorized (`HR-R1-C35-RF2-RESIDUAL-DECISIONS.md` §6), which RF2 requires before the final closure regression. Two tests that could fail without any product defect now prove the same contracts deterministically. No application, migration, database function, privilege or CI-policy file changed.

| | |
|---|---|
| Baseline | `2efe705c` = `main` = `origin/main` (WO-24 evidence); WO-24's runtime code `e7d7ee02`; clean tree, one worktree; the historical stash (`stash@{0}`, 2026-08-27) and `integration/e16-r2` preserved; 402 migrations |
| Authorized | D-RF2-7 (2026-11-20, Platform) and the owner's WO-25 dispatch (2026-11-20): test-only root cause and correction of WO22-N5 and C26-N2, affected tests and existing test helpers only, the isolated test stack, an independent review, protected-main publication |
| RF2 §6 constraints kept | Instrumented first; reproduced under repeatable load; never only a raised 25 s deadline; "one request returns 409 while the other is held" never weakened; no change to `EnsureIdempotent`, `IdempotencyGuard` or `CommittedEffectDetector` (empty diff under `app/`); harness and product failure kept apart |
| Not changed | Every file under `app/`, `database/`, `routes/`, `config/`; CI workflows and the selected-baseline rule (D-RF2-3); the development DDEV project (never started: 398 applied of 402); no synthetic UAT data |

## 1. The findings (verbatim)

- **WO22-N5** (`HR-R1-C35-WO22-TASK-REVIEW-GOVERNANCE.md:108`, LOW, CI): "`IdempotencyServerErrorRealRouteTest::two_real_processes_racing_a_released_failure_never_write_twice` (C30, unrelated to WO-22) failed once on the first candidate run: two real HTTP processes did not answer within its 25 s barrier under CI load. It passed locally and on the rerun. A timing-sensitive test; it needs its own disposition".
- **C26-N2** (`HR-R1-C26-R1-01-ENFORCEMENT.md:193`, observation, Platform): "`BacklogSignalsTest::an_automation_execution_waiting_for_its_backoff_is_not_overdue` asserts an exact age (230 s) across a second boundary and failed once with 231 on attempt 1 of C26's candidate run; the same flake was seen locally in the E16 cycle. Not a product defect; the assertion needs a tolerance or a frozen database clock".
- **RF2** (D-RF2-7, §6): authorize WO-25, test-only, before the final closure regression; a recurrence is reported, never silently rerun.

## 2. WO22-N5

**The contract the test proves (unchanged).**
- Two real processes (`curl` against `php -S` with four workers), so two PostgreSQL sessions, use one key against a route whose injected failure commits nothing.
- One request fails for real (500). The other overlaps it and finds it in flight (409).
- No note is written and no 5xx is remembered.
- Then an unheld race of the same key after the cause is removed writes exactly one note.
- The same coordination serves `two_real_processes_racing_a_committed_failure_create_one_endpoint` (a 5xx after a commit is remembered; one endpoint).

**The CI failure, inspected.** WO-22 run 43581045054, attempt 1, job 132405678034 (shard 3/4, 2,986 tests, 10:20): `neither request returned while the other was held` at line 253, before any business assertion. The log carries no timing or database state; the lock was released in `finally`, so the run shows no duplicate write and no wrong replay.

**RF1's history.** RF1 counted one failure among 70 CI runs since 2026-11-11 and 10/10 local passes; that count is RF1's, not recounted here. Since then every candidate was green on its first attempt for this test (WO-23 `44736698951`, WO-24 `44766606712`).

**Investigation (throwaway instrumented copy, deleted; isolated test database).**
- **The claim cannot make B wait on A.** `IdempotencyGuard::claim()` is an autocommitted lookup-then-INSERT outside any transaction, so B meets A's committed claim and answers 409 at once. RF1's cause 2 (a unique-index wait) is excluded by construction.
- **CPU starvation alone gives seconds, not 25 s.** The original coordination finished in 0.36 s locally. With both containers limited to 0.5 CPU it took 1.1–1.3 s, and with the platform container at 0.15 CPU, 3.7–4.9 s (8/8 passed).
- **One worker serving both requests: not reproduced.** A `php -S` worker can accept a second connection while it holds a first, which would queue B behind the held A. A direct probe did not show it: two simultaneous requests to a four-worker `php -S` running a 1.5 s script never waited behind each other (0 of 160 unloaded, 0 of 80 at 0.15 CPU).
- **Determination: test harness, root cause not reproduced.** WO22-N5's exact stall is not reproduced, and this record does not claim one. What is established is the shared weakness: both requests started together, so a stall anywhere before the trigger (the server, either request's boot, a lock on the path) and a real overlap failure were one indistinguishable message with no evidence. **No product defect was found.**

**What changed (`IdempotencyServerErrorRealRouteTest`, test-only).**
- **Proven phases, not elapsed time.** Request A starts first. It counts as held only once PostgreSQL shows another client backend of this database, running an `INSERT`, waiting on exactly the single-key advisory lock 3030 (`classid 0, objid 3030, objsubid 1`, `pg_locks` joined to `pg_stat_activity`).
- **Then B.** Only then does request B start, a second real process and session. It must be answered while A is still held.
  - A held request is inside its worker's dispatch, so the shared-worker hazard is removed by construction.
  - The race is still real: B runs against A's open, committed-claim, in-flight request.
- **The budget is unchanged:** 25 s, now measured from A's start (stricter: A's and B's boots are serial inside it), under the 30 s in-flight timeout.
- **Each failure says which phase failed,** with a snapshot of every client backend (state, wait event, statement prefix) and the server's last access-log lines:
  - A returned before reaching the barrier;
  - A never reached it (harness: server or boot);
  - B not answered while A held;
  - A no longer held when B answered;
  - B got no HTTP answer (harness).

  Application statements are bound, so only a fixture literal could appear; tokens and request bodies never do.
- **Readiness.** The server must answer a real application request (`/api/health/live`, 200, its own 20 s cap, before the race budget starts) and still be running. A stray server bound to the port is refused, and is cleared before start.
- **Cleanup.** The barrier lock is released in `finally`; the curl processes are stopped by their objects; the server is stopped. Delta review M-T1's `pkill` fallback was a **silent no-op**: `pkill` (and `ps`) do not exist in the platform image. It is replaced by a `/proc` scan with `posix_kill`, matching only `php -S 127.0.0.1:18230 `.
- **Precondition.** The barrier needs the admin connection to read other sessions' statements (superuser or `pg_read_all_stats`, true in every environment today); otherwise it fails saying so.
- **Assertion strengthened.** "A still running when B answered" is now asserted. The old `$a->isRunning() ? $b : $a` could pick the wrong request if both returned between polls.

**What is no longer forced (WO25-N1).** B now always meets A's committed claim through the lookup. The simultaneous-INSERT branch (`UniqueConstraintViolationException`) may still be exercised, never forced, by the unheld retry race and `IdempotencyRealConcurrencyTest`, as it was only probabilistic before. A deterministic test of that branch would be a separate Platform decision.

## 3. C26-N2

**Root cause, from the code and reproduced deterministically.**
- `operational_work_backlog.lease_expires_at` is `timestamp(0)` (`Schema\Builder::$defaultTimePrecision = 0`), and Laravel binds dates as `Y-m-d H:i:s`, so the insert keeps whole seconds of the REAL clock at insert time.
- The test's first `travel()` then froze the clock at a later real instant. The age is `(int) diffInSeconds`, which equals 230 + frac(insert) + elapsed, truncated: **231 whenever a second boundary falls between the insert and the first `travel()`**.
- A throwaway test (deleted) ran the original body under a controlled clock:
  - insert at .200, 3 ms → 230;
  - insert at .990, 3 ms → 230;
  - insert at .990, 15 ms → **231**;
  - insert at .999, 2 ms → **231**.
  - With `freezeSecond()` first: 230 in all four.
- **Not a product defect:** the signal is whole-second by design.

**What changed (`BacklogSignalsTest`, test-only).** `freezeSecond()` before the fixtures. The exact `230` assertion is kept; no tolerance was added. The 100 s "not overdue" step keeps its meaning (S+100 is before the lease S+120 and the backoff S+300). The file's other clock assertions are 0, greater-than or threshold checks with whole-second margins, and are unchanged.

## 4. Evidence

**Scope.** `git diff --stat` touches two test files and this record; the diff under `app/`, `database/`, `routes/` and `config/` is empty.

**Planted regressions** (each restored with `git checkout` of that one file; the tree verified clean after):

| Plant | Test result |
|---|---|
| P1: an in-flight claim lets the second request run (`EnsureIdempotent`) | **Detected:** "request B was not answered while A was held after 25.0 s; A running; B running" (product failure, named as such); the endpoint race 422 ≠ 409 |
| P2: every 5xx remembered (`CommittedEffectDetector`) | **Detected:** the release assertions in two tests |
| P4: the backlog age one second off (`OperationalSignals`) | **Detected:** 231 ≠ 230 |
| P5: the barrier removed from the injected trigger (test) | **Detected:** "request A returned before reaching the injected failure after 0.3 s" |
| P6: a lock stalls A before the trigger (test) | **Detected:** "request A never reached the injected failure (harness: server or boot) after 25.0 s", with the snapshot showing the INSERT waiting on a relation lock |

All five were run on the first fix. P1, P2, P5 and P6 were run again after the review fixes and gave the same results.

**Repetition** (isolated test stack, one run at a time):

| Run | Result |
|---|---|
| Phase timings, unloaded, 20 runs | 20/20; A held at median 0.27 s (max 0.49); B answered at median 0.42 s (max 0.71); margin to 25 s ≥ 24.29 s |
| Phase timings, both containers at 0.3 CPU, 20 runs | 20/20; held median 0.80 s (max 1.64); answered median 1.30 s (max 2.63); margin ≥ 22.37 s |
| `IdempotencyServerErrorRealRouteTest` (all 4 tests), first fix | 20/20 |
| `BacklogSignalsTest` (all 8 tests) | 20/20 |
| `IdempotencyServerErrorRealRouteTest` after the review fixes | 20/20 unloaded, 10/10 at 0.3 CPU, 5/5 after N1/N2 |

This is assurance, not proof of zero flakiness. CI is the representative environment (4-vCPU runners, the shard as planned), and its run is recorded in §7.

**Focused suites.** Idempotency 54, Observability 88, and `PayrollIdempotencyRealConcurrencyTest` 1: all green.

**Static gates.** `/publish-gate` PASS on the candidate tree: Pint, Larastan, whitespace (untracked included), gitleaks on the changed files.

**Isolation.** Each test owns its fixtures (committed and purged in tearDown), its port (18230, unique in the suite) and its advisory key; no global configuration changes and no shard order is assumed. No test was deleted, renamed, skipped or excluded. CPU limits used for the load runs were set on this worktree's own isolated Compose project only, and restored.

## 5. Independent review

An independent reviewer read the change, read only, without running anything. It is a review, not an owner approval. **No HIGH, no MEDIUM; no product defect; scope test-only (empty `app/` diff).**

| # | Rating | Finding | Treatment |
|---|---|---|---|
| R1 | LOW | The barrier probe accepted any waiter on lock 3030, including another run's own lock or a 64-bit key whose low half is 3030 (misreported phase, never a vacuous pass) | **Fixed:** exact single-key match, another client backend running an INSERT |
| R2 | LOW | "Still exercised" overstated the simultaneous-claim coverage | **Fixed** wording; recorded as WO25-N1 |
| R3 | LOW | Liveness could be answered by a stray server | **Fixed:** this server must still be running |
| R4 | LOW | B's failed curl (code 0) would read as the product assertion | **Fixed:** a separate harness failure |
| R5 | INFO | `posix_kill` unguarded | **Fixed** |
| R6 | INFO | Diagnostics built on every passing run | **Fixed:** only on failure |
| R7 | INFO | Two docblock claims overstated (bound statements, worker attribution) | **Fixed** |
| R8 | INFO | The fix is not evidence of a reproduced root cause | **Recorded** (§2): hypothesis tested, not reproduced |

**Confirmed by the reviewer:**
- the change is at least as protective, and stronger in one assertion;
- the 25 s budget is not raised (now stricter), and the readiness cap is not a race budget;
- the lock is always released and processes reaped; the `/proc` match cannot hit the test runner;
- the C26-N2 root cause and `freezeSecond()` behaviour are as stated (column precision, binding format, truncation, `travel()` from the frozen second).

**Final-state recheck** (same reviewer, read only): all eight fixes correct and complete; no new HIGH or MEDIUM. The INSERT is the held backend's top-level statement inside the BEFORE trigger, and the autocommit admin connection reads a fresh `pg_stat_activity` each poll.
- **N1 (LOW):** the barrier needs the admin role to see other sessions' statements. Fixed with an explicit precondition.
- **N2 (INFO):** clear a stray server before starting. Fixed.
- Both were applied as proposed and verified by runs and the publish gate; they were not sent for a further review.

## 6. Dispositions

| Item | Status |
|---|---|
| **WO22-N5** (LOW) | **CLOSED for development on publication.** Coordination made deterministic and diagnosable; the stall itself was not reproduced. A recurrence is reported as WO22-N5 with its phase and snapshot, never silently rerun (D-RF2-7) |
| **C26-N2** (observation) | **CLOSED for development on publication.** Root cause reproduced and removed; the exact assertion kept |
| WO25-N1 (LOW, Platform) | The simultaneous-claim INSERT branch is not deterministically tested anywhere (it never was). A deterministic test is a separate Platform decision |
| WO25-N2 (INFO) | The platform image has no `pkill`/`ps`; any other test relying on them would no-op silently (none found in `tests/` besides the one replaced) |
| WO24-N1, N2, N3 (LOW) | Unchanged, pending their owner decisions |
| Development MAJOR inventory | None open |
| RF2's 81 decisions, the 361 capability rows, the 61 audit identifiers, RG-ID-1 (open), R1-02 (PARTIAL), C33-H1..H5, Legal/DPO gates | Unchanged (no capability, seeder, register row or gate file touched) |

## 7. Publication

- **Candidate `5dcc35ef`, CI run 44781452652: green on attempt 1, no reruns.**
  - **11,998 tests, 468,263 assertions, 0 failures, 1 skip (ESI-12)**;
  - four shards (3,000 / 3,000 / 2,999 / 2,999);
  - the coverage union equal to the canonical inventory (1,419 files). Its selection sha256 `0d8fd41c…` is **identical to WO-24's**: no test was added, removed, renamed or excluded.
  - Static and frontend, full-history secrets, supply chain, repository integrity and production images all green.
- **Assertions:** 484 above WO-24's 467,779: polling-dependent counts (RF2 §8) and the added assertions; the test count is unchanged.
- **Committed-range gitleaks** (`2efe705c..5dcc35ef`): no leaks.
- **Published** with `lycenza-ci publication-check --candidate 5dcc35ef… --push` on 2026-11-20: fast-forward, gate check run 136457760905, no problems. `origin/main` = `5dcc35ef`. E16 (`OWNER-0O-E16-2026-11-01`) valid through 2026-11-28.
- **Main CI** on `5dcc35ef`: run 44785398885, green on attempt 1.
- **Regression counter:** a remote checkpoint at `5dcc35ef` (the exact-SHA full suite of a code-lane candidate); **counter 0/5.** This is a publication regression under rule 96, not the selected-baseline closure run (D-RF2-3).

**Verdict: WO-25 IMPLEMENTED — TEST STABILITY DEVELOPMENT VERIFIED.** C26-N2 is root-caused and removed. WO22-N5's coordination is deterministic and diagnosable, but its stall was not reproduced, so a recurrence is reported, never rerun. No product file changed and no development MAJOR is open. HR.R1 stays **OPEN**.

## 8. Sequencing kept

- **DDEV:** untouched (never started). The D-RF2-22 operator step names exactly the four pending migrations:
  - `2027_02_24_090000_record_separation_clearance_staff_type_basis`
  - `2027_02_25_090000_refuse_known_same_person_task_review`
  - `2027_02_26_090000_bind_task_events_to_task_version`
  - `2027_02_27_090000_bind_separation_access_to_the_person`

  It brings DDEV from 398 to 402, under the record §9 of RF2 requires.
- **Synthetic UAT** (D-RF2-23): after the DDEV step; not started.
- **HR.R1-C36:** not started. The selected baseline still needs one clean, complete CI execution of every required job; a failed-jobs-only rerun does not count (D-RF2-3). WO-25's own publication is not that run.
