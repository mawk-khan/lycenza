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

    // AI Gateway boundary (ADR 0013, ADR 0023). service_token
    // authenticates "this call came from the trusted AI Gateway
    // process"; context_signing_key is held ONLY by Laravel (never
    // shared with services/ai) and signs/verifies the short-lived
    // AiContextTokenService tokens that cryptographically bind
    // school_id/actor/capabilities into every AI tool invocation.
    'ai_gateway' => [
        'base_url' => env('AI_GATEWAY_BASE_URL', 'http://localhost:8100'),
        'service_token' => env('AI_GATEWAY_SERVICE_TOKEN'),
        'context_signing_key' => env('AI_GATEWAY_CONTEXT_SIGNING_KEY'),
    ],

];
