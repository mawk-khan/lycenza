# School OS — Engineering Rules

This file governs work in this repository. It applies to every module,
every language (PHP, TypeScript, Python, Dart), and every contributor —
human or AI. Read `docs/architecture/ARCHITECTURE.md` and
`docs/architecture/DOMAIN-MAP.md` before starting non-trivial work; they
explain *why* these rules exist.

## Repository layout

```
apps/platform    Laravel 13 + Inertia + Vue 3 + TypeScript — the authoritative ERP
apps/mobile       Flutter mobile client (hand-authored skeleton — Flutter SDK unverified, see apps/mobile/README.md)
services/ai       Python/FastAPI AI Gateway — intelligence, never authority
packages/contracts    OpenAPI + domain-event JSON Schemas (source of truth)
packages/shared-types Generated TypeScript bindings from packages/contracts
infrastructure/docker Local dev Dockerfiles + docker-compose.yml (repo root)
infrastructure/terraform  Empty by design — no cloud resources yet
infrastructure/release    Release qualification + artifact verification (ADR 0052) — never pushes or promotes
docs/architecture     ARCHITECTURE.md, DOMAIN-MAP.md, TENANCY.md, API.md, EVENTS.md, adr/
docs/ai                AI-PLATFORM.md, AI-SECURITY.md
docs/security           DATA-CLASSIFICATION.md, AUTHORIZATION.md
docs/roadmap             MASTER-ROADMAP.md
```

## Mandatory rules

1. **Inspect before editing.** Read the relevant files, tests, and
   related ADRs before changing them. Never assume a file's contents or
   a module's boundary — check `docs/architecture/DOMAIN-MAP.md` and
   `apps/platform/app/Domain/README.md`.

2. **No speculative frameworks or infrastructure.** Don't add a
   package, service, or abstraction for a need that doesn't exist yet.
   Every ADR in `docs/architecture/adr/` explicitly rejected several
   "obvious" additions (Kafka, microservices, a secrets manager,
   GraphQL, ...) for exactly this reason — read one before proposing
   the thing it already considered and deferred.

3. **Controllers stay thin.** A Laravel controller validates input,
   calls an Application-layer service, and returns a response. Business
   logic belongs in `app/Domain/<Module>/Application/`, not in
   controllers, and not in Eloquent models beyond basic relationships/
   casts. See `apps/platform/app/Http/Controllers/SystemStatusController.php`
   for the shape a controller should stay close to, even as it grows.

4. **Explicit module boundaries, no bidirectional coupling.** Follow
   `docs/architecture/DOMAIN-MAP.md`'s dependency directions. A module
   calls another module's Application-layer service or listens to its
   domain events — never reads another module's Eloquent models or
   tables directly. If your change makes Module A depend on Module B
   when B already depends on A (directly or transitively), stop and
   reconsider the design; update the domain map in the same PR if the
   dependency direction was wrong there.

5. **No cross-tenant leakage.** Every tenant-scoped query must be
   tenant-scoped by construction (`docs/architecture/TENANCY.md`, ADR
   0004) — application-layer scope AND, once implemented, Postgres RLS.
   A background job or queued listener must explicitly set tenant
   context from its payload; there is no ambient "current tenant" in a
   worker process.

6. **Authorization is required for every protected operation.**
   Every controller action, Application-layer method, queued job, and
   AI tool that touches Sensitive/Highly Sensitive data
   (`docs/security/DATA-CLASSIFICATION.md`) or changes state needs an
   explicit capability check (`docs/security/AUTHORIZATION.md`). "The
   UI hides the button" is not authorization. Never branch on a role
   name in application code — check a capability.

7. **Tenant-aware queue/background work.** Every queued job payload
   carries its tenant id explicitly. Every cache key, log line, and
   file path touching tenant data is namespaced by tenant. See
   `docs/architecture/TENANCY.md`.

8. **No direct AI database writes.** The AI Gateway (`services/ai`)
   never holds ERP database credentials and never writes to an
   application table directly. Every AI-initiated effect goes through
   an explicitly exposed Laravel "AI tool contract" endpoint, itself
   protected by normal Laravel authorization, reached only through the
   capability-gated chain in ADR 0014 / `docs/ai/AI-SECURITY.md`. If
   you're tempted to give an AI service a database connection "just for
   this one read," don't — expose a narrow, audited tool instead.

9. **Deterministic money handling.** No `float`/`double` for currency,
   ever — Postgres `NUMERIC`, exact-decimal arithmetic only. Every
   monetary value carries an explicit currency. Financial corrections
   are new, explicit adjustment/reversal records, never in-place edits.
   Payment callbacks are idempotent by construction. See
   `docs/architecture/ARCHITECTURE.md` §10 for the full list.

10. **Migration rollback safety.** Every migration must have a working
    `down()` (or be explicitly, deliberately irreversible with a
    comment explaining why — e.g. an unrecoverable data transformation).
    Never ship a migration you haven't considered rolling back.

11. **Audit significant state changes.** Financial transactions,
    student-status changes, document access, permission grants, and any
    AI-initiated action are always audited (ADR 0017) — actor, tenant,
    timestamp, entity reference, before/after where applicable. Audit
    records are append-only; corrections are new records, not edits.

12. **No secrets committed.** Only `.env.example` files are committed,
    with inert local-dev defaults that never work against a real
    external provider (ADR 0016). Real secrets live outside the repo
    entirely. If you see a credential-shaped value in a diff you're
    about to commit, stop and check it — even in a file whose name
    looks innocuous.

13. **Tests required.** New business logic ships with tests at the
    appropriate layer(s) in
    `docs/architecture/ARCHITECTURE.md` §11's table (unit, domain,
    feature, API, authorization — both allow *and* deny cases, tenancy
    isolation, integration, contract, AI tool permissions, security
    regression). A PR that adds a protected endpoint without both an
    "authorized" and an "unauthorized" test is incomplete.

14. **Scoped tests before regression.** Run the tests for the module/
    package you changed before running (or claiming to have run) the
    full suite. Don't report tests as passing unless they actually ran
    — if a tool/runtime is unavailable in your environment, say so
    explicitly rather than assuming success (this was true for Flutter
    in the environment Phase 0A was built in — see
    `apps/mobile/README.md`).

15. **Document architectural deviations.** If you must deviate from an
    existing ADR, write a new ADR (or update the existing one with a
    superseded/amended note) explaining why — don't silently drift from
    documented decisions.

