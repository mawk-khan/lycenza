<?php

namespace App\Domain\Identity\Application\AccountRecovery;

use App\Support\Auth\IdentityFingerprint;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Phase 0O.10A (ADR 0056 section 5.3): at most 3 recovery requests per hour
 * and 10 per day per IDENTITY -- keyed by the purpose-separated HMAC
 * fingerprint of the canonical email (IdentityFingerprint), never the
 * address, never a School. Exceeding it is SILENT: the caller still answers
 * the generic success response, it just does not dispatch the issuance job.
 * (Per-IP and global limits are the route throttle `account-recovery-request`.)
 */
final class AccountRecoveryIdentityLimiter
{
    public function __construct(
        private readonly IdentityFingerprint $fingerprints,
        private readonly Repository $config,
    ) {}

    /** True when this request may proceed (and is counted). */
    public function attempt(string $canonicalEmail): bool
    {
        $fingerprint = $this->fingerprints->of(IdentityFingerprint::RECOVERY_THROTTLE, $canonicalEmail);
        $limits = (array) $this->config->get('account_recovery.limits');

        $buckets = [
            ["account-recovery:identity:{$fingerprint}:h", (int) $limits['per_identity_per_hour'], 3600],
            ["account-recovery:identity:{$fingerprint}:d", (int) $limits['per_identity_per_day'], 86400],
        ];

        foreach ($buckets as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return false;
            }
        }

        foreach ($buckets as [$key, , $decay]) {
            RateLimiter::hit($key, $decay);
        }

        return true;
    }
}
