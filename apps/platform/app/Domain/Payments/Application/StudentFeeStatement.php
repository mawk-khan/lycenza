<?php

namespace App\Domain\Payments\Application;

/**
 * FEE.4 (ADR 0062 §18): one Student's fee statement, computed on read --
 * no stored balance. Per charge: outstanding = amount - allocations - live
 * adjustments, and 0 for a cancelled charge (a cancelled charge can hold
 * neither). Totals are sums of the lines. Money is exact decimal strings.
 *
 * E21.3A2: `detailExpiredThrough` names the latest financial year whose
 * detail has been (partly) expired under D8, or null. Settled charges of
 * such years are no longer listed; they contributed nothing to the dues.
 *
 * @param  list<array<string, mixed>>  $lines
 * @param  array{charged: string, adjusted: string, paid: string, outstanding: string}  $totals
 */
final class StudentFeeStatement
{
    public function __construct(
        public readonly string $studentId,
        public readonly ?string $academicYearId,
        public readonly string $currency,
        public readonly array $lines,
        public readonly array $totals,
        public readonly ?string $detailExpiredThrough = null,
    ) {}
}
