<?php

namespace App\Domain\Fees\Domain;

use InvalidArgumentException;

/**
 * FEE.1: exact-decimal helpers for fee amounts (NUMERIC(14,2), always a
 * decimal string). Never a float: parsing is by regex and arithmetic is
 * bcmath or integer paise, exactly like `App\Support\Money\Money`.
 */
final class FeeAmount
{
    /** Positive, at most 12 integer digits and 2 decimals (the column's range). */
    private const PATTERN = '/^\d{1,12}(\.\d{1,2})?$/';

    public static function isValidPositive(mixed $amount): bool
    {
        return is_string($amount)
            && preg_match(self::PATTERN, $amount) === 1
            && bccomp($amount, '0', 2) === 1;
    }

    /** Canonical two-decimal form, e.g. "1200" -> "1200.00". */
    public static function normalize(string $amount): string
    {
        if (! self::isValidPositive($amount)) {
            throw new InvalidArgumentException("Invalid fee amount '{$amount}'.");
        }

        return bcadd($amount, '0', 2);
    }

    /** @param list<string> $amounts */
    public static function sum(array $amounts): string
    {
        $total = '0.00';
        foreach ($amounts as $amount) {
            $total = bcadd($total, $amount, 2);
        }

        return $total;
    }

    public static function equals(string $a, string $b): bool
    {
        return bccomp($a, $b, 2) === 0;
    }

    /**
     * Splits an amount into $parts exact shares that sum back to it: equal
     * shares in paise, with any remaining paise added one each to the
     * earliest shares.
     *
     * @return list<string>
     */
    public static function split(string $amount, int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('An amount is split into at least one part.');
        }

        $paise = bcmul(self::normalize($amount), '100', 0);
        $base = bcdiv($paise, (string) $parts, 0);
        $remainder = (int) bcmod($paise, (string) $parts);

        $shares = [];
        for ($i = 0; $i < $parts; $i++) {
            $share = $i < $remainder ? bcadd($base, '1', 0) : $base;
            $shares[] = bcdiv($share, '100', 2);
        }

        return $shares;
    }
}
