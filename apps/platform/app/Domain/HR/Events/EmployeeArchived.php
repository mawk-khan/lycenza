<?php

namespace App\Domain\HR\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * Phase 8A closure correction: fires when
 * App\Domain\HR\Application\EmployeeService::archive() transitions
 * `Employee.record_status` from `active` to `archived`. This is the
 * Employee-record's OWN existence state (docs/modules/HR.md principle
 * 2.6) -- distinct from, and never dispatched by, Employment ending
 * (see EmploymentEnded) or account/membership deactivation (unrelated
 * to this module entirely).
 */
class EmployeeArchived implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $employeeId,
    ) {}

    public function eventType(): string
    {
        return 'employee.archived.v1';
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
        return ['employeeId' => $this->employeeId];
    }
}
