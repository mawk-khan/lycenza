<?php

namespace App\Domain\Fees\Application;

use App\Domain\Fees\Infrastructure\Charge;
use Illuminate\Support\Carbon;

/**
 * Phase 0G.4: the public return value of `ChargeService::assess()`/
 * `cancel()` -- deliberately NOT the raw `Charge` Eloquent model,
 * mirroring App\Domain\Finance\Application\JournalEntryResult's exact
 * result-boundary discipline. Minimal -- only what a caller of
 * `assess()`/`cancel()` needs to know just happened; this is NOT the
 * start of a Fees read model (that is `ChargeReadService`'s job).
 */
final class ChargeResult
{
    public function __construct(
        public readonly string $chargeId,
        public readonly string $schoolId,
        public readonly string $studentId,
        public readonly string $academicYearId,
        public readonly string $currency,
        public readonly string $amount,
        public readonly string $journalEntryId,
        public readonly ?Carbon $cancelledAt,
        public readonly ?string $cancellationJournalEntryId,
    ) {}

    public static function fromModel(Charge $charge): self
    {
        return new self(
            chargeId: $charge->id,
            schoolId: $charge->school_id,
            studentId: $charge->student_id,
            academicYearId: $charge->academic_year_id,
            currency: $charge->currency,
            amount: $charge->amount,
            journalEntryId: $charge->journal_entry_id,
            cancelledAt: $charge->cancelled_at,
            cancellationJournalEntryId: $charge->cancellation_journal_entry_id,
        );
    }
}
