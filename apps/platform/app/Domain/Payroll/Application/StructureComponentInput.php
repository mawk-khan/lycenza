<?php

namespace App\Domain\Payroll\Application;

/**
 * Phase 9.3 -- one `salary_structure_components` row, translated into
 * the plain shape `PayrollCalculationEngine::calculateFromStructure()`
 * needs -- deliberately framework-light (no Eloquent model) so the
 * engine stays a pure, DB-free calculator (golden-fixture testable in
 * isolation). Must be supplied in `displayOrder` order -- the engine
 * does not re-sort (mirrors the database's own cycle-prevention
 * invariant: a `baseComponentId` always names an earlier-ordered
 * component).
 */
final class StructureComponentInput
{
    public function __construct(
        public readonly string $structureComponentId,
        public readonly string $salaryComponentId,
        public readonly string $calculationType,
        public readonly ?string $baseComponentId,
        public readonly ?string $rate,
        public readonly bool $isEarning,
        public readonly ?string $resolvedLedgerAccountId,
    ) {}
}
