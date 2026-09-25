<?php

namespace App\Http\Middleware\Api;

use App\Support\Api\ApiAuthFailureLimiter;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 0O.3 (ADR 0049 section 7): `api-auth-failure`. Runs BEFORE
 * authentication (prepended ahead of AuthenticatesRequests in the priority
 * list): once a (client IP, presented credential id) pair has failed API
 * authentication 20 times in a minute, its requests get a 429 with
 * Retry-After without being authenticated -- bounding secret guessing
 * and the `authentication_denied` audit it would generate. Failures are
 * counted where the `/api` 401 is rendered (bootstrap/app.php).
 */
class ThrottleFailedApiAuthentication
{
    public function __construct(private readonly ApiAuthFailureLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Only the external API: never the health probes or the internal
        // AI service boundary, which has its own service authentication.
        if ($request->is('api/v1/*') && $this->limiter->tooManyAttempts($request)) {
            throw new ThrottleRequestsException('Too Many Attempts.', null, [
                'Retry-After' => (string) $this->limiter->availableIn($request),
            ]);
        }

        return $next($request);
    }
}
