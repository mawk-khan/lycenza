<?php

namespace App\Http\Middleware\Api;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 8A.15 -- a small, GENERIC platform response-privacy behavior
 * (not HR-only, despite being introduced while hardening the HR API):
 * sets `Cache-Control: private, no-store` on the response, opt-in per
 * route via this middleware, for any endpoint whose body is
 * authorization-sensitive and must never be shared-cacheable by an
 * intermediary (a reverse proxy, a CDN, a shared browser profile)
 * merely because an `Authorization` header happened to be present.
 *
 * No repository-wide Cache-Control convention existed before this
 * checkpoint (verified by inspection, not assumed) -- this middleware
 * is deliberately narrow and additive: it does not change global
 * behavior for any route that does not explicitly add it, and it never
 * sets a contradictory `public`/`s-maxage` directive that would
 * re-enable shared caching. Applied to the Employee Directory/Profile/
 * Activity Timeline/sensitive-document read endpoints
 * (docs/modules/HR.md 8A.15 as-built) -- any future endpoint returning
 * comparably sensitive, per-actor data may reuse this same alias
 * rather than repeating `$response->headers->set(...)` per controller.
 */
class EnsurePrivateNoStoreResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
