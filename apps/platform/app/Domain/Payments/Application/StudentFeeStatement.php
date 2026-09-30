<?php

namespace App\Domain\Payments\Application;

/**
 * FEE.4 (ADR 0062 §18): one Student's fee statement, computed on read --
 * no stored balance. Per charge: outstanding = amount - allocations - live
 * adjustments, and 0 for a cancelled charge (a cancelled charge can hold
 * neither). Totals are sums of the lines. Money is exact decimal strings.
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
    ) {}
}
