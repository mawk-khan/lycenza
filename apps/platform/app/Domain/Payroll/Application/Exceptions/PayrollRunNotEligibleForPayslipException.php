<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * Phase 9.10 -- raised by `PayslipReadService::render()` when the
 * targeted run has not yet crossed `PayrollRun::isApprovedOrLater()`
 * (i.e. it is still `draft`/`calculated`). A payslip is only ever
 * rendered from a frozen, immutable result set (docs/modules/PAYROLL.md
 * "Payslip rendering") -- the editable/recalculable "Calculation
 * preview" shown for an earlier-stage run is a distinct, explicitly
 * labeled surface (`App\Http\Controllers\App\Payroll\PayrollRunController::renderShow()`),
 * never this one.
 */
class PayrollRunNotEligibleForPayslipException extends PayrollException
{
    public function __construct(public readonly string $runId, public readonly string $actualStatus)
    {
        parent::__construct(422, 'PAYROLL_RUN_NOT_ELIGIBLE_FOR_PAYSLIP', "Payroll run {$runId} is {$actualStatus} -- a payslip may only be rendered once a run is approved or posted.");
    }
}
