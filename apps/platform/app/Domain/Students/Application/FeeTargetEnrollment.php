<?php

namespace App\Domain\Students\Application;

use Carbon\CarbonImmutable;

/**
 * FEE.2 (ADR 0062 §10.1): the enrollment facts Fees needs to decide fee
 * assessment eligibility -- placement context, the inclusive date interval,
 * lifecycle status and whether the Student is active. No name, contact or
 * other personal detail; Fees never reads Students' models directly.
 */
final readonly class FeeTargetEnrollment
{
    public function __construct(
        public string $enrollmentId,
        public string $studentId,
        public string $academicYearId,
        public string $gradeLevelId,
        public string $campusId,
        public string $sectionId,
        public CarbonImmutable $startsOn,
        public ?CarbonImmutable $endsOn,
        public string $status,
        public bool $studentIsActive,
    ) {}

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /** Whether the enrollment's inclusive interval overlaps [$from, $to]. */
    public function overlaps(CarbonImmutable $from, CarbonImmutable $to): bool
    {
        return $this->startsOn->lte($to) && ($this->endsOn === null || $this->endsOn->gte($from));
    }
}
