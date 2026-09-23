<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Test-Only Loopback Exception
    |--------------------------------------------------------------------------
    |
    | When true, App\Support\Webhooks\SsrfSafeUrlValidator permits
    | loopback/private webhook destinations. Double-guarded exactly
    | like config/tenancy.php's dev header override: this flag AND
    | app()->environment(['local', 'testing']) must both be true.
    | Required for the local webhook receiver integration test (Phase
    | 0C.3 section 29/58) to deliver to 127.0.0.1 at all -- never
    | enable outside local/testing.
    |
    */

    'allow_loopback_for_tests' => (bool) env('WEBHOOKS_ALLOW_LOOPBACK_FOR_TESTS', false),

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Bounds (section 34)
    |--------------------------------------------------------------------------
    */

    'connect_timeout_seconds' => (int) env('WEBHOOKS_CONNECT_TIMEOUT_SECONDS', 3),

    'delivery_timeout_seconds' => (int) env('WEBHOOKS_DELIVERY_TIMEOUT_SECONDS', 10),

    /*
    |--------------------------------------------------------------------------
    | Retry Policy (sections 41-45)
    |--------------------------------------------------------------------------
    |
    | max_attempts bounds total attempts for one logical delivery
    | (section 41: "do not retry forever"). retry_backoff_seconds is
    | indexed by (attempt_number - 1); the last entry repeats for any
    | attempt beyond the array's length. Retries are driven by our OWN
    | explicit state machine (App\Console\Commands\
    | RedispatchDueWebhookDeliveries scanning next_attempt_at), not
    | Laravel's queue-level job retry/backoff -- the sync queue
    | connection used in tests (ADR 0024) does not implement delayed
    | requeueing, and an explicit, queryable `retrying` state with a due
    | timestamp is what section 47's delivery-history API needs to show
    | "next retry" anyway.
    |
    | processing_lease_seconds bounds how long a delivery may sit in
    | `delivering` before a DIFFERENT worker is allowed to safely
    | reclaim it (section 45) -- protects against a crashed worker
    | leaving a delivery stuck forever.
    |
    | max_retry_after_seconds clamps an absurd/hostile `Retry-After`
    | value from a 429/503 response (section 42).
    |
    */

    'max_attempts' => (int) env('WEBHOOKS_MAX_ATTEMPTS', 6),

    'retry_backoff_seconds' => [60, 300, 1800, 7200, 28800],

    'processing_lease_seconds' => (int) env('WEBHOOKS_PROCESSING_LEASE_SECONDS', 60),

    'max_retry_after_seconds' => (int) env('WEBHOOKS_MAX_RETRY_AFTER_SECONDS', 3600),

    /*
    |--------------------------------------------------------------------------
    | Secret Rotation (section 9)
    |--------------------------------------------------------------------------
    */

    'secret_rotation_overlap_hours' => (int) env('WEBHOOKS_SECRET_ROTATION_OVERLAP_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Signature Verification Tolerance (section 24)
    |--------------------------------------------------------------------------
    */

    'signature_tolerance_seconds' => (int) env('WEBHOOKS_SIGNATURE_TOLERANCE_SECONDS', 300),

    /*
    |--------------------------------------------------------------------------
    | Delivery History Retention (Phase 0C closeout)
    |--------------------------------------------------------------------------
    |
    | How many days a TERMINAL delivery (delivered/failed/abandoned) --
    | and, through ON DELETE CASCADE, its append-only attempt rows -- is
    | kept after it last changed state, before
    | `platform:webhook-deliveries-prune` removes it. Deliberately NO
    | default: no retention period has been decided for delivery history
    | (docs/architecture/INTEGRATIONS.md "Retention";
    | docs/security/DATA-CLASSIFICATION.md marks retention periods
    | [LEGAL REVIEW REQUIRED]). While this is null the scheduled prune
    | deletes nothing and logs that retention is unconfigured. Pending/
    | delivering/retrying deliveries, school audit events and the
    | domain-event outbox are never touched by this setting.
    |
    */

    // Raw value; validated (positive whole number) by the prune command.
    'delivery_retention_days' => env('WEBHOOKS_DELIVERY_RETENTION_DAYS'),

    'prune_batch_size' => (int) env('WEBHOOKS_PRUNE_BATCH_SIZE', 500),

];
