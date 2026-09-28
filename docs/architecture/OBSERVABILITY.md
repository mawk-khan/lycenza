# School OS — Health, Scheduler, Queue & Observability

Phase 0C.4. This document is the operational reference for answering
one question: *is School OS alive, ready, processing asynchronous
work, scheduling jobs, accumulating backlogs, or degraded — and how
would an operator find out?* It describes what is **actually
implemented**, not a target design. See `docs/architecture/RELIABILITY.md`
for API idempotency and the rate-limiting/job-timeout invariants this
checkpoint added there, and ADR 0015 for why tracing here is a
lightweight custom abstraction rather than a full OpenTelemetry SDK.

## Liveness vs. readiness

Two deliberately separate, unauthenticated endpoints
(`App\Http\Controllers\Api\Internal\HealthController`):

- **`GET /api/health/live`** — "is this process alive enough that
  restarting it is not immediately warranted?" Always returns
  `{"status":"ok"}`, unconditionally. Never checks PostgreSQL, Redis,
  the AI Gateway, or a customer's webhook endpoint.
- **`GET /api/health/ready`** — "can this instance safely receive
  normal application traffic?" Checks PostgreSQL and Redis only
  (`App\Support\Observability\OperationalStatusService::readiness()`),
  each against a fresh, dedicated connection bounded by
  `observability.readiness_check_timeout_ms` (500ms default) so one
  slow dependency cannot hang the whole probe. Returns `200 {"status":
  "ok"}` when healthy, `503 {"status":"degraded"}` otherwise.

Object storage, the domain-event outbox, webhook delivery backlog, and
the AI Gateway are **deliberately excluded from readiness** — a
customer's own webhook endpoint being down, or the optional AI Gateway
being unreachable, must never make the core ERP "unready." Both
responses leak no infrastructure detail (no hostnames, database names,
credentials, or stack traces) — see `HealthControllerTest` for the
proof.

**Both routes are registered with `withoutMiddleware([ResolveSchoolContext::class,
DevOnlySchoolHeaderResolver::class])`** (`routes/api.php`). This is
not incidental: both middleware are appended to the whole `api`
middleware group (`bootstrap/app.php`), and `ResolveSchoolContext`
unconditionally runs a `SchoolDomain` lookup against PostgreSQL on
**every** `/api/*` request. A real local live proof for this
checkpoint (stopping the `postgres` container under `php artisan serve`)
found that, left in place, this made `/api/health/live` itself hang
indefinitely during a PostgreSQL outage — precisely the failure mode
liveness exists to survive. Any future route that must stay reachable
independent of tenant resolution should follow the same pattern rather
than assume the `api` group is always safe to sit under unmodified.

