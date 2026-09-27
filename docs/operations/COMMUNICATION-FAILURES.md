# Runbook: Communication delivery failures (OBS-17, OBS-18)

ADR 0051 §14. Communication deliveries run on the `notifications` queue
(`ProcessCommunicationDeliveryJob`) with a 30 s processing lease and retry
backoff 30 s / 2 min / 5 min (three attempts). A **deferred** delivery
(`queued` with a future `next_attempt_at`, e.g. quiet hours) is waiting
legitimately and never alerts.

## OBS-17 — overdue

An immediate delivery unclaimed for one lease, a `queued` delivery past its
`next_attempt_at`, or a `sending` delivery past its lease, not picked up
for 5 min (SEV-3) / 30 min (SEV-2).

1. Check OBS-08 for the `notifications` worker class and OBS-05 for the
   scheduler (the `communication-deliveries-redispatch` sweep and the
   `communications-publish-scheduled` publisher run every minute).
2. `console platform:operations-status` — `communications` shows unfinished
   deliveries by state, overdue count and oldest age.
3. After a Redis loss: `REDIS-LOSS-RECOVERY.md`.

## OBS-18 — failure ratio (operator baseline)

The share of deliveries ending `failed`/`bounced`/`rejected` in an hour
above the operator value (disabled until set). v1 has no external email/SMS
provider account yet (O13: the email layer is implemented, Phase 0O.9A;
email deliveries now become `bounced`/`rejected` only from provider evidence
or suppression -- see EMAIL-DELIVERABILITY.md); in-app delivery failures point at application errors
— check `event_code=application.exception` logs for the notifications job.
Metrics carry the channel only, never recipient or message.