16. **No deploys or shared-environment changes without explicit
    authorization.** Never deploy, push to production, provision cloud
    resources, purchase services, configure production secrets, send
    real external communications, or connect real school data without
    the user explicitly authorizing that specific action at that time.
    Phase-level stop gates are in `docs/roadmap/MASTER-ROADMAP.md`; they
    apply to every phase, not just Phase 0A/0B.

17. **Every tenant-owned model uses `App\Support\Tenancy\BelongsToSchool`.**
    This applies `SchoolScope` (Layer 1) and auto-fills `school_id` from
    `TenantContext` on create. There is no other sanctioned way to make
    a model tenant-scoped — do not hand-write an equivalent global
    scope. See `docs/architecture/TENANCY.md`.

18. **Every tenant-owned table's migration uses `App\Support\Tenancy\TenantRls`.**
    Call `TenantRls::enable($table)` (and `disable()` in `down()`) —
    never hand-write `ENABLE ROW LEVEL SECURITY`/`CREATE POLICY` SQL
    directly. Append-only tables (audit ledgers) additionally call
    `TenantRls::makeAppendOnly($table)`. See ADR 0021, ADR 0022.

19. **`school_id` cannot be accepted blindly from client input.** A
    request/route parameter naming a School is not, by itself,
    authorization to act as that School — always re-verify a real,
    active `SchoolMembership` (or equivalent) server-side, the way
    `SchoolSwitchController` and `Api\V1\SchoolContextController` do.
    Never trust a client-supplied `school_id` to set `TenantContext`
    directly.

20. **Tenant context comes only from trusted resolution.** Production
    tenant resolution is verified domain routing
    (`school_domains`) or a session-stored active-School selection
    re-validated against a real membership on every request
    (`App\Http\Middleware\ResolveSchoolContext`) — or, for a platform
    actor, a session-stored elevation pointer re-validated against an
    active `school_elevations` record on every request
    (`App\Http\Middleware\ResolvePlatformElevation`, ADR 0044), which
    establishes the School only on a route that explicitly opted in
    (rule 83) — never a raw client-supplied header. `X-School-Id` is
    honoured ONLY by
    `App\Http\Middleware\DevOnlySchoolHeaderResolver`, which is
    double-guarded (config flag AND `environment(['local','testing'])`)
    and must stay that way.

21. **Queue jobs must initialize and reset tenant context, not just
    initialize it.** Use the `App\Support\Tenancy\TenantScoped` trait
    (captures context at dispatch) — its `SetTenantContextForJob`
    middleware sets context before `handle()` and clears it in a
    `finally` block after. A job that touches tenant data without this
    trait is a bug, not a shortcut.

22. **Cache keys must be tenant-aware — including permission caches.**
    Use `App\Support\Tenancy\TenantCache` (namespaces
    `school:{id}:{key}`) for any tenant-scoped cache entry. A cache key
    that varies by School must include the School id; `CapabilityResolver`
    is the canonical example (cache key includes both user id and
    School id, never just user id).

23. **File paths must be tenant-aware.** Use
    `App\Support\Tenancy\TenantStoragePath::for($school, $path)` for
    any tenant-owned file path — never build a storage path by
    concatenating a caller-supplied fragment without going through it.

24. **Role names are not authorization; capabilities are.** Never write
    `if ($user->role === 'principal')` or equivalent. Check a
    capability via `Gate::authorize('capability', [$key, $school])`,
    the `capability:` route middleware
    (`App\Http\Middleware\EnsureCapability`), or the
    `App\Support\Authorization\AuthorizesCapability` controller trait.
    See `docs/security/AUTHORIZATION.md`.

25. **School roles cannot grant platform capabilities, and this is
    database-enforced, not just conventional.** `membership_role_assignments`
    only accepts `scope='school'` roles; `platform_role_assignments`
    only accepts `scope='platform'` roles — both via a Postgres trigger
    (see those tables' migrations). Since Phase 0N.5 there are exactly
    three scopes (`roles_scope_check`: `platform`, `school`, `group`):
    `group_role_assignments` only accepts `scope='group'` roles, and
    `trg_role_capabilities_scope` lets a role hold only capabilities of
    its own scope's namespace, so no role of one scope can carry another
    scope's capability. Do not add an application-level-only check that
    could be bypassed by a direct write.

26. **Platform admins do not receive database RLS bypass.** The
    `school_os_app` runtime role (used by every request/queue
    connection) is never granted `BYPASSRLS` or superuser, regardless
    of the acting user's platform role — Platform Super Admin is an
    *application-layer* capability grant, not a database privilege
    escalation. See ADR 0021.

27. **AI cannot choose or change tenant context.** The AI Gateway
    (`services/ai`) never mints or alters the signed context token that
    binds `school_id`/actor/capability to an AI tool invocation — only
    `App\Support\Ai\AiContextTokenService` (Laravel-only signing key)
    does, and only after `App\Support\Ai\AiGatewayClient` has verified
    real capability data. See ADR 0023, `docs/ai/AI-SECURITY.md`.

28. **Cross-tenant tests are mandatory for any tenant-owned table or
    protected action.** At minimum: School A cannot read/write School
    B's rows (both at the Eloquent layer AND, in a dedicated test using
    real PostgreSQL, at the raw-SQL/RLS layer — see
    `tests/Feature/Postgres/RawIsolationTest.php` for the pattern), and
    missing tenant context fails closed rather than exposing every
    tenant's data.

