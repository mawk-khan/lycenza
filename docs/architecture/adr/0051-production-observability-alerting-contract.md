# ADR 0051: Production Observability & Alerting Contract (Phase 0O.5)

- Status: Accepted (contract only — nothing implemented, exported,
  collected or activated; Phase 0O.5A implements the repository side).
- Date: 2026-09-26
- Resolves: Phase 0O decision **O12** (production observability backend
  and retention) (`docs/architecture/PHASE-0O-READINESS.md` §8).
- Amends: **ADR 0015** (OpenTelemetry-compatible observability) — see §2.
  Nothing in ADR 0015 is withdrawn.
- Builds on: ADR 0050 (production infrastructure, O10 recovery objectives,
  process manifest), ADR 0023 (AI context token), ADR 0049 (API/browser
  hardening), `docs/architecture/OBSERVABILITY.md`,
  `docs/architecture/RELIABILITY.md`, `docs/operations/*`.

## 1. Context

Phase 0C.4 built the observability *primitives* (request/correlation ids,
W3C `traceparent`, the `MetricsRecorder` and `ErrorReporter` abstractions,
`LogSanitizer`, named heartbeats, liveness/readiness, operations status).
Phase 0O.4A made queued work recoverable from PostgreSQL and produced
production images whose logs go to stderr. Nothing yet turns these into
signals an operator can alert on: logs are plain text, the only metric is
an idempotency counter written as a log line, and no alert exists. O10
recovery objectives (ADR 0050 §9) cannot be protected without alerts.

This ADR fixes what the application must emit, what the deployment must
provide, and which conditions are alerts — without choosing a vendor.

## 2. Reconciliation with ADR 0015

ADR 0015 standardised on the OpenTelemetry *data model* and named the
request id as the correlation key; it chose no backend and required no
particular export path. This ADR keeps both and makes three v1 choices
explicit:

| ADR 0015 said | ADR 0051 v1 decision | Why this is compatible |
|---|---|---|
| OTel data model for traces, metrics, logs | **Metrics** in the OpenMetrics text format on a private scrape endpoint (§9); **logs** as JSON lines on stdout/stderr (§5); **no traces** in v1 (owner decision 4) | OpenMetrics maps 1:1 onto the OTel metric model and is ingested natively by an OpenTelemetry Collector (Prometheus receiver) or any Prometheus-compatible system; JSON log fields are named for direct mapping to OTel log attributes. The OTel *model* stays the compatibility target; OTLP *push* is not required in v1. |
| Request id is the correlation key | Kept, and bounded/validated (§7) | — |
| Add OTel SDKs to Laravel and the Gateway | **Not required in v1.** The W3C `traceparent` primitive (Phase 0C.4) stays for correlation; spans are not exported | Owner decision 4: tracing is not required in v1; adding an SDK only because a framework supports it is rejected (CLAUDE.md rule 2). A later decision may add traces with its own retention/classification (§17). |

ADR 0015 is therefore **amended, not superseded** (an amendment note is
added to it).

## 3. Decision — O12 (owner decisions, frozen)

1. **Backend model:** a vendor-neutral external observability backend.
   The **application** emits structured sanitized logs, exposes
   low-cardinality operational metrics, provides health/readiness signals,
   defines alert conditions and their meaning, and provides runbooks. The
   **deployment** collects telemetry, stores it durably, evaluates alerts
   and sends notifications. No vendor is chosen (not Datadog, Grafana
   Cloud, New Relic, Splunk, CloudWatch, Azure Monitor, Google Cloud
   Monitoring or any other).
2. **Log retention: 30 days** for operational application logs.
3. **Metric retention: 90 days** minimum for operational metrics (longer
   inexpensive aggregate retention may be added operationally).
4. **Distributed tracing: not required in production v1.**
5. **Metric label privacy:** no high-cardinality or tenant/person
   identifier as a label — never `school_id`, `user_id`, `student_id`,
   `employee_id`, `membership_id`, email, any domain record id,
   `request_id`, `correlation_id`, `event_id`, `delivery_id`, key/client
   ids, URLs, hostnames of customer endpoints or source IPs. Only the
   bounded dimensions enumerated in §10.

Retention values are operational telemetry only. They do **not** change
Platform audit retention, School audit retention, legal/business record
retention or backup retention (ADR 0050 §9).

## 4. Current state (verified 2026-09-26 at `79cfd5a`)

### 4.1 Signal inventory

| Signal | Current producer | Current storage | Current consumer | Gap |
|---|---|---|---|---|
| Laravel logs | Monolog; production image `LOG_CHANNEL=stderr` | container stderr, **plain text** (`LineFormatter`; `LOG_STDERR_FORMATTER` unset) | none | not JSON; no central sanitization; raw exception text (§4.2) |
| Log context | Laravel `Context` (`TenantContext`: `request_id`, `correlation_id`, `trace_id`, `span_id`, `school_id`, `campus_id`, `actor_id`; `elevation_id`, `api_client_id`) | appended to every record | none | good content, unbounded `request_id` |
| AI Gateway logs | Python `logging` (`ai.complete`, `laravel_audit.*`) + uvicorn | stderr | none | **no logging configuration**: root level WARNING drops `ai.complete`; `extra=` fields are not rendered; not JSON |
| Request id | `AssignRequestId` (accepts any inbound `X-Request-Id`, else UUID) | request attribute, `Context`, audit `request_id` (`varchar(255)`), AI context token | logs, audit review | unbounded, unvalidated; >255 chars would fail an audited write (by inspection) |
| Correlation / trace | `ResolveSchoolContext`, `TenantScoped`, `AssignTraceContext`, `TraceContext` (PHP + Python) | `Context`, outbox rows, `traceparent` | logs | adequate; no span export (by design, §2) |
| Web liveness/readiness | `HealthController` (`/api/health/live`, `/api/health/ready`: PostgreSQL + Redis, bounded probes, maintenance-aware since 0O.4A) | — | load balancer, orchestrator | none for the contract; not a metric |
| Gateway liveness/readiness | `services/ai/app/core/health.py` (`/health/live`, `/health/ready`: configuration only) | — | orchestrator | none |
| Scheduler task heartbeats | `SchedulerHeartbeatRecorder` in `outbox-dispatch`, `webhook-deliveries-redispatch`, `communication-deliveries-redispatch`, `communications-publish-scheduled`, `automation-executions-redispatch` | `scheduler_heartbeats` (PostgreSQL) | `OperationalStatusService` watches **only** `outbox-dispatch` and `webhook-deliveries-redispatch` | three minute-cadence heartbeats unwatched; `expire-school-elevations` and the daily prune tasks record none; `last_error` stores raw exception text |
| Queue heartbeats | `RecordQueueHeartbeat` (`JobProcessed`/`JobFailed`, throttled 10 s, `queue:{name}`) | `scheduler_heartbeats` | `OperationalStatusService` for `default`, `integrations` **only** | **registered twice** (§4.3); `notifications` unwatched; an idle queue gives no signal |
| Queue depth | `Queue::size()` in `OperationalStatusService` | Redis | operations status | no oldest-age; failures read as 0 |
| Failed jobs | `failed_jobs` table, `FailedJobInspector`, `platform:failed-jobs` | PostgreSQL | CLI, status | no metric/alert |
| Outbox | `domain_event_outbox` (`pending`/`dispatched`/`failed`, `processed_at` since 0O.4A), `OutboxReconciler` | PostgreSQL; reconciler counts in a log line | operations status (**`pending` only**) | stale-dispatched and `failed` rows not reported; reconciler outcomes not metrics |
| Redis-loss reconciliation | `OutboxReconciler`, stale-`pending` redispatch branches, `platform:recover-queued-work` | log lines | none | no metrics |
| Webhooks | `webhook_deliveries` / `_attempts`, `webhook.delivery.*` log lines | PostgreSQL | operations status (backlog/age, walks every School) | no metrics; overdue-eligibility not distinguished from legitimate backoff |
| Communications | `communication_deliveries` / `_attempts`, publish-scheduled | PostgreSQL | **none** in operations status | unwatched |
| `notifications` queue | `ProcessCommunicationDeliveryJob` (0O.4A clarified; not reserved) | Redis | **none** | unwatched |
| Automation | `automation_executions` / `_attempts`, own redispatch | PostgreSQL | none | unwatched |
| Object storage | `OperationalStatusService::storage()` (Degraded only), `platform:verify-storage` | — | operations status, operator | no failure metrics |
| PostgreSQL / Redis | readiness probes | — | load balancer | engine metrics are the deployment's (§12) |
| Backups | provider (not active) | — | — | no signal exists; the application cannot originate one (§13) |
| Restore drills | `platform:verify-restore`, `RESTORE-DRILL-RECORD.md` | a document | operator | no metric; no drill performed |
| Metrics | `MetricsRecorder` → `LogMetricsRecorder`; **only** `IdempotencyMetrics` (`idempotency_*_total`) | log lines | none | no exporter, no process/queue/backlog metrics |
| Errors | `ErrorReporter`/`LogErrorReporter` bound; **no production caller**; framework handler reports unhandled exceptions with message and trace | log lines | none | raw messages; traces may carry argument values (§4.2) |

