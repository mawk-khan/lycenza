<?php

namespace App\Support\Api;

/**
 * Phase 0O.3 (ADR 0049 section 5): the partner credential format
 * `lyc_pk_<key_id>.<secret>` -- a fixed, non-secret prefix (recognisable
 * by secret scanners and distinct from human tokens), a 16-hex-character
 * non-secret lookup id, and a 256-bit secret (43 base64url characters).
 */
final class PartnerCredentialFormat
{
    public const PREFIX = 'lyc_pk_';

    private const PATTERN = '/^lyc_pk_([0-9a-f]{16})\.([A-Za-z0-9_-]{43})$/';

    public static function keyId(string $credential): ?string
    {
        return preg_match(self::PATTERN, $credential, $m) === 1 ? $m[1] : null;
    }

    /**
     * @return array{0: string, 1: string}|null [key id, secret]
     */
    public static function parse(?string $credential): ?array
    {
        if ($credential === null || preg_match(self::PATTERN, $credential, $m) !== 1) {
            return null;
        }

        return [$m[1], $m[2]];
    }

    public static function compose(string $keyId, string $secret): string
    {
        return self::PREFIX.$keyId.'.'.$secret;
    }

    public static function newKeyId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /** 256 bits from the CSPRNG, base64url without padding (43 chars). */
    public static function newSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
