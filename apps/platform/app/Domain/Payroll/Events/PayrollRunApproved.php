<?php

namespace App\Domain\Payroll\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * Phase 9.10 -- raised exactly once by `PayrollRunService::approve()`,
 * from inside the same DB transaction as the conditional
 * `status = 'calculated' -> 'approved'` UPDATE. A concurrent losing
 * approval attempt throws `ConcurrentRunApprovalConflictException`
 * before ever reaching this dispatch site, so this event can never be
 * emitted twice for the same run.
 */
class PayrollRunApproved implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $payrollRunId,
        public readonly string $approvedByUserId,
        public readonly string $preparedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'payroll_run.approved.v1';
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
            'approvedByUserId' => $this->approvedByUserId,
            'preparedByUserId' => $this->preparedByUserId,
        ];
    }
}
