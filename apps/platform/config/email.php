<?php

/*
|--------------------------------------------------------------------------
| Production email & deliverability (ADR 0055, Phase 0O.9A)
|--------------------------------------------------------------------------
|
| Every business email (Guardian account invitations, Communication Hub
| email) goes through App\Support\Email\OutboundEmailGateway: a durable
| `email_messages` row, one configured provider adapter, bounded retries,
| provider events, suppression. Nothing here selects a vendor.
|
| MAIL_PROVIDER:
| - `none` (default): email is explicitly DISABLED. Messages are recorded
|   and wait (`pending`); nothing is submitted and nothing is reported as
|   sent. Production may run this way deliberately.
| - `fake`: local/testing only (no network; deterministic outcomes).
| - `smtp`: the provider-neutral hardened SMTP baseline (TLS required,
|   authenticated, bounded timeouts). SMTP carries no delivery events.
|
| A vendor's HTTPS API adapter (and its event adapter) is written only
| when a vendor is selected (ADR 0055 section 6).
|
*/

$ring = fn (?string $current, ?string $previous): array => array_values(array_filter(
    [$current, $previous],
    fn ($value) => is_string($value) && trim($value) !== '',
));

return [

    'provider' => env('MAIL_PROVIDER', 'none'),

    /*
     * ADR 0055 section 8.1: the Lycenza-controlled sending domain. Never a
     * School's custom web domain (O9 authorizes nothing about email).
     */
    'sending_domain' => env('MAIL_SENDING_DOMAIN'),

    'from_name' => env('MAIL_FROM_NAME', 'Lycenza'),

    /*
     * ADR 0055 section 18: the operator's attestation that the sending-domain
     * evidence exists (provider domain verification, SPF, DKIM, DMARC >=
     * quarantine). It records evidence; it proves nothing by itself. In
     * production nothing is submitted while it is false.
     */
    'sending_verified' => (bool) env('MAIL_SENDING_VERIFIED', false),

    /*
     * ADR 0055 section 17: where `platform:mail-verify-domain` looks for the
     * deployment's DNS evidence (read-only). The provider dictates both.
     */
    'dns' => [
        'return_path_domain' => env('MAIL_RETURN_PATH_DOMAIN'),
        'dkim_selector' => env('MAIL_DKIM_SELECTOR'),
    ],

    /* ADR 0055 section 13: provider open/click tracking stays OFF. */
    'tracking' => [
        'opens' => (bool) env('MAIL_PROVIDER_OPEN_TRACKING', false),
        'clicks' => (bool) env('MAIL_PROVIDER_CLICK_TRACKING', false),
    ],

    /* Provider debug/sandbox switches; refused in production. */
    'debug' => (bool) env('MAIL_PROVIDER_DEBUG', false),

    'smtp' => [
        'host' => env('MAIL_HOST'),
        'port' => (int) env('MAIL_PORT', 587),
        'username' => env('MAIL_USERNAME'),
        'password' => env('MAIL_PASSWORD'),
        // `required` (STARTTLS, never downgraded), `implicit` (TLS from the
        // first byte, usually port 465) or `none` -- the last is refused
        // outside local/testing (Mailpit in DDEV).
        'tls' => env('MAIL_SMTP_TLS', 'required'),
        // Symfony's SMTP socket uses one timeout for the connect and for each
        // response: bounded by the 5 s connect limit (stricter than the 15 s
        // per-exchange limit).
        'timeout_seconds' => (int) env('MAIL_SMTP_TIMEOUT_SECONDS', 5),
        'ehlo_domain' => env('MAIL_EHLO_DOMAIN'),
    ],

    /*
     * ADR 0055 section 11: provider-event ingestion. The verifier and
     * normalizer belong to the provider adapter; `none` answers 404. Only the
     * `fake` adapter exists (local/testing, refused in production) until a
     * vendor is chosen.
     */
    'events' => [
        'adapter' => env('MAIL_PROVIDER_EVENTS', 'none'),
        'secrets' => $ring(env('MAIL_PROVIDER_EVENT_SECRET'), env('MAIL_PROVIDER_EVENT_PREVIOUS_SECRET')),
        'timestamp_tolerance_seconds' => 300,
        'max_body_bytes' => 262144,
        'max_json_depth' => 32,
        'max_events' => 100,
        'per_minute' => 600,
    ],

    /*
     * ADR 0055 section 12.3 (amended by 0O.9A): a bounded HMAC key ring --
     * current plus an optional previous key, each with a stable id. Every
     * suppression row records the key id of its fingerprint; lookups check
     * both keys, new rows use the current key.
     */
    'suppression' => [
        'key' => env('MAIL_SUPPRESSION_HMAC_KEY'),
        'key_id' => env('MAIL_SUPPRESSION_HMAC_KEY_ID'),
        'previous_key' => env('MAIL_SUPPRESSION_HMAC_PREVIOUS_KEY'),
        'previous_key_id' => env('MAIL_SUPPRESSION_HMAC_PREVIOUS_KEY_ID'),
    ],

    /* ADR 0055 sections 9.3 and 10. */
    'submission' => [
        'max_attempts' => 6,
        'backoff_seconds' => [30, 120, 600, 1800, 7200],
        'jitter' => 0.2,
        'lease_seconds' => 120,
        // Provider authentication/configuration failure: stop contacting the
        // provider for this long (every message waits, durably).
        'auth_failure_pause_seconds' => 900,
        // `none` / not-yet-verified: how long a waiting message sleeps before
        // it is looked at again.
        'disabled_recheck_seconds' => 900,
        // A standard (Communications) message's content lifetime.
        'standard_ttl_hours' => 72,
    ],

    /* ADR 0055 section 15: fairness. Deferred, never failed, when exceeded. */
    'budgets' => [
        'school_standard_per_minute' => (int) env('MAIL_SCHOOL_PER_MINUTE', 60),
        'school_standard_per_day' => (int) env('MAIL_SCHOOL_PER_DAY', 5000),
        'school_critical_per_minute' => (int) env('MAIL_SCHOOL_CRITICAL_PER_MINUTE', 20),
        'school_critical_per_day' => (int) env('MAIL_SCHOOL_CRITICAL_PER_DAY', 1000),
        'school_max_in_flight' => (int) env('MAIL_SCHOOL_MAX_IN_FLIGHT', 5),
        'global_standard_per_minute' => (int) env('MAIL_GLOBAL_PER_MINUTE', 600),
        'global_critical_per_minute' => (int) env('MAIL_GLOBAL_CRITICAL_PER_MINUTE', 120),
        'global_max_in_flight' => (int) env('MAIL_GLOBAL_MAX_IN_FLIGHT', 50),
        // In-flight slots only critical mail may use (per School and globally).
        'critical_reserved_in_flight' => 1,
    ],

    /*
     * ADR 0055 section 21: [LEGAL REVIEW REQUIRED]. Unset means nothing is
     * deleted and the status reports retention as unconfigured -- the same
     * precedent as WEBHOOKS_DELIVERY_RETENTION_DAYS.
     */
    'retention_days' => env('MAIL_RETENTION_DAYS'),

];
