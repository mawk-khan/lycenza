<?php

namespace App\Support\ServiceAuth;

use Illuminate\Contracts\Cache\Repository;
use Throwable;

/**
 * ADR 0053 section 5.5: Laravel consumes every assertion's jti exactly once,
 * in the shared production cache store (Redis: `add` is an atomic SET NX EX),
 * so a replay to ANY web replica is refused. The key holds only a digest of
 * issuer + jti -- no School, User, body or secret -- and lives no longer than
 * the assertion can still verify (exp + skew). A store failure fails CLOSED.
 * This is replay protection, not idempotency (CLAUDE.md rules 30-33).
 */
final class ServiceAssertionReplayGuard
{
    public const PREFIX = 'service-assertion:';

    public function __construct(private readonly Repository $store) {}

    public function consume(VerifiedService $service, int $now): void
    {
        $ttl = $service->expiresAt + ServiceAuthContract::CLOCK_SKEW_SECONDS - $now;
        $ttl = max(1, min($ttl, ServiceAuthContract::MAX_LIFETIME_SECONDS + ServiceAuthContract::CLOCK_SKEW_SECONDS));
        $key = self::PREFIX.$service->service.':'.hash('sha256', $service->jti);

        try {
            $first = $this->store->add($key, 1, $ttl);
        } catch (Throwable) {
            throw new ServiceAuthenticationException('replay_store_unavailable', $service->kid, $service->service);
        }

        if (! $first) {
            throw new ServiceAuthenticationException('replayed', $service->kid, $service->service);
        }
    }
}
