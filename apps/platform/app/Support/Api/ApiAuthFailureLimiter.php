<?php

namespace App\Support\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Phase 0O.3 (ADR 0049 section 7): `api-auth-failure` -- 20 failed API
 * authentications per minute per (client IP, presented credential id).
 * A rejected credential never reaches ThrottleRequests (authentication is
 * framework-prioritized ahead of it), so failures are counted explicitly
 * when the `/api` 401 is rendered, and checked BEFORE authentication by
 * App\Http\Middleware\Api\ThrottleFailedApiAuthentication.
 *
 * The bucket includes the credential's non-secret id (a Sanctum token's
 * numeric id, a partner key id) when the presented value parses, so one
 * noisy client behind a shared school NAT does not lock out everyone else,
 * while guessing secrets for one credential stays bounded. Only the id is
 * used -- never the secret, and the key is hashed by the rate limiter.
 */
final class ApiAuthFailureLimiter
{
    public const MAX_ATTEMPTS = 20;

    public const DECAY_SECONDS = 60;

    public function key(Request $request): string
    {
        return 'api-auth-failure:'.$request->ip().':'.$this->credentialId($request->bearerToken());
    }

    public function tooManyAttempts(Request $request): bool
    {
        return RateLimiter::tooManyAttempts($this->key($request), self::MAX_ATTEMPTS);
    }

    public function availableIn(Request $request): int
    {
        return RateLimiter::availableIn($this->key($request));
    }

    public function hit(Request $request): void
    {
        RateLimiter::hit($this->key($request), self::DECAY_SECONDS);
    }

    private function credentialId(?string $bearer): string
    {
        if ($bearer === null || $bearer === '') {
            return 'none';
        }

        if (preg_match('/^(\d{1,19})\|/', $bearer, $m) === 1) {
            return 'pat:'.$m[1];
        }

        $keyId = PartnerCredentialFormat::keyId($bearer);

        return $keyId !== null ? 'pk:'.$keyId : 'malformed';
    }
}
