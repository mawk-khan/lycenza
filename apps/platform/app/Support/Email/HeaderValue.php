<?php

namespace App\Support\Email;

/**
 * ADR 0055 section 13: every configurable header value is made safe BEFORE
 * it is stored (the database refuses control characters too). Removes CR,
 * LF and every other control character, collapses whitespace and caps the
 * length; a display name additionally loses the characters that let it
 * look like, or split into, an address (`< > @ " , ;`).
 */
final class HeaderValue
{
    public const SUBJECT_MAX = 200;

    public const DISPLAY_NAME_MAX = 64;

    public static function subject(string $value, string $fallback): string
    {
        $clean = self::clean($value, self::SUBJECT_MAX);

        return $clean !== '' ? $clean : self::clean($fallback, self::SUBJECT_MAX);
    }

    public static function displayName(string $value, string $fallback): string
    {
        $clean = self::clean(str_replace(['<', '>', '@', '"', ',', ';', '\\'], ' ', $value), self::DISPLAY_NAME_MAX);

        return $clean !== '' ? $clean : self::clean(str_replace(['<', '>', '@', '"', ',', ';', '\\'], ' ', $fallback), self::DISPLAY_NAME_MAX);
    }

    private static function clean(string $value, int $max): string
    {
        $value = (string) preg_replace('/[\x00-\x1F\x7F]|\p{Cc}|\p{Zl}|\p{Zp}/u', ' ', $value);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return trim(mb_substr($value, 0, $max));
    }
}
