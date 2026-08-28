<?php

namespace App\Domain\HR\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * Phase 8A closure correction: fires whenever
 * App\Domain\HR\Application\EmployeeService::update() changes a core
 * Employee field (full_name/work_email/work_phone/user_id). Mirrors
 * App\Domain\HR\Events\EmployeeCreated's exact shape. Payload carries
 * only the changed field NAMES, never their values (rule 44
 * minimization -- some of those fields, e.g. work_email, are still
 * Internal-tier, but the principle stays "the least, not whatever
 * happens to be low-sensitivity").
 */
class EmployeeUpdated implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    /**
     * @param  list<string>  $changedFields
     */
    public function __construct(
        public readonly string $schoolId,
        public readonly string $employeeId,
        public readonly array $changedFields,
    ) {}

    public function eventType(): string
    {
        return 'employee.updated.v1';
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
        return ['employeeId' => $this->employeeId, 'changedFields' => $this->changedFields];
    }
}
