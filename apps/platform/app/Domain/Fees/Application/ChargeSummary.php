<?php

namespace App\Domain\Fees\Application;

use App\Domain\Fees\Infrastructure\Charge;
use Illuminate\Support\Carbon;

/**
 * Phase 0G.4: one row of `ChargeReadService::listCharges()` -- a
 * typed, safe projection, never the raw `Charge` Eloquent model
 * (mirrors App\Domain\Finance\Application\JournalEntrySummary's exact
 * disclosure-boundary discipline). No `amount_paid`/`remaining_balance`/
 * `paid`/`partially_paid` fields -- Payments/allocation do not exist
 * yet (0G.5), and this checkpoint does not fabricate a settlement
 * status no payment record could yet justify (rule 17).
 */
final class ChargeSummary
{
    public function __construct(
        public readonly string $chargeId,
        public readonly string $studentId,
        public readonly string $academicYearId,
        public readonly string $description,
        public readonly string $amount,
        public readonly string $currency,
        public readonly ?Carbon $dueDate,
        public readonly ?Carbon $cancelledAt,
        public readonly Carbon $createdAt,
    ) {}

    public static function fromModel(Charge $charge): self
    {
        return new self(
            chargeId: $charge->id,
            studentId: $charge->student_id,
            academicYearId: $charge->academic_year_id,
            description: $charge->description,
            amount: $charge->amount,
            currency: $charge->currency,
            dueDate: $charge->due_date,
            cancelledAt: $charge->cancelled_at,
            createdAt: $charge->created_at,
        );
    }
}
