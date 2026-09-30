<?php

namespace App\Domain\Payments\Domain;

use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * FEE.5 (ADR 0062 §16; owner decision H, 2026-09-30): the pure late-fee
 * rules. Legal status: DEVELOPMENT AUTHORISED — PROD LEGAL SIGN-OFF
 * REQUIRED (ADR 0058 E31); nothing here asserts a lawful rate or grace.
 *
 * - Grace boundary (frozen): eligible only when
 *   `evaluation_date > due_date + grace_days` on School-calendar dates; the
 *   final grace date is `due_date + grace_days` and nothing is assessed on
 *   or before it.
 * - `fixed`: the configured amount. `percentage`: `multiplyByRate(p/100)`
 *   of the CURRENT OUTSTANDING (decision N: scale 2, half away from
 *   zero), never the original charge amount.
 * - Optional cap: the lesser of the calculated amount and the cap
 *   (intended; not G1's refusal).
 * - A result that is not strictly positive is no late fee.
 */
final class LateFeeCalculation
{
    public static function finalGraceDate(string $dueDate, int $graceDays): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $dueDate, 'UTC')->addDays($graceDays)->toDateString();
    }

    public static function graceElapsed(string $dueDate, int $graceDays, string $evaluationDate): bool
    {
        return $evaluationDate > self::finalGraceDate($dueDate, $graceDays);
    }

    /** @return array{calculated: Money, final: Money, capApplied: bool} */
    public static function amount(string $kind, ?string $fixedAmount, ?string $percentage, ?string $maxAmount, Money $outstanding): array
    {
        $calculated = match ($kind) {
            'fixed' => Money::of((string) $fixedAmount, $outstanding->currency()),
            'percentage' => $outstanding->multiplyByRate(bcdiv((string) $percentage, '100', 4)),
            default => throw new InvalidArgumentException("Unknown late-fee kind '{$kind}'."),
        };

        if ($maxAmount !== null && bccomp($calculated->amount(), $maxAmount, 2) > 0) {
            return ['calculated' => $calculated, 'final' => Money::of($maxAmount, $outstanding->currency()), 'capApplied' => true];
        }

        return ['calculated' => $calculated, 'final' => $calculated, 'capApplied' => false];
    }
}
