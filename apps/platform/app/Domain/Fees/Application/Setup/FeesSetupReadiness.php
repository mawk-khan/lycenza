<?php

namespace App\Domain\Fees\Application\Setup;

use App\Domain\AcademicStructure\Application\CurrentAcademicYearResolver;
use App\Domain\Fees\Infrastructure\FeeHead;
use App\Domain\Fees\Infrastructure\FeeStructure;
use App\Models\School;
use App\Support\Setup\SetupReadiness;
use App\Support\Setup\SetupReadinessProvider;

/**
 * ORG.12A (ADR 0080 §10): Fees configuration readiness -- an active fee
 * head (which already names its Finance ledger accounts) and an active fee
 * structure for the active year. Configuration only: never an amount, a
 * Charge, a balance or a Payment.
 */
class FeesSetupReadiness implements SetupReadinessProvider
{
    public function __construct(private readonly CurrentAcademicYearResolver $years) {}

    public function keys(): array
    {
        return ['finance.fees'];
    }

    public function evaluate(string $key, School $school): SetupReadiness
    {
        $yearId = $this->years->tryResolve($school)?->id;

        return SetupReadiness::fromChecks(
            [
                'fee_heads' => FeeHead::query()->where('school_id', $school->id)->where('status', FeeHead::STATUS_ACTIVE)->exists(),
                'fee_structure' => $yearId !== null && FeeStructure::query()->where('school_id', $school->id)
                    ->where('academic_year_id', $yearId)->where('status', FeeStructure::STATUS_ACTIVE)->exists(),
            ],
            configured: 'Fee heads and an active fee structure for the active year are set up.',
            incomplete: 'Set up fee heads, then activate a fee structure for the active year.',
        );
    }
}
