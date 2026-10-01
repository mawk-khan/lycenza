# School OS — Integrations: Outbound Webhook Delivery

Phase 0C.3's operational reference for the generic, provider-neutral
outbound webhook subsystem. See ADR 0018 (integration/webhook
architecture), ADR 0026 (delivery semantics/signing), ADR 0027 (SSRF
policy), and `docs/security/INTEGRATION-SECURITY.md` (the
security-specific companion to this document).

This subsystem is **infrastructure only** — no real external
integration (payment gateway, Tally, DigiLocker, UDISE+, APAAR,
WhatsApp/SMS provider, ...) is implemented here. ADR 0057 (2026-09-28)
defers all of those from Phase 0 / production v1. It exists so a future
business module only ever needs to do one thing: **emit an approved
domain event**. The module never knows which webhook URLs exist, how
they're signed, how retries work, or how delivery history is stored.

## Architecture

```
Authoritative domain transaction
        |
Transactional Outbox (ADR 0025, existing)
        |
Durable domain event (domain_event_outbox)
        |
WebhookFanoutConsumer (an ordinary EventConsumer, IdempotentConsumerGuard-wrapped)
        |
WebhookDelivery row claimed (unique per endpoint+event)
        |
Queue (DeliverWebhookJob, afterCommit)
        |
Real signed HTTP POST
        |
WebhookDeliveryAttempt recorded (one per real attempt)
        |
Retry (App\Console\Commands\RedispatchDueWebhookDeliveries) / Success / Permanent failure / Abandoned
```

No external HTTP request occurs inside the original business
transaction — `WebhookFanoutConsumer` only claims a delivery row and
queues `DeliverWebhookJob`; the real HTTP call happens entirely inside
that separately-queued job.

## Terminology (four distinct concepts, four distinct tables)

| Concept | Table | Meaning |
|---|---|---|
| **Webhook Endpoint** | `webhook_endpoints` | A School's registered destination (`name`, `url`, signing secret, `active`/`disabled` status). |
| **Webhook Subscription** | `webhook_subscriptions` | Which event type(s) an endpoint receives. Independent lifecycle from the endpoint — added/removed without touching the endpoint or its secret. |
| **Webhook Delivery** | `webhook_deliveries` | One LOGICAL event-to-endpoint delivery. Exactly one row per `(webhook_endpoint_id, event_id)` pair, enforced by a database unique constraint. |
| **Webhook Attempt** | `webhook_delivery_attempts` | One REAL HTTP attempt for a delivery. A delivery may have many attempts; each is a separate, append-only, durable row. |

All four are tenant-owned (`school_id`), RLS-enabled and forced. Cross-
School relational integrity is enforced by composite foreign keys
(`webhook_subscriptions`/`webhook_deliveries` → `webhook_endpoints(id,
school_id)`; `webhook_delivery_attempts` → `webhook_deliveries(id,
school_id)`) — a subscription or delivery whose `school_id` doesn't
match its own endpoint's `school_id` is a foreign-key violation, not
just an application bug.

## Event registry (`App\Support\Webhooks\WebhookEventRegistry`)

A domain event existing in `domain_event_outbox` does **not**
automatically become subscribable. Only event types registered here,
with `externallyVisible: true`, may become a `WebhookSubscription` —
`WebhookSubscriptionService::subscribe()` validates against this
registry, and `WebhookFanoutConsumer::handles()` re-checks it before
ever fanning an event out (defense in depth: even a row that
theoretically bypassed the subscribe-time check couldn't reach
delivery). Currently registered: `school.setting.changed.v1` (the
Phase 0C demonstration event) and `platform.webhook_test.v1` (a
synthetic event for proving this subsystem end to end without a real ERP
business event — see "Test event" below). Only its **emission** is
local/testing-only. The registry currently accepts subscriptions to it in
every environment. That is inert in production, and ADR 0057 §5.2 records
it as low-severity implementation debt to environment-gate.

## Payload minimization and immutability

Webhook payloads are never raw Eloquent model serializations. Each
domain event's `payload()` method already returns a minimal, explicit
set of fields at the moment the event occurs (e.g.
`SchoolSettingChanged`'s payload is `{key, value}`, never the full
`SchoolSetting`/`School` record) — this minimization happens once, at
event-creation time, in the owning module's event class, not
per-delivery in the webhook layer.

**Immutability**: `domain_event_outbox.payload` is written once and
never mutated afterward — `DeliverWebhookJob` reads the ORIGINAL
event's payload at delivery time, which is why it faithfully
represents what happened at event time even if the underlying
School/setting has since changed again. This is the "immutable event
payload" choice from section 87's design question (not a duplicated
copy on the delivery row) — a deliberate, documented decision, valid
as long as `domain_event_outbox` rows aren't pruned before every
delivery referencing them has completed (no outbox pruning is
implemented yet).

## Outbound event envelope

