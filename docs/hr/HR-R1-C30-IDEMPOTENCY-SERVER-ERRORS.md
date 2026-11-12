# HR.R1-C30: a server error is remembered only when something committed (C14-N2) (2026-11-12)

**HR.R1 stays OPEN, and this is not the closure audit.** C30 closes C14-N2 for development under Platform owner decisions C30-A and C30-B.

| | |
|---|---|
| Baseline | `506c6c62` = `main` = `origin/main`; `3ad20770` (C29 code) an ancestor; clean tree; the historical stash and `integration/e16-r2` preserved; nothing published since C29 |
| Last full suite before C30 | `3ad20770`: 11,718 cases, 467,527 assertions, 0 failures, 1 skip (ESI-12); counter 0/5. C29's first candidate `90ddf328` failed two register tests and was fixed; the published SHA passed on its first run |
| Authorized | Platform owner, 2026-11-12: C30-A and C30-B as recommended; implement and publish |
| Not changed | Successes, every 4xx, the `SchoolNotOperationalException` release (C11-N2), self-participating routes (`completeWithin()`), the 48 h TTL, key scope and fingerprint, authorization order, the schema (no migration), every business rule |

## 1. The finding, verbatim

C14-N2 (`HR-R1-0-AUDIT-REGISTER.md`): "A rendered 5xx is stored under its key and replayed for 48 hours. `RELIABILITY.md` said it was released, and it never was. Releasing it can double an effect on routes with no atomic completion (webhook endpoints, Inventory stock API)." Owner Platform, P2, MAJOR under C27-C. C14 offered A (keep remembering) and B (release when nothing committed) and recorded no decision (`HR-R1-C14-RESIDUAL-HARDENING.md` §2).

## 2. What was there

- **Persistence.** `api_idempotency_keys` holds:
  - the scope: School, actor type and id, route action, key, unique;
  - a fingerprint of method, route, body, School, actor and Campus authority;
  - a status (`processing`, `completed`, `failed`, `expired`), the stored status, headers and body;
  - `expires_at`, which is claim time + 48 h.

  The only writer is `IdempotencyGuard`. There is no operation-state column, and C30 adds none.
- **Routes.** 130 API routes carry `idempotent`. About 12 are self-participating: they write their completion inside their own transaction, among them Payroll posting and reversal, Leave and Attendance. The rest are default-path: `EnsureIdempotent` records the outcome after `$next()`. No web route uses it, and no frontend or mobile client retries automatically.
- **Order on the default path:**
  1. authentication, `school-membership` and capability (route middleware);
  2. the claim (autocommit);
  3. `$next()`, which runs the business work and opens and commits its own transactions;
  4. the rendered response;
  5. `complete()`.
- **Root cause.** Laravel renders an exception into a response before route middleware sees it (`Pipeline::handleException`). So every rendered response, 5xx included, reached `complete()` as `completed` and was replayed until `expires_at`. The original Phase 0C.2 intent ("release an unclassified failure") ran only on a direct call. C14 corrected the document, not the behaviour.
- **Reproduced on `506c6c62`.** `IdempotencyFailureClassificationTest` made the School check fail before any write. The 500 was stored, no note was created, and the retry replayed the 500 (`Idempotency-Replayed: true`).

## 3. The owner decisions (Platform, 2026-11-12)

| | Decision | Rejected alternative |
|---|---|---|
| **C30-A** | A rendered 5xx is **released** (the same key retries for real) **only when the request provably committed nothing**. Any committed effect, or doubt, keeps today's behaviour | Keep remembering every 5xx (C14 option A) |
| **C30-B** | A 5xx after a committed effect **keeps replaying the stored 5xx**; it is never re-executed. No new public code | A new 409 "outcome unknown" code (an API change for every idempotent route) |

Also authorized:
- `complete()` becomes conditional, so a request that lost its claim never overwrites the newer outcome.
- Rows stored before the fix are left to expire, and nothing is rewritten.
- No migration, and the TTL is unchanged.

## 4. What was built

**`App\Support\Idempotency\CommittedEffectDetector`** (a singleton) is opened by `EnsureIdempotent` before `$next()` and closed after it. "Committed" means any of:

