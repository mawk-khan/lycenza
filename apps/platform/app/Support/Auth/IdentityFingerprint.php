<?php

namespace App\Support\Auth;

use App\Support\Privacy\EmailNormalizer;
use Illuminate\Contracts\Config\Repository;
use RuntimeException;

/**
 * Phase 0O.10A (ADR 0056 section 5.3; login privacy finding): a keyed,
 * PURPOSE-SEPARATED fingerprint of a canonical email, for short-lived abuse
 * control (rate-limit keys) and bounded security evidence (a failed login)
 * -- never the address itself.
 *
 *   key  = HKDF-SHA256(APP_KEY, 32 bytes, info = "lycenza/<purpose>/v1")
 *   fp   = HMAC-SHA256(key, canonical email)
 *
 * No new secret. Rotating APP_KEY changes every fingerprint, which only
 * resets these ephemeral counters and breaks correlation with older failed-
 * login evidence -- both acceptable. Never the ADR 0055 suppression key.
 */
final class IdentityFingerprint
{
    public const LOGIN = 'login-identity';

    public const RECOVERY_THROTTLE = 'account-recovery-rate-limit';

    public function __construct(private readonly Repository $config) {}

    public function of(string $purpose, string $email): string
    {
        return hash_hmac('sha256', EmailNormalizer::canonical($email), $this->key($purpose));
    }

    private function key(string $purpose): string
    {
        $appKey = (string) $this->config->get('app.key');
        $raw = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7), true) : $appKey;

        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException('The application key is not configured.');
        }

        return hash_hkdf('sha256', $raw, 32, "lycenza/{$purpose}/v1");
    }
}
