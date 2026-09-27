<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // AI Gateway boundary (ADR 0013, ADR 0023, ADR 0053).
    //
    // - base_url: the integration is ENABLED only when this is set
    //   (production requires https); unset means no AI Gateway.
    // - service_signing_key: Laravel's own `platform` Ed25519 private key
    //   (one RFC 8037 OKP JWK, Highly Sensitive, from the managed secret
    //   store) signing per-request assertions to the Gateway.
    // - inbound_verification_keys: the ring (JSON list, 1-2 keys) of the
    //   Gateway's `ai-gateway` PUBLIC keys, verifying its calls to
    //   /api/internal/ai/*. Public, but integrity-controlled configuration.
    // - replay_store: the cache store consuming each inbound jti once
    //   (null = the default store; production requires Redis).
    // - context_signing_key: held ONLY by Laravel (never shared with
    //   services/ai); signs/verifies the short-lived AiContextTokenService
    //   tokens binding school_id/actor/capabilities (a separate secret from
    //   the service keys -- ADR 0053 section 3.2).
    // - legacy_service_token_configured: the retired shared token, read
    //   only so the production guard can refuse a leftover value; nothing
    //   authenticates with it.
    'ai_gateway' => [
        'base_url' => env('AI_GATEWAY_BASE_URL'),
        'service_signing_key' => env('AI_GATEWAY_SERVICE_SIGNING_KEY'),
        'inbound_verification_keys' => env('AI_GATEWAY_INBOUND_VERIFICATION_KEYS'),
        'replay_store' => env('AI_GATEWAY_REPLAY_STORE') ?: null,
        'context_signing_key' => env('AI_GATEWAY_CONTEXT_SIGNING_KEY'),
        'legacy_service_token_configured' => trim((string) env('AI_GATEWAY_SERVICE_TOKEN', '')) !== '',
    ],

];