| Effect | How it is seen |
|---|---|
| A write statement attempted outside any transaction the request opened | Judged before it runs (`beforeExecuting` on every connection, including ones opened mid-request). It counts whether or not it reported success: a lost acknowledgement may hide a committed write (review MEDIUM-1) |
| A write inside a transaction level later committed back to the level the request started at | `TransactionCommitted` and `TransactionRolledBack` track levels. A rolled-back level's writes are discarded |
| The outermost COMMIT of a transaction that wrote or locked rows | `TransactionCommitting` asks PostgreSQL (`pg_current_xact_id_if_assigned()`) |
| A SELECT that may write | A VOLATILE `public` function (bare, schema-qualified or quoted), or a `pg_catalog` writer (`setval`, large objects, `pg_notify`) |
| A job about to be queued, an outbound HTTP request, a mail message | `JobQueueing`/`JobQueued`, `RequestSending`, `MessageSending` |

**Treated as a commit (doubt):**
- several statements in one string, `SELECT … INTO` and `EXPLAIN ANALYZE`;
- any statement it cannot classify;
- a regex error;
- a failure to load the function list, which makes every request in that process count as committed.

**Plain reads:** SELECT (row locks such as `FOR UPDATE` stripped first), a CTE without a data change, `SHOW`, `SET`, `RESET`, `DISCARD`, `DEALLOCATE`, plain `EXPLAIN`. Connections are tracked by object, so a connection replaced by `DB::purge()` is hooked again.

**`EnsureIdempotent`:**

| Response | Before | After |
|---|---|---|
| 2xx, 3xx, 4xx | `complete()` (remembered) | `complete()`, now conditional |
| School no longer operational | Released | Released (unchanged) |
| 5xx, nothing committed | Remembered | **Released** (`releaseIfStillClaimed()`, log line `idempotency.server_error_released`) |
| 5xx after a committed effect | Remembered | **Remembered** (unchanged, C30-B) |
| An exception reaching the middleware unrendered (direct call) | Released unconditionally | The same rule: released when nothing committed, otherwise remembered as a 500 |

**`IdempotencyGuard::complete()`** updates the row only while it is still `processing` with this request's `created_at`, the same compare as `releaseIfStillClaimed()`. A request that outlived the 30 s in-flight timeout and was reclaimed records nothing; it logs `idempotency.claim_lost`.

**What it cannot see.**
- **Object storage:** bytes stored before a failure. They hold no business record (rule 86), so a retry creates no duplicate record.
- **Session-level effects:** `nextval`, advisory locks and Redis cache writes. None is a committed business effect today.
- **When remembering is guaranteed anyway:**
  - **Fresh-MFA routes:** the code is spent before the business step, itself a committed write, so every 5xx there is remembered. A retry could not reuse the code anyway.
  - **Row locks:** a committed transaction that only locked rows also counts.

## 5. Evidence

| Proof | Test | Result |
|---|---|---|
| Reproduction then correction: a 5xx before any write is released and the same key runs once; the success is then replayed | `IdempotencyFailureClassificationTest` (pin rewritten) | pass |
| Released when nothing committed. Three shapes: a read then failure; a failure in a rolled-back transaction; an inner commit undone by the outer rollback. Each probe's retry runs again rather than replaying | `IdempotencyServerErrorRecoveryTest` | pass |
| Remembered after a committed effect, never run twice, across nine shapes:<br>• a committed transaction;<br>• an autocommitted write;<br>• a response that failed to render after the commit;<br>• a VOLATILE function;<br>• a job queued through the real database queue driver;<br>• an outbound HTTP request;<br>• an autocommit write that reported failure;<br>• a manual commit;<br>• an unrendered exception (direct call). | same | pass |
| A success is still remembered and replayed. The same key with another request: released means forgotten (a new attempt); remembered means a 409 conflict, with nothing written | same | pass |
| TTL: a remembered 5xx is replayed at +1 min, +24 h, +47:59:59 and +48 h exactly, and forgotten at +48 h 1 s, like any outcome (deterministic clock) | same | pass |
| A 5xx row stored before C30 is replayed until it expires and is not rewritten | same | pass |
| Real routes with real server-side failures, injected by a database trigger on the isolated test database and dropped in teardown:<br>• a note whose transaction rolled back is released, then succeeds once under the same key;<br>• C14's webhook endpoint, which commits before its audit fails, is remembered, and the retry never creates a second endpoint. | `IdempotencyServerErrorRealRouteTest` | pass |
| Two real processes (`curl`) against a `php -S` server with 4 workers. A deterministic advisory-lock barrier holds the first request inside the failing statement until the second has been answered:<br>• exactly `[409, 500]` in both cases;<br>• no note and nothing remembered for the released case;<br>• exactly one endpoint and the remembered 5xx for the committed case. | same | pass |
| A slow request cannot overwrite the outcome of the request that reclaimed its claim, before or after that request completes | `IdempotencyFailureClassificationTest` | pass |
| Statement classification, 25 shapes | `CommittedEffectDetectorClassificationTest` | pass |
| The existing real-HTTP concurrency proof now genuinely overlaps (workers added) | `IdempotencyRealConcurrencyTest` | pass |