29. **Consequential retryable mutation endpoints must evaluate
    idempotency requirements.** Any state-changing endpoint a mobile
    client, browser, reverse proxy, or automation could plausibly retry
    (connectivity loss, timeout, duplicate tap) must be explicitly
    reviewed for whether it needs the `idempotent` route middleware
    (`App\Http\Middleware\EnsureIdempotent`) — never applied globally,
    never reflexively added to every POST, but never skipped by default
    either for an endpoint whose duplicate execution would be costly or
    dangerous. See `docs/architecture/RELIABILITY.md` ("API
    idempotency").

30. **Never implement idempotency using only an existence check.** A
    "check-then-insert" (`if (!$exists) { create(); }`) leaves a race
    window under real concurrency. PostgreSQL's unique constraint
    (`api_idempotency_keys_scope_unique`) plus
    `UniqueConstraintViolationException` handling is the sole
    authoritative concurrency guarantee
    (`App\Support\Idempotency\IdempotencyGuard::claim()`) — Redis
    `SETNX`/in-memory locks/application pre-checks are never sufficient
    on their own.

31. **Idempotency scope must include School/security context, never
    the client key alone.** Uniqueness is always
    `(school_id, actor_type, actor_id, route_action, idempotency_key)`
    or an equivalently narrow scope — never `idempotency_key` alone.
    Two different Schools, or two different actors, reusing the
    identical literal key must never collide or share a result.

32. **Authorization is re-evaluated before replaying a protected
    response, never short-circuited by a prior success.** Order:
    authentication → tenant/membership validation → authorization →
    idempotency. An actor who has since been disabled, had their
    membership suspended, or lost the required capability must be
    rejected before the idempotency guard is ever consulted — see
    `docs/security/AUTHORIZATION.md` ("Idempotent replay is not an
    authorization bypass").

33. **A feature-specific external idempotency model may still be
    required.** The generic `Idempotency-Key` middleware only
    guarantees "effectively-once under its documented in-flight
    timeout" for its own default completion path (section 17/"Crash
    window" of `docs/architecture/RELIABILITY.md`). A consequential
    mutation that cannot tolerate that gap (financial transactions,
    anything expensive/dangerous to duplicate) must call
    `IdempotencyGuard::completeWithin()` inside its own
    `DB::transaction()`, exactly like
    `App\Http\Controllers\Api\V1\Internal\IdempotencyDemoController`
    does — the generic middleware alone cannot guarantee this for
    arbitrary future controller logic.

34. **Payment-provider idempotency is separate from the client API
    `Idempotency-Key` contract.** A payment callback/webhook needs its
    own idempotent-handling contract keyed on the provider's own
    delivery/transaction identifiers — a client also sending an
    `Idempotency-Key` on the originating request does not satisfy this.
    A future Fees/Payments module needs both (ARCHITECTURE.md §10,
    RELIABILITY.md "Payment and webhook readiness").

35. **Webhook delivery idempotency is separate from the client API
    `Idempotency-Key` contract.** `webhook_deliveries`'
    `(webhook_endpoint_id, event_id)` uniqueness is its own mechanism —
    never reuse `App\Http\Middleware\EnsureIdempotent` for inbound/
    outbound webhook delivery, and never conflate the two in review.

36. **Stored idempotency responses are sensitive operational data.**
    `api_idempotency_keys.response_body`/`response_headers` are
    ordinary tenant-owned PostgreSQL columns — RLS-protected, but with
    no column-level encryption-at-rest beyond the deployment's disk
    encryption. A future endpoint returning Sensitive/Highly Sensitive
    data under the `idempotent` middleware must be reviewed for whether
    storing its full response for the retention window is acceptable
    (`docs/security/DATA-CLASSIFICATION.md`).

37. **Do not log request/response bodies through idempotency
    middleware.** `App\Support\Idempotency\IdempotencyMetrics`/the
    `idempotency.outcome` structured log line carry School id, actor
    id, route/action, and request/correlation id only — never the
    payload, never the stored response, never the raw idempotency key
    (hash or partially redact it if it must appear in a log line).

38. **External HTTP side effects never run inside an authoritative
    domain transaction.** `App\Support\Events\Consumers\
    WebhookFanoutConsumer` only claims a durable delivery row and
    queues `App\Jobs\DeliverWebhookJob`; the real HTTP call happens
    entirely inside that separately-queued job, never inline with the
    business transaction that produced the triggering event. Any
    future outbound integration (payment gateway calls, SMS/WhatsApp
    sends, ...) follows the same pattern.

39. **Webhook receivers must be told, explicitly, to assume
    at-least-once delivery.** School OS's own `(webhook_endpoint_id,
    event_id)` uniqueness guarantees exactly one logical delivery
    internally, but a broken connection after a receiver has already
    processed a request means the receiver can genuinely see the same
    event twice — never claim exactly-once external delivery in code,
    comments, or documentation (`docs/architecture/INTEGRATIONS.md`,
    ADR 0026).

40. **Every logical webhook delivery has a stable identity, separate
    from any individual HTTP attempt.** `webhook_deliveries` (one row
    per `(webhook_endpoint_id, event_id)` pair) and
    `webhook_delivery_attempts` (one append-only row per real HTTP try)
    are distinct tables with distinct lifecycles — never collapse
    attempt history into the delivery row, and never infer an attempt
    number with a bare `count() + 1` (a database unique constraint on
    `(webhook_delivery_id, attempt_number)` is what makes numbering
    safe under concurrency, section 43/44 of the 0C.3 checkpoint).

41. **Outbound webhook URLs require SSRF validation, re-checked at
    every delivery attempt, not only at registration.**
    `App\Support\Webhooks\SsrfSafeUrlValidator` is the one sanctioned
    validator for any user-supplied destination URL an outbound HTTP
    feature reaches — do not hand-write an equivalent check, and do
    not skip the send-time re-check because the endpoint "was already
    validated once" (DNS can change between registration and
    delivery). See ADR 0027.

42. **Webhook redirects are never followed.** `allow_redirects: false`
    is the fixed policy; a 3xx response is classified as a permanent
    delivery failure. A redirect target has not itself passed SSRF
    validation, so following it is not a safe default to relax later
    without a full redesign (each hop would need its own
    validate-and-pin sequence).

43. **A testing-only SSRF override can never widen policy beyond what
    it explicitly names.** `WEBHOOKS_ALLOW_LOOPBACK_FOR_TESTS` permits
    loopback addresses (`127.0.0.0/8`, `::1`) ONLY, double-guarded by
    `app()->environment(['local', 'testing'])`, exactly like
    `App\Http\Middleware\DevOnlySchoolHeaderResolver`'s pattern — never
    introduce a broader `disable_ssrf_protection`-style flag, and never
    let a test override implicitly relax protection for private/
    link-local/reserved ranges beyond loopback.

44. **Webhook payloads are explicit, versioned, minimized DTOs — never
    a raw Eloquent model serialization.** A domain event's `payload()`
    method is the single place minimization happens (at event-creation
    time, in the owning module); the webhook layer delivers that
    payload unchanged inside the fixed envelope
    (`docs/architecture/INTEGRATIONS.md`), it does not re-serialize a
    live model at delivery time (which would also violate section 87's
    "the webhook must represent what happened at event time" rule).

45. **A domain event type is not externally subscribable unless it is
    registered as such.** `App\Support\Webhooks\WebhookEventRegistry`
    is the one closed catalog gating both subscription creation
    (`WebhookSubscriptionService::subscribe()`) and fanout
    (`WebhookFanoutConsumer::handles()`) — an internal-only event
    (e.g. a future security/audit event) must never become reachable
    as a webhook subscription just because it exists in
    `domain_event_outbox`.

46. **Webhook secrets never enter logs, audit metadata, or any
    response other than the one-time creation/rotation response.**
    `webhook_endpoints.secret_encrypted`/`previous_secret_encrypted`
    use Laravel's `encrypted` cast (never hashed — the raw value must
    be retrievable to compute an outbound HMAC); the plaintext value is
    returned exactly twice in its lifetime (creation, each rotation)
    and never again. See `docs/security/INTEGRATION-SECURITY.md`.

47. **Webhook administration requires capabilities
    (`integrations.webhooks.view`/`.manage`), never a role-name
    check** — the same rule 24 principle applied to this subsystem
    specifically, called out because it is the first module whose
    entire external-facing surface is administrative rather than
    end-user-facing.

48. **Queue duplicate delivery of the same webhook must be safe by
    construction.** Two workers (or one worker processing a
    redelivered/duplicate job) racing the same `webhook_deliveries` row
    must not both send the HTTP request — `DeliverWebhookJob`'s atomic
    processing-lease claim (a conditional `UPDATE`, not an
    application-level check-then-act) is the sanctioned pattern for any
    future queued job with the same "at most one worker actually acts"
    requirement.

49. **Webhook failure classification determines retry behavior, and
    that classification is fixed, not per-integration-configurable
    yet.** 2xx succeeds; 3xx and 4xx other than 408/429 are permanent
    failures (never retried); 408/429/5xx/timeout/network errors are
    transient (retried, bounded by `webhooks.max_attempts`, respecting
    a clamped `Retry-After` where present). Do not retry a 4xx
    reflexively, and do not treat a 3xx as anything other than a
    permanent failure.

50. **Never run a destructive test-database operation
    (`migrate:fresh`, `db:seed`, a raw `migrate:rollback` against
    `pgsql_admin`, ...) without positively verifying the resolved
    database name first.** `APP_ENV=testing` alone does NOT guarantee
    `DB_DATABASE` points at the test database — this project
    deliberately has no `.env.testing` file (see rule 52), so a plain
    `artisan` invocation with `--env=testing` falls back to `.env`'s
    ordinary development database unless `DB_DATABASE` (and, for
    `pgsql_admin`, its admin credentials) are explicitly overridden for
    that invocation. This is exactly how the Phase 0C.3 test-database
    incident happened — see
    `App\Support\Testing\TestDatabaseGuard`'s docblock.

51. **Testing commands must use the project's canonical test
    runner/reset path — `composer test` to run the suite,
    `php artisan platform:test-db-reset --force` to reset the test
    database — never a hand-typed `migrate:fresh`/`db:seed` pair.**
    The reset command verifies test-database identity before doing
    anything destructive and runs the FULL canonical seed set
    (`Database\Seeders\DatabaseSeeder`, not `CapabilityAndRoleSeeder`
    alone — a partially-seeded test database silently breaks unrelated
    suites, e.g. every AI Gateway test needs `ServiceIdentitySeeder`
    too).

52. **`APP_ENV=testing` must fail closed if any authoritative test-DB
    connection resolves to a non-test database — do not rely on
    `APP_ENV` alone to guarantee DB selection.**
    `App\Providers\AppServiceProvider::register()` runs
    `TestDatabaseGuard::assertSafe()` on every boot (web, artisan,
    tinker): whenever `app()->environment('testing')` is true, EVERY
    connection capable of a destructive operation (`pgsql`,
    `pgsql_admin`) must resolve to exactly
    `config('database.testing_database')` (an explicit config value,
    never fragile substring/prefix matching) or the application refuses
    to boot at all. This also means `composer test`/`php artisan test`
    is not automatically safe if something upstream already exported a
    conflicting `DB_DATABASE` — PHPUnit's `<env>` block only fills in
    variables that aren't already set, it does not override one; the
    guard is what actually catches that case now, aborting the run
    instead of quietly executing tests against the development
    database. Do not add a second `.env.testing` file as an
    alternative fix — a second, driftable copy of connection
    configuration is exactly the kind of duplicate configuration system
    this guard exists to make unnecessary.

53. **Do not manually substitute a development database name into a
    testing command "just this once."** If `TestDatabaseGuard` aborts,
    fix the actual environment variables for that invocation (see rule
    51's canonical commands) — do not work around the abort by passing
    `--database=pgsql` with development credentials, disabling the
    guard, or hardcoding an override. The abort is the safety mechanism
    working correctly, not an obstacle to route around.

54. **Migrations must use the approved migration/admin connection
    (`--database=pgsql_admin`, ADR 0021) and must never be run against
    a database whose identity has not been positively verified** —
    this applies to `migrate`, `migrate:fresh`, and `migrate:rollback`
    equally. `TestDatabaseGuard` enforces this for the testing
    environment specifically; the same discipline (know exactly which
    database a migration command is about to touch before running it)
    applies to any other environment a human might interact with
    directly.

55. **Liveness and readiness are distinct endpoints, never collapsed
    into one.** Liveness answers "is this process alive enough that
    restarting it is not warranted" and must never depend on
    PostgreSQL/Redis/the AI Gateway/a customer endpoint. Readiness
    checks only the dependencies genuinely essential to safely serve
    traffic (PostgreSQL, Redis) — an optional subsystem outage is a
    `Degraded` internal-diagnostics signal, never a readiness failure.
    See `docs/architecture/OBSERVABILITY.md`.

56. **The AI Gateway, object storage, the domain-event outbox, and a
    customer's webhook endpoint are optional subsystems.** Their
    outage must never make the core ERP unready or unhealthy — cap
    their status at `Degraded` in
    `App\Support\Observability\OperationalStatusService`, never
    `Unhealthy`, and never include them in `readiness()`.

57. **A long-running worker (queue job, console command) must clear
    tenant/trace/correlation context when its unit of work ends, in a
    `finally` block — not just initialize it.** This is the same
    invariant as rule 21, extended: `App\Support\Observability\TraceContext`
    and correlation ids are request/job-scoped exactly like
    `TenantContext`, and leaking them into whatever the same
    long-running process handles next is a real bug, not a cosmetic
    one.

58. **Every queued job declares `$tries`/`$timeout` explicitly, and
    `$timeout` must stay well below every queue connection's
    `retry_after`** (`config/queue.php`) — otherwise a still-running
    job can be picked up and executed again by a second worker,
    silently multiplying side effects. See `docs/architecture/RELIABILITY.md`
    ("Job timeout invariants").

59. **Domain-level retry and queue-level retry must never both apply
    to the same job.** A job that owns its own retry/backoff state
    (e.g. `DeliverWebhookJob`, driven by `webhook_deliveries.next_attempt_at`)
    must declare `$tries = 1` — letting Laravel ALSO retry it at the
    queue level multiplies attempts and invalidates the job's own
    documented backoff schedule.

60. **Distributed locks always go through `App\Support\Concurrency\TenantLock`**
    (itself a thin wrapper over Laravel's `Cache::lock()`) — never a
    hand-written Redis `SET NX`/Lua algorithm. A School-scoped
    operation always uses `TenantLock::forSchool()`, whose key
    namespace includes the School id — never a lock keyed only by
    operation name, which would let School A block School B.

61. **A rate limiter's key must never assume a route's declared
    middleware array order is its runtime order.** Laravel sorts
    execution by `$middlewarePriority`; framework-prioritized
    middleware (`Illuminate\Routing\Middleware\ThrottleRequests`
    included) runs before this project's own non-prioritized custom
    middleware regardless of declaration order. A tenant-aware
    limiter must resolve its tenant identity from something reliably
    available at throttle-evaluation time (the route parameter, the
    authenticated user) — never from `App\Support\Tenancy\TenantContext`,
    which is not yet populated that early. See
    `App\Providers\RateLimiterServiceProvider::tenantKey()` and
    `docs/architecture/RELIABILITY.md` ("Rate limiting interaction")
    for the real bug this caused and its fix.

62. **Traces, correlation ids, and request ids are diagnostic only —
    never consulted for an authorization decision anywhere in this
    codebase.** `App\Support\Observability\TraceContext` explicitly
    never trusts a caller-supplied span-id as identifying anything
    about this service's own execution, for the same reason: these
    values cross trust boundaries (a caller can set `traceparent` to
    anything) and must never gain security meaning.

63. **Logs, error reports, and metrics have different secrecy and
    cardinality rules — do not blur them.** Structured logs/error
    reports go through `App\Support\Observability\LogSanitizer`
    (redacting password/token/secret/credential/signature-shaped keys)
    as a backstop, but callers must still only pass minimal, already-
    safe metadata. Metric labels
    (`App\Support\Observability\MetricsRecorder`) additionally must
    never carry a high-cardinality identifier — `request_id`,
    `correlation_id`, `event_id`, `delivery_id`, `user_id`, `school_id`
    — a metrics backend indexes one time series per unique label
    value; that data belongs in logs, not metrics. Detailed internal
    diagnostics (`GET /api/internal/operations/status`,
    `platform:operations-status`/`platform:failed-jobs` CLI commands)
    require platform or internal-service authorization and are never
    exposed publicly or unauthenticated.

64. **AcademicYear is School-owned, and only one may be `active` per
    School.** This is database-enforced via a PostgreSQL partial
    unique index (`academic_years_one_active_per_school`), never an
    application-level check-then-update. See
    `App\Domain\AcademicStructure\Application\AcademicYearService` and
    `docs/modules/ACADEMIC-STRUCTURE.md`.

65. **AcademicYear activation must be transactional and
    concurrency-safe.** Activating a year closes whatever was
    previously active in the SAME transaction; a genuine race between
    two concurrent activations for the same School must be caught
    (`ConcurrentActivationConflictException`, translated from
    PostgreSQL's `UniqueConstraintViolationException`), never silently
    produce two active years. Proven with two real separate OS
    processes, not a sequential simulation — see
    `Tests\Feature\AcademicStructure\AcademicYearActivationConcurrencyTest`.

66. **Section belongs to exactly one AcademicYear.** Section history is
    never represented by mutating or reusing a prior year's row —
    "Grade 5 A" in 2026-27 and "Grade 5 A" in 2027-28 are permanently
    distinct database rows. This is what makes future student-
    enrollment history possible without a later redesign.

67. **GradeLevel ordering uses an explicit `sequence` column,** never
    inferred from `name`/`code` string parsing — pedagogical order
    (Nursery, LKG, UKG, Grade 1, …) cannot be recovered from a string
    sort.

68. **`school_id` never comes from request payload for Academic
    Structure endpoints, same as everywhere else in this codebase.**
    The active School is always the trusted route/session context
    (`App\Models\School` route binding + `school-membership`
    middleware, or `TenantContext::requireSchool()` for session-
    authenticated Inertia pages) — see rule 19.

69. **Every School-owned Academic Structure table uses
    `App\Support\Tenancy\TenantRls`,** exactly like every other
    tenant-owned table (rule 18). `education_boards` is the one
    deliberate exception — a platform reference catalog with no
    `school_id` and no RLS, the same shape as `capabilities`/`roles`.

70. **Cross-School parent/child relationships require a structural
    database constraint, not just an application check.** Every
    Academic Structure child row referencing a School-scoped parent
    (Section → AcademicYear/Campus/GradeLevel; Room → Campus;
    SubjectOffering → AcademicYear/Campus/GradeLevel/Subject; Subject →
    AcademicDepartment) uses a composite foreign key against
    `(id, school_id)` on the parent table — the same pattern
    `membership_role_assignments`/`webhook_subscriptions` already
    established. Never rely on SchoolScope/RLS alone to prevent a
    cross-School reference at INSERT time.

71. **Subjects (and GradeLevel, AcademicDepartment) are School-scoped
    reference data, not global.** A code (`MATH`, `G5`, …) is unique
    within a School, never platform-wide — two different Schools
    reusing the identical code must never collide.

72. **Subject Offerings are AcademicYear-specific, never a global
    Grade→Subject map.** Changing next year's offering must never
    rewrite this year's history — `subject_offerings` rows are scoped
    to one specific `academic_year_id`, permanently.

73. **Reference entities are deactivated, not deleted, once they may
    have historical references.** `GradeLevel`, `AcademicDepartment`,
    `Subject`, `Room`, `Section`, `SubjectOffering`, and `Campus` use a
    `status` (`active`/`inactive`) column and have no DELETE endpoint —
    a future SIS/Attendance/Exams module may hold a real foreign key to
    any of these rows. `AcademicYear` uses its own richer lifecycle
    (`draft`/`active`/`closed`) for the same underlying reason.

74. **Codes are normalized for case-insensitive uniqueness, both at
    the model layer and before validation.** Every model with a `code`
    column uses `App\Support\NormalizesCode` (uppercases on
    assignment); every controller accepting a `code` input uses
    `App\Support\NormalizesCodeInput` before running its
    `Rule::unique()` check, so a duplicate submission returns a clean
    `422`, not a raw database constraint-violation exception.

75. **`schools.status` (platform tenant lifecycle) and School
    operational profile fields are structurally separate, and no
    School-scoped capability may write `status`.** See
    `docs/modules/ORGANIZATION.md`. Never add a validated field list
    for a `school.profile.manage`-gated action that includes `status`.

76. **Controllers stay thin even for Academic Structure's simpler CRUD
    entities.** A short, direct create-then-audit-then-event block
    inline in a controller action (matching
    `App\Http\Controllers\App\SchoolSettingsController::update()`'s
    established pattern) is acceptable for a genuinely simple entity;
    a dedicated Application service is required once real invariants
    exist (date-range/overlap validation, a multi-step state machine) —
    see `App\Domain\AcademicStructure\Application\AcademicYearService`/
    `AcademicTermService` for that line.

77. **Domain events for Academic Structure use the transactional
    outbox exactly like any other module (ADR 0025), and are never
    externally webhook-publishable by default.** A new event type
    becomes reachable as a webhook subscription only through an
    explicit, reviewed addition to
    `App\Support\Webhooks\WebhookEventRegistry` — never automatically
    because it exists in `domain_event_outbox` (rule 45).

78. **Run tests through `apps/platform/bin/safe-test`
    (`composer test:safe` from a host with Composer), not a
    hand-typed `docker exec`/`vendor/bin/phpunit` invocation.** See
    `docs/architecture/TEST-ENVIRONMENT-AND-WORKTREE-SAFETY.md`. It
    validates resolved configuration via `platform:env-diagnostic`
    before running anything, passes the full safe-environment override
    set explicitly (defense-in-depth beyond rule 79's `force="true"`,
    and the only protection a raw `artisan` command such as
    `platform:test-db-reset` gets, since it never parses
    `phpunit.xml`), and raises the PHP CLI `memory_limit` for the
    `phpunit` subprocess (the default 128M reliably exhausts on this
    repository's full suite). `composer test`/a raw `docker exec`
    remain correct if you already know every override needed; the
    runner exists so nobody has to reconstruct that list from memory.

79. **`phpunit.xml`'s `<php>` block uses `force="true"` on every
    entry, and this is load-bearing, not decorative.** Without it,
    PHPUnit only defines a variable that is not already present in the
    process environment — it never overrides one that is (see
    `vendor/phpunit/phpunit/src/TextUI/Configuration/PhpHandler.php`).
    `docker-compose.yml`'s `env_file:` makes every value in
    `apps/platform/.env` (development defaults —
    `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `MAIL_MAILER=log`,
    `DB_DATABASE=school_os`, `APP_ENV=local`) a real ambient process
    environment variable inside the `platform` container, which is
    exactly the "already set" state a non-forced `<env>` respects.
    This produced 44 unrelated-looking test failures during Phase 5
    closure verification before the root cause was found. Never remove
    `force="true"` from a safety-relevant entry, and never add a new
    safety-relevant `<env>` entry without it.
    The ONLY sanctioned way to point the suite at a test database in
    another location (CI's `127.0.0.1` service, DDEV's `db` service) is
    the explicit `PHPUNIT_DB_HOST` / `PHPUNIT_DB_PORT` /
    `PHPUNIT_DB_ADMIN_USERNAME` / `PHPUNIT_DB_ADMIN_PASSWORD` overrides
    applied in `tests/bootstrap.php` -- connection target only; never
    add `DB_DATABASE`, `APP_ENV` or any cache/queue/mail value to that
    list.

