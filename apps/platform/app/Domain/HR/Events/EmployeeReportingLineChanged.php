<?php

namespace App\Domain\HR\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * HR.3A (replaces `assignment.manager_changed.v1`): a subordinate
 * employment's reporting line changed. Belongs to the SUBORDINATE's
 * Employee. `operation` is a closed code (`assigned`, `changed`, `removed`,
 * `corrected`, `subordinate_employment_ended`, `manager_employment_ended`);
 * `managerEmploymentId` is the manager from `effectiveOn` (null when the line
 * ends). Internal only: not in WebhookEventRegistry (rule 45).
 */
class EmployeeReportingLineChanged implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $employeeId,
        public readonly string $subordinateEmploymentId,
        public readonly string $operation,
        public readonly string $effectiveOn,
        public readonly ?string $previousManagerEmploymentId,
        public readonly ?string $managerEmploymentId,
    ) {}

    public function eventType(): string
    {
        return 'employee.reporting_line_changed.v1';
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
            'employeeId' => $this->employeeId,
            'subordinateEmploymentId' => $this->subordinateEmploymentId,
            'operation' => $this->operation,
            'effectiveOn' => $this->effectiveOn,
            'previousManagerEmploymentId' => $this->previousManagerEmploymentId,
            'managerEmploymentId' => $this->managerEmploymentId,
        ];
    }
}
