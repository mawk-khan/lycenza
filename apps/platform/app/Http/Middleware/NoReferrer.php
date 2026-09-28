<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 0O.10A (ADR 0056 section 8.4): `Referrer-Policy: no-referrer` for the
 * account-recovery pages -- stricter than the global
 * `strict-origin-when-cross-origin` (ApplySecurityHeaders keeps a policy a
 * route already set).
 */
class NoReferrer
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
