<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Payroll\Infrastructure\CompensationAssignmentValue;

/**
 * Phase 9.7 -- one Employee-specific fixed component amount, returned
 * ONLY by `PayrollCompensationReadService::getAssignmentValues()`
 * (`payroll.compensation.sensitive.view`), never the raw
 * `CompensationAssignmentValue` Eloquent model. Highly Sensitive
 * (`docs/security/DATA-CLASSIFICATION.md`) -- deliberately absent from
 * `CompensationAssignmentSummary`, the non-sensitive DTO
 * `payroll.compensation.view` returns.
 */
final class CompensationAssignmentValueDetail
{
    public function __construct(
        public readonly string $salaryStructureComponentId,
        public readonly string $amount,
    ) {}

    public static function fromModel(CompensationAssignmentValue $value): self
    {
        return new self(
            salaryStructureComponentId: $value->salary_structure_component_id,
            amount: $value->amount,
        );
    }
}
