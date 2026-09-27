# Alert index (OBS-01 … OBS-27)

ADR 0051 §14. The rules are generated from
`App\Support\Observability\Alerts\AlertCatalog` into
[`lycenza-alerts.rules.yml`](lycenza-alerts.rules.yml) (provider-neutral
Prometheus rule format; `php artisan platform:alerts-export` re-renders
them with the deployment's operator values). The application sends **no**
notification; **no alert routing is active** — the deployment attaches it.

Severity: **SEV-1 Critical** (service/security boundary unavailable or
data-loss risk — immediate), **SEV-2 High** (major subsystem degraded —
prompt), **SEV-3 Warning** (investigate next working day). Threshold
source: **A** contract, **B** runtime cadence, **C** operator value.

| ID | Condition | Severities (source) | Runbook |
|---|---|---|---|
| OBS-01 | Web readiness failing | SEV-1 (C: for 120 s) | [MAINTENANCE-WINDOW-RELEASE](../MAINTENANCE-WINDOW-RELEASE.md) |
| OBS-02 | PostgreSQL unavailable | SEV-1 (C: for 120 s) | [BACKUP-AND-RESTORE](../BACKUP-AND-RESTORE.md) |
| OBS-03 | Redis unavailable | SEV-1 (C: for 120 s) | [REDIS-LOSS-RECOVERY](../REDIS-LOSS-RECOVERY.md) |
| OBS-04 | AI Gateway not ready | SEV-3 only (C: for 600 s) | [PRODUCTION-IMAGES-AND-PROCESSES](../PRODUCTION-IMAGES-AND-PROCESSES.md) |
| OBS-05 | Scheduler stopped | SEV-2 > 300 s, SEV-1 > 1800 s (B) | [MAINTENANCE-WINDOW-RELEASE](../MAINTENANCE-WINDOW-RELEASE.md) |
| OBS-06 | One scheduled task stale | SEV-3: minute task > 300 s, daily task > 26 h (B) | [PRODUCTION-IMAGES-AND-PROCESSES](../PRODUCTION-IMAGES-AND-PROCESSES.md) |
| OBS-07 | Duplicate scheduler suspected | SEV-3: > 1.5 runs/min over 10 min (B) | [PRODUCTION-IMAGES-AND-PROCESSES](../PRODUCTION-IMAGES-AND-PROCESSES.md) |
| OBS-08 | Worker class not processing | SEV-2: canary heartbeat > 300 s (B) | [PRODUCTION-IMAGES-AND-PROCESSES](../PRODUCTION-IMAGES-AND-PROCESSES.md) |
| OBS-09 | Oldest queued job too old | SEV-3 > 300 s, SEV-2 > 1800 s (B) | [FAILED-JOBS](../FAILED-JOBS.md) |
| OBS-10 | Jobs failed permanently | SEV-3 any in 15 min (B); SEV-2 above operator rate (C) | [FAILED-JOBS](../FAILED-JOBS.md) |
| OBS-11 | Outbox dispatch backlog | SEV-3 > 300 s, SEV-2 > 1800 s (B) | [REDIS-LOSS-RECOVERY](../REDIS-LOSS-RECOVERY.md) |
| OBS-12 | Outbox reconciliation not succeeding | SEV-2: last success > 300 s or a failed run (B) | [REDIS-LOSS-RECOVERY](../REDIS-LOSS-RECOVERY.md) |
| OBS-13 | Stale unacknowledged outbox events persist | SEV-3: > 0 for 30 min (B) | [REDIS-LOSS-RECOVERY](../REDIS-LOSS-RECOVERY.md) |
| OBS-14 | Outbox events exhausted | SEV-2: any new `failed` in 1 h (B) | [REDIS-LOSS-RECOVERY](../REDIS-LOSS-RECOVERY.md) |
| OBS-15 | Webhook deliveries overdue | SEV-3 > 300 s, SEV-2 > 1800 s (B) | [WEBHOOK-FAILURES](../WEBHOOK-FAILURES.md) |
| OBS-16 | Webhook finals above baseline | SEV-3 (C; disabled until set) | [WEBHOOK-FAILURES](../WEBHOOK-FAILURES.md) |
| OBS-17 | Communication deliveries overdue | SEV-3 > 300 s, SEV-2 > 1800 s (B) | [COMMUNICATION-FAILURES](../COMMUNICATION-FAILURES.md) |
| OBS-18 | Communication failure ratio | SEV-3 (C; disabled until set) | [COMMUNICATION-FAILURES](../COMMUNICATION-FAILURES.md) |
| OBS-19 | Automation recovery / overdue | SEV-3 > 300 s, SEV-2 > 1800 s (B) | [Automation module](../../modules/AUTOMATION.md) |
| OBS-20 | PostgreSQL recovery point too old | SEV-2 > 10 min or absent, **SEV-1 > 15 min** (A: RPO 15 min) | [BACKUP-AND-RESTORE](../BACKUP-AND-RESTORE.md) |
| OBS-21 | PostgreSQL base backup stale/failed | SEV-2 > 26 h, absent or failed; SEV-1 > 50 h (A/C) | [BACKUP-AND-RESTORE](../BACKUP-AND-RESTORE.md) |
| OBS-22 | Object copy stale/failed | SEV-2 > 20 h, absent or failed; **SEV-1 > 24 h** (A: RPO 24 h) | [BACKUP-AND-RESTORE](../BACKUP-AND-RESTORE.md) |
| OBS-23 | Restore drill overdue/failed | SEV-3 > 92 days or none; SEV-2 > 120 days or FAIL (A: quarterly) | [RESTORE-DRILL-RECORD](../RESTORE-DRILL-RECORD.md) |
| OBS-24 | Storage validation failing | SEV-2: `verify-storage` FAIL (B); failures above operator rate (C) | [BACKUP-AND-RESTORE](../BACKUP-AND-RESTORE.md) |
| OBS-25 | Security-boundary stress | SEV-3 (C; disabled until set) | [INTEGRATION-SECURITY](../../security/INTEGRATION-SECURITY.md) |
| OBS-26 | Telemetry collection failing | SEV-2 (C: for 300 s) | [TELEMETRY-COLLECTION](../TELEMETRY-COLLECTION.md) |
| OBS-27 | Service-to-service authentication failing (unknown kid, bad signature, expired key, or the peer refusing our assertions), or a service key ≥ 76 days old (ADR 0053) | SEV-3 only (A); never pages -- the AI Gateway is optional | [SERVICE-KEY-ROTATION](../SERVICE-KEY-ROTATION.md) |

Operator values (`ALERT_*`, `config/observability.php` `alerts`): unset
means the tier is **disabled** and the exported file says so; a value
outside its safeguard bounds makes the export fail rather than be accepted.

Alerting shortens the time to notice a problem. It does not itself
guarantee an RPO or RTO.