80. **A `force="true"` `<env>` alone is not sufficient — Laravel's
    `env()`/`config()` can still resolve the ambient value even when
    `getenv()` is correctly forced.** `vlucas/phpdotenv`'s
    `RepositoryBuilder` reads `$_SERVER` (`ServerConstAdapter`) before
    `$_ENV`/`getenv()`, and PHPUnit's `force="true"` never touches
    `$_SERVER`. `tests/bootstrap.php` (phpunit.xml's `bootstrap=`
    target) closes this by re-syncing `$_SERVER` from `$_ENV` once,
    after PHPUnit applies its forced values and before Laravel boots —
    do not change `phpunit.xml`'s `bootstrap=` back to
    `vendor/autoload.php` directly, and do not assume a passing
    `getenv()`-based check proves `config()` will agree with it; see
    `docs/architecture/TEST-ENVIRONMENT-AND-WORKTREE-SAFETY.md` section
    1 ("The `$_SERVER` gap") for the confirmed mechanism.

81. **A bare `docker compose up`/`down`/`run` (no explicit `-p
    <project>`) always targets the shared `school-os` project
    (`docker-compose.yml`'s hardcoded `name: school-os`), regardless
    of which worktree directory it is run from.** Running it from a
    non-primary worktree recreates the shared `platform` container
    bound to THAT worktree's code (`docker-compose.yml`'s
    `./apps/platform:/var/www/app` bind mount is resolved relative to
    the invoking directory); running a bare `down` from ANY worktree
    stops and removes the shared project's containers, not whatever
    the operator locally intended — reproduced directly during the
    test-environment-hardening checkpoint itself (see
    `docs/architecture/TEST-ENVIRONMENT-AND-WORKTREE-SAFETY.md`
    section 17, "Failure examples"). Prefer
    `apps/platform/bin/safe-test` (rule 78), which always passes an
    explicit `-p` on every Compose invocation it makes. If you must run
    a raw `docker compose` command directly, inspect
    `docker compose config`/`docker ps` first and pass `-p` explicitly
    once more than one Compose project might plausibly be in play —
    never assume you are the only worktree using the shared project.
    `SAFE_TEST_ISOLATED=1 apps/platform/bin/safe-test` runs against a
    dedicated, deterministically-named, no-published-host-ports
    Compose project derived from the current worktree path, for
    exactly the situation where two worktrees might run tests
    concurrently.

