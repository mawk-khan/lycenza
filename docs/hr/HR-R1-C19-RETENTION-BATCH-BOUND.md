# HR.R1-C19: C15-N1, the retention batch bound (2026-11-08)

**HR.R1 stays OPEN, and this is not the closure audit.** It closes C15-N1 for development: a real defect in Retention's batched deletes, owned by Retention/Platform, corrected under the existing E21 contract. Baseline `a7a97e55` (C18 runtime `26ace7b3`).

## 1. Scope and ownership

| Owner | What C19 touched |
|---|---|
| Retention | 14 batched `retention_expire_*` function bodies, patched in place (rule 146); no predicate, period, hold or identity check changed |
| Platform | Test only: the C15-N1 assertion keeps its output; no CI, sharding or gate change |
| HR, Payroll | Nothing. Their evidence units take no batch limit and are untouched |
| Legal/DPO | Nothing. E21-D9 stays unratified; no period changed |

## 2. The evidence

| Occurrence | SHA | Run, shard | Output |
|---|---|---|---|
| C15 | `8132f9c7` | 42423542688, shard 2 | Not captured: "Output does not contain 'Deleted 5 School'". Job re-run passed |
| C18, 2nd candidate | `ce0821a9` | 42476818442, shard 4 | Not captured, same message. The whole shard 4/4 ran green locally on the same inventory |
| C18, 3rd candidate | `26ace7b3` | 42480517599, shard 4 | **`Deleted 4 School and 0 platform audit event(s); held: 0 School, 0 platform.`** after a dry run of 5. Job re-run passed |

The failing assertion (`AuditRetentionPruneTest::dry_run_counts_batches_and_reruns_are_safe`) expects one run with batch size 2 to delete all five eligible rows. That is the contract: `platform:audit-prune` deletes every eligible, unheld row it can lock, in bounded batches (E21 determination: "a batch capped at 5000, `ORDER BY id`, `SKIP LOCKED`, and the predicate re-applied"). The assertion was right, and it is unchanged.

## 3. Root cause

**The statement.** Each of 14 functions deleted (one writes `FOR UPDATE OF e SKIP LOCKED`):

```sql
DELETE FROM t x WHERE x.id IN (SELECT id FROM t WHERE <eligible> ORDER BY id LIMIT n FOR UPDATE SKIP LOCKED) AND <eligible>;
```

**What PostgreSQL may do with it.** The planner may choose a Nested Loop Semi Join with the `LIMIT` subquery as its inner side, rescanned for every outer row. One manual measurement on the isolated test database (PostgreSQL 16, 20,000 other rows, five rows with random ids, `ANALYZE`, no planner setting forced) got that plan for a custom plan:

| Plan | Shape | Rows deleted by one call with `LIMIT 2` |
|---|---|---|
| Custom (`force_custom_plan`) | Nested Loop Semi Join, subquery `loops=5` | **3** |
| Generic | Hash Semi Join, subquery once | 2 |

On each rescan, `LockRows` skips the rows the statement has already deleted, so `LIMIT n` returns the next ones, and the call deletes more than `n`.

**Why the run stopped.** `RetentionExpiry::run()` loops `while ($deleted === $batch)`. An overshoot is not a full batch, so the run ended. How far one call overshoots depends on the rows' physical order against their id order: in id order it deletes all five ("Deleted 5", a pass); with the highest id stored after the first two, the first call deletes 4 and leaves the highest id, exactly CI's output.

**Why only CI, and only sometimes.** Two conditions had to meet: the rescanning plan, and a physical row order that differs from id order. PL/pgSQL uses custom plans for a statement's first five executions in a session, and the test harness opens a new retention session per test, so the plan follows `school_audit_events` statistics; the physical order follows how a churned table reuses free space. Both come from what earlier tests in the same shard did, which fits a failure that depended on test order and passed on re-run. That is inference: an independent reviewer's unforced attempt with different data chose a hash join, and no CI plan was captured.

