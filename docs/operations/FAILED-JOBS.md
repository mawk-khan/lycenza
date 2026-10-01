# Runbook: failed jobs and queue backlog (OBS-09, OBS-10)

ADR 0051 §14. Jobs that own their retries (`DeliverWebhookJob`,
`ProcessCommunicationDeliveryJob`, Automation) declare `$tries = 1` and
record their own failure state; a row in `failed_jobs` therefore means an
**unexpected** failure worth looking at (OBS-10, SEV-3; a SEV-2 rate is an
operator value). OBS-09 fires when the oldest waiting job on a queue is
older than 5 min (SEV-3) / 30 min (SEV-2) — a worker class too slow or
down.

1. `console platform:failed-jobs --summary`, then `console platform:failed-jobs`
   for the recent failures (class and queue; the stored exception text is
   operator-only diagnostic data — never paste it into tickets or chat).
2. Correlate with logs: `event_code=application.exception` and the job
   class in `job`; the `request_id`/`correlation_id` link to the originating
   request.
3. Fix the cause, then retry deliberately (`console queue:retry <uuid>`) —
   durable work (outbox, webhooks, Communications, Automation) is also
   re-driven by the recovery sweeps, so retrying is rarely needed for it.
4. For OBS-09 check OBS-08 (worker class alive?) and worker capacity.

**Retention (E21-D13, project-adopted, pending legal ratification).** A
failed job's payload and exception may contain personal data.
`platform:failed-jobs-prune` (daily) deletes rows `FAILED_JOBS_RETENTION_DAYS`
(adopted: 30) after `failed_at`. It has no default, so it deletes nothing
while unset. Handle a failure within that window, and use `--dry-run` to
preview.
