<?php

namespace App\Domain\HR\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * Phase 8A closure correction: fires from
 * App\Domain\HR\Application\EmployeeAssignmentService::setPrimary().
 */
class PrimaryAssignmentChanged implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $employeeId,
        public readonly string $employmentRecordId,
        public readonly string $assignmentId,
    ) {}

    public function eventType(): string
    {
        return 'assignment.primary_changed.v1';
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
            'employmentRecordId' => $this->employmentRecordId,
            'assignmentId' => $this->assignmentId,
        ];
    }
}
