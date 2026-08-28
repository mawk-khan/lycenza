<?php

namespace App\Domain\HR\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * Phase 8A closure correction: fires from
 * App\Domain\HR\Application\EmployeeLifecycleService::rehire(), in
 * ADDITION to the EmploymentStarted (and, if an initial Assignment was
 * given, AssignmentStarted/PrimaryAssignmentChanged) events that fire
 * from the lower-level services rehire() calls through to. This is
 * deliberate, not double-counting: EmploymentStarted alone cannot tell
 * a downstream consumer "this was specifically a rehire, not this
 * Employee's first hire" -- that fact only exists at this command
 * layer (docs/modules/HR.md "Rehire strategy": rehire is identified by
 * requiring prior EmploymentRecord history, a check EmploymentService::create()
 * itself has no knowledge of).
 */
class EmployeeRehired implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $employeeId,
        public readonly string $employmentRecordId,
    ) {}

    public function eventType(): string
    {
        return 'employee.rehired.v1';
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
        return ['employeeId' => $this->employeeId, 'employmentRecordId' => $this->employmentRecordId];
    }
}
