<?php

namespace App\Domain\HR\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * Phase 8A closure correction: fires from
 * App\Domain\HR\Application\ReportingHierarchyService::setManager().
 * Belongs to the SUBORDINATE's Employee (whose reporting line
 * changed), matching the audit event's own `employeeId` resolution
 * (see setManager()'s docblock). `newManagerAssignmentId` is null when
 * a manager pointer is cleared, not changed to another manager.
 */
class EmployeeManagerChanged implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $employeeId,
        public readonly string $subordinateAssignmentId,
        public readonly ?string $previousManagerAssignmentId,
        public readonly ?string $newManagerAssignmentId,
    ) {}

    public function eventType(): string
    {
        return 'assignment.manager_changed.v1';
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
            'subordinateAssignmentId' => $this->subordinateAssignmentId,
            'previousManagerAssignmentId' => $this->previousManagerAssignmentId,
            'newManagerAssignmentId' => $this->newManagerAssignmentId,
        ];
    }
}
