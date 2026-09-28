<?php

namespace App\Domain\Identity\Application\AccountRecovery;

/**
 * Phase 0O.10A (ADR 0056 section 6.1): the credential's two parts.
 * - selector: 16 random bytes, unpadded base64url (22 chars) -- non-secret,
 *   it only locates the row;
 * - secret: 32 random bytes / 256 bits, unpadded base64url (43 chars) --
 *   carried only in the link's URL FRAGMENT and the reset POST body.
 * Only SHA-256(secret) is stored; comparison is constant-time.
 */
final class RecoveryCredential
{
    public const SELECTOR_PATTERN = '/^[A-Za-z0-9_-]{22}$/';

    public const SECRET_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    public function __construct(
        public readonly string $selector,
        public readonly string $secret,
    ) {}

    public static function generate(): self
    {
        return new self(self::encode(random_bytes(16)), self::encode(random_bytes(32)));
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public static function matches(string $secret, string $storedHash): bool
    {
        return preg_match(self::SECRET_PATTERN, $secret) === 1 && hash_equals($storedHash, self::hash($secret));
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