### 4.2 Readiness-audit debt re-checked (not copied)

| Finding (Phase 0O readiness audit / 0L closeout) | Status at `79cfd5a` |
|---|---|
| Laravel logs are plain text | **Still true** (stderr, line format) |
| Metrics exist only as log lines | **Still true** (one metric family) |
| `LogSanitizer` unused by production code | **Still true** — only `LogErrorReporter` (itself uncalled) and the test-environment diagnostic command use it |
| Raw exception messages can reach logs | **Still true**: `platform:outbox-dispatch`, `-webhook-deliveries-redispatch`, `-communication-deliveries-redispatch`, `communications:publish-scheduled` log `$e->getMessage()`; `ProcessOutboxEventJob` logs consumer error text; `RecordQueueHeartbeat` and those commands store it in `scheduler_heartbeats.last_error`; the framework reporter logs unhandled exceptions (a `QueryException` message contains SQL with bound values). **New finding:** the production image runs with `zend.exception_ignore_args = 0`, so logged stack traces can carry argument values |
| Inbound request ids unbounded/unvalidated | **Still true** (plus the `varchar(255)` audit consequence above) |
| Duplicate heartbeat listener | **Still true** — `php artisan event:list` shows `RecordQueueHeartbeat@handleProcessed` and `@handleFailed` each registered **twice** (explicit `Event::listen` in `AppServiceProvider` plus Laravel's listener discovery, which registers every public `handle*` method in `app/Listeners`) |
| Operations status omits the Communication heartbeat | **Still true** (and the Automation heartbeat) |
| Operations status omits the `notifications` queue | **Still true** |
| Operations status 500s when the database is down | **Still true** — `database()`/`redis()`/`storage()` are guarded, but `scheduler()`, `queues()`, `outbox()` and `webhooks()` query PostgreSQL unguarded; the CLI (`platform:operations-status`) crashes the same way |
| `domain_event_outbox.status` never `failed` | **Resolved by 0O.4A**: `ProcessOutboxEventJob::failed()` marks events whose retries are exhausted, and `OutboxReconciler` marks events `failed` after 25 reconciliations. Not carried forward as debt; `failed` rows now need *reporting* (§11). |

## 5. Structured logging contract

**Format.** In production every application log record is **one JSON
object per line** on the process's stderr (the image already sets
`LOG_CHANNEL=stderr`). The container runtime collects stdout/stderr; the
application never ships logs over the network itself (§16).

**Fixed, bounded schema.** Top-level keys are drawn from this list only;
anything else goes under `ctx` after sanitization (§6):

| Field | Content |
|---|---|
| `ts` | RFC 3339 UTC with milliseconds |
| `level` | `debug`/`info`/`notice`/`warning`/`error`/`critical`/`alert`/`emergency` |
| `service` | `platform` or `ai-gateway` |
| `role` | process role: `web`, `worker`, `scheduler`, `console`, `gateway` |
| `env` | `APP_ENV` / `ENVIRONMENT` |
| `event` | stable dotted event code (`platform.outbox_dispatch.completed`, `webhook.delivery.failed`, `http.request.rejected`, `application.exception`, …) — automation keys on this, never on prose |
| `message` | optional human text written by the developer; never interpolated untrusted input or exception text |
| `request_id`, `correlation_id`, `trace_id` | correlation (§7) |
| `school_id`, `actor_id`, `elevation_id`, `api_client_id` | identifiers already carried by `Context`; ids only, never names/emails |
| `route` / `command` / `job` | route **name**, artisan command name or job class basename (never a URL with query string) |
| `queue` | one of the bounded queue names |
| `outcome`, `error_code` | bounded vocabularies |
| `exception_class`, `sqlstate` | for exceptions (§6.3) |
| `duration_ms`, `count`, `status_code`, `attempt` | numbers |
| `ctx` | explicit, allowlisted, sanitized extra fields |

Arbitrary request payloads, request/response bodies, headers, cookies and
model serialisations are never log fields. Local/DDEV keeps the readable
line format; the JSON formatter is selected by the production channel
configuration, and tests assert it.

**Levels.** `error` and above are reserved for conditions an operator may
need to act on; expected domain refusals (validation, authorization
denials, rate limits) are `info`/`notice` with an `outcome` code. Alerts
never key on "any error line" (§14).

## 6. Sanitization, redaction and exception contract

### 6.1 Central boundary

Sanitization happens at **one central boundary every record passes
through**: a Monolog processor installed by a Laravel logging `tap` on the
production channel(s) (the repository-consistent mechanism — `tap` is how
Laravel customises a channel without touching call sites), which applies
`LogSanitizer` to the record's context **and** `extra` (where `Context`
data lands) and enforces the §5 schema. Callers must still pass only
minimal, already-safe metadata; the processor is defence in depth, never
the only control. The same processor is used by `ErrorReporter`, so there
is exactly one sanitizer. The Gateway gets the equivalent `logging`
configuration (a JSON formatter plus a redacting filter) in its own code.

### 6.2 Never logged

Passwords and password hashes; session ids and cookies; Sanctum tokens
(`lyc_pat_…`) and their hashes; partner API secrets (`lyc_pk_…`) and
credential hashes; `Authorization` headers; webhook signing secrets
(current or previous); AI service tokens and service-identity credentials;
the AI context-signing key and context tokens; `APP_KEY`/
`APP_PREVIOUS_KEYS`; database, Redis and storage credentials or DSNs; MFA
secrets, TOTP codes and recovery codes; lookup HMAC keys; full AI prompts
or model outputs; document or attachment contents; arbitrary request or
response bodies; demo/selector credentials (no exemption for demo data).

