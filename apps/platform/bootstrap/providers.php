<?php

use App\Providers\AppServiceProvider;
use App\Providers\DomainsServiceProvider;
use App\Providers\PlatformServiceProvider;
use App\Providers\RateLimiterServiceProvider;

return [
    AppServiceProvider::class,
    DomainsServiceProvider::class,
    PlatformServiceProvider::class,
    RateLimiterServiceProvider::class,
];
