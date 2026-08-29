<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * Thrown when the conditional UPDATE `WHERE status = 'calculated'` in
 * `PayrollRunService::approve()` affects zero rows -- a genuine
 * concurrent race: another approve() (or some other status change)
 * committed between this call's entry check and its own UPDATE
 * statement. Mirrors
 * `App\Domain\Communications\Application\Exceptions\ApprovalAlreadyDecidedException`'s
 * identical role.
 */
class ConcurrentRunApprovalConflictException extends PayrollException
{
    public function __construct(public readonly string $runId)
    {
        parent::__construct(409, 'PAYROLL_CONCURRENT_RUN_APPROVAL', "Payroll run {$runId} could not be approved: its status changed concurrently.");
    }
}
