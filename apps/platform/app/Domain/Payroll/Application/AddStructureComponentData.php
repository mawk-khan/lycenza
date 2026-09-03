<?php

namespace App\Domain\Payroll\Application;

/**
 * Phase 9.2 -- the typed input for `SalaryStructureService::addComponent()`,
 * used instead of an unbounded array (matches
 * `App\Domain\Finance\Application\JournalLineData`'s established
 * precedent). `baseComponentId`/`rate` are meaningful only for
 * `percentage_of_base` -- the database's own shape CHECK
 * (`salary_structure_components_shape_check`) remains authoritative
 * regardless of Application-layer validation.
 */
final class AddStructureComponentData
{
    public function __construct(
        public readonly string $salaryComponentId,
        public readonly string $calculationType,
        public readonly ?string $baseComponentId,
        public readonly ?string $rate,
        public readonly int $displayOrder,
    ) {}
}