**What it never did.** Delete an ineligible row (the outer predicates re-apply, and the anchor guard checks every deleted row), delete a held row (the hold check runs first, and in the delete trigger), or cross a School.

**Classification:** an actual pruning defect in production code, under-deletion only. The test assertion and fixture were correct. No environment or process-isolation cause was found, and none was needed.

## 4. The correction

`2027_02_14_090000_evaluate_retention_batches_once` rewrites the one batched statement of each of the 14 functions to:

```sql
DELETE FROM t x WHERE x.id = ANY (ARRAY(SELECT id FROM t WHERE <eligible> ORDER BY id LIMIT n FOR UPDATE SKIP LOCKED)) AND <eligible>;
```

An uncorrelated `ARRAY(...)` sublink is an InitPlan, evaluated once per statement, so the locked batch is fixed before any row is deleted. Under the same custom plan, the call now locks 2 rows once and deletes 2.

- **Applied exactly once per body,** refusing any other shape (an `OF <alias>` lock target is kept); `down()` reverses it. Owner, grants, `SECURITY DEFINER` and `search_path` are kept.
- **No PHP change.** With every call at or under the batch, `while ($deleted === $batch)` is correct: a short batch now only means that fewer than `n` unlocked eligible rows remain.
- **No migration of data, no new table.** Forced RLS is still 254 tables.

**Functions:** `api_client_credentials`, `cancelled_communication_events`, `communication_delivery_policy_decisions`, `communication_events`, `elective_teaching_assignments`, `erasure_cases`, `group_role_assignments`, `membership_role_assignments`, `platform_audit_events`, `platform_role_assignments`, `released_email_suppressions`, `school_audit_events`, `school_elevations`, `teaching_assignments` (each `retention_expire_*`).

## 5. Proof

**`RetentionBatchBoundTest` (15 tests).** It forces the rescanning plan on the retention session, so the outcome no longer depends on statistics. It covers:

- the forced settings produce the rescanning shape (checked on an owner session with the same settings, since the retention role reads no table);
- one call never deletes more than its batch;
- every batch boundary: 0, 1, batch − 1, batch, batch + 1, two full batches, a partial last batch, batch of one;
- ineligible rows and another School untouched;
- a held School keeps every row;
- a row locked by another session is skipped, the rest are deleted, the run ends, and the next run takes it;
- the command reports every row;
- every batched function evaluates its batch once (inventory pinned at 14, any `OF <alias>` spelling included).

**Mutation (the unfixed functions, same tests).**

- One `LIMIT 2` call deleted 4.
- The command printed `Deleted 4 School ... held: 0 School`, CI's output exactly.
- The 7/3 and 5/1 boundaries left a row.
- The locked-row case was wrong, and the structural guard failed.
- With the migration, all 15 pass.
- The independent review found that a first version of the migration missed `retention_expire_communication_events` (it writes `FOR UPDATE OF e`). It was added, and the widened guard failed on the 13-function version, naming that function, before the fix.

**Other checks.**

- **Rollback proof:** the schema is identical after rollback and re-apply (28 schema lines removed by the rollback). `platform:verify-database` reports `failed=0 operator_evidence_required=1`.
- **Suites:** the full Retention suite (291 tests), `AuditRetentionPruneTest`, `HrxRetentionConcurrencyTest`, the Staff Attendance suite (62) and the month-lock raw-role tests pass on the isolated test database.

**Independent review.** Two rounds on the final candidate.

| Round | HIGH | MEDIUM | LOW | What changed |
|---|---|---|---|---|
| First | 1 | none | 2 | H1: the 14th function, `retention_expire_communication_events` (`FOR UPDATE OF e`), was unpatched and the guard missed it. Fixed. L1 (physical order) and L2 (what the plan test proves) were fixed in this record and the test name |
| Re-review | none | none | 2 | L-a: the IN-LIMIT refusal now runs over every `retention_expire_*` body. L-b: this record's wording. Both fixed after the re-review, test and wording only |

