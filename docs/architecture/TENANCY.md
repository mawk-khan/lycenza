# School OS — Multi-Tenancy Architecture

For the decision record and alternatives, see ADR 0004, ADR 0020
(terminology), ADR 0021 (database roles), ADR 0022 (context
propagation), ADR 0024 (real-Postgres test infrastructure). This
document is the operational reference for implementing tenant-aware
code, updated in Phase 0B to describe what is **actually implemented**,
not only the target design.

## Tenant model

- **School** = the tenant/isolation boundary. Every tenant-scoped table
  carries a `school_id` (the School's id) -- see ADR 0020 for why the
  column is literally named `school_id`, not `tenant_id`.
- **Campus** = a sub-tenant dimension. A `campus_id` column exists on
  campus-scoped tables, but Campus is **not** a separate isolation
  boundary — staff scoped to a School may legitimately need
  cross-campus visibility within that one School.
- **School Group / Trust** = a grouping entity *above* the tenant
  boundary. A group admin's cross-school access is an explicit,
  granted, audited elevation (see `docs/security/AUTHORIZATION.md`),
  never a default consequence of a school belonging to a group.
- **Platform Super Admin** = operates outside normal tenant scoping
  through a dedicated, audited administrative path — not a flag that
  application code can casually flip.

## Isolation layers (defense in depth)

No single layer is trusted alone. All three must independently prevent
cross-tenant access:

### 1. Application layer — implemented

Every tenant-scoped Eloquent model uses the `App\Support\Tenancy\
BelongsToSchool` trait, which applies the `SchoolScope` global scope
(`App\Support\Tenancy\SchoolScope`). The scope resolves the current
School from `App\Support\Tenancy\TenantContext` (ADR 0022) — never from
a static/global. With no School context set, the scope adds
`whereRaw('1 = 0')`, so the query returns zero rows rather than every
tenant's rows (fail-closed, proven in
`tests/Feature/Tenancy/SchoolScopeTest.php`).

To intentionally cross Schools, call
`Model::withoutGlobalScope(SchoolScope::class)` explicitly — there is
no other unscoped path. `SchoolScopeTest` also proves that removing
this scope alone still cannot leak another School's row, because Layer
2 (RLS) independently blocks it on the same connection.

### 2. Database layer — PostgreSQL Row-Level Security — implemented

Every tenant-scoped table has RLS **enabled and forced**
(`App\Support\Tenancy\TenantRls::enable()`, called from that table's
migration), with a policy keyed on the session-local Postgres setting
`app.current_school_id`:

```sql
USING (school_id = NULLIF(current_setting('app.current_school_id', true), '')::uuid)
```

`TenantContext::set()` assigns this via `set_config('app.current_school_id',
?, false)` (session-level, not `SET LOCAL` — see ADR 0022 for why);
`TenantContext::clear()`/`clearAll()` issue `RESET app.current_school_id`.
Missing/empty context makes the cast operand `NULL`, so the policy
matches nothing — fail-closed, identical in spirit to Layer 1, and
proven independently against real Postgres in
`tests/Feature/Postgres/RawIsolationTest.php` (raw SQL, bypassing
Eloquent entirely) and `tests/Feature/Tenancy/SmokeTest.php`.
See "TenantContext cleanup and aborted-transaction safety" below for
what happens to this GUC when the connection's current transaction is
aborted, not merely committed/rolled back normally.

Critically, this is only meaningful because the runtime connection
(`pgsql`, using the `school_os_app` role) is **not** a superuser and
does **not** have `BYPASSRLS` — see ADR 0021. `RawIsolationTest`
asserts this directly against `pg_roles`, not just by convention.

### 3. Infrastructure layer

