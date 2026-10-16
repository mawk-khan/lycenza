<?php

namespace App\Domain\Fees\Application;

use App\Domain\Fees\Infrastructure\Charge;

/**
 * UAT-C4 / UAT-C6 (UAT-FIN-20, ADR 0075 sections 17 and 19): the one
 * "these two charges look alike" evaluator, shared by the ad-hoc charge
 * warning (`ChargeAdministrationService::similarUncancelledCharges()`) and
 * fee-run overlap detection (`FeeRunOverlapDetector`). It only compares
 * what a charge row carries; it never decides that a charge IS a duplicate.
 */
final class SimilarCharge
{
    /** Descriptions are compared without case, spacing or punctuation. */
    public static function normalize(string $description): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower($description));
    }

    /** Equal once normalized, or one contains the other (at least 6 characters). */
    public static function descriptionsResemble(string $existing, string $wanted): bool
    {
        $existing = self::normalize($existing);
        $wanted = self::normalize($wanted);
        $shorter = min(strlen($existing), strlen($wanted));

        return $existing === $wanted
            || ($shorter >= 6 && (str_contains($existing, $wanted) || str_contains($wanted, $existing)));
    }

    /** The same amount and the same due date. */
    public static function sameAmountAndDueDate(Charge $charge, string $amount, ?string $dueDate): bool
    {
        return $dueDate !== null
            && $charge->due_date?->toDateString() === $dueDate
            && bccomp($charge->amount, $amount, 2) === 0;
    }
}
