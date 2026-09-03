<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * ADR 0034 "Deduction accounting": "A deduction with a nonzero total
 * and no configured mapping blocks posting entirely (no suspense-
 * account fallback)." Raised by `PayrollPostingService::post()`'s
 * `resolveDeductionLedgerAccountId()` when a nonzero-total deduction
 * component's `liability_ledger_account_id` is either unset, or set
 * but no longer valid (the account no longer exists in this School,
 * is inactive, or is no longer typed `liability`) -- resolved and
 * validated LIVE at posting time (Phase 9.5 accounting-integrity
 * correction), never trusted from a calculation-time snapshot. There
 * is no fallback account a run can post to instead; the fix is to
 * configure/repair the component's liability account mapping and
 * retry posting (no recalculation is needed -- the monetary amounts
 * are already correct and frozen; only the account mapping was
 * invalid).
 */
class DeductionMissingLedgerMappingException extends PayrollException
{
    public function __construct(public readonly string $salaryComponentId, public readonly string $payrollRunId)
    {
        parent::__construct(
            422,
            'PAYROLL_DEDUCTION_MISSING_LEDGER_MAPPING',
            "Salary component '{$salaryComponentId}' has a nonzero deduction total in run '{$payrollRunId}' but no configured, active, correctly-typed liability ledger account -- posting is blocked.",
        );
    }
}
