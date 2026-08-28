<?php

namespace App\Domain\HR\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * Phase 8A closure correction: fires from
 * App\Domain\HR\Application\EmployeeAssignmentService::end() -- the
 * single dispatch site for both a direct end() call and the
 * Assignment-closure cascade
 * App\Domain\HR\Application\EmploymentService::end() runs when an
 * Employment ends (that cascade updates rows directly rather than
 * calling this service's end() in a loop, so it does NOT also fire
 * this event per closed Assignment -- EmploymentEnded already covers
 * that case at the Employment level, avoiding an event storm for what
 * is really one business action).
 */
class AssignmentEnded implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $employeeId,
        public readonly string $assignmentId,
    ) {}

    public function eventType(): string
    {
        return 'assignment.ended.v1';
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
        return ['employeeId' => $this->employeeId, 'assignmentId' => $this->assignmentId];
    }
}