The same live proof also found that a bare `PDO::ATTR_TIMEOUT` does
**not** bound the pdo_pgsql driver's initial TCP connection attempt —
only libpq's own `connect_timeout` DSN parameter does
(`OperationalStatusService::database()` now sets both, DSN-first,
floored at 2 seconds per libpq's own minimum). Neither bounds DNS
resolution time: a host that stops resolving entirely (e.g. a
container removed from Docker's embedded DNS, not merely refusing
connections) can still block for the OS resolver's own timeout. This
residual gap is accepted, not fixed — bounding system DNS resolution
portably from PHP has no clean solution, and a real production outage
is far more likely to look like "host resolves, port refuses/times
out" (which IS bounded) than "the hostname stops existing."

The FastAPI AI Gateway mirrors this split (`services/ai/app/core/health.py`):
`GET /health/live` always `{"status":"ok"}`; `GET /health/ready` checks
only that `settings.service_token` is configured (no Laravel call, no
model-provider call).

## Internal diagnostics

**`GET /api/internal/operations/status`**
(`App\Http\Controllers\Api\Internal\OperationsController`) is the
authenticated counterpart — every component
`OperationalStatusService::full()` knows how to check, gated by the
`platform.operations.view` capability (platform-scoped only; no School
role can ever grant it — the database trigger behind
`platform_role_assignments`/`membership_role_assignments` enforces
this, not just application code). Human platform-capability auth, not
internal-service auth: no service identity has a legitimate reason to
read this today.

**`php artisan platform:operations-status [--json]`**
(`App\Console\Commands\ShowOperationsStatus`) is the CLI equivalent for
an operator with shell access but no HTTP session — same service, same
component set, no separate diagnostic logic to keep in sync.

## Operational status model

`App\Support\Observability\OperationalStatus` — four states:
`Healthy`, `Degraded`, `Unhealthy`, `Unknown`. `worstOf(array $statuses)`
aggregates many component checks into one overall status using a fixed
severity order (`Unhealthy > Degraded > Unknown > Healthy`) — used by
both readiness (a small essential subset) and internal diagnostics
(every component). Every individual check
(`App\Support\Observability\ComponentStatus`) carries `component`,
`status`, an optional `reason`, and a `detail` array — `detail`/`reason`
may carry operationally-useful facts (an age in seconds, a pending
count) but never a hostname, database name, credential, or stack trace,
regardless of audience.

## Scheduler heartbeats

`App\Support\Observability\SchedulerHeartbeatRecorder` wraps the
`scheduler_heartbeats` table (`name` primary key,
`last_run_at`/`last_success_at`/`last_error`). One small, generically
named table is reused for **both** scheduled-command heartbeats
(`outbox-dispatch`, `webhook-deliveries-redispatch` — `routes/console.php`)
**and** queue-processing heartbeats (`queue:default`, `queue:integrations`
— recorded by `App\Listeners\RecordQueueHeartbeat` on Laravel's
`JobProcessed`/`JobFailed` events, throttled to one write per 10 seconds
per queue) — both are "a named operational task reported it is alive,"
the same shape already models both. A heartbeat is `stale` once
`last_success_at` is older than `observability.scheduler_stale_after_seconds`
(300s — five scheduler intervals of margin) or
`observability.queue_stale_after_seconds` (600s for queues, which are
legitimately burstier than the once-a-minute scheduler).

## Queue health model

`OperationalStatusService::queues()` checks `QueueName::Default` and
`QueueName::Integrations` (`App\Support\Observability\QueueName` — a
6-case enum; `Notifications`/`Ai`/`Low`/`Critical` are reserved names
with no dispatched jobs yet, formalizing the topology without
speculative infrastructure). For each queue:

- **Pending count** — `Queue::size($queue)`. Deliberately NOT "oldest
  pending job age," which is unreliable/undefined for the Redis queue
  driver used in this project.
- **Failed count** — `failed_jobs` table, filtered by queue.
- **Staleness** — the queue's own processing heartbeat, per above.

A queue with **zero pending jobs is always Healthy** (or Degraded if it
has failed jobs), regardless of heartbeat age — there is nothing to
have processed recently, so an idle queue is never "stalled." A queue
with pending work IS "stalled" (Unhealthy) once its heartbeat is stale
or has never recorded a success. See `QueueHealthTest` for all four
cases.

## Failed-job visibility

`App\Support\Observability\FailedJobInspector` (read-only) groups
`failed_jobs` by queue with counts/oldest-failure, and lists recent
failures with a best-effort School id / correlation id recovered from
the serialized job payload via a narrow property-name regex — it
deliberately never `unserialize()`s the stored command blob (no reason
to execute arbitrary `__wakeup` on our own job classes for a listing).
`php artisan platform:failed-jobs [--limit=20] [--summary]`
(`App\Console\Commands\InspectFailedJobs`) is the CLI surface. Retrying
or deleting a failed job stays Laravel's own `queue:retry`/`queue:forget`
— not reinvented here.

**Payload privacy** (section 22): every queued job in this codebase
carries only ids/references, never full models or free-form user
content — `DeliverWebhookJob`/`ProcessOutboxEventJob` carry only
`schoolId`/`deliveryId`/`eventId` strings; `RecordSchoolAuditPingJob`'s
one free-text constructor argument is only ever called with a short
fixed diagnostic literal (`'ping'`, `'first'`, ...) in tests, never
user-controlled input. A failed job's payload can therefore be surfaced
by the tools above without a separate redaction pass.

## Job timeout / retry invariants

Moved to `docs/architecture/RELIABILITY.md` ("Job timeout invariants")
since it is a correctness property of the queue architecture, not
specifically an observability concern: every job declares `$tries`/
`$timeout` explicitly, `$timeout` always stays well under every queue
connection's `retry_after` (90s), and domain-level retry (e.g.
`DeliverWebhookJob`'s delivery-row-driven backoff) never doubles up
with queue-level retry.

## Distributed locks

`App\Support\Concurrency\TenantLock` wraps Laravel's own
`Cache::lock()` — never a hand-written Redis algorithm. School-scoped
locks are namespaced `school:{school_id}:lock:{operation}`; a central
(cross-tenant) lock is namespaced `platform:lock:{operation}`, a
distinct prefix so the two can never collide even with the same
operation name. See `TenantLockTest` for the isolation proof: two
Schools holding "the same" named lock never contend with each other,
while the same School+operation genuinely does.

## Correlation & tracing semantics

Five distinct identifiers, never conflated:

- **`request_id`** — one HTTP request (`App\Http\Middleware\AssignRequestId`,
  pre-existing).
- **`correlation_id`** — a logical workflow; starts equal to
  `request_id` for a fresh top-level request
  (`App\Http\Middleware\ResolveSchoolContext`) and propagates unchanged
  through outbox → queue → webhook/AI calls (`TenantScoped`,
  `ProcessOutboxEventJob`) — never a fresh id minted at each layer.
- **`causation_id`** — the immediately preceding cause (existing domain
  event fields; unchanged this checkpoint).
- **`trace_id`/`span_id`** — W3C Trace Context-compatible
  (`App\Support\Observability\TraceContext`), diagnostic only. **Never**
  used for authorization anywhere in this codebase.

`TraceContext` is a from-scratch, ~90-line implementation of the W3C
`traceparent` header format (`{version}-{trace-id}-{span-id}-{flags}`),
not a full OpenTelemetry SDK integration — ADR 0015 explicitly
anticipated this exact tradeoff ("if full native OpenTelemetry
integration ... is immature or introduces disproportionate complexity,
implement a clean tracing abstraction compatible with W3C Trace
Context"). `App\Http\Middleware\AssignTraceContext` parses an inbound
`traceparent` header (or starts a fresh trace), stores it on
`TenantContext`, and echoes it back on the response. `AiGatewayClient`
forwards a child span's `traceparent` on every Laravel → FastAPI tool
call; the FastAPI side (`app/core/trace.py`, a structurally identical
Python port) forwards its own child span back on the durable
audit-write-back call to Laravel. A trace-id survives a
Laravel → FastAPI → Laravel round trip; a **new span-id is always
minted locally** at each hop — a service never adopts a caller-supplied
span-id as if it were its own.

## Structured logging & sanitization

`App\Support\Observability\LogSanitizer` is the backstop, not the
primary control — callers are still expected to pass only minimal,
already-safe metadata. It recursively redacts any array key containing
(case-insensitive, `-`/space-normalized) `password`, `token`,
`authorization`, `secret`, `api_key`, `apikey`, `access_key`,
`private_key`, `credential`, or `signature`, replacing the value with
`[redacted]` — deliberately broad substring matching over an exact
allowlist a new field name could silently slip past.

## Error reporting

`App\Support\Observability\ErrorReporter` (interface) /
`LogErrorReporter` (default implementation, bound in
`AppServiceProvider`) emit one structured `application_error` log line
per reported exception, sanitized via `LogSanitizer`, with a **stable
fingerprint** — `{exception_class}:{component}:{operation}`,
deliberately **not** a stack-trace hash, so the same logical failure at
a different call depth or line number still groups together. No
commercial error-tracking vendor is bound; a future `ErrorReporter`
implementation (Sentry, Bugsnag, ...) can be swapped in without any
call site changing.

## Metrics abstraction

`App\Support\Observability\MetricsRecorder` (interface: `counter`/
`gauge`/`timing`) / `LogMetricsRecorder` (default, structured `metric`
log lines — the same shape `App\Support\Idempotency\IdempotencyMetrics`
established in Phase 0C.2, now delegating to this shared abstraction).
**High-cardinality safety rule**: a metric label is never a
`request_id`, `correlation_id`, `event_id`, `delivery_id`, `user_id`,
or `school_id` — those belong in structured *logs*, which are queried
per-instance, never in a *metrics* label, which a time-series backend
would otherwise be asked to index one series per unique value of
forever. No commercial metrics vendor/exporter is bound here.

## Rate limiting

Covered in full in `docs/architecture/RELIABILITY.md` ("Rate limiting
interaction") — six named limiters
(`App\Providers\RateLimiterServiceProvider`: `login`, `public-api`,
`school-api-mutations`, `webhook-admin`, `internal-service`,
`internal-diagnostics`), why `throttle:*` middleware's ACTUAL runtime
position differs from its declared position in `routes/api.php`
(Laravel's framework middleware-priority list), and why every
tenant-aware limiter key is read from the route parameter rather than
`TenantContext` as a result.

## AI Gateway is optional, by design

`OperationalStatusService::aiGateway()` calls the FastAPI Gateway's own
`/health/live`; an unreachable Gateway is reported `Degraded`, **never**
`Unhealthy`, and is excluded from `readiness()` entirely. A customer's
own webhook endpoint being persistently down is the same story —
`webhooks()` caps its status at `Degraded` regardless of how old the
retry backlog gets (never *our* unhealthy). Both are internal
diagnostics signals only, never a public readiness failure.

## Production observability contract (ADR 0051, Phase 0O.5)

ADR 0051 resolves O12 and fixes what production telemetry must look like;
Phase 0O.5A implements the repository side. In short:

- **Logs:** one JSON object per line on stderr with a fixed, bounded
  schema and stable `event` codes; sanitization at one central boundary (a
  Monolog processor installed by a logging `tap`, applying `LogSanitizer`
  to context and `extra`), extended key and value redaction; exceptions
  logged by class/`error_code`/`sqlstate`, never raw SQL or HTTP-client
  text; stack traces without arguments. Retention 30 days; Confidential.
- **Request ids:** inbound `X-Request-Id` accepted only if it matches
  `^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$`, otherwise replaced by a new UUID.
  The correlation model above is unchanged.
- **Metrics:** OpenMetrics on a private port of the `web` role, bearer
  scrape token, scraped by a deployment collector; scrape-time gauges from
  PostgreSQL/queues and best-effort Redis counters; a closed label
  allowlist (never School/user/record/request ids). Retention 90 days;
  Confidential. No tracing in v1.
- **Heartbeats:** process-class, not replica: scheduler task heartbeats
  (all tasks) and per-queue canary jobs for `default`, `integrations` and
  `notifications`.
- **Alerts:** SEV-1/2/3 catalog OBS-01…OBS-41 (OBS-39–OBS-41, Phase 0O.10A / ADR 0056: account recovery — see "Account recovery" below; OBS-31–OBS-38, Phase 0O.9A / ADR 0055: production email — see "Production email" below; OBS-27: ADR 0053 service authentication; OBS-28–OBS-30, Phase 0O.8A / ADR 0054: a custom domain suspended — SEV-3; a custom-domain certificate ≤ 21 days from expiry — SEV-3, ≤ 7 days — SEV-2; custom-domain checks indeterminate for 3 days — SEV-3; runbook `docs/operations/CUSTOM-DOMAINS.md`; none pages as SEV-1 and none touches readiness) with thresholds derived from
  this file's cadences and ADR 0050's recovery objectives.
- **Operations status** stays the operator view over the same checks,
  complete (all heartbeats, all three queues, Communications, Automation,
  outbox stale/failed, reconciliation) and degrading per component instead
  of failing when PostgreSQL is down.

Verified debt this contract assigns to 0O.5A: `RecordQueueHeartbeat` is
registered twice (explicit `Event::listen` plus listener discovery of its
`handle*` methods); operations status watches only two heartbeats and two
queues and is unguarded; `LogSanitizer`/`ErrorReporter` have no
production caller; raw exception text reaches logs and
`scheduler_heartbeats.last_error`; inbound request ids are unbounded.

## Implemented (Phase 0O.5A)

The contract above is implemented; see the ADR 0051 implementation
amendment for the exact field names, label keys and metric names. Quick
reference:

- `App\Support\Observability\Logging\*` — tap, processor, JSON formatter;
  `LogSanitizer`, `SafeException`, `ErrorReporter` (handled exceptions).
- `App\Support\Observability\Metrics\*` — `MetricCatalog`,
  `StoreMetricsRecorder`, stores, `MetricsExporter`, `MetricsEndpoint`,
  `DeploymentEvidence`; front controller `apps/platform/metrics/index.php`
  (private listener only).
- `App\Support\Observability\Signals\OperationalSignals` — the shared
  readers behind operations status and the scrape.
- `App\Support\Observability\Alerts\*` — the 26 alerts,
  `platform:alerts-export`.
- `WorkerCanaryJob`, `platform:dispatch-worker-canaries`,
  `RecordScheduledTaskRun`, `RecordQueueHeartbeat` (registered once).

The queue-health model changed: with a per-minute canary on every required
queue, a stale `queue:{name}` heartbeat now means the worker class is not
processing (`stalled`), whether or not other work is waiting.

## Custom School domains (ADR 0054, Phase 0O.8A)

Closed labels only -- never a hostname, School, domain id, token or ticket:

| Metric | Type | Labels |
|---|---|---|
| `lycenza_host_responses_total` | counter | `outcome` = `misdirected` (421), `not_served` (health on a named non-platform host), `surface_not_found` (outside the School surface), `alias_redirect` (308) |
| `lycenza_domain_checks_total` | counter | `check` = `ownership`/`routing`/`tls`; `outcome` = `match`/`mismatch`/`absent`/`indeterminate`/`pass`/`fail` |
| `lycenza_domain_transitions_total` | counter | `to` = the target lifecycle state |
| `lycenza_school_domains` | gauge (scrape) | `state` |
| `lycenza_domain_certificate_min_days_remaining` | gauge (scrape) | none (absent when no active certificate is recorded) |
| `lycenza_domain_indeterminate_max_age_seconds` | gauge (scrape) | none |

Log lines `domains.check` / `domains.transition` carry the domain id and
closed codes; `session_handoff.failed` carries a closed outcome only. The
scheduled task `domains-check` is a minute-cadence heartbeat task. One
School's domain problem never changes readiness.

## Production email (ADR 0055, Phase 0O.9A)

Closed labels only (`message_class` is the closed purpose catalog; never a
School, recipient, domain, provider message id, internal message id or
template):

| Metric | Type | Labels |
|---|---|---|
| `lycenza_email_messages_total` | counter | `message_class`, `outcome` (queued, submitted, delivered, deferred, bounced, complained, suppressed, failed, cancelled) |
| `lycenza_email_submission_attempts_total` | counter | `message_class`, `outcome` (accepted, transient_failure, permanent_failure, auth_failure) |
| `lycenza_email_webhook_requests_total` | counter | `outcome` (accepted, unauthenticated, too_large, malformed, duplicate, disabled) |
| `lycenza_email_pending_messages` | gauge (scrape) | `message_class` |
| `lycenza_email_oldest_pending_age_seconds` | gauge (scrape) | `message_class` |
| `lycenza_email_last_event_timestamp_seconds` | gauge (scrape; only with an event adapter) | — |

**Alerts:**
- OBS-31: critical email waiting more than 1800 s (SEV-2).
- OBS-32: standard email waiting more than 1800 s (SEV-3).
- OBS-33: submission failure ratio (SEV-3; operator value).
- OBS-34: provider authentication/TLS refusal (SEV-2).
- OBS-35: event feed stale for 24 h while mail is submitted (SEV-3).
- OBS-36: hard-bounce spike (SEV-3; operator value).
- OBS-37: complaint spike (SEV-2; operator value).
- OBS-38: webhook authentication failures (SEV-3; operator value).

None pages as SEV-1. The runbook is
`docs/operations/EMAIL-DELIVERABILITY.md`.

**Operations Status and recovery:**
- The `email` Operations Status component is `Degraded` at worst:
  `disabled`, `sending_not_verified`, `provider_auth_failure`, `backlog`,
  `events_stale`. It is never part of readiness and never calls the
  provider.
- The email backlog is mirrored into `operational_work_backlog` as
  `email:<purpose>`.
- Scheduled tasks: `email-messages-redispatch` (every minute; recovery
  source `email`) and `email-prune` (daily).

## Account recovery (ADR 0056, Phase 0O.10 — contract; built in Phase 0O.10A)

- **Metrics** (closed labels only; never email, user, School, selector, IP
  or token):
  - `lycenza_account_recovery_requests_total{outcome}`: `accepted`,
    `rate_limited_identity`, `dispatch_failed` (the IP and global 429s are
    route throttles, visible as HTTP 4xx);
  - `lycenza_account_recovery_issuance_total{outcome}`: `issued`,
    `unknown`, `ineligible`, `active_limit`, `email_unavailable`,
    `disabled` (aggregate, operator-only; never per account);
  - `lycenza_account_recovery_resets_total{outcome}`: `succeeded`,
    `invalid`, `policy_rejected`;
  - `lycenza_account_recovery_enabled{state="enabled"|"available"}`.
- **Alerts** (runbook `docs/operations/ACCOUNT-RECOVERY.md`; none pages as
  SEV-1, none touches readiness):
  - OBS-39: request spike (SEV-3; operator value
    `ALERT_ACCOUNT_RECOVERY_REQUESTS_PER_HOUR`);
  - OBS-40: invalid-reset spike (SEV-3; operator value
    `ALERT_ACCOUNT_RECOVERY_INVALID_RESETS_PER_HOUR`);
  - OBS-41: recovery enabled while critical email is unavailable (SEV-2,
    for 5 minutes).

  Recovery-email backlog is OBS-31 (critical class).
- **Operations Status:** the `account_recovery` component (`disabled`,
  `unavailable`, healthy). Degraded at worst; never readiness.
- **Logs:** `auth.account_recovery.reset` (outcome only),
  `auth.account_recovery.dispatch_failed`, `auth.session_revoked`
  (reason only). `LogSanitizer` redacts the recovery keys and any recovery
  link or 43-character fragment.
- **Scheduled task:** `account-recovery-prune` (hourly).
