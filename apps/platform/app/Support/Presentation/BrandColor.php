<?php

namespace App\Support\Presentation;

/**
 * ORG.12F (ADR 0080 §11.6): the one accent-colour rule. A School or Network
 * supplies only a background accent (`#RRGGBB`); the readable foreground is
 * DERIVED -- white or near-black, whichever contrasts more (WCAG relative
 * luminance), which always reaches at least 4.5:1. Body text and page
 * backgrounds are never brand-controlled, and a User's own appearance and
 * accessibility settings are untouched.
 */
final class BrandColor
{
    public const SYSTEM_ACCENT = '#0F172A';

    public const LIGHT = '#FFFFFF';

    public const DARK = '#111827';

    public static function normalize(?string $value): ?string
    {
        $value = $value === null ? null : strtoupper(trim($value));

        return $value === null || $value === '' ? null : $value;
    }

    public static function isValid(string $value): bool
    {
        return preg_match('/^#[0-9A-F]{6}$/', $value) === 1;
    }

    public static function foreground(string $accent): string
    {
        $l = self::luminance($accent);

        return self::contrast($l, self::luminance(self::LIGHT)) >= self::contrast($l, self::luminance(self::DARK)) ? self::LIGHT : self::DARK;
    }

    public static function contrastRatio(string $a, string $b): float
    {
        return self::contrast(self::luminance($a), self::luminance($b));
    }

    private static function contrast(float $a, float $b): float
    {
        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split(substr($hex, 1), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
