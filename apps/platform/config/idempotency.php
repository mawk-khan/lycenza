<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Retention
    |--------------------------------------------------------------------------
    |
    | How long a completed/failed idempotency record remains valid for
    | replay (section 22). A stable per-endpoint default; a future
    | payment-specific module MAY need a longer, module-specific
    | retention window (section 35) rather than reusing this value --
    | see docs/architecture/RELIABILITY.md.
    |
    */

    'default_ttl_hours' => (int) env('IDEMPOTENCY_DEFAULT_TTL_HOURS', 48),

    /*
    |--------------------------------------------------------------------------
    | In-Flight (Processing) Timeout
    |--------------------------------------------------------------------------
    |
    | How long a 'processing' record is treated as genuinely in-flight
    | before App\Support\Idempotency\IdempotencyGuard considers its
    | owner abandoned (crashed worker, dropped connection) and allows a
    | retry to atomically reclaim the same key. Deliberately much
    | shorter than default_ttl_hours -- see section 17's crash-window
    | discussion.
    |
    */

    'in_flight_timeout_seconds' => (int) env('IDEMPOTENCY_IN_FLIGHT_TIMEOUT_SECONDS', 30),

    /*
    |--------------------------------------------------------------------------
    | Key Validation
    |--------------------------------------------------------------------------
    |
    | Idempotency-Key is an opaque client value (section 26) -- not
    | required to be a UUID, but bounded so a client cannot submit an
    | arbitrarily large value.
    |
    */

    'key_min_length' => (int) env('IDEMPOTENCY_KEY_MIN_LENGTH', 8),

    'key_max_length' => (int) env('IDEMPOTENCY_KEY_MAX_LENGTH', 255),

    /*
    |--------------------------------------------------------------------------
    | Pruning
    |--------------------------------------------------------------------------
    |
    | Bounded batch size for `php artisan platform:idempotency-prune`
    | (section 23) -- per School, per invocation.
    |
    */

    'prune_batch_size' => (int) env('IDEMPOTENCY_PRUNE_BATCH_SIZE', 500),

];
