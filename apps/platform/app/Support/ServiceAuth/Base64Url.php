<?php

namespace App\Support\ServiceAuth;

final class Base64Url
{
    public static function encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** Strict, canonical, unpadded base64url; null for anything else. */
    public static function decode(mixed $text): ?string
    {
        if (! is_string($text) || preg_match('/\A[A-Za-z0-9_-]*\z/', $text) !== 1 || strlen($text) % 4 === 1) {
            return null;
        }
        $data = base64_decode(strtr($text, '-_', '+/'), true);

        return $data !== false && self::encode($data) === $text ? $data : null;
    }
}
