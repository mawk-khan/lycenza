<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Payroll\Infrastructure\SalaryStructure;

/**
 * Phase 9.8 -- the non-sensitive view of a `SalaryStructure` revision,
 * returned by `PayrollStructureReadService::listStructures()`
 * (`payroll.structures.view`). No component/rate detail here -- see
 * `SalaryStructureDetail` for the full revision.
 */
final class SalaryStructureSummary
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly int $version,
        public readonly string $name,
        public readonly string $status,
    ) {}

    public static function fromModel(SalaryStructure $structure): self
    {
        return new self(
            id: $structure->id,
            code: $structure->code,
            version: $structure->version,
            name: $structure->name,
            status: $structure->status,
        );
    }
}
