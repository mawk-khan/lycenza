<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Payroll\Infrastructure\PayrollRunPosting;

/**
 * Phase 9.8 idempotency correction -- the single source of truth for
 * `PayrollRunPostingController::post()`/`reverse()`'s JSON `data` shape.
 * Used by BOTH the controller (a fresh, non-replayed response) and
 * `PayrollPostingService::post()`/`reverse()` (the body handed to
 * `IdempotencyGuard::completeWithin()` inside the SAME transaction as
 * the business action) so a stored-and-later-replayed response is
 * byte-for-byte identical to what a fresh request would have returned
 * -- never two independently-maintained shapes that could drift apart.
 */
final class PayrollRunPostingSummary
{
    public function __construct(
        public readonly string $id,
        public readonly string $payrollRunId,
        public readonly string $journalEntryId,
        public readonly string $postingKind,
        public readonly ?string $reversalOfPayrollRunPostingId,
        public readonly ?string $reason,
    ) {}

    public static function fromModel(PayrollRunPosting $posting): self
    {
        return new self(
            id: $posting->id,
            payrollRunId: $posting->payroll_run_id,
            journalEntryId: $posting->journal_entry_id,
            postingKind: $posting->posting_kind,
            reversalOfPayrollRunPostingId: $posting->reversal_of_payroll_run_posting_id,
            reason: $posting->reason,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'payrollRunId' => $this->payrollRunId,
            'journalEntryId' => $this->journalEntryId,
            'postingKind' => $this->postingKind,
            'reversalOfPayrollRunPostingId' => $this->reversalOfPayrollRunPostingId,
            'reason' => $this->reason,
        ];
    }
}
