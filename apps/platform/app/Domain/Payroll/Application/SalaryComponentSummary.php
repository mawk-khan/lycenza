<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Payroll\Infrastructure\SalaryComponent;

/**
 * Phase 9.8 -- the non-sensitive view of a `SalaryComponent`, returned
 * by `PayrollStructureReadService` (`payroll.structures.view`). Policy
 * shape only (semantic identity, type, Finance liability mapping) --
 * never an amount, which lives at the Employee-specific
 * `CompensationAssignmentValue`/`PayrollRunResultLine` layer instead.
 */
final class SalaryComponentSummary
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $name,
        public readonly string $type,
        public readonly ?string $liabilityLedgerAccountId,
        public readonly string $status,
    ) {}

    public static function fromModel(SalaryComponent $component): self
    {
        return new self(
            id: $component->id,
            code: $component->code,
            name: $component->name,
            type: $component->type,
            liabilityLedgerAccountId: $component->liability_ledger_account_id,
            status: $component->status,
        );
    }
}
