<?php

namespace App\Domain\Fees\Application;

use Illuminate\Support\Carbon;

/**
 * FEE.4 (ADR 0062 §18): the Fees-owned facts one statement or receipt line
 * needs about one charge -- read through `ChargeService`, never Fees'
 * tables (Payments -> Fees). Fee head and billing period come from the
 * charge's fee assessment when it has one (live or voided); adjustments
 * include cancelled ones with their cancellation time. Money is exact
 * decimal strings.
 *
 * @param  list<array{id: string, feeConcessionId: string, category: string, amount: string, postedAt: string, cancelledAt: string|null}>  $adjustments
 */
final class ChargeStatementLine
{
    public function __construct(
        public readonly string $chargeId,
        public readonly string $studentId,
        public readonly string $academicYearId,
        public readonly string $description,
        public readonly string $amount,
        public readonly string $currency,
        public readonly ?string $dueDate,
        public readonly Carbon $assessedAt,
        public readonly ?Carbon $cancelledAt,
        public readonly ?string $feeHeadId,
        public readonly ?string $feeHeadName,
        public readonly ?string $billingPeriodKey,
        public readonly ?string $billingPeriodLabel,
        public readonly array $adjustments,
        public readonly string $liveAdjustedTotal,
    ) {}
}
