# School OS — Reliability Patterns

This document is the operational home for cross-cutting reliability
patterns that every future business module reuses rather than
reinventing. It covers **API idempotency** (Phase 0C.2, below).
**Webhook delivery reliability** (retry policy, delivery/attempt state
machines, at-least-once semantics) is Phase 0C.3's equivalent concern
and is documented in `docs/architecture/INTEGRATIONS.md` rather than
here — it is a large enough, sufficiently distinct topic (a different
failure/retry model entirely, since it governs *outbound HTTP to a
third party* rather than *replaying a client's own retried API
request*) to warrant its own document, cross-referenced from both
sides rather than merged. Future Phase 0C checkpoints (health/
readiness, queue/scheduler staleness detection, rate limiting) will
extend one of these two documents rather than create further parallel
ones — see `docs/roadmap/MASTER-ROADMAP.md`'s Phase 0C entry for what
has and hasn't landed yet.

## API idempotency

### Why

A mobile client, browser, reverse proxy, or automation retry can cause
the *same logical request* to reach the server more than once —
network timeout, dropped connection, duplicate tap, retried payment
callback. Without a server-side guarantee, a naive retry of a
state-changing endpoint repeats its side effect. The goal: **a client
can safely retry a consequential School mutation after a network
failure without accidentally performing the action twice, while
cross-tenant isolation, authorization, auditability, and PostgreSQL
concurrency guarantees remain intact.**

### What it is NOT

- Not applied globally. Only routes that explicitly opt in via the
  `idempotent` middleware alias get this behavior — GET/HEAD/OPTIONS
  and ordinary reads never need it, and not every POST should reach
  for it reflexively (`App\Http\Middleware\EnsureIdempotent`).
- Not a substitute for payment-provider or webhook-delivery
  idempotency — see "Payment and webhook readiness" below.
- Not exactly-once execution. See "Crash window" below for the exact,
  proven boundary.

### Mechanism

```
Request with Idempotency-Key
  → authenticate (auth:sanctum)
  → establish trusted School/actor context (school-membership middleware)
  → authorize the endpoint (capability middleware)
  → idempotent middleware:
      validate key → compute fingerprint → atomically claim
      claimed  → run the controller → mark completed (or self-participating completeWithin)
      replay   → return the stored result, unchanged, without re-running
      conflict → 409 IDEMPOTENCY_KEY_CONFLICT
      in-progress → 409 IDEMPOTENCY_REQUEST_IN_PROGRESS
```

Order matters: authentication and authorization run **before** the
idempotency guard is ever consulted (section 12/19) — a claim/replay
never bypasses current access control, and a previously-successful
response is never handed to an actor who has since lost the
capability that produced it (see "Authorization and replay" below).

### Storage: `api_idempotency_keys`

Tenant-owned, RLS-protected (`App\Support\Tenancy\TenantRls`, enabled
and forced) — the same isolation guarantee as every other School-owned
table (`docs/architecture/TENANCY.md`). An idempotency claim is
created and consumed entirely within one already-School-scoped
request; there is no cross-School background process that needs to
see every School's keys at once (unlike the domain-event outbox, which
a central dispatcher must scan across every School).

| Column | Notes |
|---|---|
| `id` | UUIDv7 primary key. |
| `school_id` | FK, cascade delete. RLS scope column. |
| `actor_type` / `actor_id` | `'user'` today; `'service_identity'` reserved for a future service-identity-authenticated route. Not a single FK — the two id spaces are not guaranteed disjoint. |
| `route_action` | The Laravel route **name**, not the raw path — stable across route-parameter values. |
| `idempotency_key` | Opaque client value (section 26). |
| `request_fingerprint` | SHA-256 hex digest of the canonicalized logical request (see below). |
| `request_method` | HTTP method, for diagnostics. |
| `status` | `processing` / `completed` / `failed` / `expired` — CHECK-constrained. See "Record states." |
| `response_status` / `response_headers` / `response_body` | Captured for replay. `response_headers` is an explicit allowlist (`Content-Type` only) — never the full header set (no `Date`, `Set-Cookie`, request/trace IDs, auth tokens). |
| `completed_at` / `expires_at` | Lifecycle timestamps. |

