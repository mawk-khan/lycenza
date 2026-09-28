<?php

namespace App\Support\Privacy;

use InvalidArgumentException;

/**
 * Deterministic, exact-match email normalization -- the contract every
 * future create/update/lookup/duplicate-detection call site must share,
 * so two call sites can never silently disagree about whether two
 * strings represent "the same" email. Deliberately conservative:
 *
 * - trims surrounding whitespace
 * - lowercases the ENTIRE address (local part and domain) for a single,
 *   predictable case-folding rule -- real-world mail providers treat
 *   the local part case-insensitively even though RFC 5321 technically
 *   allows a case-sensitive local part; this codebase picks the
 *   pragmatic, deterministic rule rather than guessing per-provider
 *   behavior
 * - does NOT strip dots or +tags, and does NOT apply any
 *   provider-specific aliasing (e.g. Gmail's dot-insensitivity) --
 *   `a.b@x.com` and `ab@x.com` are never treated as the same identity,
 *   because this codebase cannot know that assumption holds for every
 *   provider a School's Guardians use
 *
 * Never persists the normalized plaintext -- callers pass the result
 * straight into ContactLookupHasher::hash() and Laravel's `encrypted`
 * cast, never into a plaintext column.
 */
class EmailNormalizer
{
    /**
     * Phase 0O.10A (ADR 0056 section 4.4): THE canonical form of a human
     * account email -- trim, lowercase, nothing else (no provider-specific
     * aliasing, no Unicode rewriting). Login, recovery and every User
     * writer use it; `users_email_canonical_check` enforces it in the
     * database. No validation here: callers validate format separately.
     */
    public static function canonical(string $rawValue): string
    {
        return strtolower(trim($rawValue));
    }

    public function normalize(string $rawValue): string
    {
        $trimmedLower = self::canonical($rawValue);

        if (filter_var($trimmedLower, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException("Invalid email address: {$rawValue}");
        }

        return $trimmedLower;
    }
}
