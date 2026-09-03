<?php

namespace App\Domain\Payroll\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * Phase 9.10 -- raised exactly once by `PayrollRunService::calculate()`,
 * from inside the same DB transaction that replaces the run's whole
 * result set. Emitted for every calculate()/recalculate() call (not
 * only a successful `-> calculated` transition), mirroring the
 * existing `payroll.run.calculated` audit call's identical
 * unconditional shape -- a recalculation that leaves the run at
 * `draft` because some EmploymentRecord remains unresolved is still a
 * genuine, event-worthy occurrence. Aggregate counts only (`resolvedCount`/
 * `unresolvedCount`) -- never a `payroll_run_results` figure.
 */
class PayrollRunCalculated implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $payrollRunId,
        public readonly int $resolvedCount,
        public readonly int $unresolvedCount,
        public readonly bool $transitionedToCalculated,
    ) {}

    public function eventType(): string
    {
        return 'payroll_run.calculated.v1';
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
            'payrollRunId' => $this->payrollRunId,
            'resolvedCount' => $this->resolvedCount,
            'unresolvedCount' => $this->unresolvedCount,
            'transitionedToCalculated' => $this->transitionedToCalculated,
        ];
    }
}
