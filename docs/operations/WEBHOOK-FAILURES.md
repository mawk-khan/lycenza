# Runbook: webhook delivery failures (OBS-15, OBS-16)

ADR 0051 §14. Outbound webhooks (ADR 0026, `docs/security/INTEGRATION-SECURITY.md`)
are at-least-once and retried on their own schedule (60 s, 5 min, 30 min,
2 h, 8 h; six attempts), so **a delivery waiting for its next attempt is
not a problem** and never alerts. Alerts fire on:

- **OBS-15 — overdue:** a delivery is eligible (`pending` unclaimed for one
  60 s lease, `retrying` past `next_attempt_at`, `delivering` past its lease)
  but no worker picked it up for 5 min (SEV-3) / 30 min (SEV-2).
- **OBS-16 — failing:** permanently failed or abandoned deliveries in an hour
  above the operator baseline (SEV-3; disabled until set).

## OBS-15 (overdue) — the platform is not delivering

1. Check OBS-08 for the `integrations` worker class and OBS-05 for the
   scheduler: an overdue backlog almost always means the `integrations`
   worker or the scheduler is down. Restart the failed process class.
2. `console platform:operations-status` — the `webhooks` component shows
   the overdue count and oldest age; `recovery` shows the webhook sweep's
   freshness.
3. After a Redis loss, the sweep re-dispatches within one lease
   (`REDIS-LOSS-RECOVERY.md`); `console platform:recover-queued-work` runs it now.
4. Never edit `webhook_deliveries` by hand; the lease claim keeps one send
   per delivery.

## OBS-16 (failing) — customers' endpoints are failing

Customer endpoints fail for their own reasons; this is not an ERP outage
(rule 56) and never pages on one endpoint. Look for a pattern (one School's
endpoint vs. many), using the per-delivery attempt history in the School's
webhook administration view — the metrics deliberately carry no URL,
School or delivery id. SSRF rejections and 3xx/4xx responses are
permanent by design (rules 41-42, 49).