- **Tenant-aware queues — implemented.** The `App\Support\Tenancy\
  TenantScoped` job trait captures `school_id`/`campus_id`/`actor_id`/
  `request_id` into the job's own payload at dispatch time; its
  `middleware()` returns `SetTenantContextForJob`, which sets
  `TenantContext` before `handle()` and clears it after — proven safe
  for sequential jobs targeting different Schools in
  `tests/Feature/Tenancy/QueueContextPropagationTest.php`
  (`RecordSchoolAuditPingJob` is the Phase 0B proof job).
  `try`/`catch` (not a bare `finally`) since Phase 1B.4A — see
  "TenantContext cleanup and aborted-transaction safety" below.
- **Tenant-aware caches — implemented.** `App\Support\Tenancy\TenantCache`
  namespaces every key `school:{id}:{key}` (vs. `TenantCache::platformKey()`'s
  `platform:{key}`) and throws `TenantContextRequiredException` if
  called with no School context — see `TenantCacheTest`.
  `CapabilityResolver`'s own permission cache uses this same
  discipline: cache keys always include both user id and School id, so
  permissions for one School are never served from another's entry.
- **Tenant-aware files — primitive implemented, no Documents module
  yet.** `App\Support\Tenancy\TenantStoragePath::for($school, $path)`
  builds `schools/{school_id}/...` and rejects traversal/absolute/
  malformed fragments (`tests/Unit/Tenancy/TenantStoragePathTest.php`).
  ADR 0012's Documents module itself remains unbuilt — out of scope per
  this checkpoint's brief.
- **Tenant-aware logs — implemented.** `TenantContext::set()`/`setActor()`/
  `setRequestId()` push `school_id`, `campus_id`, `actor_id`, and
  `request_id` into Laravel's `Context` facade, which every log entry
  picks up automatically; `clear()`/`clearAll()` remove them.
- **Tenant-aware AI requests — implemented.** Every Laravel → AI
  Gateway call carries `school_id` explicitly (renamed from Phase 0A's
  `tenant_id` per ADR 0020); the outbound half additionally binds
  School + actor + capability into a signed context token before any
  network call (ADR 0023, `docs/ai/AI-SECURITY.md`) — a stronger
  guarantee than "carries school_id in the payload" alone, since the AI
  Gateway cannot alter or widen what that token grants.

## What "no implicit cross-tenant access" means in practice

- A missing `school_id` filter in a raw/manual query is a bug caught by
  RLS (Layer 2), not a silent data leak — but it should still never
  ship; RLS is the backstop, not the intended primary control.
- A background job that forgets to set tenant context fails loudly:
  `TenantContext::requireSchool()` throws `TenantContextRequiredException`
  rather than silently no-op-ing or querying the wrong School.
- Cross-tenant reporting (Layer 5 in `docs/architecture/DOMAIN-MAP.md`)
  is possible because it's one shared database, but every such code
  path is explicit and treated as privileged — never the default query
  behavior any module reaches for. No such reporting path is
  implemented yet. **Confirmed unchanged by ADR 0040 (Phase 0L.1,
  Analytics Domain Contract):** Analytics v1 is single-School/
  tenant-local only, using the ordinary isolation layers above with no
  new bypass of any kind; cross-School/platform-wide Analytics remains
  explicitly deferred to its own future, separately-reviewed
  architecture checkpoint. **ADR 0048 (built in Phase 0N.11):**
  the one authorized cross-School read is Group reporting of
  `curriculum.coverage` — not a cross-tenant query but a bounded sequence
  of ordinary single-School reads: for each active member School, one
  `TenantContext::withSchool()` (SchoolScope + forced RLS as always),
  cleared before the next School; never two Schools in one context, no
  RLS change, no `BYPASSRLS`, no multi-School session or SQL.

## TenantContext cleanup and aborted-transaction safety (Phase 1B.4A)

### GUC lifecycle

