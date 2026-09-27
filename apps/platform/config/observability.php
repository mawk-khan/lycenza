<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Readiness Budget
    |--------------------------------------------------------------------------
    |
    | Every individual dependency check inside /health/ready is bounded
    | by this timeout (section 8) so a single slow dependency cannot
    | hang the whole probe. Kept short and cheap deliberately -- this
    | is a liveness-adjacent probe hit frequently by infrastructure,
    | not a diagnostic tool.
    |
    */

    'readiness_check_timeout_ms' => (int) env('OBSERVABILITY_READINESS_TIMEOUT_MS', 500),

    /*
    |--------------------------------------------------------------------------
    | Scheduler Staleness
    |--------------------------------------------------------------------------
    |
    | A named heartbeat (App\Models\SchedulerHeartbeat, recorded by
    | App\Support\Observability\SchedulerHeartbeatRecorder) older than
    | this is considered stale. Scheduled tasks in this project run
    | every minute (routes/console.php); 5x that interval gives real
    | margin for a single missed/slow run without false-positiving on
    | every minor scheduler jitter, while still catching a genuinely
    | stopped scheduler quickly.
    |
    */

    'scheduler_stale_after_seconds' => (int) env('OBSERVABILITY_SCHEDULER_STALE_SECONDS', 300),

    /*
    |--------------------------------------------------------------------------
    | Queue Staleness
    |--------------------------------------------------------------------------
    |
    | A queue is only ever "stalled" when BOTH pending work exists AND
    | its processing heartbeat is older than this -- an idle queue with
    | zero work is never stalled (section 20). Longer than the
    | scheduler threshold because queue throughput is legitimately
    | bursty (a queue can go several minutes with no jobs at all under
    | light load without anything being wrong).
    |
    */

    'queue_stale_after_seconds' => (int) env('OBSERVABILITY_QUEUE_STALE_SECONDS', 600),

    /*
    |--------------------------------------------------------------------------
    | Outbox Backlog Thresholds
    |--------------------------------------------------------------------------
    |
    | Internal-diagnostics-only (section 48) -- a short backlog must
    | NOT affect public readiness. Both the outbox and webhook
    | thresholds below are deliberately configurable, not hardcoded
    | production constants.
    |
    */

    'outbox_degraded_after_seconds' => (int) env('OBSERVABILITY_OUTBOX_DEGRADED_SECONDS', 300),

    'outbox_critical_after_seconds' => (int) env('OBSERVABILITY_OUTBOX_CRITICAL_SECONDS', 1800),

    /*
    |--------------------------------------------------------------------------
    | Webhook Delivery Backlog Thresholds
    |--------------------------------------------------------------------------
    |
    | Customer endpoint failures never affect this project's OWN
    | readiness (section 49/59) -- these thresholds only classify
    | INTERNAL diagnostics severity, never public health.
    |
    */

    'webhook_degraded_after_seconds' => (int) env('OBSERVABILITY_WEBHOOK_DEGRADED_SECONDS', 900),

    'webhook_critical_after_seconds' => (int) env('OBSERVABILITY_WEBHOOK_CRITICAL_SECONDS', 3600),

    /*
    |--------------------------------------------------------------------------
    | AI Gateway Reachability Check Timeout
    |--------------------------------------------------------------------------
    |
    | AI is an OPTIONAL subsystem (section 12/50) -- unreachable AI
    | Gateway is reported as a degraded internal-diagnostics component,
    | never a readiness failure. Short timeout so an unreachable AI
    | Gateway cannot slow down internal diagnostics noticeably either.
    |
    */

    'ai_gateway_check_timeout_ms' => (int) env('OBSERVABILITY_AI_GATEWAY_TIMEOUT_MS', 500),

    /*
    |--------------------------------------------------------------------------
    | Phase 0O.5A (ADR 0051): logging, metrics, shared thresholds, alerts
    |--------------------------------------------------------------------------
    */

    'service' => 'platform',

    'logging' => [
        // `json` (one object per line, fixed schema) is the production
        // format; `line` keeps local/DDEV logs readable. Production refuses
        // to boot with anything but `json` (ProductionConfigurationGuard).
        'format' => env('LOG_FORMAT', env('APP_ENV') === 'production' ? 'json' : 'line'),
    ],

    'metrics' => [
        // `redis` (production: one shared hash, all processes), `array`
        // (tests: in-process), `null` (disabled). Best effort always.
        'store' => env('METRICS_STORE', 'redis'),
        'redis_connection' => env('METRICS_REDIS_CONNECTION', 'default'),
        'redis_key' => 'observability:metrics',
        // The collector's bearer token for the private metrics listener --
        // a production secret (ADR 0050 §4, O4). No default: without it
        // the listener refuses every scrape.
        'scrape_token' => env('METRICS_SCRAPE_TOKEN'),
        // Deployment-controlled evidence (backups, restore drills), a
        // read-only file the application validates and re-exposes; absent
        // means "no evidence", never "healthy".
        'deployment_evidence_file' => env('OBSERVABILITY_DEPLOYMENT_EVIDENCE_FILE'),
    ],

    /*
    | One set of thresholds for operations status, metrics-derived signals
    | and the alert catalog (ADR 0051 §14). Derived from runtime cadence:
    | every recovery sweep and the canaries run every 60 s, so 300 s is five
    | missed cycles (the existing scheduler staleness value) and 1800 s the
    | existing outbox critical window.
    */
    'thresholds' => [
        'minute_task_stale_seconds' => 300,
        'minute_task_critical_seconds' => 1800,
        'daily_task_stale_seconds' => 26 * 3600,
        'worker_heartbeat_stale_seconds' => 300,
        'backlog_warning_seconds' => 300,
        'backlog_high_seconds' => 1800,
    ],

    /*
    | Owner/operator alert values (ADR 0051 §14.3 source C). Each is null
    | (the alert is explicitly DISABLED until the operator sets it) or a
    | number inside its safeguard bounds; anything else is refused by
    | App\Support\Observability\Alerts\AlertCatalog.
    */
    'alerts' => [
        'readiness_for_seconds' => env('ALERT_READINESS_FOR_SECONDS', 120),
        'dependency_for_seconds' => env('ALERT_DEPENDENCY_FOR_SECONDS', 120),
        'gateway_unready_for_seconds' => env('ALERT_GATEWAY_UNREADY_FOR_SECONDS', 600),
        'failed_jobs_high_per_15m' => env('ALERT_FAILED_JOBS_HIGH_PER_15M'),
        'webhook_final_failures_per_hour' => env('ALERT_WEBHOOK_FINAL_FAILURES_PER_HOUR'),
        'communication_failure_ratio_per_hour' => env('ALERT_COMMUNICATION_FAILURE_RATIO_PER_HOUR'),
        'storage_failures_per_15m' => env('ALERT_STORAGE_FAILURES_PER_15M'),
        'security_rejections_per_15m' => env('ALERT_SECURITY_REJECTIONS_PER_15M'),
        'telemetry_down_for_seconds' => env('ALERT_TELEMETRY_DOWN_FOR_SECONDS', 300),
        // Phase 0O.9A (ADR 0055 section 16): operator baselines (C); unset
        // leaves that alert tier explicitly disabled.
        'email_failure_ratio_per_hour' => env('ALERT_EMAIL_FAILURE_RATIO_PER_HOUR'),
        'email_hard_bounces_per_hour' => env('ALERT_EMAIL_HARD_BOUNCES_PER_HOUR'),
        'email_complaints_per_hour' => env('ALERT_EMAIL_COMPLAINTS_PER_HOUR'),
        'email_webhook_auth_failures_per_15m' => env('ALERT_EMAIL_WEBHOOK_AUTH_FAILURES_PER_15M'),
    ],

];