```json
{
  "id": "<event UUID>",
  "type": "school.setting.changed.v1",
  "version": 1,
  "createdAt": "2026-08-23T00:00:00+00:00",
  "schoolId": "<School UUID>",
  "data": { "key": "communications.digest_frequency", "value": "weekly" },
  "metadata": { "correlationId": "<correlation UUID>" }
}
```

No actor/security metadata is ever included in the payload itself —
`correlationId` is the one deliberate exception, included for
integrator-side tracing/support correlation, not because it's needed
by the integrator's business logic.

## Delivery state machine

```
pending -> delivering -> delivered                      (2xx)
                      \-> retrying -> delivering -> ...  (transient: 408/429/5xx/timeout/network error, bounded)
                      \-> failed                          (permanent: 3xx/4xx other than 408/429)
                      \-> abandoned                       (retries exhausted, endpoint disabled/gone, event missing, or SSRF-rejected)
```

Enforced by a database CHECK constraint — never an arbitrary string.
Transitions are only ever performed by `App\Jobs\DeliverWebhookJob`.

## Concurrency and the processing lease

The hard requirement: two workers racing the same `webhook_deliveries`
row must not both perform the HTTP attempt. `DeliverWebhookJob::claim()`
attempts one atomic conditional `UPDATE`:

```sql
UPDATE webhook_deliveries
SET status = 'delivering', processing_lease_expires_at = now() + lease
WHERE id = ?
  AND status IN ('pending', 'retrying', 'delivering')
  AND (processing_lease_expires_at IS NULL OR processing_lease_expires_at < now())
```

Exactly one concurrent `UPDATE` can affect the row; the other affects
zero rows and returns immediately without attempting delivery. This
also recovers a delivery whose worker crashed mid-attempt: once the
lease (`webhooks.processing_lease_seconds`, default 60s) expires, the
SAME conditional claim lets a different worker safely reclaim it.
Attempt numbering (`attempt_number = delivery.attempts + 1`) is
computed only by whichever worker wins the claim, and is additionally
backstopped by a database unique constraint on
`(webhook_delivery_id, attempt_number)` — never a bare `count() + 1`
race.

Retry timing is driven by `App\Console\Commands\
RedispatchDueWebhookDeliveries`, not Laravel's job-level `$tries`/
`$backoff` (the sync queue connection used in tests, per ADR 0024, has
no delayed-requeue mechanism) — it scans, per School,
`retrying` deliveries whose `next_attempt_at` has passed and
`delivering` deliveries whose lease has expired, and re-dispatches
`DeliverWebhookJob` for each. `DeliverWebhookJob` itself performs
exactly one HTTP attempt per invocation and always completes
(recording whatever outcome occurred) — it never throws to trigger a
framework-level retry.

## Retry policy

| Response | Classification | Behavior |
|---|---|---|
| 2xx | success | Delivered. |
| 3xx | permanent_failure | Never followed (SSRF: a redirect target is unvalidated); classified failed, never retried. |
| 400, 401, 403, 404, 409 (etc.) | permanent_failure | Failed, never retried — the request itself is wrong, retrying won't fix it. |
| 408, 429 | transient_failure | Retried, respecting a valid `Retry-After` (clamped to `webhooks.max_retry_after_seconds`, default 3600s). |
| 5xx | transient_failure | Retried with backoff. |
| Timeout | timeout | Retried with backoff. |
| Connection/DNS failure | network_error | Retried with backoff. |

Backoff schedule (`webhooks.retry_backoff_seconds`): `[60, 300, 1800,
7200, 28800]` seconds, indexed by attempt number, the last entry
repeating beyond the array's length, with ±10% jitter. Bounded by
`webhooks.max_attempts` (default 6) — a delivery that exhausts all
attempts becomes `abandoned`, a visible terminal state, never retried
forever.

## Delivery semantics: at-least-once (ADR 0026)

**Webhooks are at-least-once delivery, never exactly-once.** The crash
window: the receiver can accept an HTTP request and even process it
successfully, but the network connection can break before School OS
observes the response — School OS then believes the attempt failed and
retries, and the receiver genuinely receives the same event twice.
Receivers **must** deduplicate using the event id (`X-SchoolOS-Event-Id`)
or delivery id (`X-SchoolOS-Delivery-Id`) — this is documented
prominently for third-party developers, not left implicit.

This does not mean School OS's own logical-delivery bookkeeping is
duplicated: the `(webhook_endpoint_id, event_id)` unique constraint
guarantees exactly one `webhook_deliveries` row exists regardless of
how many times the underlying domain event consumer is redelivered
(proven in `WebhookReliabilityTest`/`EndToEndProofBTest`'s duplicate-
event tests). "At-least-once" describes the **external HTTP contract**,
not School OS's own internal state.

## A suspended School (Phase 0N.9, ADR 0047)

