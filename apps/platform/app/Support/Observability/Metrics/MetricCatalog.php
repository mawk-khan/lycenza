<?php

namespace App\Support\Observability\Metrics;

use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Identity\Application\AccountRecovery\AccountRecoveryTelemetry;
use App\Support\Domains\DomainState;
use App\Support\Domains\DomainTelemetry;
use App\Support\Email\EmailTelemetry;
use App\Support\Observability\QueueName;
use App\Support\Retention\RetentionMetrics;
use App\Support\ServiceAuth\ServiceAuthContract;
use App\Support\ServiceAuth\ServiceAuthTelemetry;

/**
 * Phase 0O.5A (ADR 0051 §10-§13): the CLOSED catalog of every metric the
 * application exposes -- name, type, help and, for each label, its closed
 * value set. Nothing outside this catalog can be recorded or exported:
 * StoreMetricsRecorder drops it and MetricsExporter refuses it.
 *
 * Label keys come only from LABELS, a bounded operational vocabulary; no
 * key may name a School, user, person, record, request, client key,
 * delivery/event id, URL or IP (guard-tested), and no value is ever taken
 * from user input or an unbounded domain field.
 */
final class MetricCatalog
{
    public const PREFIX = 'lycenza_';

    public const HISTOGRAM_BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0, 30.0];

    /** The only label keys any metric may use. */
    public const LABELS = [
        'queue', 'scheduled_task', 'request_surface', 'status_class', 'code', 'outcome', 'delivery_channel',
        'recovery_source', 'backup_store', 'state', 'dependency', 'operation', 'sqlstate_class', 'check', 'result', 'component',
        // ADR 0053: service-to-service authentication (closed catalogs).
        'direction', 'service',
        // ADR 0054: custom-domain lifecycle target state (closed).
        'to',
        // ADR 0055: the closed email purpose catalog.
        'message_class',
    ];

    public const REQUEST_SURFACES = ['web', 'api_v1', 'api_partner', 'api_internal', 'health'];

    public const REJECTION_CODES = ['401', '403', '404', '419', '429'];

    public const RECOVERY_SOURCES = ['outbox', 'webhook', 'communication', 'automation', 'email'];

    /** PostgreSQL SQLSTATE classes worth distinguishing; anything else is `other`. */
    public const SQLSTATE_CLASSES = ['08', '22', '23', '25', '28', '40', '42', '53', '54', '55', '57', '58', 'XX', 'P0', 'other'];

    /** App\Support\ApiClients\PartnerCredentialAuthenticator's closed outcomes. */
    public const PARTNER_AUTH_OUTCOMES = ['malformed', 'unknown_key', 'client_missing', 'secret_mismatch', 'credential_revoked', 'credential_expired', 'client_revoked'];

    /**
     * @return array<string, array{type: 'counter'|'gauge'|'histogram', help: string, labels: array<string, list<string>>}>
     */
    public static function definitions(): array
    {
        $queues = self::queues();
        $tasks = self::scheduledTasks();
        $sources = self::RECOVERY_SOURCES;
        $channels = [...array_map(fn (CommunicationChannel $c) => $c->value, CommunicationChannel::cases()), 'other'];

        return [
            // HTTP and security (ADR 0051 §11 "API and security")
            'lycenza_http_requests_total' => self::counter('HTTP requests by surface and status class.', ['request_surface' => self::REQUEST_SURFACES, 'status_class' => ['1xx', '2xx', '3xx', '4xx', '5xx']]),
            'lycenza_http_rejections_total' => self::counter('Rejected HTTP requests (401/403/404/419/429) by surface.', ['request_surface' => self::REQUEST_SURFACES, 'code' => self::REJECTION_CODES]),
            'lycenza_http_request_duration_seconds' => self::histogram('HTTP request duration by surface.', ['request_surface' => self::REQUEST_SURFACES]),
            'lycenza_partner_auth_failures_total' => self::counter('Partner API authentication failures by closed outcome code.', ['outcome' => self::PARTNER_AUTH_OUTCOMES]),
            // ADR 0053 (Phase 0O.7A): service-to-service authentication. Closed
            // labels only -- never kid, School, User or request id.
            'lycenza_service_auth_total' => self::counter('Service-assertion authentication outcomes by direction, calling service and closed outcome code.', ['direction' => [ServiceAuthTelemetry::INBOUND, ServiceAuthTelemetry::OUTBOUND], 'service' => [...ServiceAuthContract::SERVICES, 'unknown'], 'outcome' => ServiceAuthTelemetry::OUTCOMES]),
            'lycenza_service_signing_key_age_days' => self::gauge('Age in days of this process\'s own service signing key (hard limit 90; alert from 76). Only when the AI Gateway is configured.', ['service' => [ServiceAuthContract::PLATFORM]]),
            'lycenza_service_verification_key_max_age_days' => self::gauge('Age in days of the oldest key in the verification ring for a calling service. Only when the AI Gateway is configured.', ['service' => [ServiceAuthContract::AI_GATEWAY]]),
            // ADR 0054 (Phase 0O.8A): the Host boundary and custom School
            // domains. Closed labels only -- never hostname, School or domain id.
            'lycenza_host_responses_total' => self::counter('Requests answered by the Host boundary itself: 421 unknown Host, 404 health or surface outside it, 308 alias.', ['outcome' => DomainTelemetry::HOST_OUTCOMES]),
            'lycenza_domain_checks_total' => self::counter('Custom-domain checks by check and closed outcome.', ['check' => DomainTelemetry::CHECKS, 'outcome' => DomainTelemetry::CHECK_OUTCOMES]),
            'lycenza_domain_transitions_total' => self::counter('Custom-domain lifecycle transitions by target state.', ['to' => array_map(fn (DomainState $s) => $s->value, DomainState::cases())]),
            'lycenza_school_domains' => self::gauge('Custom domains by lifecycle state (computed at scrape).', ['state' => array_map(fn (DomainState $s) => $s->value, DomainState::cases())]),
            'lycenza_domain_certificate_min_days_remaining' => self::gauge('Fewest whole days before any active custom domain\'s certificate expires (absent when none is recorded).', []),
            'lycenza_domain_indeterminate_max_age_seconds' => self::gauge('How long the oldest live custom domain has had only indeterminate DNS/TLS results (0 when none).', []),
            'lycenza_api_token_operation_errors_total' => self::counter('Human API token issue/revoke operations that failed unexpectedly.', ['operation' => ['issue', 'revoke']]),
            // E21.2B: retention maintenance rows per closed category; failures are the scheduler task metrics.
            'lycenza_retention_rows_total' => self::counter('Retention maintenance rows by category and outcome.', ['operation' => RetentionMetrics::categories(), 'outcome' => RetentionMetrics::OUTCOMES]),
            'lycenza_idempotency_requests_total' => self::counter('Idempotency-Key outcomes (formerly idempotency_{outcome}_total log lines).', ['outcome' => ['new', 'replay', 'conflict', 'in_progress', 'failed']]),

            // Health and dependencies
            'lycenza_readiness_status' => self::gauge('1 when the readiness dependency check passes, 0 otherwise (computed at scrape).', ['dependency' => ['postgresql', 'redis']]),
            'lycenza_ai_gateway_ready' => self::gauge('1 when the AI Gateway /health/ready answers 200 (only when a Gateway is configured; optional subsystem).', []),
            'lycenza_dependency_check_failures_total' => self::counter('Failed readiness/status dependency checks.', ['dependency' => ['postgresql', 'redis', 'object_storage']]),
            'lycenza_database_query_errors_total' => self::counter('Unhandled database errors by SQLSTATE class.', ['sqlstate_class' => self::SQLSTATE_CLASSES]),
            'lycenza_storage_operation_failures_total' => self::counter('Object-storage operation failures.', ['operation' => ['read', 'write', 'delete', 'exists']]),
            'lycenza_verification_last_result' => self::gauge('1 PASS / 0 FAIL of the last operator verification run.', ['check' => ['verify_database', 'verify_storage', 'verify_restore']]),

            // Scheduler and workers
            'lycenza_scheduler_task_runs_total' => self::counter('Scheduled task runs by outcome.', ['scheduled_task' => $tasks, 'outcome' => ['success', 'failure', 'skipped']]),
            'lycenza_scheduler_task_duration_seconds' => self::histogram('Scheduled task run duration.', ['scheduled_task' => $tasks]),
            'lycenza_scheduler_task_last_success_timestamp_seconds' => self::gauge('Unix time of the task\'s last successful run (scheduler_heartbeats).', ['scheduled_task' => $tasks]),
            'lycenza_queue_heartbeat_last_success_timestamp_seconds' => self::gauge('Unix time a job (at least the per-minute canary) was last processed on the queue.', ['queue' => $queues]),
            'lycenza_queue_pending_jobs' => self::gauge('Jobs waiting on the queue.', ['queue' => $queues]),
            'lycenza_queue_delayed_jobs' => self::gauge('Delayed jobs on the queue.', ['queue' => $queues]),
            'lycenza_queue_reserved_jobs' => self::gauge('Jobs reserved by a worker.', ['queue' => $queues]),
            'lycenza_queue_oldest_pending_age_seconds' => self::gauge('Age of the oldest pending job (0 when empty).', ['queue' => $queues]),
            'lycenza_queue_jobs_processed_total' => self::counter('Jobs processed.', ['queue' => [...$queues, 'other']]),
            'lycenza_queue_jobs_failed_total' => self::counter('Jobs that failed permanently.', ['queue' => [...$queues, 'other']]),
            'lycenza_failed_jobs' => self::gauge('Rows in failed_jobs.', ['queue' => [...$queues, 'other']]),

            // Outbox and recovery sweeps
            'lycenza_outbox_pending_events' => self::gauge('Outbox events not yet dispatched.', []),
            'lycenza_outbox_oldest_pending_age_seconds' => self::gauge('Age of the oldest undispatched outbox event.', []),
            'lycenza_outbox_unacknowledged_dispatched_events' => self::gauge('Dispatched outbox events without processed_at.', []),
            'lycenza_outbox_stale_events' => self::gauge('Unacknowledged dispatched events older than the reconciler stale window.', []),
            'lycenza_outbox_failed_events' => self::gauge('Outbox events marked failed (terminal).', []),
            'lycenza_reconciliation_runs_total' => self::counter('Recovery sweep runs.', ['recovery_source' => $sources, 'outcome' => ['success', 'failure']]),
            'lycenza_reconciliation_rows_total' => self::counter('Rows handled by recovery sweeps.', ['recovery_source' => $sources, 'result' => ['inspected', 'redispatched', 'acknowledged', 'failed']]),
            'lycenza_reconciliation_duration_seconds' => self::histogram('Recovery sweep duration.', ['recovery_source' => $sources]),
            'lycenza_reconciliation_last_success_timestamp_seconds' => self::gauge('Unix time of the sweep\'s last success (scheduler_heartbeats).', ['recovery_source' => $sources]),

            // Webhooks (never URL, School, secret or delivery id)
            'lycenza_webhook_deliveries' => self::gauge('Unfinished webhook deliveries by state.', ['state' => ['pending', 'retrying', 'delivering']]),
            'lycenza_webhook_overdue_deliveries' => self::gauge('Eligible webhook deliveries not picked up (legitimate backoff excluded).', []),
            'lycenza_webhook_oldest_overdue_age_seconds' => self::gauge('How long the most overdue webhook delivery has been eligible.', []),
            'lycenza_webhook_attempts_total' => self::counter('Webhook HTTP attempts by outcome.', ['outcome' => ['success', 'transient_failure', 'permanent_failure']]),
            'lycenza_webhook_deliveries_finished_total' => self::counter('Webhook deliveries reaching a final state.', ['outcome' => ['delivered', 'failed', 'abandoned']]),
            'lycenza_webhook_request_duration_seconds' => self::histogram('Webhook HTTP attempt duration.', []),

            // Communications (never recipient or person)
            'lycenza_communication_deliveries' => self::gauge('Unfinished Communication deliveries by state (queued with a future next_attempt_at is deferred).', ['state' => ['pending', 'deferred', 'queued_due', 'sending']]),
            'lycenza_communication_overdue_deliveries' => self::gauge('Eligible Communication deliveries not picked up (deferred excluded).', []),
            'lycenza_communication_oldest_overdue_age_seconds' => self::gauge('How long the most overdue Communication delivery has been eligible.', []),
            'lycenza_communication_attempts_total' => self::counter('Communication delivery attempts.', ['delivery_channel' => $channels, 'outcome' => ['success', 'transient_failure', 'permanent_failure']]),
            'lycenza_communication_deliveries_finished_total' => self::counter('Communication deliveries reaching a final state.', ['delivery_channel' => $channels, 'outcome' => ['accepted', 'sent', 'delivered', 'read', 'failed', 'bounced', 'rejected', 'expired', 'cancelled']]),

            // Email (ADR 0055 section 16; never School, recipient, domain,
            // provider message id, internal message id or template)
            'lycenza_email_messages_total' => self::counter('Email messages by purpose and closed outcome (queued, submitted -- never "delivered" unless the provider said so -- and provider/terminal outcomes).', ['message_class' => EmailTelemetry::messageClasses(), 'outcome' => EmailTelemetry::MESSAGE_OUTCOMES]),
            'lycenza_email_submission_attempts_total' => self::counter('Email provider submission attempts by purpose and closed outcome.', ['message_class' => EmailTelemetry::messageClasses(), 'outcome' => EmailTelemetry::ATTEMPT_OUTCOMES]),
            'lycenza_email_webhook_requests_total' => self::counter('Email provider-event webhook requests by closed outcome.', ['outcome' => EmailTelemetry::WEBHOOK_OUTCOMES]),
            'lycenza_email_pending_messages' => self::gauge('Email messages not yet accepted by the provider (pending or submitting), by purpose (computed at scrape).', ['message_class' => EmailTelemetry::messageClasses()]),
            'lycenza_email_oldest_pending_age_seconds' => self::gauge('How long the oldest not-yet-accepted email has existed, by purpose (0 when none).', ['message_class' => EmailTelemetry::messageClasses()]),
            'lycenza_email_last_event_timestamp_seconds' => self::gauge('Unix time the last provider event was received (only while a provider event adapter is configured; 0 when none yet).', []),

            // Account recovery (ADR 0056 section 15; never email, User, School,
            // selector, IP or token)
            'lycenza_account_recovery_requests_total' => self::counter('Password-recovery requests answered (every one gets the same generic response) by closed outcome.', ['outcome' => AccountRecoveryTelemetry::REQUEST_OUTCOMES]),
            'lycenza_account_recovery_issuance_total' => self::counter('Password-recovery issuance decisions (operator-only view) by closed outcome.', ['outcome' => AccountRecoveryTelemetry::ISSUANCE_OUTCOMES]),
            'lycenza_account_recovery_resets_total' => self::counter('Password-reset submissions by closed outcome (invalid covers every credential failure).', ['outcome' => AccountRecoveryTelemetry::RESET_OUTCOMES]),
            'lycenza_account_recovery_enabled' => self::gauge('1 when self-service account recovery is enabled; with `available` 0 while critical email is not available.', ['state' => ['enabled', 'available']]),

            // Automation (never rule, School or subject)
            'lycenza_automation_executions_total' => self::counter('Automation executions by outcome.', ['outcome' => ['started', 'succeeded', 'skipped', 'failed', 'abandoned']]),
            'lycenza_automation_pending_executions' => self::gauge('Pending or running Automation executions.', ['state' => ['pending', 'running']]),
            'lycenza_automation_overdue_executions' => self::gauge('Pending executions past next_attempt_at or running past their lease.', []),
            'lycenza_automation_oldest_overdue_age_seconds' => self::gauge('How long the most overdue Automation execution has been eligible.', []),
            'lycenza_automation_review_items_created_total' => self::counter('Automation review items created (append-only; v1 has no resolution).', []),

            // Deployment-fed evidence (ADR 0051 §13; never originated here)
            'lycenza_backup_last_success_timestamp_seconds' => self::gauge('Deployment evidence: last successful backup.', ['backup_store' => ['postgresql', 'objects']]),
            'lycenza_backup_recovery_point_timestamp_seconds' => self::gauge('Deployment evidence: newest PostgreSQL point-in-time recovery point.', ['backup_store' => ['postgresql']]),
            'lycenza_backup_last_failure_timestamp_seconds' => self::gauge('Deployment evidence: last failed backup run.', ['backup_store' => ['postgresql', 'objects']]),
            'lycenza_restore_drill_last_success_timestamp_seconds' => self::gauge('Deployment evidence: last successful restore drill.', []),
            'lycenza_restore_drill_duration_seconds' => self::gauge('Deployment evidence: time to validated service in the last drill.', []),
            'lycenza_restore_drill_recovery_point_age_seconds' => self::gauge('Deployment evidence: requested vs observed recovery point gap in the last drill.', []),
            'lycenza_restore_drill_last_result' => self::gauge('Deployment evidence: 1 PASS / 0 FAIL of the last drill.', []),

            // Telemetry about telemetry
            'lycenza_metrics_collection_errors_total' => self::counter('Metric writes or scrape-time collections that failed (best effort).', ['component' => ['recorder', 'queues', 'outbox', 'backlog', 'heartbeats', 'failed_jobs', 'readiness', 'ai_gateway', 'evidence', 'domains', 'email', 'account_recovery']]),
        ];
    }

    /** @return list<string> */
    public static function queues(): array
    {
        return array_map(fn (QueueName $q) => $q->value, QueueName::requiredWorkerQueues());
    }

    /**
     * The scheduled task names (routes/console.php `->name()`), guard-tested
     * against the live schedule.
     *
     * @return list<string>
     */
    public static function scheduledTasks(): array
    {
        return [
            'outbox-dispatch', 'webhook-deliveries-redispatch', 'communication-deliveries-redispatch',
            'communications-publish-scheduled', 'automation-executions-redispatch', 'expire-school-elevations',
            'worker-canaries', 'idempotency-prune', 'webhook-deliveries-prune', 'domains-check',
            'email-messages-redispatch', 'email-prune', 'account-recovery-prune',
            'staff-account-credentials-prune', 'outbox-prune', 'failed-jobs-prune',
            'audit-prune', 'email-suppressions-prune', 'authority-history-prune',
            'communications-prune', 'storage-orphans-prune',
        ];
    }

    /** Daily tasks (their staleness window is a day, not minutes). */
    public const DAILY_TASKS = ['idempotency-prune', 'webhook-deliveries-prune', 'email-prune', 'account-recovery-prune', 'staff-account-credentials-prune', 'outbox-prune', 'failed-jobs-prune', 'audit-prune', 'email-suppressions-prune', 'authority-history-prune', 'communications-prune', 'storage-orphans-prune'];

    /**
     * @param  array<string, string>  $labels
     */
    public static function accepts(string $name, array $labels): bool
    {
        $definition = self::definitions()[$name] ?? null;

        if ($definition === null || array_keys($labels) !== array_keys($definition['labels'])) {
            return false;
        }

        foreach ($labels as $key => $value) {
            if (! in_array($value, $definition['labels'][$key], true)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, list<string>> $labels */
    private static function counter(string $help, array $labels): array
    {
        return ['type' => 'counter', 'help' => $help, 'labels' => $labels];
    }

    /** @param array<string, list<string>> $labels */
    private static function gauge(string $help, array $labels): array
    {
        return ['type' => 'gauge', 'help' => $help, 'labels' => $labels];
    }

    /** @param array<string, list<string>> $labels */
    private static function histogram(string $help, array $labels): array
    {
        return ['type' => 'histogram', 'help' => $help, 'labels' => $labels];
    }
}
