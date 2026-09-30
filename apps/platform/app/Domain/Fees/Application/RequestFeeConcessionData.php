<?php

namespace App\Domain\Fees\Application;

/**
 * FEE.3 (ADR 0062 §14.1): a concession request as submitted. Amounts are
 * exact decimal strings, never floats. A targeted request names a charge
 * (its Student and year come from the charge); a standing request names
 * the Student, year, optional fee head and validity window. There is no
 * note field (owner decision M).
 */
final class RequestFeeConcessionData
{
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $scope,
        public readonly string $category,
        public readonly string $kind,
        public readonly ?string $fixedAmount = null,
        public readonly ?string $percentage = null,
        public readonly ?string $chargeId = null,
        public readonly ?string $studentId = null,
        public readonly ?string $academicYearId = null,
        public readonly ?string $feeHeadId = null,
        public readonly ?string $validFrom = null,
        public readonly ?string $validTo = null,
    ) {}
}