The sanitizer's key fragments are extended accordingly (at least
`cookie`, `session`, `mfa`, `otp`, `totp`, `recovery_code`, `_hash`,
`prompt`, `completion`, `body`, `content`, `dsn`, `app_key`,
`signing_key`, `hmac`, in addition to the current `password`, `token`,
`authorization`, `secret`, `api_key`, `apikey`, `access_key`,
`private_key`, `credential`, `signature`), and it adds **value** scrubbing
for strings that look like secrets regardless of key: `Bearer …`,
`lyc_pat_…`, `lyc_pk_…`, `base64:` keys, and URL userinfo
(`scheme://user:pass@`). Application logs and audit records stay separate
systems (ADR 0017): logs are never audit evidence, and audit metadata is
never copied into logs.

### 6.3 Exceptions

A logged exception carries: `exception_class`, a stable `error_code`
(where the code knows one), `sqlstate` for database exceptions, and a
**message only when the exception type is known to carry safe text**
(the application's own domain exceptions whose messages are written for
users, and framework exceptions with fixed messages). Never logged
verbatim:

- `QueryException`/`PDOException` messages (they contain SQL and bound
  values) — record `sqlstate` and the connection name only;
- HTTP-client exceptions' messages (they can contain URLs with credentials
  or customer endpoint details) — record the exception class and, for
  webhook delivery, the existing bounded failure classification;
- provider, storage-SDK and Gateway-proxied error text.

Stack traces may be logged at `error` for unexpected exceptions, as
frames (`file:line` and function) **without arguments**:
`zend.exception_ignore_args = On` in the production PHP configuration is
required. The same rules apply to stored diagnostic strings:
`scheduler_heartbeats.last_error` holds an error code/exception class,
never raw exception text. Diagnostic value is preserved through the
exception class, `error_code`, `sqlstate`, correlation ids and the stable
`{exception_class}:{component}:{operation}` fingerprint that already
exists.

## 7. Request id and correlation contract

**Inbound `X-Request-Id` is untrusted input.** It is accepted only if it
matches

```
^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$
```

(ASCII, 8–128 characters, conservative class — UUIDs, ULIDs and typical
proxy/load-balancer ids all match). Anything else — missing, too short,
too long, other characters — is **discarded, not truncated or escaped**,
and a new server UUID is generated. The value used is echoed in the
response header and becomes the `request_id` in `Context`, logs, audit
envelopes (which store it in `varchar(255)`, so the bound also removes
the over-length write failure) and the AI context token. A rejected
inbound value is not logged. No existing external contract requires
another format (the mobile client and `docs/architecture/API.md` only
require that the header exist).

**Correlation model (unchanged, documented in OBSERVABILITY.md):**
`request_id` identifies one HTTP request; `correlation_id` identifies a
logical workflow and propagates through outbox → queue → webhook/AI calls;
`causation_id` stays on domain events; `trace_id`/`span_id` are
diagnostic only. Asynchronous work is **not** required to carry the
originating request id through its whole life: a job's log records carry
the `correlation_id` it was dispatched with plus the bounded execution
identifier that already exists for that work — outbox event id,
`webhook_deliveries.id`, `communication_deliveries.id`,
`automation_executions.id` — as **log** fields (never metric labels).
None of these ids is ever used for authorization (rule 62).

## 8. AI Gateway logging boundary

Preserved unchanged (G3, `docs/ai/AI-SECURITY.md`): Gateway logs and
errors never carry prompts, outputs, request/response bodies, credentials,
service tokens or context tokens. The Gateway's records use the §5 schema
with `service=ai-gateway`; correlation with Laravel uses the
`traceparent` trace id both sides already share and the `request_id`
claim inside the verified context token (logged only after verification;
never taken from an unverified header). The Gateway logs no additional
identifiers beyond what `ai.complete` already records (School id from the
verified token, agent, provider, outcome, latency, audited flag). Nothing
here relaxes Phase 0M: no external provider, no new data flow.

## 9. Metrics transport and backend boundary

**Choice: an OpenMetrics (Prometheus text exposition) endpoint on a
private port, scraped by a deployment-provided collector.** Evaluated:

| Option | Assessment |
|---|---|
| **A. OpenMetrics private scrape endpoint** | Chosen. No outbound credentials or exporter process in the application; a scrape failure never touches a business request; PHP-FPM's shared-nothing model needs no in-process export buffer; ingested natively by an OpenTelemetry Collector or any Prometheus-compatible backend (ADR 0015's OTel model preserved). |
| B. OTLP push from each process | Rejected for v1: needs an OTel SDK/exporter in every short-lived PHP-FPM worker and CLI process, in-process batching buffers, and outbound credentials to a collector; more moving parts for no v1 benefit. |
| C. Metrics derived by the backend from JSON log lines | Kept as a **fallback only** (the existing `metric` log lines continue); not the contract, because log-derived counters depend on log delivery and retention. |

**Where values come from.** Two kinds of metric, both served by the
`web` role at scrape time:

- **State gauges computed from PostgreSQL and the queue at scrape time**
  (backlogs, oldest ages, heartbeat freshness, failed counts, reconciler
  last-success): durable, identical from any replica, unaffected by a
  Redis loss, bounded queries with the readiness timeout discipline.
- **Event counters** (HTTP outcome counts, delivery outcomes, reconciler
  outcomes, sweep results) incremented by web, worker and scheduler
  processes in **one shared Redis hash per metric family** (bounded
  fields = label combinations), so a single scrape sees every process.
  Counter resets on a Redis loss are acceptable (OpenMetrics `rate()`
  semantics tolerate resets; the durable facts remain in PostgreSQL).

**Exposure.** The endpoint (`/metrics`) is served by nginx on a
**separate internal port** of the `web` role that the public TLS proxy
never routes, and the application additionally requires a static bearer
scrape token (`METRICS_SCRAPE_TOKEN`) from the secret store — an ordinary
production secret under ADR 0050 §4/O4, added to the `app_runtime` secret
group, compared in constant time, rotated by redeploy. The metrics route
is never registered on the public port and returns 404 there. This is not
a service identity and **does not resolve O5**; if O5 later introduces
service-to-service identities, the scrape credential may move under it.
The collector scrapes the service (one logical target); because counters
and gauges are global, the backend must not sum the same series across
replicas (drop the per-replica `instance` dimension or scrape one
replica per interval).

## 10. Metric model and label rules

- Names: `lycenza_` prefix, OpenMetrics units in the name (`_seconds`,
  `_total`, `_bytes`); no dynamic value in a name.
- Types: counters (`_total`), gauges, and histograms only where a
  distribution is actually used (HTTP and webhook latency).
- **Permitted labels** (closed set, each with an enumerated vocabulary):
  `service`, `role`, `queue` (`default|integrations|notifications`),
  `task` (scheduled task names from `routes/console.php`), `surface`
  (`web|api_v1|api_partner|api_internal|health|metrics`), `status_class`
  (`2xx|3xx|4xx|5xx`), `status` (only `401|403|404|419|422|429|500|503`),
  `component` (operations-status component names), `outcome` (per-family
  vocabulary from the owning code: e.g. webhook `delivered|retrying|failed|abandoned`),
  `channel` (Communication channel enum), `source` (reconciliation source:
  `outbox|webhook|communication|automation`), `backup` (`postgresql|objects`).