**Suites (DDEV, isolated test database):**
- Idempotency and detector: 115 tests, all passing.
- Domains with idempotent routes, all green: HR 1,487; Leave 176; Payroll 542; Staff Attendance 76; Attendance 155; Webhooks 48; Inventory 132; Payments 186; Fees 268; Students 554; Admissions 165.

**Performance (DDEV, one synthetic note):**
- A new request runs 20 queries, of which the detector adds one: the commit check, 0.43 ms.
- Mean request 18.7 ms new and 7.7 ms replay.
- One `pg_proc` read per process loads the function list. Under PHP-FPM that is once per idempotent request.
- No SLA is claimed.

## 6. Reviews

1. **Independent correctness/security review. No HIGH.**
   - **MEDIUM-1:** writes and queue pushes were learned only after success, so a lost acknowledgement read as "nothing committed". Fixed: statements are judged before they run, an attempted autocommit write counts, and `JobQueueing` is used.
   - **LOWs, all fixed:**
     - LOW-2: the function list failed open on a load error.
     - LOW-3: gaps in the statement classifier.
     - LOW-4: the race tests did not really race, because `php -S` had no workers.
     - LOW-5: cases left untested.
   - **INFO:** the `SchoolNotOperationalException` release stays unconditional, as decided.
2. **Independent delta review of those fixes. No HIGH or MEDIUM in product code.**
   - **M-T1 (test infrastructure, unverified):** orphaned `php -S` workers. Not reproduced, since no orphan was found after several runs; teardown now kills the port's server anyway.
   - **LOWs, all fixed:**
     - L1: hooks keyed by name, not object.
     - L2: a regex error counted as a read.
     - L3: a sleep, not a barrier, made the overlap.
     - L4: the throwaway probe was deleted.
   - **INFO, recorded:**
     - a faked event or queue blinds the detector in tests; no current test does this on a 5xx idempotent route;
     - VOLATILE read helpers make a release rarer;
     - session-level effects are invisible.
3. **Final-state recheck of L1–L3:** §8.

## 7. Open after C30

| Item | Status |
|---|---|
| C14-N2 | **CLOSED for development** (§2–§6) |
| C30-N1 (LOW, Platform) | Some read-only helpers are declared VOLATILE in `public` (for example `inventory_location_has_operational_history`), so a 5xx after calling one is remembered though nothing was written. Conservative, not a defect: marking pure read helpers STABLE would make a release possible there |
| C30-N2 (INFO, Platform) | The default path's documented limit is unchanged: a request slower than the 30 s in-flight timeout and its reclaimer can both execute. C30 only stops the slower one overwriting the recorded outcome; a route that cannot tolerate it uses `completeWithin()` |
| C29-N1, C29-N2, C28-N1, C28-N3, C28-N4 | Unchanged, open |
| D-3 (LVA-1/TRR-2), person-level Identity MEDIUM | Unchanged, open, blocking under C27-C |
| Seven P3 (HRC-4, HRC-8, LVA-4, XRP-3, XRP-4, TRR-6, TRR-8) | Unchanged |
| R1-02 | PARTIAL, unchanged |
| Legal, DPO, Security gates; accessibility carry-forwards (C18-A12, WO-5, C20-N1) | Unchanged. C30 changes no screen |

## 8. Final-state recheck and publication evidence

**Final-state recheck of L1–L3 (independent, read-only): no HIGH or MEDIUM.**
- **L1, object-keyed tracking:** correct. A reused object id after garbage collection can only over-report, never produce a false "nothing committed".
- **L2:** no fail-open regex path remains.
- **L3, the barrier:** deterministic. Only the claimer reaches the trigger, so the first request to return is always the 409.
- **Its LOWs:**
  - the barrier deadline is now 25 s, under the 30 s in-flight timeout;
  - `pkill`'s exit code is unchecked, which is harmless.
- **Existing behaviour, unchanged:** a handler that leaves its own transaction open can lose `complete()`, as `release()` could before.

Publication evidence is recorded in the evidence commit.
