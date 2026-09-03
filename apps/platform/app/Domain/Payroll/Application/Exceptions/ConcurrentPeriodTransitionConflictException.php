<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * Phase 9.11 -- thrown when the conditional UPDATE
 * (`WHERE status = 'draft'` for open, `WHERE status = 'open'` for
 * close) in `PayrollPeriodService` affects zero rows -- a genuine
 * concurrent race: another open()/close() call committed between this
 * call's entry check and its own UPDATE statement. Mirrors
 * `ConcurrentRunApprovalConflictException`'s identical role for run
 * approval.
 */
class ConcurrentPeriodTransitionConflictException extends PayrollException
{
    public function __construct(public readonly string $periodId)
    {
        parent::__construct(409, 'PAYROLL_CONCURRENT_PERIOD_TRANSITION', "Payroll period {$periodId} could not be transitioned: its status changed concurrently.");
    }
}
