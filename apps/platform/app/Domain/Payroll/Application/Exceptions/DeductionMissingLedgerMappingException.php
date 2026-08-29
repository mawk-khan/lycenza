<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * ADR 0032 "Deduction accounting": "A deduction with a nonzero total
 * and no configured mapping blocks posting entirely (no suspense-
 * account fallback)." Raised by `PayrollPostingService::post()` when a
 * `salary_components` row backing a nonzero-total deduction line never
 * had `liability_ledger_account_id` set at the time its
 * `payroll_run_result_lines.resolved_ledger_account_id` snapshot was
 * taken (Checkpoint 9.3) -- there is no fallback account a run can
 * post to instead; the fix is to configure the component's liability
 * account and recalculate the run before posting.
 */
class DeductionMissingLedgerMappingException extends PayrollException
{
    public function __construct(public readonly string $salaryComponentId, public readonly string $payrollRunId)
    {
        parent::__construct(
            422,
            'PAYROLL_DEDUCTION_MISSING_LEDGER_MAPPING',
            "Salary component '{$salaryComponentId}' has a nonzero deduction total in run '{$payrollRunId}' but no configured liability ledger account -- posting is blocked.",
        );
    }
}