82. **Every unit of work is finished -- tested, committed, pushed and
    published -- before the next one starts, and the full regression
    suite runs on a fixed cadence.** Per phase/unit: focused domain
    tests, the relevant integration/authorization tests, the static/
    style/frontend gates (Pint, Larastan, vue-tsc, ESLint, Prettier,
    build), a DDEV smoke/review (`ddev demo-reset`,
    `docs/development/DDEV-DEMO-REVIEW.md`), then commit, push and
    merge/publish through the normal `integration/*` gate. After every
    4-5 completed units -- or sooner after any major cross-domain
    integration -- run the COMPLETE suite (`ddev test --reset-db` or
    `bin/safe-test`), investigate every failure, fix repository defects,
    re-run the affected tests and then the full suite again, and publish
    those corrections before continuing. Environment-specific tests
    (e.g. the real-MinIO Documents tests,
    `docs/architecture/TEST-ENVIRONMENT-AND-WORKTREE-SAFETY.md`) are
    reported separately, never skipped to make a run green. Record in
    each unit's report which unit it is since the last full-regression
    checkpoint.

83. **Platform elevation grants tenant context, never School authority,
    and no School route accepts it by default.** An elevated request
    (`App\Support\Tenancy\ElevationContext`) is refused with 403 on
    every School route unless that route declares
    `school-context:elevated`, and adding that declaration to any route
    requires its own ADR naming the operation, a narrow `platform.*`
    capability checked alongside the elevation, and allow/deny tests
    (ADR 0044 section 8). No route declares it as of Phase 0N.3
    (`Tests\Feature\Tenancy\SchoolContextRouteGuardTest`). Never map a
    platform role to School capabilities, never create or reuse a School
    membership for an elevated actor, never extend or reactivate an
    elevation (fixed 30 minutes, database-checked), and never resolve
    elevation on `/api/*` or for AI context tokens.

