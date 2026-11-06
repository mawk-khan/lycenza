<?php

namespace App\Domain\Payroll\Application\Exceptions;

/** HR.R1-C6 (PAY-18, OD-HR-23): the run's makers, its approver and whoever executed the reversal never review it. */
class ReconciliationReviewerNotIndependentException extends PayrollException
{
    public function __construct(public readonly string $reconciliationId)
    {
        parent::__construct(409, 'PAYROLL_RECONCILIATION_REVIEWER_NOT_INDEPENDENT', 'You took part in this run or its reversal, so you cannot review it. Someone independent of both must do this.');
    }
}
