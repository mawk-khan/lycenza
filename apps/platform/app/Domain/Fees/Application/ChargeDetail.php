<?php

namespace App\Domain\Fees\Application;

use App\Domain\Fees\Infrastructure\Charge;
use Illuminate\Support\Carbon;

/**
 * Phase 0G.4: `ChargeReadService::getChargeDetail()`'s typed result --
 * adds the structural ledger linkage (`journalEntryId`,
 * `receivableLedgerAccountId`, `revenueLedgerAccountId`,
 * `cancellationJournalEntryId`) that `ChargeSummary` omits, still never
 * the raw `Charge` Eloquent model.
 */
final class ChargeDetail
{
    public function __construct(
        public readonly string $chargeId,
        public readonly string $studentId,
        public readonly string $academicYearId,
        public readonly string $description,
        public readonly string $amount,
        public readonly string $currency,
        public readonly ?Carbon $dueDate,
        public readonly string $receivableLedgerAccountId,
        public readonly string $revenueLedgerAccountId,
        public readonly string $journalEntryId,
        public readonly ?Carbon $cancelledAt,
        public readonly ?string $cancellationJournalEntryId,
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
            receivableLedgerAccountId: $charge->receivable_ledger_account_id,
            revenueLedgerAccountId: $charge->revenue_ledger_account_id,
            journalEntryId: $charge->journal_entry_id,
            cancelledAt: $charge->cancelled_at,
            cancellationJournalEntryId: $charge->cancellation_journal_entry_id,
            createdAt: $charge->created_at,
        );
    }
}
