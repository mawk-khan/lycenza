<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Payroll\Infrastructure\SalaryStructureComponent;

/**
 * Phase 9.8 -- one formula line of a `SalaryStructureDetail`. `rate` is
 * a structure-level policy fraction (0-1), never an Employee-specific
 * amount -- safe under `payroll.structures.view`.
 */
final class SalaryStructureComponentSummary
{
    public function __construct(
        public readonly string $id,
        public readonly string $salaryComponentId,
        public readonly string $calculationType,
        public readonly ?string $baseComponentId,
        public readonly ?string $rate,
        public readonly int $displayOrder,
    ) {}

    public static function fromModel(SalaryStructureComponent $component): self
    {
        return new self(
            id: $component->id,
            salaryComponentId: $component->salary_component_id,
            calculationType: $component->calculation_type,
            baseComponentId: $component->base_component_id,
            rate: $component->rate,
            displayOrder: $component->display_order,
        );
    }
}