`app.current_school_id` is a PostgreSQL **session-level** GUC
(`set_config(name, value, is_local = false)`, never `SET LOCAL`/
`is_local = true`) — it persists past `COMMIT`, which is exactly why
`TenantContext::clear()`/`clearAll()` must explicitly `RESET` it at the
end of every unit of work (request, queue job, test) rather than
relying on transaction boundaries alone. "Session-level" does **not**
mean "immune to `ROLLBACK`", though: proven directly (`BEGIN;
SELECT set_config('app.current_school_id', 'x', false); <force an
error>; RESET app.current_school_id; -- fails, SQLSTATE 25P02 --
ROLLBACK; SELECT current_setting('app.current_school_id', true); --
back to whatever it was before BEGIN`), a session-level `set_config`
call made **inside** a transaction is still reverted automatically the
moment that transaction is rolled back — it only *survives* a `COMMIT`.
This is the fact the aborted-transaction defect below hinges on.

### Root cause: aborted-transaction cleanup

If the connection's current transaction is in PostgreSQL's **aborted**
state (SQLSTATE `25P02`, "current transaction is aborted, commands
ignored until end of transaction block" — the state PostgreSQL enters
after ANY failed statement inside a transaction, until a `ROLLBACK`),
**no statement other than `ROLLBACK`/`COMMIT`/`ROLLBACK TO SAVEPOINT`
can execute** — including a plain `RESET app.current_school_id`. Before
Phase 1B.4A, `TenantContext::withSchool()`'s `finally` block issued
that `RESET` unconditionally; if the wrapped callback's own database
write had just aborted the transaction (e.g. a duplicate-key insert
attempted outside its own `DB::transaction()`), the `RESET` itself
failed with `SQLSTATE 25P02` — and because this happened in a `finally`
block, PHP's exception semantics meant this **new, unrelated** failure
*replaced* the real original exception (e.g.
`UniqueConstraintViolationException`) before it ever reached the
caller. Two compounding effects made this "test-order flakiness"
rather than a one-test failure:

1. Every sanctioned Application service in this codebase (`StudentEnrollmentService`,
   `StudentGuardianRelationshipService`, `AcademicYearService`, ...)
   already wraps its own mutation in `DB::transaction()`, so by the
   time `withSchool()`'s cleanup ran, that inner transaction had
   already rolled back (to a savepoint) and the GUC had already
   reverted automatically — masking the defect everywhere those
   services are the only caller. It only reproduced through a raw,
   unwrapped write (a raw-SQL Postgres integrity test, or a bug in
   future code) — see
   `tests/Feature/Tenancy/TenantContextAbortedTransactionTest.php`'s
   "unwrapped" test group for a deterministic repro.
2. `Tests\TestCase::tearDown()` called `TenantContext::clearAll()`
   *unconditionally* on every test, regardless of pass/fail. If a test
   left the shared connection aborted (per (1)), `clearAll()`'s own
   `RESET` failed — and since that exception aborted `tearDown()`
   itself, `DatabaseTransactions`' own `ROLLBACK` (registered as a
   `beforeApplicationDestroyed` callback, run inside `parent::tearDown()`,
   which this ordering bug prevented from ever executing) never ran —
   leaving the **same poisoned connection** for whichever *unrelated*
   test PHPUnit happened to run next. That test's very first database
   statement would then fail with the same masking symptom, making the
   failure appear to belong to a random, unrelated test class
   depending on execution order — exactly the "test-order / suite-order
   flakiness" first observed in the Phase 1B.4 checkpoint report.

### Fix architecture

- **`TenantContext::withSchool()`** now uses `try`/`catch` instead of
  `try`/`finally`, with two distinct restoration paths:
  - **Exception path** (`restoreAfterFailure()`): PHP-side state
    (`$school`/`$campus`/`Context` entries) is restored
    *unconditionally, first* — a database failure must never leave PHP
    still believing the old context is active. The database-side GUC
    restore is then attempted; a `SQLSTATE 25P02` failure from *that
    specific attempt* is discarded (proven safe per "GUC lifecycle"
    above — the callback's own failure already aborted the transaction
    that will revert the GUC automatically once rolled back by whoever
    owns it), and the **original exception continues propagating
    unmodified**. Any other database error from the restore is never
    swallowed — a genuine double-failure is never hidden.
  - **Success path** (`restoreOnSuccess()`): unchanged behaviour for a
    healthy connection. If the restore itself fails with `SQLSTATE
    25P02` here — meaning the callback returned *normally* but somehow
    left the connection poisoned (it caught and swallowed its own
    database exception without rolling back or rethrowing) —
    `TenantContext` never silently pretends the restore succeeded; it
    raises `App\Support\Tenancy\TenantContextPoisonedConnectionException`
    (wrapping the original `QueryException`) so the bug in the
    swallowing code is impossible to miss.
