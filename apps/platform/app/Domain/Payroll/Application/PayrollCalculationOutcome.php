<?php

namespace App\Domain\Payroll\Application;

/**
 * Phase 9.3 -- the result of `PayrollRunService::calculate()`.
 * `transitionedToCalculated` is true only when every eligible
 * EmploymentRecord was resolved -- ADR 0032's fail-closed rule: "a run
 * may never represent an unresolved Employee as calculated."
 */
final class PayrollCalculationOutcome
{
    /**
     * @param  list<string>  $resolvedEmploymentRecordIds
     * @param  list<string>  $unresolvedEmploymentRecordIds
     */
    public function __construct(
        public readonly array $resolvedEmploymentRecordIds,
        public readonly array $unresolvedEmploymentRecordIds,
        public readonly bool $transitionedToCalculated,
    ) {}
}
