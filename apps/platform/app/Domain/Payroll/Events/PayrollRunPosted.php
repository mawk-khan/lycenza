<?php

namespace App\Domain\Payroll\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * Phase 9.10 -- raised exactly once by `PayrollPostingService::post()`,
 * from inside the same DB transaction as the row-locked
 * `approved -> posted` transition, the Finance `LedgerService::post()`
 * call, and (when a NEW idempotency claim is present)
 * `IdempotencyGuard::completeWithin()` -- all commit or roll back
 * together. A replay never re-enters `post()` at all (see that
 * method's own docblock), so this event structurally cannot be
 * duplicated by a retried HTTP request. `lineCount` is the number of
 * posted journal LINES (a structural fact, like
 * `App\Domain\Finance\Events\JournalEntryPosted::$lineCount`) -- never
 * a monetary figure.
 */
class PayrollRunPosted implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $payrollRunId,
        public readonly string $journalEntryId,
        public readonly int $lineCount,
        public readonly string $postedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'payroll_run.posted.v1';
    }

    public function eventVersion(): int
    {
        return 1;
    }

    public function schoolId(): ?string
    {
        return $this->schoolId;
    }

    public function payload(): array
    {
        return [
            'payrollRunId' => $this->payrollRunId,
            'journalEntryId' => $this->journalEntryId,
            'lineCount' => $this->lineCount,
            'postedByUserId' => $this->postedByUserId,
        ];
    }
}
