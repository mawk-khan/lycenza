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

    /** DS-1 (ADR 0082): the design system's page ground. */
    public const GROUND = '#F2F2F3';

    /** WCAG 2.x AA for normal-size text. */
    public const TEXT_CONTRAST = 4.5;

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

    /**
     * DS-1 (ADR 0082 §2.4): the deep step of an accent, used for accent text
     * on the ground and as a fill under white text. The accent itself when it
     * already reaches 4.5:1 on the ground; otherwise the accent darkened
     * (each sRGB channel scaled toward black in 1 % steps, which keeps its
     * hue) until it does. Deterministic, so a School accent always yields the
     * same strong step.
     */
    public static function strong(string $accent): string
    {
        $channels = array_map(static fn (string $pair): int => (int) hexdec($pair), str_split(substr($accent, 1), 2));

        for ($step = 0; $step <= 100; $step++) {
            $factor = 1 - $step / 100;
            $candidate = vsprintf('#%02X%02X%02X', array_map(static fn (int $c): int => (int) round($c * $factor), $channels));
            if (self::contrastRatio($candidate, self::GROUND) >= self::TEXT_CONTRAST) {
                return $candidate;
            }
        }

        return '#000000';
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
