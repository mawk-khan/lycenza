<?php

namespace App\Domain\Payroll\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * Phase 9.10 -- raised exactly once by `CompensationService::assign()`,
 * from inside the same DB transaction as the new
 * `employee_compensation_assignments` row (and, when one was open, the
 * UPDATE closing it). One event type covers both a first assignment
 * (`previousAssignmentId` null) and a supersession
 * (`previousAssignmentId` set) -- `assign()` is itself a single method
 * for both cases (see its own docblock), so a separate
 * "EmployeeCompensationSuperseded" event class would only duplicate
 * this one's shape for no genuine distinction. `effectiveFrom` mirrors
 * exactly what the existing `payroll.compensation.assigned` audit call
 * already logs (ADR 0032 "Sensitive values") -- never an amount, rate,
 * or component value.
 */
class EmployeeCompensationAssigned implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $employmentRecordId,
        public readonly string $compensationAssignmentId,
        public readonly string $salaryStructureId,
        public readonly ?string $previousAssignmentId,
        public readonly string $effectiveFrom,
    ) {}

    public function eventType(): string
    {
        return 'employee_compensation.assigned.v1';
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
            'employmentRecordId' => $this->employmentRecordId,
            'compensationAssignmentId' => $this->compensationAssignmentId,
            'salaryStructureId' => $this->salaryStructureId,
            'previousAssignmentId' => $this->previousAssignmentId,
            'effectiveFrom' => $this->effectiveFrom,
        ];
    }
}