84. **School Group authority is its own scope and never School
    authority.** Group capabilities (`group.*`) come only from an
    unrevoked `group_role_assignments` grant for one explicitly named
    School Group and are resolved only through
    `CapabilityResolver::canInGroup()`/`groupCapabilities()` — never
    through `can()`, never cached, never read by the platform or School
    side. A Group never enters `TenantContext`, a Group page reads only
    platform tables directly, and a Group grant never creates or implies a
    School membership or School capability. The ONE tenant-data exception
    (ADR 0048) is an explicitly registered Group-safe Analytics report
    (`App\Domain\Analytics\Application\Group\GroupSafeReportRegistry`, v1
    `curriculum.coverage` only) read under `group.reporting.view` through
    Analytics' `GroupSafeReportGate` -- one School `TenantContext` at a time,
    active member Schools only, never by Group code touching tenant models,
    read models or source services, never cached, persisted or exported.
    It is not a general Group tenant-data allowance: another report needs
    an ADR 0048 amendment. Which Schools belong to a Group and
    who holds Group authority are platform-governed
    (`SchoolGroupGovernanceService`; no self-grants; Groups are archived,
    never deleted). Group-derived School entry is ADR 0044 elevation
    recording its authorizing Group and grant; losing that authority ends
    it, with no fallback to any other authority (ADR 0045).

