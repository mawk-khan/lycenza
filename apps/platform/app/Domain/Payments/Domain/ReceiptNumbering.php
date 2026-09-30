<?php

namespace App\Domain\Payments\Domain;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * FEE.4 (ADR 0062 §17.2; owner decision I, corrected 2026-09-30): the pure
 * receipt-numbering rules, mirrored in SQL by `payments_fy_series_key()`
 * and `payments_receipt_number()` (the database re-checks every insert).
 *
 * - Series key: the School financial year of an instant in the School's
 *   timezone -- `<starting year>-<last two digits of the next year>` for
 *   EVERY start month (January included). Never an AcademicYear.
 * - Number: `<PREFIX>/<series>/<sequence>`, the sequence padded to at
 *   least six digits and never wrapped.
 *
 * The prefix and start month are Fees settings (`FeeSettingsService`).
 */
final class ReceiptNumbering
{
    public static function seriesKey(CarbonInterface $instant, string $timezone, int $startMonth): string
    {
        if ($startMonth < 1 || $startMonth > 12) {
            throw new InvalidArgumentException("Financial-year start month must be 1-12, got {$startMonth}.");
        }

        $local = CarbonImmutable::instance($instant)->setTimezone($timezone);
        $year = $local->month < $startMonth ? $local->year - 1 : $local->year;

        return sprintf('%d-%02d', $year, ($year + 1) % 100);
    }

    public static function format(string $prefix, string $seriesKey, int $sequence): string
    {
        if ($sequence < 1) {
            throw new InvalidArgumentException("Receipt sequence must be positive, got {$sequence}.");
        }

        return $prefix.'/'.$seriesKey.'/'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }
}