- **`TenantContext::clearAllTolerantly()`** (new) — for a caller that
  cannot know whether the preceding unit of work succeeded or failed
  (`Tests\TestCase::tearDown()`, which runs unconditionally and,
  additionally, must run *before* `parent::tearDown()` since Laravel
  flushes/nulls the application container as part of its own
  teardown — there is no way to run cleanup after it). Always clears
  PHP-side state; a `SQLSTATE 25P02` GUC-reset failure is logged
  (`tenancy.context.reset_skipped_aborted_transaction`, never silent)
  and skipped rather than thrown, so `Tests\TestCase::tearDown()`
  always completes and `DatabaseTransactions`' own rollback (which
  reverts the GUC automatically) always gets to run.
- **`TenantContext::clearAllAfterFailure()`** (new) — `clearAll()`'s
  counterpart for a caller with a `Throwable` already propagating and
  no "previous context" to restore to (full reset, not nested
  restoration). Used by `SetTenantContextForJob`.
- **`SetTenantContextForJob`** now uses the same `try`/`catch` shape as
  `withSchool()` (`clearAllAfterFailure()` on the job's own exception,
  plain `clearAll()` on success) instead of a bare `finally`, for the
  identical reason.

### Transaction ownership (unchanged)

`TenantContext` **never** issues its own `ROLLBACK` or `COMMIT` — it
does not own the callback's transaction, and does not know whether
some other code higher up the call stack still needs that transaction
open. Every fix above only decides whether to *attempt* a GUC
statement and how to react if that attempt fails with the one,
specifically-proven-safe SQLSTATE — it never changes what the calling
code's own transaction does.

### Residual scope

The same architectural risk (a bare `finally { $context->clearAll(); }`
around a unit of work that might leave the connection aborted) exists
in several other call sites not touched by Phase 1B.4A —
`App\Http\Middleware\ResolveSchoolContext`,
`App\Http\Middleware\Api\EnsureSchoolMembershipContext`,
`App\Http\Controllers\Api\Internal\AiToolController`/`AiAuditController`,
`App\Jobs\ProcessOutboxEventJob`, `App\Jobs\DeliverWebhookJob`. This
checkpoint deliberately fixed only the two call sites directly
implicated by the observed defect (`withSchool()`, used by every
sanctioned Application service, and `SetTenantContextForJob`, the one
production site `TenantContext`'s own docblock already named as
load-bearing) plus the test-suite ordering bug that explained the
empirically observed flakiness, rather than rewriting every
`finally`-block call site speculatively. A dedicated follow-up sweep
applying the same `try`/`catch` pattern to the remaining sites is
recommended before relying on this guarantee universally in
production.

## School-scoped web routes (Phase 0N.1)

