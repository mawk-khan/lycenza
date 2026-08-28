<?php

namespace App\Domain\HR\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * Phase 8A closure correction: fires from
 * App\Domain\HR\Application\EmploymentService::end() -- the single
 * dispatch site for BOTH a direct end() call and
 * App\Domain\HR\Application\EmployeeLifecycleService::separate()
 * (which calls straight through to end(), never duplicating its
 * logic). This is deliberately the one and only "ending" event --
 * matching docs/modules/HR.md 8A.13's own "No new audit event family"
 * decision (reuse `hr.employment.ended`, never invent a parallel
 * `employee.separated` audit trail) applied the same way to domain
 * events.
 */
class EmploymentEnded implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $employeeId,
        public readonly string $employmentRecordId,
        public readonly string $status,
    ) {}

    public function eventType(): string
    {
        return 'employment.ended.v1';
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
            'status' => $this->status,
        ];
    }
}
