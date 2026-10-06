<?php

namespace App\Domain\Fees\Application\Sources;

/**
 * OPF.4 (ADR 0067 §17): the trusted input of one source event charge. The
 * source computes the amount under its own policy (D3); FEE takes the
 * ledger destination from the fee head and owns the charge. Identifiers are
 * already resolved by the source; `charges`' composite foreign keys prove
 * they are the assessing School's.
 */
final readonly class FeeSourceChargeData
{
    public function __construct(
        public string $studentId,
        public string $academicYearId,
        public string $feeHeadId,
        public string $amount,
        public string $description,
    ) {}
}