Every signed-in web route that reads or writes one School's data lives in
`routes/web.php`'s School group, `Route::middleware(['auth',
'school-context'])`. `school-context` (`App\Http\Middleware\RequireSchoolContext`)
is the single School-context prerequisite for the web surface:

- It consumes the School `ResolveSchoolContext` established (verified
  domain, or the session selection re-validated against an active
  membership and an active School) and additionally requires an active
  membership in that School and an account that is not disabled. It
  never selects a School and never falls back to another membership.
- Without a valid School the controller never runs: a GET/HEAD page
  request returns to the `/app` landing ("Select a School to continue");
  a JSON request or any mutation gets `409` with code
  `school_context_required` (Inertia mutations: `409` +
  `X-Inertia-Location: /app`). A stale session School is removed and the
  Inertia history key rotated.
- It is priority-pinned after the School resolvers and `auth`, and
  before `SubstituteBindings` — School-scoped route model binding never
  runs without a validated School — and before `capability:`/`mfa`.
- A controller in the School group may rely on
  `TenantContext::requireSchool()`. `TenantContextRequiredException`
  remains the fail-closed invariant for jobs, commands and services; on a
  School-group route it indicates a bug.
- The context-neutral signed-in routes (`/app`, School activation,
  account security, logout, platform-scoped actions) are an explicit
  allowlist in `Tests\Feature\Tenancy\SchoolContextRouteGuardTest`,
  which fails for any new signed-in web route that is in neither.
- `/api/v1` is untouched: the School is in the URL and
  `school-membership` (`EnsureSchoolMembershipContext`) verifies it.

See `docs/architecture/PHASE-0N-READINESS.md` section 11 ("Resolution").

## Platform elevation (ADR 0044 — substrate implemented in Phase 0N.3)

ADR 0044 fixes how a platform actor temporarily establishes one
School's context; Phase 0N.3 built the substrate with **no School route
accepting it**. For tenancy it means:

- Elevation uses the existing `TenantContext::set()` for exactly one
  School — same GUC, same `SchoolScope`, same forced RLS. No second
  context system, no multi-School context, no `BYPASSRLS`.
- It is a third trusted source of **web** School context (CLAUDE.md rule
  20): the session pointer `platform_elevation_id`, re-validated by
  `App\Http\Middleware\ResolvePlatformElevation` on every request
  (active, unexpired, same actor, actor not disabled, still holding
  `platform.schools.elevate`, School active, actor not a member, MFA
  factor still active, no `active_school_id` beside it). A valid one sets
  the request-scoped `App\Support\Tenancy\ElevationContext` only; any
  School a domain or header resolved is discarded, and a different one
  blocks School context for that request.
- The record, `school_elevations`, is platform-owned and read before any
  School context exists, so it has no RLS — a deliberate exception like
  `school_memberships`; no School route or School capability reads it.
  It is database-guarded: one active row per actor, a 30-minute CHECK,
  finished rows immutable, `DELETE` revoked from the runtime role.
- `RequireSchoolContext` admits an elevated context only on a route
  declaring `school-context:elevated` — none does (CLAUDE.md rule 83) —
  and only then puts the target School into `TenantContext`; every other
  School route refuses it (403) before binding and the controller.
- Never on `/api/v1` (`school-membership` stays membership-only),
  internal APIs or AI context tokens; never derived from a verified
  domain or `X-School-Id`.

## School Groups (ADR 0045 — foundation implemented in Phase 0N.5)

A School Group is **never** tenant context. `TenantContext` stays one
School; no Group request sets `app.current_school_id` or reads a tenant
table. Group scope (ADR 0045) is a separate authorization scope over the
platform tables `school_groups` / `school_group_members` /
`group_role_assignments` (no RLS: platform-owned, like
`school_memberships`), bound to a `{schoolGroup}` route parameter with no
ambient "current Group". Groups are archived, never deleted (the runtime
role cannot `DELETE` one, and membership no longer cascades from it). A Group administrator reaches one member
School only through ADR 0044 elevation, which records the authorizing
Group and grant and still grants no School capability; removing the
School from the Group, revoking the grant or archiving the Group ends it
immediately. A School may belong to several Groups; authority is never
pooled across them.

## School lifecycle and tenant operation (ADR 0047 — built in Phase 0N.9)

A tenant is operational only while `schools.status = 'active'`
(`School::isActive()`); `provisioning` (created, never activated) and
`suspended` are not. Request paths already fail closed on that predicate:
`ResolveSchoolContext` sets no context for a non-active School (session
and verified-domain paths), `RequireSchoolContext` then clears the stale
`active_school_id`, and the API returns a non-disclosing 404 — so
suspension needs no session enumeration. Background work used not to
check it (`SetTenantContextForJob` loads the School by id only, and the
redispatch/publish commands walked every School). ADR 0047 §8 makes
**execution time authoritative** without a blanket refusal in
`SetTenantContextForJob` (it also carries safety work): each business job,
consumer and School-walking command re-checks the School when it runs and
defers (webhooks, communications — existing `retrying`/`queued` +
`next_attempt_at`, no attempt consumed, redispatchers skip non-active
Schools), skips (automation, terminal `skipped`) or creates nothing;
AI context tokens are never minted for a non-active School. Platform
safety work — elevation expiry, audit, pruning, heartbeats — continues.
The runtime role has no `DELETE` on `schools` (147 cascading foreign
keys; no application School delete); committing tests clean up through
`TestCase::deleteSchoolAsAdmin()`. As built (Phase 0N.9):
`App\Support\Tenancy\SchoolOperationalGuard::holdOperational()` reads
the School row FOR SHARE inside each claim transaction, the lifecycle
services lock it FOR UPDATE, so a claim either commits before a
suspension (in-flight work the suspension waits for) or sees it.

## Runtime role name in production (ADR 0050, O6)

`school_os_app` is the **fixed v1 production runtime role name** — a
contract, not an accident: `TenantRls::makeAppendOnly()`/`revokeDelete()`
default to it (44 migration calls) and three migrations grant to it by
name. The production database must contain a login role with exactly that
name, NOSUPERUSER/NOBYPASSRLS, never a member of the migration role, and
granted `ALTER DEFAULT PRIVILEGES FOR ROLE <migration role>` table and
sequence privileges (the local init scripts do this for `school_os`).
RLS policies and the Phase 0O.1A root boundary are role-name-independent.
A future ADR may generalize the name.

**Phase 0O.4A:** production bootstrap is
`infrastructure/postgres/production-bootstrap.sql` (any migration role
name; verifies rather than alters an existing `school_os_app`; never
grants on existing tables, so migration-level `DELETE`/`UPDATE`
revocations survive), proven on a throwaway PostgreSQL 16 cluster, and
`php artisan platform:verify-database` checks the result read-only —
including forced RLS on every `school_id` table except the documented
platform-resolvable bootstrap tables, guard-tested against the real
schema. `platform:verify-restore` re-proves after a restore that RLS fails
closed without a School and isolates Schools. Production refuses the test
database and a local `APP_URL` (`environment_not_separated`). Queue
recovery re-dispatches jobs whose payload carries the School id, so tenant
context is set exactly as for a first dispatch (rule 21).

## What is NOT yet implemented (Phase 0B honesty note)

Real School/Campus/membership/role data model, RLS, application-layer
scoping, and tenant-aware queue/cache/storage/log/AI plumbing are now
real (this section previously said none of it existed — that was
Phase 0A). What remains for a future phase: School Group/Trust
cross-school elevation workflows (the *structural* tables exist —
`school_groups`, `school_group_members` — but no "enter a member
School's context as a group admin" action is built, deliberately, per
section 12 of the Phase 0B brief: no invisible see-every-tenant-row
mode), tenant-custom roles (only system-defined roles exist), and a
production-grade credential-issuance story for the `school_os`/
`school_os_app` role split (ADR 0021's "Future extraction path").

## Custom School domains (ADR 0054 — contract, Phase 0O.8; implementation 0O.8A)

`school_domains` becomes a real, explicit lifecycle. Until Phase 0O.8A
lands, the behaviour described above is still today's code. Once it lands:
- **What selects a School.** Only an **`active`** domain of an active
  School selects a School, after exact canonical host classification.
  Unknown and non-active hosts get 421, with no default School.
- **Web only.** Domain resolution happens only for web routes on a School
  host. `/api/v1` keeps its `{school}`-in-the-URL selection and never
  reads the Host.
- **Host and session.** On a School host the host decides the School. The
  session selection is aligned to it, a disagreement is refused, and an
  active membership is still required: domain ownership grants none.
- **Switching** navigates to the target School's canonical origin: its
  primary active domain, or else the platform host.
- **Isolation.** Internal service routes (ADR 0053) and elevation (ADR
  0044) never use domain resolution.
