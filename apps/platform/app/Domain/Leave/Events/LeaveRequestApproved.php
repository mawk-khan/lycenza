<?php

namespace App\Domain\Leave\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * HRX.2 (ADR 0065 §16, §23.11): an approved leave request, with its
 * chargeable-day evidence. Minimized: ids, dates, portions, integer units
 * and the paid/tracked flags HRX.5 needs. No free text, no health data.
 * Not webhook-publishable (WebhookEventRegistry, rule 45/77); nothing
 * consumes it before HRX.5.
 */
class LeaveRequestApproved implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    /** @param  list<array{date: string, portion: string, units: int, leaveYearId: string}>  $days */
    public function __construct(
        public readonly string $schoolId,
        public readonly string $leaveRequestId,
        public readonly string $employmentRecordId,
        public readonly string $employeeId,
        public readonly string $leaveTypeId,
        public readonly bool $isPaid,
        public readonly bool $tracksBalance,
        public readonly array $days,
    ) {}

    public function eventType(): string
    {
        return 'leave.request.approved.v1';
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
            'days' => $this->days, 'totalUnits' => array_sum(array_column($this->days, 'units')),
        ];
    }
}