85. **Platform authority has a root and nothing root-equivalent.**
    `platform_super_admin` is provisioned out of band only and is never
    granted or revoked through the application (the database refuses a
    runtime grant or revocation of any role not marked
    `roles.runtime_assignable`, and — since Phase 0O.1A — refuses any
    grantor-less (out-of-band) grant from a role that does not hold the
    `platform_role_assignments` owner's privileges, so the runtime role
    can never mint root; never make the runtime role a member of the owner
    role). Root arrives only through `platform:bootstrap-root` (first boot)
    or `platform:provision-root` (existing account) on the admin
    connection; tests use `createPlatformRoot()`. Only code-approved, runtime-assignable,
    non-root roles are granted at runtime — v1 `platform_auditor` alone,
    through `PlatformRoleGovernanceService` and the root-reserved
    `platform.role_grants.manage` — never to or by oneself, with history
    kept (revocation, never deletion). Root-reserved capabilities
    (`platform.role_grants.manage`, `platform.schools.manage`) never sit on
    a runtime-assignable role. Platform audit review needs
    `platform.audit.view` and current MFA assurance, reads only
    `platform_audit_events` through `PlatformAuditEventReader` (seven
    envelope fields, never metadata, IP or user agent), sets no School
    context, and records one `platform.audit_log.viewed` per review; it is
    never merged with the School audit-log review (ADR 0046).

86. **A School operates only while `schools.status = 'active'`, and the
    lifecycle is database-enforced.** `provisioning -> active ->
    suspended -> active` is the only permitted path
    (`trg_schools_status_transition`, every role); `archived` has no
    application transition; the default is `provisioning`; the runtime
    role cannot `DELETE` a School (tests clean up through
    `TestCase::deleteSchoolAsAdmin()`). Only
    `App\Domain\Platform\Application\Schools\SchoolLifecycleService`
    creates a School or changes its status, and only
    `SchoolBootstrapAdministrationService` writes School memberships from
    the platform side -- the bootstrap School Administrator, while the
    School is `provisioning`, never afterwards (no platform membership
    administration). Every lifecycle change needs
    `platform.schools.manage`, explicit confirmation and a fresh MFA
    re-verification. Every School business effect re-checks the School
    at EXECUTION time through `App\Support\Tenancy\SchoolOperationalGuard`
    (FOR SHARE inside its claim transaction) -- never a blanket check in
    `SetTenantContextForJob` -- and a new queued job or School-walking
    command must decide its suspended-School behaviour
    (`SchoolLifecycleArchitectureGuardTest`, ADR 0047).