The re-review confirmed, in SQL on the reset database, that all 14 bodies read `= ANY (ARRAY(SELECT` and that no public function anywhere still has an `IN (SELECT ... LIMIT`.

## 6. Dispositions

| Id | Disposition |
|---|---|
| C15-N1 | **CLOSED for development** by this correction. Its own assertion is unchanged, and now keeps the real output |
| C19-N1 (new, LOW) | `CommunicationRetentionService` deletes `communication_deliveries` in its own PHP loop: it selects `batch` ids, locks them `SKIP LOCKED`, and continues only while the deleted count equals `batch`. It cannot overshoot, but one row locked by another session ends the run although later rows remain (`RetentionBatch` continues on the selected count instead). Not observed, not fixed here. Owner: Communications/Retention |

## 7. C18 acceptance debt (from `HR-R1-C18-WO6-MONTH-LOCK.md`)

None of these is affected by C19, and none is marked done. Each is open.

| Id | Item | Kind |
|---|---|---|
| C18-A1 | Race: a register save against a month lock | Concurrency test |
| C18-A2 | Race: a decision against a later ordinary write | Concurrency test |
| C18-A3 | Race: Payroll capture against a locked-month correction | Concurrency test |
| C18-A4 | A stale fresh-MFA proof refused | Assurance test |
| C18-A5 | REPEATABLE READ refused on `staff_attendance_locked_corrections` (request and decision) | Database test |
| C18-A6 | The database-clock branch of `lockMonth`'s translation, which matches message text, not SQLSTATE 23514 | Test, and a possible hardening |
| C18-A7 | `platform:verify-database` pins for the new triggers | Verifier |
| C18-A8 | A malformed `{lockedCorrection}` under elevation is a 404 before the 403, like every UUID parameter | Informational |
| C18-A9 | A direct `/api/v1` refusal test of a locked-month write | API test |
| C18-A10 | A real logged-in browser walkthrough | UAT |
| C18-A11 | Automated accessibility checks (axe) on the two pages | Accessibility |
| C18-A12 | A manual screen-reader pass (a limitation of this environment) | Accessibility |
| C18-A13 | Representative performance measurements | Performance |
| C18-A14 | School-month advisory-lock contention measurements | Performance |

## 8. Current-month locking: HR-owner confirmation package

C18 lets only an **ended** month be locked (service, page and database). C17 did not decide this; it was a conservative engineering choice, and C19 does not change it.

**The question.** May a School lock the current month before it ends?

| Option | Effect |
|---|---|
| **A. Ended months only** (current behaviour) | A month locks from the 1st of the next month. Nothing in an open month is ever unrecordable |
| B. Any month up to today | The rest of the month can no longer be recorded at all, since a lock never reopens; each later day needs a two-person correction, and a correction cannot create a record |
| C. The current month from its last day | Same as B for the last day only, and it depends on the School's timezone at midnight |

**Recommendation:** A. Under B, the remaining days are lost for good, because nothing reopens a month and a correction only changes an existing record.

**Needed from the owner:** A (confirm), or B/C (a change, implemented in its own checkpoint).

## 9. Limits

- **Behavioural tests** run against `retention_expire_school_audit_events` through the real command and service. The other 13 carry the same one-statement change (`communication_events`, the most complex, keeps its `OF e` lock target), pinned structurally by the inventory guard, not each by its own fixture. The guard also refuses an `IN (SELECT ... LIMIT` in every `retention_expire_*` body, whatever its lock spelling.
- **The forced plan** stands in for CI's statistics. The unforced custom-plan measurement in §3 was one manual check on the isolated test database, not a committed test, and an independent attempt with other data did not reproduce it; the mechanism, not the CI plan, is what is proven.
- **Contention accounting:** a row locked by another session is not deleted and is not reported separately. `eligible − deleted − held` is what remains. The report contract (eligible, deleted, held) is unchanged.
