<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

/**
 * Phase 0O.4A (ADR 0050 section 2): replaces the framework's TrustProxies.
 * Trusts exactly the configured list (`trustedproxy.proxies`, parsed and
 * validated by App\Support\Http\TrustedProxyList) and nothing else: no
 * `*`, no host-name heuristics (the framework trusts every proxy for
 * `*.on-forge.com` / `*.on-vapor.com` hosts, which a client controls), and
 * only the four forwarded headers the contract names. With no proxy
 * configured, a client's X-Forwarded-* headers are ignored entirely.
 */
class TrustConfiguredProxies extends TrustProxies
{
    protected $headers = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO;

    protected function setTrustedProxyIpAddresses(Request $request)
    {
        /** @var list<string> $proxies */
        $proxies = (array) config('trustedproxy.proxies', []);

        $this->setTrustedProxyIpAddressesToSpecificIps($request, $proxies);
    }

    protected function headers()
    {
        return $this->headers;
    }

    protected function proxies()
    {
        return config('trustedproxy.proxies', []);
    }
}