While a School is not `active` (`provisioning`, `suspended`), no
outbound webhook HTTP request is made for it. The fanout still records
the delivery row (evidence of what was due); `DeliverWebhookJob` re-checks
the School when it claims the delivery (reading the School row FOR SHARE
in the claim transaction) and, if it is not active, leaves the row
`retrying` with `next_attempt_at` set -- no attempt row, `attempts`
unchanged -- and `platform:webhook-deliveries-redispatch` skips non-active
Schools, so nothing loops. After RESUME the normal redispatch delivers it:
possibly late, and still at-least-once. Retention pruning continues.

## Manual redelivery

`WebhookDeliveryService::redeliver()` only accepts a **terminal**
delivery (`delivered`/`failed`/`abandoned`) and resets it to `pending`,
re-dispatching `DeliverWebhookJob` — it reuses the SAME logical
delivery identity (the unique constraint makes a genuinely new row for
the same pair impossible, and unnecessary): prior
`webhook_delivery_attempts` history is never mutated or deleted; a
redelivery's new attempt(s) simply continue the same `attempt_number`
sequence. Requires `integrations.webhooks.manage` and is idempotency-
key-protected (`docs/architecture/RELIABILITY.md`).

## Endpoint status

`active` | `disabled` — the smallest correct set (section 10). A
disabled endpoint receives no NEW deliveries (`WebhookFanoutConsumer`
only queries `status = 'active'`); already-queued/in-flight deliveries
for it are abandoned by `DeliverWebhookJob` the next time they're
attempted, rather than silently sent to a destination the administrator
just disabled. There is no automatic health-based auto-disable —
disabling is always an explicit administrator action (or a future,
separately-designed security-triggered one), never a side effect of
transient delivery failures.

## Test event (local/testing only)

`platform.webhook_test.v1`
(`App\Domain\Platform\Events\PlatformWebhookTestEmitted`), emitted only
via `POST /api/v1/schools/{schoolId}/webhook-test-events`
(`App\Http\Controllers\Api\V1\Internal\WebhookTestEventController`) —
registered only when `app()->environment(['local', 'testing'])`, and
still capability-gated (`integrations.webhooks.manage`) even there.
Exists purely so this subsystem's live proof (and any future manual
verification) can exercise the full chain without a real ERP business
event or fake Student module.

## Retention

Payload/response minimization (section 86/87) remains the primary
protection; deletion is secondary. Since the Phase 0C closeout,
`php artisan platform:webhook-deliveries-prune [--days=N] [--dry-run]`
(`App\Console\Commands\PruneWebhookDeliveries`, scheduled daily)
removes TERMINAL deliveries (`delivered`/`failed`/`abandoned`, no live
processing lease) whose last state change is older than
`WEBHOOKS_DELIVERY_RETENTION_DAYS` — per School, in bounded batches,
through the RLS-bound runtime connection, exactly like
`App\Console\Commands\PruneIdempotencyRecords`. Their append-only
attempt rows leave only through `ON DELETE CASCADE`, never individually;
pending/delivering/retrying deliveries, school audit events and the
outbox are never touched.

`WEBHOOKS_DELIVERY_RETENTION_DAYS` has **no default**: until an operator
sets it, the scheduled run deletes nothing. Full record:
`docs/architecture/PHASE-0C-CLOSEOUT.md`.

**E21.2A (E21-D4, project-adopted, pending legal ratification;
`docs/security/E21-RETENTION-DETERMINATION.md`):**
- **Separate periods.** `WEBHOOKS_DELIVERY_RETENTION_DAYS` now governs
  `delivered` rows only (adopted: 30). `failed`/`abandoned` rows use
  `WEBHOOKS_FAILED_DELIVERY_RETENTION_DAYS` (adopted: 90). Each is
  fail-closed, and `--failed-days` overrides the second for a manual run.
- **Holds.** A School on `RETENTION_HOLD_SCHOOL_IDS` is skipped.
- **Outbox.** `platform:outbox-prune` (daily) deletes processed outbox rows
  and their consumer receipts after `OUTBOX_RETENTION_DAYS` (adopted: 30).
  It never deletes a row whose event still has a delivery that is not
  `delivered`: a retry or redelivery reads the payload from the outbox, so
  that row outlives the delivery's own period.

## Rate limiting

Rate limiting landed in Phase 0C.4 (`App\Providers\RateLimiterServiceProvider`,
`docs/architecture/OBSERVABILITY.md`). Every webhook management route —
including manual redelivery — carries the tenant-aware
`throttle:webhook-admin` limiter, so a redelivery cannot bypass the
limit applied to other administrative actions.

## Health

A customer's webhook endpoint being unreachable is never a factor in
`/health/ready` or any platform readiness signal — it is the
integration's problem, tracked in delivery history, not a platform
outage.

## Payment and webhook-delivery-vs-API-idempotency distinctions

Documented once, in full, in `docs/architecture/RELIABILITY.md`
("Payment and webhook readiness") — not duplicated here. Summary:
client API `Idempotency-Key` idempotency, payment-provider webhook
idempotency, and THIS document's outbound webhook delivery idempotency
are three separate mechanisms; a future Fees/Payments module needs all
three, and none substitutes for another.
