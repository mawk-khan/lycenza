<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Payroll\Infrastructure\SalaryStructure;
use App\Domain\Payroll\Infrastructure\SalaryStructureComponent;

/**
 * Phase 9.8 -- `PayrollStructureReadService::getStructureDetail()`'s
 * return type: a `SalaryStructureSummary` plus its ordered component
 * formula lines, never the raw `SalaryStructure`/`SalaryStructureComponent`
 * Eloquent models.
 *
 * @param  list<SalaryStructureComponentSummary>  $components
 */
final class SalaryStructureDetail
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly int $version,
        public readonly string $name,
        public readonly string $status,
        public readonly array $components,
    ) {}

    public static function fromModel(SalaryStructure $structure): self
    {
        return new self(
            id: $structure->id,
            code: $structure->code,
            version: $structure->version,
            name: $structure->name,
            status: $structure->status,
            components: $structure->components->map(fn (SalaryStructureComponent $c) => SalaryStructureComponentSummary::fromModel($c))->all(),
        );
    }
}
