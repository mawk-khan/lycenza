<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Payroll\Infrastructure\PayrollRunResultLine;

/**
 * Phase 9.10 -- one earning/deduction line of a `Payslip`. Adds the
 * component's own non-sensitive identity (`code`/`name`/`type`) on top
 * of `PayrollRunResultLineDetail`'s shape -- useful, human-readable
 * payslip content, never a bank/statutory field. `resolvedLedgerAccountId`
 * is deliberately NOT carried here (unlike `PayrollRunResultLineDetail`)
 * -- an internal Finance account reference has no place on a document
 * an Employee-facing payslip surface renders.
 */
final class PayslipLine
{
    public function __construct(
        public readonly string $salaryComponentId,
        public readonly string $componentCode,
        public readonly string $componentName,
        public readonly string $componentType,
        public readonly string $amount,
        public readonly string $effect,
    ) {}

    public static function fromModel(PayrollRunResultLine $line): self
    {
        return new self(
            salaryComponentId: $line->salary_component_id,
            componentCode: $line->component->code,
            componentName: $line->component->name,
            componentType: $line->component->type,
            amount: $line->amount,
            effect: $line->effect,
        );
    }
}
