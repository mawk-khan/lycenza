<?php

use App\Support\Http\CorsOriginList;

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing -- Phase 0O.3 (ADR 0049 section 10)
|--------------------------------------------------------------------------
|
| Replaces the framework default (any origin on api/*). Deny by default:
| cross-origin browser access to the API is allowed only from the exact
| origins in CORS_ALLOWED_ORIGINS -- empty by default, which means none.
| Same-origin requests never need CORS. External API clients authenticate
| with bearer credentials, never cross-origin session cookies, so
| credentials are never supported, and the origin is never `*`.
| CorsOriginList rejects wildcards, patterns and anything that is not an
| exact https origin (config loading fails rather than widening).
|
*/

return [

    'paths' => ['api/*'],

    // Exactly the verbs the /api/v1 route table uses.
    'allowed_methods' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'],

    'allowed_origins' => CorsOriginList::parse(env('CORS_ALLOWED_ORIGINS')),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'Idempotency-Key', 'X-Request-Id'],

    'exposed_headers' => ['X-Request-Id', 'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'],

    'max_age' => 600,

    'supports_credentials' => false,

];