87. **A release is one immutable, verified image digest; the repository
    reaches VERIFIED, never PUBLISHED or PROMOTED (ADR 0052).** Production
    bases are pinned `image:version@sha256:…`; every workflow action is
    pinned to a full commit SHA with a `# vX.Y.Z` comment; workflows start
    at `permissions: contents: read`, never use `pull_request_target`, and
    never hold a registry credential, signing identity or repository secret.
    Dependencies install from their locks only -- Composer with
    `--no-scripts --no-plugins` (`allow-plugins: false`), npm with the
    repository `.npmrc` (`ignore-scripts`), the Gateway from the hash-locked
    `services/ai/requirements.lock` (`--require-hashes --no-deps
    --only-binary=:all:`; regenerate it with pip-compile, never hand-edit).
    Never `npm audit fix`/`composer update` in CI or a build, never edit a
    scanner report, never fabricate an exception approval: vulnerability
    thresholds live only in `infrastructure/release/lycenza_release/evaluate.py`,
    exceptions are exact and time-bounded in
    `infrastructure/release/vulnerability-exceptions.json`, and the verdict
    comes only from `infrastructure/release/verify-artifact`. Repository
    signing uses an ephemeral per-run NON-PRODUCTION key only -- never commit,
    reuse or configure a real key or keyless identity without explicit
    deployment authorization. Secret-scan allowlists are exact (one path +
    one value, scoped to one gitleaks rule). `SupplyChainGuardTest` and
    `infrastructure/release/tests` enforce this.

88. **Every request Host is classified exactly, and a Host is intent, never
    authority (ADR 0054).** `App\Http\Middleware\ClassifyRequestHost` runs
    right after trusted-proxy handling: platform (APP_URL + exact aliases),
    `INTERNAL_HOSTS`, an ACTIVE custom School domain, an alias (308 to the
    stored primary) or the probe path -- everything else is one fixed 421
    before any session/CSRF/School/URL logic; never add a regex, wildcard or
    "first School" fallback. A School host serves only the closed
    `SchoolHostSurface` (no platform, Group, API, internal, health or storage
    route) and sets `TenantContext` only for a signed-in ACTIVE member. Only
    `SchoolDomainService`/`SchoolDomainCheckService` write `school_domains`;
    every writer takes the School's advisory lock (`lockSchool()`) before any
    row lock; `active` is reached only on evidence (no force-activate). Absolute
    School URLs come only from `App\Support\Domains\CanonicalOrigin`, never
    a request Host. Cookies stay host-only (`SESSION_DOMAIN` empty); crossing
    origins uses the one-time `CrossHostHandoff` ticket, which is continuity,
    never authority, and is never logged. The DNS/TLS fakes exist only behind
    `DOMAIN_FAKES_ENABLED` AND local/testing.

## Running things locally

```bash
# Backend infra
docker compose up -d postgres redis minio minio-init   # minio-init creates the local bucket

# Laravel (apps/platform)
cd apps/platform
composer install
cp .env.example .env && php artisan key:generate

# Migrations run ONLY via the admin connection (ADR 0021) -- the
# default `pgsql` connection deliberately lacks the privilege to alter
# RLS/create policies/triggers.
php artisan migrate --database=pgsql_admin
php artisan db:seed --class=CapabilityAndRoleSeeder

npm install && npm run build   # or: npm run dev
php artisan serve --no-reload   # --no-reload is REQUIRED in Docker --
                                  # without it, artisan serve silently
                                  # drops most container env vars for
                                  # the actual request-handling process
                                  # (see infrastructure/docker/platform.Dockerfile)
                                  # `artisan serve` itself is local-dev-only --
                                  # never the production server.

# Quality gates (apps/platform)
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=512M
npm run type-check
npm run lint            # ESLint
npm run format:check    # Prettier

# Tests run against REAL PostgreSQL, not SQLite (ADR 0024) -- create
# a dedicated school_os_test database first (once per fresh database;
# infrastructure/docker/postgres/init/*.sql do this automatically on a
# fresh docker-compose volume):
#   createdb -U school_os school_os_test
#   psql -U school_os -d school_os_test -f ../../infrastructure/docker/postgres/init/01-roles.sql
#
# DO NOT run a raw `migrate:fresh`/`migrate --env=testing` by hand to
# (re)populate it -- `--env=testing` alone does NOT guarantee
# DB_DATABASE points at school_os_test (this project has no
# .env.testing file; see App\Support\Testing\TestDatabaseGuard's
# docblock for the exact incident this caused, Phase 0C.3A). Use the
# canonical, fail-closed reset command instead (see rule 78):
#
#   apps/platform/bin/safe-test --reset-db
#
# ...which passes the same explicit values documented below and
# verified by TestDatabaseGuard -- do this by hand only if the script
# is unavailable for some reason:
#
#   APP_ENV=testing \
#   DB_HOST=... DB_DATABASE=school_os_test \
#   DB_USERNAME=school_os_app DB_PASSWORD=school_os_app_local_only_password \
#   DB_ADMIN_USERNAME=school_os DB_ADMIN_PASSWORD=school_os \
#   php artisan platform:test-db-reset --force
#     (or: composer test:reset-db, an identical thin wrapper --
#     same required env vars, same guard, same seed set)
#
# It verifies test-database identity (aborting otherwise -- the SAME
# check App\Providers\AppServiceProvider::register() runs on every
# boot when APP_ENV=testing), migrates fresh via pgsql_admin, and runs
# the FULL canonical seed set (Database\Seeders\DatabaseSeeder --
# never just CapabilityAndRoleSeeder alone; a partially-seeded test DB
# silently breaks unrelated suites, e.g. every AI Gateway test needs
# ServiceIdentitySeeder too).
#
# The canonical way to RUN the suite is (see rule 78):
apps/platform/bin/safe-test
#   (or: composer test:safe, from a host with Composer installed)
#
# `composer test`/`php artisan test` remain correct too -- phpunit.xml's
# <env ... force="true"/> block (rule 79) now makes DB_DATABASE=school_os_test
# (and every other safety-relevant value) authoritative regardless of
# ambient shell/container state, closing the exact "ambient
# DB_DATABASE=school_os silently wins" class of incident this comment
# used to warn about by hand. bin/safe-test is preferred anyway because
# it also protects the raw artisan commands force="true" cannot reach
# (platform:test-db-reset, platform:env-diagnostic), validates resolved
# configuration before running anything, and raises the PHP CLI
# memory_limit the full suite needs. See
# docs/architecture/TEST-ENVIRONMENT-AND-WORKTREE-SAFETY.md for the
# full incident history and mechanism.
composer test

# AI Gateway (services/ai)
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements-dev.txt
ruff check . && mypy app && pytest -q
uvicorn app.main:app --reload --port 8100

# Contracts -> shared types (packages/shared-types)
npm install
npm run generate && npm run type-check
```

## When in doubt

Prefer the option documented in an existing ADR
(`docs/architecture/adr/`) over inventing a new pattern. If no ADR
covers the situation, raise it rather than guessing — this codebase is
still small enough that a wrong early pattern is expensive to unwind
once several modules copy it.
