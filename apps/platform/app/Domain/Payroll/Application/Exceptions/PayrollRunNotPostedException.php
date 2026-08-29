<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * Raised by `PayrollPostingService::reverse()` when the targeted run
 * has no `posted` status yet (nothing to reverse) -- distinct from
 * `InvalidRunTransitionException`, since reversal is not itself a
 * `payroll_runs.status` transition (a posted run stays `posted`
 * forever; reversal is a derived read over `payroll_run_postings`,
 * ADR 0032 "Run kinds, correction model, and posting").
 */
class PayrollRunNotPostedException extends PayrollException
{
    public function __construct(public readonly string $runId, public readonly string $actualStatus)
    {
        parent::__construct(422, 'PAYROLL_RUN_NOT_POSTED', "Payroll run {$runId} is {$actualStatus} -- it has not been posted, so it cannot be reversed.");
    }
}
