# Redis loss and queued-work recovery

ADR 0050 §10. **Redis holds no durable business record.** PostgreSQL is
the source of truth for every queued business effect; losing Redis (a
crash without persistence, a flush, a replacement instance) loses queued
jobs, sessions, caches, locks and rate-limit counters — and the
application rebuilds or re-derives all of them. Redis persistence (AOF) is
**not** required for correctness.

## What is lost, and what happens

| Redis content | On loss | Recovery |
|---|---|---|
| Queued jobs (`default`, `integrations`, `notifications`) | Jobs disappear | **Rebuilt from PostgreSQL** by the scheduled sweeps below; each effect still happens exactly once |
| Sessions (`SESSION_DRIVER=redis`) | Every user is signed out; MFA assurance and platform elevation pointers go with the session | Users sign in again (and re-verify MFA where required). Elevations are database-checked and expire on their own; nothing is granted by a lost session. |
| Cache (capability cache, settings caches, …) | Cold cache | Rebuilt on read from PostgreSQL; no cache entry is a source of truth |
| Locks (`TenantLock`, `withoutOverlapping`, `onOneServer`) | Released | Every correctness guarantee is a PostgreSQL constraint/lease, never the lock alone (rules 30, 48); a lost lock can at most allow a duplicate attempt that the database then refuses |
| Rate-limit counters | Reset | Limits start counting again |
| Maintenance flag | Not in Redis (PostgreSQL `cache` table) | — |

## Queued-work reconciliation (PostgreSQL-driven)

| Durable state | Stranded when | Swept by (scheduled every minute) | Safe because |
|---|---|---|---|
| `domain_event_outbox` `dispatched`, no `processed_at` | its `ProcessOutboxEventJob` was lost | `platform:outbox-dispatch` → `OutboxReconciler`, after `STALE_AFTER_SECONDS` (600 s > the job's 5 × 30 s + backoff lifecycle) | Consumers are receipted (`event_consumer_receipts`, unique); an event whose consumers all hold receipts is only acknowledged, never replayed; rows are claimed `FOR UPDATE SKIP LOCKED`, bounded per batch, re-dispatched at most once per window, and failed after 25 reconciliations |
| `webhook_deliveries` `pending` | its `DeliverWebhookJob` was lost | `platform:webhook-deliveries-redispatch`, after one processing lease (60 s) | The job's atomic lease claim: a duplicate job finds nothing to claim; the attempt counter is untouched; `updated_at` is bumped so each row is re-dispatched at most once per lease |
| `communication_deliveries` `pending` (immediate) | its `ProcessCommunicationDeliveryJob` was lost | `platform:communication-deliveries-redispatch`, after one processing lease (30 s) | Same atomic claim; deferred (`queued` + `next_attempt_at`) deliveries are never pulled forward |
| `automation_executions` `pending` | its job was lost | `automation:executions-redispatch` (existing; `next_attempt_at` grace 60 s) | Existing execution claim — reused, not duplicated |

`ProcessOutboxEventJob` now records `processed_at` when every consumer has
succeeded and marks the event `failed` when its retries are exhausted, so
the reconciler only ever looks at genuinely unacknowledged rows (partial
index `domain_event_outbox_unacknowledged`).

`php artisan platform:recover-queued-work [--batch=100]` runs all four
sweeps once, for an operator who does not want to wait for the scheduler.

## After a Redis loss

1. Bring Redis back (empty is fine) with its password (`REDIS_PASSWORD` is
   required in production).
2. Nothing else is required: the scheduler's sweeps restore queued work
   within the stale windows above (≤ 10 minutes for outbox events).
3. Optionally, after 10 minutes: `console platform:recover-queued-work`
   and `console platform:operations-status`.
4. Expect support contacts about being signed out.

## Proof

`Tests\Feature\Recovery\RedisQueueLossRecoveryTest` — on **real Redis**
(an isolated database index), with time moved by `travel()`, never sleep:
durable outbox and webhook work, queued jobs lost, Redis empty, sweeps run,
jobs restored, one webhook HTTP request and one attempt row; an immediate
Communication delivery the same; deferred deliveries untouched; duplicate
jobs after completion do nothing; Automation's own sweep is asserted to
remain in the recovery set. `Tests\Feature\Recovery\OutboxReconcilerTest`
covers staleness, acknowledgement from receipts, partial receipts,
batching and the attempt bound.

## Observability (Phase 0O.5A)

Watch `lycenza_reconciliation_*` and the `recovery` component of
`platform:operations-status`; alerts OBS-11 to OBS-14 (outbox), OBS-15
(webhooks), OBS-17 (Communications) and OBS-19 (Automation). A Redis loss
also resets the metrics counters (they live in Redis); rate-based alerts
tolerate resets, and every state metric is recomputed from PostgreSQL.
