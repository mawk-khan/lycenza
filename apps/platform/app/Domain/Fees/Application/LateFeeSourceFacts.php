<?php

namespace App\Domain\Fees\Application;

/**
 * FEE.5 (ADR 0062 §16.2): one late-fee candidate -- an uncancelled charge
 * with a LIVE fee assessment (structure-generated), read through
 * `ChargeService`. A late-fee charge has no fee assessment, so it is never
 * a candidate (no late fee on a late fee).
 */
final class LateFeeSourceFacts
{
    public function __construct(
        public readonly string $chargeId,
        public readonly string $studentId,
        public readonly string $academicYearId,
        public readonly string $feeStructureId,
        public readonly string $feeHeadId,
        public readonly string $billingPeriodKey,
        public readonly ?string $dueDate,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $description,
        // ORG.7G-1A (ADR 0076 §49): the source Charge's stored Campus, which
        // its late fee inherits (ChargeCampusAttribution::fromCharge()).
        public readonly ?string $campusId = null,
    ) {}
}