- **Prohibited labels:** every identifier listed in §3.5, plus route
  names/URIs (a bounded `surface` replaces them), exception messages and
  anything derived from user input.
- No per-School series, no tenant rankings. School-level investigation is
  a log/operator-console activity.
- Tests assert the label set of every registered metric against this
  allowlist.

**Failure behaviour (best effort).** Incrementing a counter swallows and
locally logs (rate-limited, `event=observability.metrics.write_failed`)
any Redis error — it never throws into a request, job or transaction,
never retries, and never buffers beyond the single write. A scrape that
cannot compute a gauge omits that series and exposes
`lycenza_metrics_collection_errors_total{component}` instead of failing
the whole scrape. No telemetry queue, no durable telemetry store, no
rollback because telemetry failed. The backend noticing a missing scrape
(`up == 0`) is the signal that collection itself failed.

## 11. Signals by area

"Required" means 0O.5A must emit it. Thresholds are in §14.

**Health (unchanged semantics).** Liveness = process alive; readiness =
PostgreSQL + Redis + not in maintenance; metrics = measurements; operations
status = human summary. None is overloaded to serve another. Object
storage stays **out of readiness** (transient storage trouble must not
pull the whole ERP out of load balancing; rule 56) and is monitored by
metrics and alerts instead. The Gateway's readiness stays
configuration-only. `lycenza_readiness_status{component}` (1/0) is exposed
from the same checks for alerting on the dependency rather than on the
probe.

**Process-class heartbeats (not replica heartbeats).** The question is
"has this required process class made progress recently?". Container
health answers per-replica liveness separately.

- *Scheduler:* each minute-cadence task already records a named heartbeat;
  0O.5A adds heartbeats to `expire-school-elevations` and the two daily
  prune tasks, and exposes
  `lycenza_scheduler_task_last_success_timestamp_seconds{task}` and
  `lycenza_scheduler_task_runs_total{task,outcome}`. A stopped scheduler
  shows as every minute-cadence task going stale together.
- *Duplicate scheduler:* the application cannot prove that exactly one
  scheduler exists (ADR 0050 requires one). It can detect the symptom: a
  minute-cadence task running materially more than once per minute
  (`rate(lycenza_scheduler_task_runs_total[10m]) > 1.5/60`). Every
  scheduled command is already duplicate-safe (claims, leases,
  `withoutOverlapping`); the alert is a Warning, not a guarantee.
- *Worker classes:* an idle queue currently produces no heartbeat, so
  "idle" and "dead" are indistinguishable. 0O.5A adds a **canary**: the
  scheduler dispatches one trivial no-op job to each required queue
  (`default`, `integrations`, `notifications`) every minute; processing it
  records the `queue:{name}` heartbeat (the existing throttled mechanism,
  registered **once**). `lycenza_queue_heartbeat_last_success_timestamp_seconds{queue}`.

**Queues** (`default`, `integrations`, `notifications`; label `queue`
only): `lycenza_queue_pending_jobs`, `lycenza_queue_delayed_jobs`,
`lycenza_queue_oldest_pending_age_seconds` (from the queue driver's
oldest-pending creation time), `lycenza_queue_jobs_processed_total`,
`lycenza_queue_jobs_failed_total`, and `lycenza_failed_jobs` (rows in
`failed_jobs`, by `queue`).

**Outbox and Redis-loss reconciliation** (source: the 0O.4A
`processed_at` model): `lycenza_outbox_pending_events`,
`lycenza_outbox_oldest_pending_age_seconds`,
`lycenza_outbox_unacknowledged_dispatched_events` (dispatched, no
`processed_at`) and `…_stale_events` (older than
`OutboxReconciler::STALE_AFTER_SECONDS`), `lycenza_outbox_failed_events`;
`lycenza_reconciliation_runs_total{source,outcome}`,
`lycenza_reconciliation_rows_total{source,result}` with `result` in
`inspected|redispatched|acknowledged|failed`,
`lycenza_reconciliation_duration_seconds{source}`,
`lycenza_reconciliation_last_success_timestamp_seconds{source}`. Sources:
`outbox`, `webhook`, `communication`, `automation`. No event ids, no
payloads.

**Webhooks** (no URL, School, secret or delivery id):
`lycenza_webhook_deliveries{status}` for `pending|retrying|delivering`,
`lycenza_webhook_overdue_deliveries` and
`lycenza_webhook_oldest_overdue_age_seconds` — **overdue** meaning
eligible (`pending` unclaimed for a lease, `retrying` past
`next_attempt_at`, `delivering` past its lease) but not yet picked up;
legitimate backoff (up to ≈10.6 h over the 60/300/1800/7200/28800 s
schedule) is never "overdue"; `lycenza_webhook_attempts_total{outcome}`
(`success|transient_failure|permanent_failure`),
`lycenza_webhook_deliveries_finished_total{outcome}`
(`delivered|failed|abandoned`),
`lycenza_webhook_request_duration_seconds` (histogram, no labels beyond
`outcome`). These gauges are computed across Schools; the current
per-School walk in `OperationalStatusService::webhooks()` must be replaced
by one bounded aggregate query path that respects RLS (0O.5A decides the
mechanism — e.g. a narrow security-definer aggregate returning counts only
— and documents it; it must not give the runtime role RLS bypass, rule 26).

**Communications:** `lycenza_communication_deliveries{status}` for
`pending|queued|sending`, `lycenza_communication_overdue_deliveries` and
`…_oldest_overdue_age_seconds` (immediate `pending` unclaimed for a lease,
`queued` past `next_attempt_at`, `sending` past its lease — deferred
deliveries are never overdue), `lycenza_communication_attempts_total{channel,outcome}`,
`lycenza_communication_deliveries_finished_total{channel,outcome}`
(terminal statuses), and the `communications-publish-scheduled` task
heartbeat. The `notifications` queue is covered by the queue metrics
above (it is the Communication delivery queue, not reserved).

**Automation:** `lycenza_automation_executions_total{outcome}`
(`succeeded|skipped|failed|abandoned`), `lycenza_automation_pending_executions`,
`lycenza_automation_overdue_executions` (pending past `next_attempt_at`),
the `automation-executions-redispatch` heartbeat, and
`lycenza_automation_review_items_open` (a count only). No rule, template
or School label.

**API and security** (bounded; security investigation remains a log
activity): `lycenza_http_requests_total{surface,status_class}`,
`lycenza_http_rejections_total{surface,status}` for
`401|403|404|419|429`, `lycenza_http_request_duration_seconds{surface}`
(histogram), `lycenza_partner_auth_failures_total{reason}` with the
authenticator's bounded reasons, `lycenza_api_token_operation_errors_total{operation}`
(`issue|revoke|rotate`). No user, client key id, School or IP label. Rate-
limit and auth-failure *details* stay in logs (IP where already logged,
classified Confidential).

