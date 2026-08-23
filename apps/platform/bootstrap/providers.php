<?php

use App\Providers\AppServiceProvider;
use App\Providers\PlatformServiceProvider;
use App\Providers\RateLimiterServiceProvider;

return [
    AppServiceProvider::class,
    PlatformServiceProvider::class,
    RateLimiterServiceProvider::class,
];
