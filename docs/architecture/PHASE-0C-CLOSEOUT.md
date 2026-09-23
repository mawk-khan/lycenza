# Phase 0C — Reliability & Integration Substrate: Closeout

**Status: COMPLETE (2026-09-23)**, with the deferrals recorded in
section 6. This report consolidates the core substrate, 0C.2, 0C.3,
0C.3A and 0C.4 into one checkpoint record, and records the closeout
work that finished the phase.

Roadmap entry: `docs/roadmap/MASTER-ROADMAP.md`, "Phase 0C — Reliability
& Integration Substrate".

## 1. Original scope

From the roadmap: every later business module needs to reliably trigger
notifications, integrations, automation, analytics and AI "without
losing work, duplicating dangerous side effects, crossing tenants, or
tightly coupling to external infrastructure". Phase 0C was re-sequenced
ahead of the organizational phase (now 0D) to build that substrate first,
in these checkpoints:

| Checkpoint | Scope |
|---|---|
| **0C (core substrate)** | durable domain events + transactional outbox, event-consumer idempotency, `SKIP LOCKED` outbox dispatcher, notification infrastructure (fake/local providers only), webhook infrastructure (HMAC, SSRF protection, retry/dead-letter), service identities, feature flags, School settings, AI Gateway durable-audit write-back; end-to-end Proofs A, B and C |
| **0C.2** API Idempotency Foundation | opt-in `idempotent` middleware + `IdempotencyGuard` |
| **0C.3** Webhook & External Integration Delivery Foundation | production-grade webhook subsystem |
| **0C.3A** Test-Database Safety & Transactional-Outbox Architecture Closure | `TestDatabaseGuard`, `platform:test-db-reset`, ADR 0025 |
| **0C.4** Health, Scheduler, Queue Operations & Observability Completion | liveness/readiness, heartbeats, queue health, failed jobs, rate limiting, logging/metrics/tracing, internal diagnostics |
| **Closeout** (roadmap: "remaining Phase 0C work") | webhook delivery/attempt retention pruning; this consolidated report |

The audit for this closeout also found one item explicitly deferred to
"the rest of Phase 0C's operational-safety work": scheduling
`platform:idempotency-prune` (`docs/architecture/RELIABILITY.md`).

## 2. Completeness matrix

