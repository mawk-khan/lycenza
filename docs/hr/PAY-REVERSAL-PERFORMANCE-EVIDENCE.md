# Payroll reversal performance evidence (HR.R1-C8)

Measured 2026-11-07, synthetic data only, on the local DDEV stack. **These are observations, not targets. No SLA exists.**

## Environment

PostgreSQL 16.15, 16 CPUs and 32 GB shared with the host, `shared_buffers` 128 MB, `work_mem` 4 MB, `jit` on, `max_parallel_workers_per_gather` 2, `random_page_cost` 4, autovacuum on. Schema: every migration to `2027_02_10_090200`. A fixture is committed nowhere: each runs in one transaction that rolls back, as `school_os_app` under forced RLS, with a School context set.

The harness was a temporary test built on the `PayrollReconciliationLargePopulationTest` fixture. It is not in the repository, and its method is described here so it can be rebuilt.

**Dataset.** N Employees, each with one employment and one compensation assignment by one author. One reversed run, approved independently, plus D dependent posted runs approved by the author, each with N calculation sources. Two uncorrected ended links per Employee (2N rows), neither held by a decider. Same-School single tenant. Cold and warm are the first call and later calls in one connection: OS caches were not dropped, so this is not a true cold start.

## What the slow measurement was

`payroll_reversal_affected_runs` as published in HR.R1-C7 (`fc7fcffe`), to completion, at N = 10,000, D = 2, all tables analyzed:

| Body | Time | Rows |
|---|---|---|
| Old | **172.7 s** | 2 |
| New | 0.26 s | 2 |

That reproduces the reported 184 s in order of magnitude. The 560 s reversal was the same query three times: discovery once, and the obligation guard once per obligation (3 × 184 = 552).

**Root cause.** SQL functions here are planned without parameter values. The link check was an anti-join inside the join that finds what the reversed run discharged. The planner estimated **1 row** after it, moved it ahead of the rest of the join, and joined every remaining row to every assignment. `EXPLAIN (ANALYZE, BUFFERS)` at N = 4,000:

```
->  Result  (cost=6061.59..19246.96 rows=1 width=64) (actual time=37.367..69345.847 rows=4000 loops=1)
->  Nested Loop  (cost=6061.59..19246.96 rows=1 width=64) (actual time=37.365..69343.338 rows=4000 loops=1)
Rows Removed by Join Filter: 15996000
->  Nested Loop  (cost=6053.16..19234.49 rows=1 width=160) (actual time=31.344..42021.894 rows=16000000 loops=1)
->  Result  (cost=0.01..7117.01 rows=8000 width=48) (actual time=13.233..14.456 rows=8000 loops=1)
->  Nested Loop  (cost=0.00..5993.15 rows=4000 width=128) (actual time=3.881..12.218 rows=4000 loops=1)
```

Estimated 1, actual 4,000; 16,000,000 candidate rows; 87 million buffer touches. New body at the same size: Execution Time: 88.176 ms.

## Why fast and slow measurements disagreed

The plan depends on planner statistics, and with partial statistics on the ANALYZE sample too. Eight fresh fixtures per state, N = 10,000, D = 2, 12 s statement timeout:

| Statistics | Old body | Old plans | New body | New plans |
|---|---|---|---|---|
| No statistics | 0.4 s, 0.4 s, 0.5 s, 0.5 s, 0.4 s, 0.4 s, 0.6 s, 0.4 s | 3 | 0.3 s, 0.4 s, 0.5 s, 0.4 s, 0.4 s, 0.4 s, 0.7 s, 0.4 s | 2 |
| All but the link table analyzed | 6.6 s, 6.6 s, 6.2 s, 6.1 s, 0.2 s, 0.2 s, 6.0 s, 7.2 s | 3 | 0.2 s, 0.2 s, 0.2 s, 0.2 s, 0.2 s, 0.2 s, 0.3 s, 0.2 s | 3 |
| All analyzed | 8.7 s, >12 s, 8.7 s, >12 s, 8.7 s, >12 s, >12 s, >12 s | 2 | 0.3 s, 0.3 s, 0.4 s, 0.3 s, 0.3 s, 0.4 s, 0.3 s, 0.4 s | 1 |

