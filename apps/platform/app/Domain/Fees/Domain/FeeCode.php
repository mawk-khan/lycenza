<?php

namespace App\Domain\Fees\Domain;

/**
 * FEE.1: the code shape shared by fee heads, fee structures and billing
 * period keys -- the same pattern as their database CHECK constraints
 * (`fee_heads_code_format_check` and siblings). Callers normalize first
 * (trim + uppercase, `App\Support\NormalizesCode`).
 */
final class FeeCode
{
    public const PATTERN = '/^[A-Z0-9][A-Z0-9_-]{0,31}$/';

    public const RULE_MESSAGE = 'A code is 1-32 uppercase letters, digits, "_" or "-", starting with a letter or digit.';

    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    public static function isValid(string $code): bool
    {
        return preg_match(self::PATTERN, self::normalize($code)) === 1;
    }
}