| Requirement | Status | Evidence |
|---|---|---|
| Durable domain events + transactional outbox | COMPLETE | `domain_event_outbox`; ADR 0025; `tests/Feature/Events/OutboxTransactionalityTest.php` |
| Event-consumer idempotency | COMPLETE | `event_consumer_receipts` (`EventConsumerReceipt`); Proof A |
| Reliable queued dispatch (`SKIP LOCKED`) | COMPLETE | `platform:outbox-dispatch` (`DispatchOutboxEvents`), scheduled every minute |
| Notification infrastructure (fake/local providers) | COMPLETE | `App\Support\Notifications` (`NotificationDispatcher`, `Providers/`) |
| Webhook infrastructure (HMAC, SSRF, retry/dead-letter) | COMPLETE | 0C.3 below; ADRs 0018, 0026, 0027 |
| Service identities distinct from User | COMPLETE | `service_identities`, `service_identity_capabilities`; `App\Support\ServiceIdentities`; `tests/Feature/Api/Internal/AiAuditControllerTest.php` |
| Feature flags | COMPLETE | `feature_flags`, `feature_flag_school_overrides`; `App\Support\FeatureFlags` |
| School settings foundation | COMPLETE | `school_settings`; `App\Support\Settings` |
| AI Gateway durable-audit write-back | COMPLETE | ADR 0023; `AiAuditControllerTest`, `tests/Feature/Ai/AiGatewayClientTest.php` |
| Proof A (event → outbox → dispatcher → consumer → receipt → audit) | COMPLETE | `tests/Feature/Events/EndToEndProofATest.php` |
| Proof B (event → webhook → real local HTTP → HMAC → no duplicate on replay) | COMPLETE | `tests/Feature/Webhooks/EndToEndProofBTest.php` |
| Proof C (Laravel → signed context → FastAPI → durable audit) | COMPLETE | Phase 0C live cross-container proof; `AiGatewayClientTest`, `services/ai` tests |
| 0C.2 API idempotency | COMPLETE | `EnsureIdempotent`, `IdempotencyGuard`; `tests/Feature/Idempotency/*` |
| 0C.3 webhook delivery foundation | COMPLETE | `webhook_endpoints/subscriptions/deliveries/delivery_attempts`; `DeliverWebhookJob`; `platform:webhook-deliveries-redispatch`; `tests/Feature/Webhooks/*` |
| 0C.3A test-DB safety + outbox ADR | COMPLETE | `TestDatabaseGuard`, `platform:test-db-reset` (made repeatable 2026-09-23); ADR 0025; `tests/Unit/Support/Testing/TestDatabaseGuardTest.php` |
| 0C.4 health/scheduler/queue/observability | COMPLETE | `docs/architecture/OBSERVABILITY.md`; `/api/health/*`; `platform:operations-status`, `platform:failed-jobs`; `tests/Feature/Observability`, `tests/Feature/RateLimiting` |
| **Webhook delivery/attempt retention pruning** | **COMPLETE (mechanism); retention period DEFERRED** | section 3; `platform:webhook-deliveries-prune`; `tests/Feature/Webhooks/PruneWebhookDeliveriesTest.php` |
| **Scheduled idempotency pruning** | **COMPLETE** | `routes/console.php` `idempotency-prune` (daily) |
| Consolidated closeout report | COMPLETE | this document |
| Domain-event outbox retention | DEFERRED WITH DOCUMENTED REASON | section 6 |

## 3. Webhook delivery retention (closeout implementation)

- **Command:** `php artisan platform:webhook-deliveries-prune [--days=N] [--dry-run]`
  (`App\Console\Commands\PruneWebhookDeliveries`).
- **What is eligible:** a `webhook_deliveries` row whose status is
  TERMINAL (`delivered`, `failed`, `abandoned` —
  `WebhookDelivery::TERMINAL_STATUSES`), that holds no live processing
  lease, and whose last state change (`updated_at`) is strictly older
  than the retention period.
- **Attempts:** `webhook_delivery_attempts` is append-only (UPDATE/DELETE
  revoked from the runtime role). Attempt rows are never deleted
  individually; they leave only with their delivery through the existing
  `ON DELETE CASCADE`, which PostgreSQL applies independently of the
  session's table privileges.
- **Never touched:** `pending`/`delivering`/`retrying` deliveries; school
  audit events (`integrations.webhook_*` in `school_audit_events`);
  the domain-event outbox; endpoints and subscriptions.
- **Tenant safety:** one School at a time inside
  `TenantContext::withSchool()`, on the RLS-bound runtime connection —
  never a cross-tenant DELETE, never `pgsql_admin` (the
  `PruneIdempotencyRecords` pattern).
- **Bounded and concurrency-safe:** batches of `webhooks.prune_batch_size`
  (default 500), each a short DELETE by id. The DELETE re-applies the
  status/lease/age predicate itself, so a delivery an administrator
  redelivers (→ `pending`) between the batch SELECT and the DELETE is not
  removed. Idempotent: a re-run deletes nothing new.
- **Retention period:** `WEBHOOKS_DELIVERY_RETENTION_DAYS`
  (`config('webhooks.delivery_retention_days')`) has **no default**. No
  retention period for delivery history has been decided, and
  `docs/security/DATA-CLASSIFICATION.md` marks retention periods
  **[LEGAL REVIEW REQUIRED]**, so none is invented here. While unset, the
  scheduled run deletes nothing and logs
  `webhooks.deliveries_prune.skipped`. `--days` sets a period for one
  explicit manual run.