So a database with normal statistics was the exposed state, not a fresh one, and the fast reading was one of several plans. **Not established:** the exact state of the one run that read 184 ms with these tables analyzed. Fully analyzed here, the old body never came in under 8.6 s in 8 of 8 runs, so that reading was not reproduced. The new body stayed under 0.7 s in all 24 runs, across every plan.

## Locks and waits

`pg_stat_activity` was sampled every 4 s for the whole of the first benchmark (1,325 active samples): no `Lock` wait. CPU 802, parallel worker IPC 396, IO 121, other 6. The time is query execution, not contention. One writer ran at a time, so this says nothing about a reversal competing with live decisions: it holds the School's exclusive compensation lock throughout, and they queue behind it.

## Growth with size and dependent runs

New body, before the guard fix. Discovery in ms over 9 calls; recording is three runs.

| Entries | Dependent runs | Min | Median | Max | Recording, median | Obligations | Queries |
|---|---|---|---|---|---|---|---|
| 500 | 2 | 37.4 | 43 | 51.1 | 0.3 s | 2 | 16 |
| 2,000 | 2 | 49.5 | 55 | 61.3 | 0.5 s | 2 | 16 |
| 5,000 | 2 | 198.5 | 250.5 | 314.2 | 1.7 s | 2 | 16 |
| 10,000 | 0 | 96.6 | 120.9 | 142.1 | 0.1 s | 0 | 2 |
| 10,000 | 1 | 207.1 | 240.6 | 297.6 | 0.9 s | 1 | 9 |
| 10,000 | 2 | 288.7 | 318.9 | 455.3 | 2.2 s | 2 | 16 |
| 10,000 | 6 | 698.5 | 810.5 | 1094.3 | 7.9 s | 6 | 44 |
| 10,000 | 12 | 1576.2 | 1660 | 1979.3 | 29.0 s | 12 | 86 |
| 10,000 | 24 | 2889.3 | 3223.5 | 3984 | 105.3 s | 24 | 170 |
| 10,000 | 48 | 9974.2 | 11359.9 | 12119.5 | 550.4 s | 48 | 338 |
| 500 | 24 | 479.1 | 502.1 | 646.4 | 16.0 s | 24 | 170 |
| 2,000 | 24 | 1662 | 1730.6 | 2002.9 | 53.9 s | 24 | 170 |

Discovery grows roughly with entries × runs. Recording grew much faster, because the guard rediscovered every run for each obligation. `2027_02_10_090200` asks only about the run in hand. Same rule, no cap, no cache. The two-argument form still finds every run.

| Entries | Dependent runs | Recording before | After, median | After, range (5 runs) |
|---|---|---|---|---|
| 10,000 | 2 | 2.2 s | 0.5 s | 0.5 to 0.5 s |
| 10,000 | 12 | 29.0 s | 6.6 s | 6.5 to 6.7 s |
| 10,000 | 24 | 105.3 s | 19.7 s | 19.5 to 19.8 s |
| 10,000 | 48 | 550.4 s | 72.0 s | 71.3 to 72.5 s |
| 500 | 24 | 16.0 s | 4.4 s | 4.3 to 4.5 s |

Recording remains superlinear in dependent runs, because deciding whether one run pays a change first reads every other run paying it. That is the rule, not an inefficiency, and it is not traded away. Query counts are flat per obligation (7).

## Guards

`PayrollReconciliationLargePopulationTest` runs discovery at 10,000 entries with link history, analyzed, under a 30 s statement timeout, and pins that the guard asks about one run. On the published function it fails at the timeout; with the fix it takes 6 s in all. It is a bound that only a quadratic plan reaches, not a latency assertion. Latency stays here, measured.

## Operational reading, for the owner

No objective exists. **Recommended for approval, as a target rather than an observation:** a reversal at the expected School size (up to 2,000 staff, up to 3 dependent runs) under 5 s. Measured: 0.5 s at 10,000 entries with 2 runs.

- **Suitable for local UAT.** Staging-scale measurement on real hardware is still owed, and these numbers are not a substitute.
- **Limit:** a reversal of a run with many dependent runs, all approved by the change's own author, at large scale: 20 s at 24 runs and 72 s at 48 with 10,000 entries, with every Payroll decision in that School waiting. 24 months of such approvals is unusual, but nothing prevents it. Not acceptable unnoticed: it should be watched in staging.
