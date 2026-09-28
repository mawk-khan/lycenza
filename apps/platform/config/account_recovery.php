<?php

/*
|--------------------------------------------------------------------------
| Account recovery (ADR 0056, Phase 0O.10A)
|--------------------------------------------------------------------------
|
| Self-service password recovery for eligible human Users, on the canonical
| platform host only. OFF by default: disabled is a complete, safe mode (no
| "Forgot password?" link; the pages answer with the generic response;
| nothing is issued). Production refuses to enable it while critical email
| is disabled (ProductionConfigurationGuard), and nothing is issued while
| EmailProviderResolver::criticalEmailAvailable() is false.
|
| The values below are the contract's (ADR 0056 sections 5.3, 7, 8.3, 13);
| they are not deployment knobs.
|
*/

return [

    'enabled' => (bool) env('ACCOUNT_RECOVERY_ENABLED', false),

    'lifetime_minutes' => 30,

    'max_active_per_user' => 3,

    'limits' => [
        'per_ip_per_15_minutes' => 10,
        'global_per_hour' => 1000,
        'per_identity_per_hour' => 3,
        'per_identity_per_day' => 10,
        'reset_per_ip_per_15_minutes' => 20,
        'reset_per_selector_per_15_minutes' => 5,
    ],

    'prune_after_hours' => 24,

];
