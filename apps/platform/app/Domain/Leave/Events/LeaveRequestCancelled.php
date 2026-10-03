<?php

namespace App\Domain\Leave\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * HRX.2 (ADR 0065 §16, §23.11): an approved leave request was cancelled; its
 * consumption was reversed per leave year. Minimized like
 * LeaveRequestApproved -- the signal HRX.5 will turn into a payroll pending
 * difference. Not webhook-publishable.
 */
class LeaveRequestCancelled implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    /** @param  list<array{leaveYearId: string, units: int}>  $reversed */
    public function __construct(
        public readonly string $schoolId,
        public readonly string $leaveRequestId,
        public readonly string $employmentRecordId,
        public readonly string $employeeId,
        public readonly string $leaveTypeId,
        public readonly bool $isPaid,
        public readonly bool $tracksBalance,
        public readonly array $reversed,
    ) {}

    public function eventType(): string
    {
        return 'leave.request.cancelled.v1';
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
            'leaveRequestId' => $this->leaveRequestId, 'employmentRecordId' => $this->employmentRecordId, 'employeeId' => $this->employeeId,
            'leaveTypeId' => $this->leaveTypeId, 'isPaid' => $this->isPaid, 'tracksBalance' => $this->tracksBalance,
            'reversed' => $this->reversed,
        ];
    }
}