**Uniqueness scope** (`api_idempotency_keys_scope_unique`):
`(school_id, actor_type, actor_id, route_action, idempotency_key)`.
Not `idempotency_key` alone — School A/Parent X and School B/Parent Y
reusing the identical literal key never collide, and the same actor
reusing a key against a *different* endpoint is a different claim, not
a collision. This constraint is enforced by PostgreSQL, not an
application-level existence check — see "Concurrency model."

### Request fingerprint

`App\Support\Idempotency\RequestFingerprint` hashes: HTTP method,
canonical route name, normalized route parameters, canonicalized JSON
body, School id, and actor. Deliberately **excluded**: User-Agent,
request/correlation/trace IDs, the Authorization token, timestamps —
transport noise that legitimately differs between two retries of the
same logical request.

Canonicalization is JSON-object-key sorting only (recursive `ksort`):
logically-equivalent payloads with different key ordering fingerprint
identically; array/list order is preserved because it is semantically
meaningful. **Known limitation, out of scope for this checkpoint:**
multipart uploads, streaming bodies, and very large payloads are not
specially handled — a future module accepting uploads under an
idempotency key should fingerprint upload *metadata* (filename, size,
checksum), not raw bytes, and needs its own review at that time.

### Concurrency model

The hard requirement: two near-simultaneous identical requests must
produce exactly one underlying side effect. This is guaranteed by
PostgreSQL alone, **never** by an application-level
"check-then-insert" (which leaves a race window) or by Redis
`SETNX`/in-memory locks (which cannot be authoritative across
processes):

1. `IdempotencyGuard::claim()` attempts an `INSERT`. The unique
   constraint above means at most one concurrent `INSERT` for the same
   scope/key can succeed.
2. The loser catches `UniqueConstraintViolationException` and re-reads
   the winner's row.
3. If the row is `processing` and younger than
   `idempotency.in_flight_timeout_seconds` (default 30s): refused as
   `IDEMPOTENCY_REQUEST_IN_PROGRESS` — never an unbounded wait inside
   an HTTP worker.
4. If `processing` and **older** than the timeout (presumed abandoned
   — crashed worker, dropped connection): reclaimed via a conditional
   `UPDATE ... WHERE status = 'processing' AND created_at <= ?`.
   PostgreSQL's row-level locking makes this a safe compare-and-swap —
   a second concurrent reclaim attempt blocks on the row lock, then
   re-evaluates its `WHERE` clause against the now-changed row and
   affects zero rows.

Proven against real PostgreSQL two ways: `IdempotencyGuardTest`
(direct claim-sequence assertions) and, as the **mandatory real
concurrency proof**, `IdempotencyRealConcurrencyTest` — two genuinely
separate `curl` OS processes, launched non-blocking against a real
`php -S` server subprocess, both carrying the identical
Idempotency-Key/body/actor/School. Not a sequential simulation.

### Record states

`processing` → `completed` | `failed`. `expired` exists in the CHECK
constraint for schema completeness, but is never eagerly written at
runtime — a terminal row whose `expires_at` has passed is simply
treated as if it does not exist (deleted on next lookup, freeing the
scope/key for a fresh claim). A `processing` row is **never** pruned
or treated as expired by `expires_at` — only the shorter, separate
in-flight timeout governs its lifecycle (see above), so a
still-genuinely-running request is never yanked out from under itself.

### Replay behavior (same key, same request)

`IdempotencyOutcome::Replay` returns the stored `response_status` /
`response_body` unchanged, with `Idempotency-Replayed: true` added.
The controller action is **not** re-invoked — proven by asserting the
underlying side effect (the demonstration counter, and any business
audit event) does not increment/duplicate on replay.

Request-scoped identifiers are handled deliberately (section 20): the
`X-Request-Id` **response header** is always refreshed to the current
transport request's id (headers are transport-level and cheap to
manage generically). For a JSON body following this codebase's
`data`/`meta.requestId` convention (every existing endpoint), the
middleware also refreshes `meta.requestId` and adds
`meta.originalRequestId` for operational linkage back to the original
execution. A response body without that shape is replayed
byte-for-byte, unchanged — the middleware never attempts to rewrite
arbitrary application-defined JSON.

