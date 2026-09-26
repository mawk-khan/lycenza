# Dashboard specification (provider-neutral)

ADR 0051 §19. Four views, defined as metric queries (PromQL, portable to
any OpenMetrics/Prometheus-compatible backend). No vanity metrics, no
tenant rankings, no per-School view — the metrics carry no School label by
construction. Guard-tested: every metric named here exists in
`MetricCatalog` (`Tests\Feature\Observability\AlertCatalogTest`).

## 1. Service health

| Panel | Query |
|---|---|
| Readiness by dependency | `lycenza_readiness_status` |
| Requests by surface and class | `sum by (request_surface, status_class) (rate(lycenza_http_requests_total[5m]))` |
| Rejections (401/403/404/419/429) | `sum by (request_surface, code) (rate(lycenza_http_rejections_total[5m]))` |
| Latency p95 by surface | `histogram_quantile(0.95, sum by (request_surface, le) (rate(lycenza_http_request_duration_seconds_bucket[5m])))` |
| Database errors by SQLSTATE class | `sum by (sqlstate_class) (rate(lycenza_database_query_errors_total[15m]))` |
| AI Gateway ready (optional) | `lycenza_ai_gateway_ready` |

## 2. Queues, workers and scheduler

| Panel | Query |
|---|---|
| Worker-class heartbeat age | `time() - lycenza_queue_heartbeat_last_success_timestamp_seconds` |
| Pending / delayed / reserved | `lycenza_queue_pending_jobs`, `lycenza_queue_delayed_jobs`, `lycenza_queue_reserved_jobs` |
| Oldest pending job age | `lycenza_queue_oldest_pending_age_seconds` |
| Throughput and failures | `sum by (queue) (rate(lycenza_queue_jobs_processed_total[5m]))`, `sum by (queue) (increase(lycenza_queue_jobs_failed_total[1h]))`, `lycenza_failed_jobs` |
| Task heartbeat age | `time() - lycenza_scheduler_task_last_success_timestamp_seconds` |
| Task runs per minute (duplicate-scheduler signal) | `rate(lycenza_scheduler_task_runs_total{outcome="success"}[10m]) * 60` |
| Task duration p95 | `histogram_quantile(0.95, sum by (scheduled_task, le) (rate(lycenza_scheduler_task_duration_seconds_bucket[1h])))` |

## 3. Integrations and durable-work recovery

| Panel | Query |
|---|---|
| Outbox | `lycenza_outbox_pending_events`, `lycenza_outbox_oldest_pending_age_seconds`, `lycenza_outbox_unacknowledged_dispatched_events`, `lycenza_outbox_stale_events`, `lycenza_outbox_failed_events` |
| Recovery sweeps | `sum by (recovery_source, result) (increase(lycenza_reconciliation_rows_total[1h]))`, `time() - lycenza_reconciliation_last_success_timestamp_seconds`, `sum by (recovery_source, outcome) (increase(lycenza_reconciliation_runs_total[1h]))` |
| Webhooks | `lycenza_webhook_deliveries`, `lycenza_webhook_overdue_deliveries`, `lycenza_webhook_oldest_overdue_age_seconds`, `sum by (outcome) (increase(lycenza_webhook_attempts_total[1h]))`, `sum by (outcome) (increase(lycenza_webhook_deliveries_finished_total[1h]))` |
| Communications | `lycenza_communication_deliveries`, `lycenza_communication_overdue_deliveries`, `lycenza_communication_oldest_overdue_age_seconds`, `sum by (delivery_channel, outcome) (increase(lycenza_communication_deliveries_finished_total[1h]))` |
| Automation | `sum by (outcome) (increase(lycenza_automation_executions_total[1h]))`, `lycenza_automation_pending_executions`, `lycenza_automation_overdue_executions`, `increase(lycenza_automation_review_items_created_total[1d])` |
| Partner auth failures | `sum by (outcome) (increase(lycenza_partner_auth_failures_total[1h]))` |

## 4. Backup and recovery

| Panel | Query |
|---|---|
| PostgreSQL recovery-point age | `time() - lycenza_backup_recovery_point_timestamp_seconds{backup_store="postgresql"}` |
| Backup freshness per store | `time() - lycenza_backup_last_success_timestamp_seconds` |
| Last backup failure | `lycenza_backup_last_failure_timestamp_seconds` |
| Restore drill | `time() - lycenza_restore_drill_last_success_timestamp_seconds`, `lycenza_restore_drill_last_result`, `lycenza_restore_drill_duration_seconds`, `lycenza_restore_drill_recovery_point_age_seconds` |
| Operator verifications | `lycenza_verification_last_result` |
| Storage failures | `sum by (operation) (increase(lycenza_storage_operation_failures_total[1h]))` |

The backup and drill panels stay empty until the deployment connects its
evidence file (`BACKUP-AND-RESTORE.md`); empty is itself an alert (OBS-20 to
OBS-23), never "healthy".
