<?php

namespace App\Domain\HR\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * Phase 8A closure correction: fires from
 * App\Domain\HR\Application\EmploymentService::create() -- covers
 * both a genuine first hire and the EmploymentRecord half of a rehire
 * (App\Domain\HR\Application\EmployeeLifecycleService::rehire() calls
 * straight through to this same create(), so this event and
 * EmployeeRehired both fire for a rehire -- see EmployeeRehired's own
 * docblock for why that is correct, not duplicative).
 */
class EmploymentStarted implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $employeeId,
        public readonly string $employmentRecordId,
    ) {}

    public function eventType(): string
    {
        return 'employment.started.v1';
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
