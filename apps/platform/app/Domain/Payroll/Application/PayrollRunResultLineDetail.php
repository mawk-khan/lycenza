<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Payroll\Infrastructure\PayrollRunResultLine;

/**
 * Phase 9.7 -- one line of `PayrollRunResultReadService::listResults()`'s
 * detail, never the raw `PayrollRunResultLine` Eloquent model.
 */
final class PayrollRunResultLineDetail
{
    public function __construct(
        public readonly string $salaryComponentId,
        public readonly string $amount,
        public readonly string $effect,
        public readonly ?string $resolvedLedgerAccountId,
    ) {}

    public static function fromModel(PayrollRunResultLine $line): self
    {
        return new self(
            salaryComponentId: $line->salary_component_id,
            amount: $line->amount,
            effect: $line->effect,
            resolvedLedgerAccountId: $line->resolved_ledger_account_id,
        );
    }
}
