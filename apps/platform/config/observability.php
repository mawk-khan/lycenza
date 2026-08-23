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

];