**Application-side dependency signals** (engine metrics are the
deployment's — §12): `lycenza_dependency_check_failures_total{dependency}`
(`postgresql|redis|object_storage`) from readiness/status probes,
`lycenza_database_query_errors_total{sqlstate_class}` (two-character
SQLSTATE class only), `lycenza_storage_operation_failures_total{operation}`
(`read|write|delete|exists`), `lycenza_verification_last_result{check}`
(1/0 for `verify_database|verify_storage|verify_restore`, set when the
commands run — schema/migration mismatch shows here).

**Operations status** stays the human/operator view (CLI and the
authenticated internal endpoint) over **the same underlying checks** as the
metrics; it is not the telemetry backend.

## 12. What the deployment monitors itself

Not rebuilt inside Laravel: PostgreSQL engine metrics (connections,
replication, WAL, bloat, `pg_stat_*`), Redis engine metrics (memory,
evictions, connections, persistence, replication), container/replica
health, CPU/memory, nginx/PHP-FPM process metrics, TLS certificate
expiry, object-storage provider capacity/versioning/replication status,
and the collector's own health. The application documents which of these
alerts are required (§14) but does not emit them.

## 13. Backup and restore-drill observability

The repository **cannot truthfully originate** backup status; a fake
"backup successful" application flag is prohibited. The deployment must
feed these metrics (names fixed here so dashboards and alerts are
portable):

| Metric (deployment-fed) | Meaning |
|---|---|
| `lycenza_backup_last_success_timestamp_seconds{backup="postgresql"}` | last successful base backup |
| `lycenza_backup_recovery_point_timestamp_seconds{backup="postgresql"}` | newest point PITR can restore to (WAL archive freshness) |
| `lycenza_backup_last_failure_timestamp_seconds{backup}` | last failed backup/archive run |
| `lycenza_backup_last_success_timestamp_seconds{backup="objects"}` | last successful independent object copy |
| `lycenza_restore_drill_last_success_timestamp_seconds` | date of the last **successful** restore drill |
| `lycenza_restore_drill_duration_seconds` | measured time to validated service in that drill |
| `lycenza_restore_drill_recovery_point_age_seconds` | requested-vs-observed recovery point gap in that drill |
| `lycenza_restore_drill_last_result` | 1 PASS / 0 FAIL |

Restore-drill values come from the drill record (`platform:verify-restore`
already prints the observed recovery point and validation duration); they
carry no restored data. The real drill remains deploy-gated and
outstanding (ADR 0050 §20).

## 14. Alerting

### 14.1 Principle

Alerts are **actionable operational conditions** — unavailability,
freshness violations, backlog age, exhausted retries, persistent failure
rates, backup freshness, reconciliation failures, overdue drills — never
"an error was logged", and never per-School or per-id alerts. Each alert
names a runbook; alert text states the condition, not remediation steps.

### 14.2 Severity (frozen terminology)

| Severity | Meaning | Expected response |
|---|---|---|
| **SEV-1 Critical** | The service or a security boundary is materially unavailable, or a data-loss-risk condition exists (e.g. recovery point beyond RPO) | Immediate, any hour |
| **SEV-2 High** | A major subsystem is degraded (durable work not progressing, a required process class down) | Prompt, same working period |
| **SEV-3 Warning** | Degradation or an approaching threshold that needs investigation | Next working day |

No further tiers, no ITSM hierarchy.

### 14.3 Threshold sources

- **A — contract-derived** (ADR 0050 O10, this ADR);
- **B — derived from existing runtime cadence/retry behaviour** in the
  repository (values quoted);
- **C — owner/operator value required** (capacity-dependent; configured in
  the deployment within the stated safeguard).

### 14.4 Required alert catalog

| ID | Condition | Severity | Threshold (source) | Runbook |
|---|---|---|---|---|
| OBS-01 | Web readiness failing (no ready replica) | SEV-1 | readiness 503/absent on all replicas for 2 consecutive probe intervals (C: probe interval is the deployment's; safeguard ≤ 2 min) | maintenance-window release / dependency runbooks |
| OBS-02 | PostgreSQL unavailable to the application | SEV-1 | `lycenza_readiness_status{component="database"}==0` for 2 min (C, ≤ 2 min) | BACKUP-AND-RESTORE, DATABASE-BOOTSTRAP |
| OBS-03 | Redis unavailable | SEV-1 | `…{component="redis"}==0` for 2 min (C, ≤ 2 min) | REDIS-LOSS-RECOVERY |
| OBS-04 | AI Gateway readiness failing where the deployment runs it | SEV-3 | 10 min (C); never SEV-1/2 — optional subsystem (rule 56) | PRODUCTION-IMAGES-AND-PROCESSES |
| OBS-05 | Scheduler stopped (all minute-cadence task heartbeats stale) | SEV-2 at 300 s; SEV-1 at 1800 s | B: tasks run every 60 s; 300 s = the existing `observability.scheduler_stale_after_seconds`; 1800 s = the existing `outbox_critical_after_seconds` (all async effects stalled) | MAINTENANCE-WINDOW-RELEASE (scheduler), REDIS-LOSS-RECOVERY |
| OBS-06 | One scheduled task stale or failing while others run | SEV-3 | minute tasks: 300 s (B); daily prune tasks: 26 h since last success (B: daily + 2 h margin) | per task |
| OBS-07 | Duplicate scheduler suspected | SEV-3 | minute-task run rate > 1.5/min over 10 min (B) | PRODUCTION-IMAGES-AND-PROCESSES (one scheduler) |
| OBS-08 | Worker class not processing (canary heartbeat stale) | SEV-2 | 300 s (B: canary every 60 s + `RecordQueueHeartbeat` 10 s throttle; 5 missed cycles, same margin as the scheduler) | PRODUCTION-IMAGES-AND-PROCESSES |
| OBS-09 | Queue oldest pending job too old | SEV-3 at 300 s; SEV-2 at 1800 s | B: canary cadence; 1800 s matches the existing outbox critical window. Operators may lower (C) | as OBS-08 |
| OBS-10 | New failed jobs | SEV-3 | `increase(lycenza_queue_jobs_failed_total[15m]) > 0` (B: jobs that own their retries declare `$tries=1` and should not reach `failed_jobs` routinely); a SEV-2 rate is an operator value (C) | `platform:failed-jobs` |
| OBS-11 | Outbox dispatch backlog | SEV-3 at 300 s; SEV-2 at 1800 s oldest pending age | B: existing `outbox_degraded_after_seconds` / `outbox_critical_after_seconds` | REDIS-LOSS-RECOVERY |
| OBS-12 | Outbox reconciliation not succeeding | SEV-2 | reconciler last success older than 300 s, or a failed run (B: runs inside every minute of `outbox-dispatch`) | REDIS-LOSS-RECOVERY |
| OBS-13 | Stale unacknowledged outbox events persist | SEV-3 | `…_stale_events > 0` for 30 min (B: `STALE_AFTER_SECONDS` 600 s + three reconciliation windows) | REDIS-LOSS-RECOVERY |
| OBS-14 | Outbox events exhausted/failed | SEV-2 | `increase(lycenza_outbox_failed_events[1h]) > 0` (B: `failed` is terminal — consumer retries or 25 reconciliations exhausted) | REDIS-LOSS-RECOVERY; a new webhook/integration runbook (0O.5A) |
| OBS-15 | Webhook deliveries overdue | SEV-3 at 300 s; SEV-2 at 1800 s oldest overdue age | B: redispatch every 60 s, lease 60 s; overdue excludes legitimate backoff | webhook/integration runbook |
| OBS-16 | Webhook deliveries abandoned/failing | SEV-3 | abandoned/permanently failed deliveries in 1 h above an operator value (C; customer endpoints fail legitimately — never page on a single customer's endpoint) | webhook/integration runbook |
| OBS-17 | Communication deliveries overdue | SEV-3 at 300 s; SEV-2 at 1800 s | B: redispatch every 60 s, lease 30 s, backoff 30/120/300 s | new Communications runbook (0O.5A) |
| OBS-18 | Communication delivery failure rate | SEV-3 | terminal failed/bounced/rejected share above an operator value over 1 h (C) | Communications runbook |
| OBS-19 | Automation recovery not running / executions overdue | SEV-3 (SEV-2 if > 1800 s) | heartbeat 300 s; overdue pending past `next_attempt_at` 300 s (B: grace 60 s, backoff 60/300 s) | Automation module doc |
| OBS-20 | PostgreSQL recovery point too old | SEV-2 at > 10 min; **SEV-1 at > 15 min** | A: protects RPO ≤ 15 min | BACKUP-AND-RESTORE |
| OBS-21 | PostgreSQL base backup stale or failed | SEV-2 at > 26 h since success or any failure; SEV-1 at > 50 h | A/C: daily base backups expected by the provider schedule (+2 h margin); the operator sets the schedule | BACKUP-AND-RESTORE |
| OBS-22 | Object-storage independent copy stale or failed | SEV-2 at > 20 h; **SEV-1 at > 24 h** | A: protects object RPO ≤ 24 h | BACKUP-AND-RESTORE |
| OBS-23 | Restore drill overdue | SEV-3 at > 92 days since the last successful drill; SEV-2 at > 120 days; SEV-2 immediately on a FAIL result | A: quarterly drills (ADR 0050 §11) | BACKUP-AND-RESTORE, RESTORE-DRILL-RECORD |
| OBS-24 | Storage validation failing | SEV-2 | `lycenza_verification_last_result{check="verify_storage"}==0`, or object-storage operation failures above an operator value over 15 min (C) | BACKUP-AND-RESTORE (storage) |
| OBS-25 | Security boundary stress | SEV-3 | 401/403/429 or partner auth failures above an operator baseline (C) — investigation happens in logs | INTEGRATION-SECURITY |
| OBS-26 | Telemetry collection failing | SEV-2 | scrape target down 5 min, or `lycenza_metrics_collection_errors_total` increasing (C for the interval) | this ADR §9–§10 |

Alerting reduces the time to notice a problem; it does **not** itself
guarantee an RPO or RTO. Every B-threshold is expressed in 0O.5A as a
constant or configuration value derived from the named source, and tests
compute the condition from fixture state (no real backend).

## 15. Operations status

`OperationalStatusService` remains the operator summary (CLI
`platform:operations-status`, authenticated `GET
/api/internal/operations/status` under `platform.operations.view`). 0O.5A
must:

- read **the same checks** the metrics expose (one implementation, two
  presentations);
- watch every minute-cadence heartbeat (adding
  `communication-deliveries-redispatch`, `communications-publish-scheduled`,
  `automation-executions-redispatch`, `expire-school-elevations`), all
  three queues (adding `notifications`), outbox stale/failed state and
  reconciliation status, Communications and Automation backlogs;
- **degrade per component**: every component check is guarded, so a
  PostgreSQL outage yields `database: unhealthy` and the dependent
  components `unknown`/unavailable — never a 500 and never exception
  detail. The HTTP endpoint still needs the database to authenticate, so
  during a database outage **the CLI is the diagnostic path**; this is
  documented rather than worked around.

## 16. Failure isolation and backpressure

- Logs go to stderr and are collected by the container runtime; the
  application never blocks on a remote log backend. If the local
  collection path is saturated, the runtime's bounded buffering/drop policy
  applies (a deployment choice); request and job execution must not wait
  on log shipping. `LOG_LEVEL` in production is `info` (debug is never on,
  keeping volume bounded).
- Metrics are pull-based; counter writes are single best-effort Redis
  operations (§10). No telemetry retry loop, no telemetry queue, no
  business rollback because of telemetry.
- Telemetry failure is itself observable: scrape `up`, the collection
  error counter, and a rate-limited local log event.
- Local/DDEV and tests need no backend: JSON formatting, the sanitizer,
  metric exposition and alert-condition calculations are testable in
  process; DDEV/test telemetry never goes to a production backend (ADR
  0050 §16). Demo accounts get no special treatment in logs.

## 17. Retention and classification

| Telemetry | Retention (minimum) | Classification |
|---|---|---|
| Operational application logs (Laravel, Gateway, nginx access) | **30 days** | **Confidential** by default. May contain pseudonymous identifiers already in `Context` (School, actor, elevation, API client ids), request/correlation ids and, for security events, source IP. Must never contain Highly Sensitive payload data (children's data, credentials, document contents, prompts/outputs). A specific security event that must carry a Sensitive identifier is documented narrowly at its call site; the log tier is not lowered. |
| Operational metrics | **90 days** | **Confidential** (DATA-CLASSIFICATION.md: operational data whose disclosure — capacity, failure and security-rejection rates — could harm the product; no personal data by construction) — never Public |
| Traces | not collected in v1 | a later decision sets both |
| Alert history / notifications | per backend; at least as long as metrics | Confidential |

These retentions are independent of audit (Platform and School), legal
record and backup retention, and logs are never audit evidence.

## 18. Backend access and credentials

The production observability backend is an operational/security system:
access only for authorized operators (least privilege, MFA per the
organisation's policy); dashboards are never public; School users never
see raw production logs or metrics. No new application role or capability
is needed to reach the external backend (it is outside the application).
Any credential the collector or backend needs is a production secret under
ADR 0050 §4 (O4) — no in-database credential system is created. The
scrape token (§9) is the only application-side telemetry secret.

## 19. Dashboards and runbooks

Provider-neutral dashboard specifications (panels as metric queries in
text, not a vendor's export format), four views only:

1. **Service health** — readiness per dependency, HTTP outcomes/latency by
   surface, Gateway readiness.
2. **Queues, workers and scheduler** — canary and task heartbeats, pending
   and oldest-pending per queue, failed jobs, duplicate-scheduler signal.
3. **Integrations and durable-work recovery** — outbox backlog/stale/failed,
   reconciliation outcomes, webhook and Communication overdue/outcomes,
   Automation.
4. **Backup and recovery** — recovery-point age, backup freshness per
   store, restore-drill age/result/duration.

No vanity dashboards, no tenant rankings, no per-School dashboard in v1.
Alerts link to existing runbooks (`docs/operations/`: Redis loss,
maintenance-window release, backup/restore, database bootstrap, images and
processes); 0O.5A adds concise runbooks for webhook/integration failures,
Communication delivery failures, failed jobs and telemetry collection
failure, plus an alert index.

## 20. O12 contribution to O1 (definition of done)

O12 is complete for Phase 0O when there is evidence of:

1. structured, centrally sanitized production logs (repository);
2. bounded, validated correlation ids (repository);
3. real operational metrics emitted by application processes (repository);
4. worker, scheduler and reconciliation visibility (repository);
5. backlog, failure and freshness metrics (repository);
6. the backup/recovery metric contract (repository defines, deployment
   feeds);
7. the alert catalog with deterministic condition tests (repository);
8. operations status aligned with the same signals and resilient to
   outages (repository);
9. the external backend integration contract (repository);
10. **30-day log retention configured in the actual deployment**
    (operator evidence);
11. **90-day metric retention configured in the actual deployment**
    (operator evidence);
12. **alert routing activated** and reaching the intended channel
    (operator evidence).

Repository implementation alone proves 1–9, never 10–12.

## 21. Proposed Phase 0O.5A — Observability & Alerting Foundation (not implemented)

Repository-only; no backend activation, no telemetry sent anywhere:

1. JSON log formatter + central sanitizing processor via a logging `tap`
   (Laravel) and the equivalent Gateway logging configuration; extended
   key and value redaction; `ErrorReporter` routed through it.
2. Safe exception logging (§6.3): replace the raw `getMessage()` log and
   `last_error` sites; framework exception reporting through the same
   rules; `zend.exception_ignore_args = On` in the production image.
3. Request-id validation (§7) with tests (valid, over-length, invalid
   characters, header injection attempts).
4. Metrics substrate: Redis-backed best-effort counters, scrape-time
   gauges, the private `/metrics` port in the web role, the scrape-token
   guard (production refuses to enable metrics without it), label
   allowlist tests.
5. Process/queue/scheduler signals: canary jobs, missing task heartbeats,
   **fix the duplicate `RecordQueueHeartbeat` registration**, run
   counters.
6. Outbox/reconciliation/webhook/Communication/Automation/API/dependency
   metrics per §11, including an RLS-respecting aggregate path.
7. Resilient, complete operations status (§15).
8. Backup/restore metric input contract (documented names; no fake
   producer) and the drill-record-to-metric procedure.
9. Alert catalog as provider-neutral rule specifications
   (`docs/operations/alerts/`), with deterministic condition tests over
   fixture metric values; dashboard specifications; the new runbooks.
10. Image verification extended (JSON log lines, `/metrics` unreachable
    on the public port, token required).

## 22. Future deployment evidence (deploy-gated)

Phase 0O closeout needs operator evidence that telemetry reaches a real
backend; log retention ≥ 30 days; metric retention ≥ 90 days; the required
alerts are active; alert routing reaches the intended channel; backup
freshness metrics are connected; and the restore-drill overdue alert is
active. None of this is performed by a repository agent (ADR 0050 §19).

## 23. Boundaries

- **O16 stays open.** This ADR adds no SBOM, scanner, signing, provenance
  or registry-promotion policy. **No production image may be pushed to a
  registry or promoted before O16 is resolved** (the 0O.4A images exist
  locally only).
- **O5 stays open.** The metrics scrape token is an O4 production secret,
  not a service identity.
- Still open after this ADR: **O1, O2, O5, O9, O13, O14, O15, O16**.
- Phase 0M stays BLOCKED; nothing here changes AI data flows.

## Alternatives considered

- **Pick a vendor now.** Rejected by owner decision; nothing in the
  application needs one.
- **OTLP push with an OTel SDK in every process (ADR 0015's literal
  path).** Deferred: more moving parts, in-process buffering and outbound
  credentials for no v1 benefit; the OpenMetrics endpoint preserves the
  OTel model through a collector.
- **Log-derived metrics only.** Rejected as the contract (delivery- and
  retention-dependent); kept as a fallback.
- **Per-replica heartbeats.** Rejected: unbounded identity cardinality;
  container health covers replicas.
- **Object storage in readiness.** Rejected: would remove the whole ERP
  from load balancing for a partial dependency (rule 56).
- **Tracing in v1.** Rejected by owner decision.
- **Alert on error-log volume.** Rejected: noisy and not actionable.

## Consequences

- 0O.5A has a concrete, testable scope that fixes verified debt
  (duplicate listener, unwatched heartbeats and queue, unguarded status,
  raw exception text, unbounded request ids, trace arguments).
- A deployment can use any OpenMetrics/OTel-compatible backend without
  code changes.
- Phase 0O closure additionally needs operator evidence (§22).

## References

ADR 0015, ADR 0017, ADR 0021, ADR 0023, ADR 0049, ADR 0050;
`docs/architecture/OBSERVABILITY.md`, `RELIABILITY.md`,
`PRODUCTION-RELEASE.md`, `PHASE-0O-READINESS.md`, `API.md`;
`docs/security/DATA-CLASSIFICATION.md`, `INTEGRATION-SECURITY.md`;
`docs/ai/AI-SECURITY.md`; `docs/operations/*`;
`apps/platform/app/Support/Observability/*`,
`app/Http/Middleware/AssignRequestId.php`,
`app/Listeners/RecordQueueHeartbeat.php`, `routes/console.php`,
`config/observability.php`, `config/logging.php`,
`deploy/php/production.ini`, `services/ai/app/main.py`.

## Implementation amendment (Phase 0O.5A, 2026-09-26)

Status: the **repository side** of O12 is implemented. **No real
observability backend is active, no alert routing is active, and no
retention is configured anywhere** — those remain deploy-gated operator
evidence (§22). No vendor was chosen; no image was pushed (O16 open).

| Section | Implemented as |
|---|---|
| §5 structured logs | `App\Support\Observability\Logging\StructuredLogTap` on every application channel (`stack`, `single`, `daily`, `stderr`, `syslog`, `errorlog`) installs `SafeLogProcessor` and, when `observability.logging.format` is `json` (production default; the image sets `LOG_FORMAT=json`, `LOG_LEVEL=info`), `StructuredJsonFormatter`. **Final field names** (the brief's, replacing the ADR's `ts`/`event`/`role`/`env`): `time`, `level`, `service`, `process_role`, `environment`, `event_code`, `message`, `request_id`, `correlation_id`, `trace_id`, `school_id`, `actor_user_id`, `elevation_id`, `api_client_id`, `route`, `command`, `job`, `queue`, `outcome`, `error_code`, `exception_class`, `sqlstate`, `ctx` (guard-tested). A dotted lower-case message is the event code (the codebase's existing convention). `process_role` comes from `PROCESS_ROLE`, exported by the image entrypoint. |
| §6 sanitization | `LogSanitizer` rewritten to word-segment key matching (no over-redaction: `footprint`, `input_tokens`, `session_ended` stay readable) plus bounded value scrubbing (`Bearer …`, `lyc_pat_…`, `lyc_pk_…`, `base64:` keys, URL userinfo, the committed development tokens); applied centrally to message, context and `extra`, again in the formatter. |
| §6.3 exceptions | `SafeException`: class, SQLSTATE, error code; a message only for the application's own `App\` exceptions and authentication/authorization/validation; argument-free frames. The processor rewrites the framework reporter's record (message → `application.exception` for unsafe types). The four scheduled commands and `ProcessOutboxEventJob` no longer log, print or store exception text (also not in `failed_jobs`); `scheduler_heartbeats.last_error` holds a bounded code. `zend.exception_ignore_args = On` in the production image. `ErrorReporter` is kept and **redefined** as the one API for HANDLED exceptions (`report($e, $eventCode, $component, $operation, $context)`), logging through the same pipeline — not a second sanitizer. |
| §7 request ids | `AssignRequestId::PATTERN` = `/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D` (`D`: no trailing newline); anything else → a fresh UUID, never truncated. `=` is outside the class, so e.g. AWS `Root=…` trace ids are replaced. The oversized-id audit failure is proven fixed. |
| §8 Gateway | `app/core/logging.py`: one JSON handler on stderr at INFO (uvicorn's loggers routed through it), the same field catalog (`service=ai-gateway`, `process_role=gateway`), extras serialized, G3 redaction as backstop, exceptions by class and frames only, uvicorn access lines reduced to method/path (no query)/status with **no client address**. `ai.complete` now carries the verified token's `request_id` and the shared trace id (one `TraceContext` per request, reused for the audit write-through). New setting `log_format` (`json` default). |
| §9 transport | Prometheus text exposition 0.0.4 (OpenMetrics-compatible) from `apps/platform/metrics/index.php` — **outside `public/`, not a Laravel route** — mapped by nginx only on a private listener, **port 9102**, with the FastCGI marker `LYCENZA_METRICS_LISTENER=1`; the public listener 404s it. `MetricsEndpoint`: bearer `METRICS_SCRAPE_TOKEN` compared over SHA-256 digests with `hash_equals`; missing, wrong and unconfigured tokens get the identical empty 401. The script bootstraps the application without the HTTP middleware stack, so **metrics stay available during a maintenance window**. `METRICS_SCRAPE_TOKEN` joins the `app_runtime` secret group; O5 untouched. |
| §10 model | `MetricCatalog`: the closed catalog (names, types, help, closed label values). **Final label keys** (the brief's names): `queue`, `scheduled_task`, `request_surface`, `status_class`, `code`, `outcome`, `delivery_channel`, `recovery_source`, `backup_store`, `state`, `dependency`, `operation`, `sqlstate_class`, `check`, `result`, `component` (+ `le`). `StoreMetricsRecorder` validates every write (throws in local/testing, drops and counts in production), writes to one shared Redis hash (`METRICS_STORE=redis`; `array` in tests, forced in `phpunit.xml`), never throws into business code, never retries or buffers. Scrape-time state via `OperationalSignals`; collectors isolated and counted in `lycenza_metrics_collection_errors_total{component}`; with PostgreSQL down, database-backed collectors are skipped (readiness 0 is the signal). The idempotency log-line counters became `lycenza_idempotency_requests_total{outcome}`; `LogMetricsRecorder` was removed. |
| §11 signals | **Backlog without RLS bypass:** migration `2026_10_22_090000` adds `operational_work_backlog` — trigger-maintained scheduling state (source, item id, state, `state_since`, next attempt, lease) of unfinished webhook deliveries, Communication deliveries and Automation executions; no `school_id`, no payload; backfilled one School context at a time. `state_since` survives the 0O.4A redispatch `updated_at` bump. **Worker canaries:** `WorkerCanaryJob` (no-op, 1 try, 10 s, unique per queue for 600 s) dispatched every minute by `platform:dispatch-worker-canaries` (task `worker-canaries`). **Duplicate heartbeat listener fixed** (discovery only). Heartbeats written with an atomic upsert (two-process race test). `RecordScheduledTaskRun` (scheduler events) counts every task's runs/duration and gives `expire-school-elevations`, `worker-canaries` and the two prune tasks their heartbeat. Metric adjustments: `lycenza_webhook_deliveries{state}`, `lycenza_communication_deliveries{state=pending\|deferred\|queued_due\|sending}`, `lycenza_automation_pending_executions{state}`, `lycenza_readiness_status{dependency}`, `lycenza_partner_auth_failures_total{outcome}` (the authenticator's seven codes), `lycenza_http_rejections_total{request_surface,code}`, new `lycenza_ai_gateway_ready` (only where a Gateway token is configured). **`lycenza_automation_review_items_open` became `lycenza_automation_review_items_created_total`**: review items are append-only with no resolution in v1, so an "open" count does not exist. Verification commands record `lycenza_verification_last_result` as a stored gauge (they stay database-read-only). |
| §13 evidence | `DeploymentEvidence`: schema `lycenza.deployment-evidence/v1`, a JSON file at `OBSERVABILITY_DEPLOYMENT_EVIDENCE_FILE` (read-only mount), strictly validated (known keys, integer Unix times since 2020 and not in the future, bounded durations, drill `record_id` `DRILL-YYYY-QN-n` and explicit `PASS`/`FAIL`); unset → no evidence; malformed/oversized → no evidence and a collection error. Authenticity rests on deployment file permissions; the document is not signed; nothing is accepted over HTTP. |
| §14 alerts | `AlertCatalog` (26 rules, tiers with portable PromQL and an equivalent deterministic PHP condition over `MetricSnapshot`), `AlertRulesExporter`, `platform:alerts-export`; generated `docs/operations/alerts/lycenza-alerts.rules.yml` (33 tiers) kept equal to the catalog by a test. Operator values (`ALERT_*`): unset → the tier is **disabled** and listed as such (OBS-16, OBS-18, OBS-25 and the SEV-2 tiers of OBS-10/OBS-24 today); outside the safeguard bounds → the export fails. Runbooks for OBS-19 and OBS-25 are the Automation module doc and INTEGRATION-SECURITY. |
| §15 status | `OperationalStatusService` reads `OperationalSignals` with the shared `observability.thresholds`; covers all 9 scheduled tasks, all three queues (canary semantics: stale heartbeat = `stalled`), outbox pending/failed/stale, `recovery`, `webhooks`, `communications`, `automation`; every component guarded (`unknown`/`unavailable`, never an exception). The CLI reports a PostgreSQL outage instead of crashing (proven). |
| §16, guard | `ProductionConfigurationGuard` adds `log_format_not_structured` and `metrics_scrape_token_invalid` (missing, < 32 characters or a placeholder). Telemetry failure isolation proven for HTTP, transactions, queued and recovery work. |
| §19 | `docs/operations/dashboards.md` (four views as PromQL; guard-tested), `alerts/README.md` (index), runbooks `WEBHOOK-FAILURES`, `COMMUNICATION-FAILURES`, `FAILED-JOBS`, `TELEMETRY-COLLECTION`. |

Additional finding fixed during implementation: the outbox "oldest pending
age" counted events scheduled for later (future `available_at`) as old;
only due events now contribute.

Test environment: the isolated Compose test container now also mounts
`docs/` and `services/ai/requirements.txt` read-only (same precedent as
`packages/contracts` and `infrastructure/`), because the alert, runbook,
dashboard and no-vendor-SDK tests read them.

Observed (not changed here, recorded for a later reliability unit): with
PostgreSQL unreachable, an ordinary public request takes about 52 s to fail
(each of several framework database touches waits `DB_CONNECT_TIMEOUT`).
Readiness answers 503 within seconds, so a load balancer stops routing to
the replica; the metrics scrape skips database collectors in that state.

Still outstanding (deploy-gated, §22): telemetry reaching a real backend;
≥ 30-day log and ≥ 90-day metric retention configured; the alert rules
loaded and routed to the intended channel; backup freshness evidence
connected; the restore-drill overdue alert active. O12's repository portion
is complete; O12 is **not** operationally complete in production.

**Note (Phase 0O.6, 2026-09-26):** the O16 boundary in §23 is resolved by
ADR 0052; supply-chain findings never become runtime metric labels.
