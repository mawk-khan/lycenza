<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * `payroll_run_postings_one_reversal_per_posting` (a partial unique
 * index, Checkpoint 9.1): at most one reversal per original posting.
 * Raised both by `PayrollPostingService::reverse()`'s own sequential
 * pre-check (the common, non-racing case) and by its translation of
 * `App\Domain\Finance\Application\Exceptions\JournalEntryAlreadyReversedException`
 * (the genuine concurrent-race case, caught from
 * `LedgerService::reverseById()`) -- both paths raise this SAME
 * exception, mirroring
 * `App\Domain\Fees\Application\ChargeService::cancel()`'s identical
 * translation of the same underlying Finance exception into its own
 * `ChargeAlreadyCancelledException`. A caller of `PayrollPostingService`
 * never needs to know about `App\Domain\Finance`'s exception types.
 */
class PayrollRunAlreadyReversedException extends PayrollException
{
    public function __construct(public readonly string $runId)
    {
        parent::__construct(409, 'PAYROLL_RUN_ALREADY_REVERSED', "Payroll run '{$runId}' has already been reversed.");
    }
}