- **Operational logging:** `webhooks.deliveries_prune.pruned` /
  `.would_prune` per School (id and count only), `.completed` summary.
- **Scheduling:** daily 02:20 (`webhook-deliveries-prune`) with
  `withoutOverlapping()`, in `routes/console.php`, run by the existing
  scheduler (`schedule:work` in DDEV). It records no scheduler heartbeat:
  `OperationalStatusService` judges heartbeats against a single
  minute-scale staleness threshold that a daily task would always breach.

`platform:idempotency-prune` is scheduled the same way (daily 02:10,
`idempotency-prune`): its 48-hour TTL was already a documented policy.

## 4. Tables, commands, ADRs

- **Migrations/tables:** `domain_event_outbox`, `event_consumer_receipts`,
  `api_idempotency_keys`, `school_settings`, `feature_flags`,
  `feature_flag_school_overrides`, `service_identities`,
  `service_identity_capabilities`, `webhook_endpoints`,
  `webhook_subscriptions`, `webhook_deliveries`,
  `webhook_delivery_attempts`, `scheduler_heartbeats` (all
  `2026_08_23_08xxxx`). The closeout adds no migration.
- **Scheduled commands:** `platform:outbox-dispatch`,
  `platform:webhook-deliveries-redispatch`,
  `platform:communication-deliveries-redispatch`,
  `communications:publish-scheduled` (every minute);
  `platform:idempotency-prune`, `platform:webhook-deliveries-prune`
  (daily).
- **Operator commands:** `platform:operations-status`,
  `platform:failed-jobs`, `platform:test-db-reset`.
- **ADRs:** 0018 (integration/webhook architecture), 0021 (runtime vs
  migration roles), 0022 (tenant-context propagation), 0023 (AI context
  token), 0024 (real-PostgreSQL tests), 0025 (transactional outbox), 0026
  (webhook delivery semantics), 0027 (SSRF destination policy).
- **Design docs:** `docs/architecture/RELIABILITY.md`, `INTEGRATIONS.md`,
  `OBSERVABILITY.md`, `EVENTS.md`, `docs/security/INTEGRATION-SECURITY.md`.

## 5. Tests (closeout)

`tests/Feature/Webhooks/PruneWebhookDeliveriesTest.php` (frozen clock,
relative timestamps): unconfigured retention deletes nothing; only old
terminal deliveries (and their attempts) are pruned while recent,
pending, retrying, delivering and lease-holding ones remain; strict
boundary; per-School tenant isolation; bounded batches; safe re-runs;
dry-run; `--days` override and validation; audit events and outbox
untouched; both prunes scheduled daily without overlap.

## 6. Deferred items and known limitations

- **Delivery-history retention period** — DEFERRED pending the
  [LEGAL REVIEW REQUIRED] retention decision. The mechanism is complete
  and scheduled; an operator enables it by setting
  `WEBHOOKS_DELIVERY_RETENTION_DAYS`.
- **Domain-event outbox retention** — DEFERRED WITH DOCUMENTED REASON
  (ADR 0025). `DeliverWebhookJob` reads the ORIGINAL event payload from
  `domain_event_outbox` at delivery time (`INTEGRATIONS.md`,
  "Immutability"), and consumer receipts/replay rely on it; pruning it
  safely requires the delivery-history policy above plus a rule that no
  non-terminal delivery still references the event. It was never part of
  the roadmap's Phase 0C list and is not required to close the phase.
- **Attempt-level pruning inside a retained delivery** — not provided by
  design: attempt rows are append-only; they are removed only with their
  delivery.
- **Heartbeat for daily tasks** — not recorded (see section 3); daily
  prune results are observable through their structured log lines.

## 7. Publication

Published to `main` via `integration/phase-0c-reliability-integration-closeout`.
The publication (merge) SHA is recorded in the commit that publishes
this document's closeout and in `git log --first-parent origin/main`
("merge: publish Phase 0C reliability & integration closeout").
