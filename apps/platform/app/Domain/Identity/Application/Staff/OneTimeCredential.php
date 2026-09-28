<?php

namespace App\Domain\Identity\Application\Staff;

use App\Domain\Identity\Application\AccountRecovery\RecoveryCredential;

/**
 * Phase 0O.12B (ADR 0059 section 10): the activation / staff-invitation
 * credential -- exactly the ADR 0056 scheme (RecoveryCredential): a 22-char
 * selector locating the row and a 256-bit secret carried only in the link's
 * URL FRAGMENT and the POST body; only SHA-256(secret) is stored, compared
 * in constant time. A separate purpose, a separate table: a recovery
 * credential can never be used here, nor this one for recovery.
 */
final class OneTimeCredential
{
    public const SELECTOR_PATTERN = RecoveryCredential::SELECTOR_PATTERN;

    public const SECRET_PATTERN = RecoveryCredential::SECRET_PATTERN;

    public static function generate(): RecoveryCredential
    {
        return RecoveryCredential::generate();
    }

    public static function hash(string $secret): string
    {
        return RecoveryCredential::hash($secret);
    }

    public static function matches(string $secret, string $storedHash): bool
    {
        return RecoveryCredential::matches($secret, $storedHash);
    }

    public static function wellFormed(string $selector, string $secret): bool
    {
        return preg_match(self::SELECTOR_PATTERN, $selector) === 1 && preg_match(self::SECRET_PATTERN, $secret) === 1;
    }
}