### Conflict behavior (same key, different request)

`IdempotencyOutcome::Conflict` → `409 IDEMPOTENCY_KEY_CONFLICT`,
standard error envelope. The differing request is **never** executed
and never overwrites the original stored result.

### Authorization and replay

Order: **authentication → tenant/membership validation → authorization
→ idempotency**. Because `capability:*` middleware runs before
`idempotent` on every route that uses this pattern, an actor who has
since been disabled, had their membership suspended, or lost the
required capability is rejected **before** the guard is ever
consulted — they cannot fetch a previously-stored successful response
merely by knowing an old key. Proven in
`IdempotencyDemoEndpointTest::an_actor_who_loses_authorization_cannot_obtain_a_replayed_response`.

### Crash window — exactly what is and is not guaranteed

Two separate guarantees exist, and they are **not** the same strength:

- **Default path** (`EnsureIdempotent`'s own post-`$next()` completion):
  `claim()` and `complete()`/`failDeterministically()` are two separate
  statements. If a worker crashes after the business action commits
  but before `complete()` runs, the record is left `processing` until
  the in-flight timeout elapses — at which point a retry **reclaims
  the key as a fresh execution**, because the guard alone cannot know
  whether the earlier attempt's business action actually ran. This is
  a real, accepted limitation, proven (not just asserted) in
  `IdempotencyCrashWindowTest::the_default_path_can_rerun_a_business_action_whose_worker_crashed_before_marking_completion`.
  The correct term for what the default path provides is
  **"effectively-once side effects under the documented in-flight
  timeout window," not exactly-once.**
- **Self-participating path** (`IdempotencyGuard::completeWithin()`,
  called inside the controller's own `DB::transaction()` alongside its
  authoritative state change — see
  `App\Http\Controllers\Api\V1\Internal\IdempotencyDemoController`):
  the business write and the completion write commit or roll back
  **together**, atomically. There is no observable state where one
  happened and the other didn't. Proven in
  `IdempotencyCrashWindowTest::a_self_participating_transaction_prevents_a_split_between_the_business_action_and_completion`.

**Guidance for future endpoints:** if a consequential mutation cannot
tolerate the default path's gap (financial transactions, anything
whose duplicate execution would be expensive or dangerous to unwind),
its controller/Application-layer code MUST call `completeWithin()`
inside the same transaction as its own state change, exactly like the
demonstration endpoint does. The generic middleware cannot guarantee
this for arbitrary future controller logic on its own.

### Failure classification

- **Deterministic** (`ValidationException`, `AuthorizationException`
  thrown from inside the wrapped action): recorded via
  `failDeterministically()` and **replayed verbatim** on retry —
  re-running the same logical request would deterministically reject
  it again anyway, so replaying the stored rejection is both safe and
  avoids repeating wasted work.
- **Unclassified** (anything else — worker crash, DB disconnection,
  dependency timeout, an unexpected exception): the claim is
  **released** (the row is deleted) via `IdempotencyGuard::release()`.
  A retry gets a genuinely fresh claim attempt. The key is never
  permanently poisoned by an infrastructure blip.

### Expiration and pruning

`expires_at` defaults to `idempotency.default_ttl_hours` (48h,
configurable per-environment via `IDEMPOTENCY_DEFAULT_TTL_HOURS`).
A single default is deliberately not assumed sufficient forever — a
future payment-specific module may need a longer, module-specific
retention window (see "Payment and webhook readiness").

