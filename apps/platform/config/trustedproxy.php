<?php

use App\Support\Http\TrustedProxyList;

/*
|--------------------------------------------------------------------------
| Trusted proxies -- Phase 0O.4A (ADR 0050 section 2)
|--------------------------------------------------------------------------
|
| The reverse proxies / load balancers whose X-Forwarded-For, -Host, -Port
| and -Proto headers are believed (client IP for rate limits, HTTPS for
| HSTS and secure cookies, generated URLs). Explicit IPs/CIDRs from
| TRUSTED_PROXIES only; empty = trust nobody. Trust-all values, hostnames
| and malformed entries make configuration loading fail
| (App\Support\Http\TrustedProxyList). Always an array -- never null, so
| the framework's host-based "trust everyone" fallbacks can never apply.
|
*/

return [

    'proxies' => TrustedProxyList::parse(env('TRUSTED_PROXIES')),

];