`php artisan platform:idempotency-prune` deletes expired,
non-`processing` records, one School and one bounded batch
(`idempotency.prune_batch_size`, default 500) at a time, through the
ordinary RLS-protected runtime connection
(`TenantContext::withSchool()`) — never a single cross-tenant `DELETE`,
never the migration-only `pgsql_admin` connection. Originally
unscheduled (deliberately, per Phase 0C.2's brief); **scheduled daily
since the Phase 0C closeout** (`routes/console.php`, `idempotency-prune`,
`withoutOverlapping()`), alongside the webhook delivery-history prune
(`docs/architecture/PHASE-0C-CLOSEOUT.md`).

### Rate limiting interaction

Rate limiting landed in Phase 0C.4 (`App\Providers\RateLimiterServiceProvider`,
`docs/architecture/OBSERVABILITY.md`). Every route's middleware ARRAY
is still declared `capability:` then `throttle:*` then `idempotent`
(section 71's prescribed order), and that declared order IS what
determines relative ordering between `capability:` and `idempotent`
themselves. It is **not**, however, `throttle:*`'s actual position at
runtime: `Illuminate\Routing\Middleware\ThrottleRequests` is one of
Laravel's framework-prioritized middleware
(`$middlewarePriority`), so Laravel's middleware sorter runs it
BEFORE any of this project's own custom, non-prioritized middleware —
`school-membership`, `capability`, `idempotent` — regardless of the
order declared in routes/api.php. `throttle:*` genuinely executes
before `capability:` has run.

Two consequences follow, both handled deliberately rather than
accidentally:

- Every tenant-aware limiter's key (`App\Providers\RateLimiterServiceProvider::tenantKey()`)
  reads the School id directly off the **route parameter**, never off
  `App\Support\Tenancy\TenantContext` — TenantContext's School is not
  yet populated at throttle-evaluation time. This was a real bug this
  checkpoint's own test suite caught (a School-scoped limiter silently
  collapsing to actor-only keying) before being fixed; see
  `Tests\Feature\RateLimiting\SchoolApiMutationsThrottleTest` and
  `Tests\Unit\RateLimiterKeyingTest`.
- An authenticated caller who ultimately fails the `capability:` check
  still consumes one unit of their OWN quota bucket first (quota is
  consumed before authorization is evaluated for that specific
  request). This is a narrower guarantee than "an unauthorized caller
  never consumes quota" would ideally provide, but the exposure is
  bounded: `Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests`
  (`auth:sanctum`) IS framework-prioritized ahead of `ThrottleRequests`,
  so a genuinely unauthenticated caller (no/invalid token) is still
  rejected before ever reaching the limiter — only an authenticated
  actor's own capability-denied attempts spend their own quota, never
  someone else's.

Quota itself is keyed by (School, actor) or service identity, never by
the `Idempotency-Key` — a client legitimately retrying the same
logical mutation with the SAME key still consumes one unit of quota
per HTTP attempt (a replay is not free), but changing the key on every
retry is not a way to get a larger effective quota. Authentication
throttles (`throttle:login`) are independent of and never weakened by
this interaction.

### Payment and webhook readiness

Documented distinctions for future modules, not implemented here:

- **Client API idempotency** (this document) proves "the client's
  request was not duplicated." It does **not** replace
  **payment-provider webhook idempotency** or **provider
  transaction/reference uniqueness** — a payment callback needs its
  own idempotent-handling contract keyed on the provider's own
  delivery/transaction identifiers, independent of whether the
  original client request also carried an `Idempotency-Key`. A future
  Fees/Payments module needs both.
- **Webhook delivery idempotency** (Phase 0C.3, `webhook_deliveries`'
  `(webhook_endpoint_id, event_id)` uniqueness — full design in
  `docs/architecture/INTEGRATIONS.md`) is a **separate** mechanism from
  this document's `Idempotency-Key` contract. Do not conflate the two,
  and do not reuse this checkpoint's `idempotent` middleware for
  webhook delivery — the two systems solve different problems (a
  client's own retried API request vs. School OS's own outbound HTTP
  to a third party) and are not interchangeable.

### Mobile client guidance

```
Generate ONE idempotency key per ONE logical mutation.
If the request times out or the connection drops, retry with the SAME key.
For a genuinely NEW logical action, generate a NEW key.
Do not reuse a key across different logical actions, even against the same endpoint.
```

Keys are opaque client values — 8-255 characters,
`[A-Za-z0-9._:-]` only (`App\Support\Idempotency\IdempotencyKeyValidator`).
Not required to be a UUID, though a UUID satisfies the constraint.

### Observability

Every claim outcome (`new` / `replay` / `conflict` / `in_progress`)
produces one structured log line (`idempotency.outcome`) carrying
School id, actor id, route/action, request id, and correlation id —
never the request/response body, never the raw idempotency key.
`App\Support\Idempotency\IdempotencyMetrics` emits a vendor-neutral
`idempotency_<outcome>_total` structured metric line per outcome; a
future metrics exporter can consume these without any call-site
change. This checkpoint does not create a general observability/
tracing foundation (tracked separately in Phase 0C's core substrate) —
these structured logs are what that future foundation will consume.

### Job timeout invariants (Phase 0C.4)

Every queued job declares `$tries` and `$timeout` explicitly — never
Laravel's implicit defaults — and `$timeout` is always kept well below
every queue connection's `retry_after` (90 seconds; `config/queue.php`,
all three drivers). This is a correctness invariant, not a style
preference: `retry_after` is when the queue driver assumes a worker
died and makes the job available to a SECOND worker. If a job's own
`$timeout` were allowed to reach or exceed `retry_after`, a slow-but-
still-running first attempt could be picked up and executed again by a
second worker concurrently — silently multiplying side effects for any
job that is not purely idempotent by construction.

Current declared values: `DeliverWebhookJob` (`tries=1`, `timeout=30`
— retries are modeled at the delivery-row level via
`RedispatchDueWebhookDeliveries`, not via Laravel's job retry, so a
second Laravel-level try would double-send; see that job's docblock),
`ProcessOutboxEventJob` (`tries=5`, `timeout=30`, explicit `$backoff`),
`RecordSchoolAuditPingJob` (`tries=3`, `timeout=15`). All satisfy
`timeout < retry_after` with a wide margin.

A second, independent invariant: **domain-level retry and queue-level
retry must not multiply**. `DeliverWebhookJob` is the canonical
example — it always completes after exactly one HTTP attempt
(`tries=1`) and expresses its OWN retry schedule via
`webhook_deliveries.next_attempt_at` / `RedispatchDueWebhookDeliveries`
(`docs/architecture/adr/0026-webhook-delivery-semantics.md`). If it
also carried Laravel-level `tries > 1`, a transient failure would be
retried by BOTH mechanisms independently, multiplying attempts and
invalidating the documented backoff/jitter schedule. Any future job
that owns its own domain-level retry state must follow this same
`tries=1` pattern.

### School lifecycle at execution time (Phase 0N.9, ADR 0047)

A School business job re-checks the School when it RUNS, never trusting
the state at dispatch: `App\Support\Tenancy\SchoolOperationalGuard::holdOperational()`
reads the School row FOR SHARE inside the job's own claim transaction
(the lifecycle services lock it FOR UPDATE), so a suspension and a claim
serialize. Outcomes per substrate: webhook and communication deliveries
are deferred without consuming an attempt (`retrying` / `queued`), their
redispatchers skip non-active Schools (no loop), automation executions
become terminal `skipped`, scheduled announcements stay `scheduled`.
`SetTenantContextForJob` deliberately has no blanket check -- platform
safety work (audit, elevation expiry, pruning) keeps running for a
suspended School. `SchoolLifecycleArchitectureGuardTest` fails on a new
queued job or School-walking command that has not decided this.

### Security boundary honesty

Stored `response_body`/`response_headers` are ordinary PostgreSQL
column data — RLS prevents cross-School reads (proven at the raw-SQL
level, bypassing Eloquent, in `ApiIdempotencyKeysIsolationTest`), but
there is **no column-level/application-level encryption-at-rest**
beyond whatever PostgreSQL-level disk encryption the deployment
environment provides. A future endpoint returning Sensitive/Highly
Sensitive data (`docs/security/DATA-CLASSIFICATION.md`) under this
middleware should be reviewed for whether storing its full response
body for the retention window is acceptable, or whether it should
store a narrower reference instead — this checkpoint's demonstration
endpoint deliberately returns nothing sensitive (a counter value).
