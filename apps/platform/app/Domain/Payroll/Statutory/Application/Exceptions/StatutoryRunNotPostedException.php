<?php

namespace App\Domain\Payroll\Statutory\Application\Exceptions;

/**
 * Checkpoint 9.6F -- the statutory journal entry is posted alongside
 * (never instead of) the main payroll posting, and depends on it
 * (the statutory entry's employee-withholding debit leg uses the SAME
 * salary-payable ledger account the main posting already validated) --
 * so a run's main posting must exist first.
 */
class StatutoryRunNotPostedException extends StatutoryPayrollException
{
    public function __construct(string $runId, string $actualStatus)
    {
        parent::__construct(422, 'STATUTORY_RUN_NOT_POSTED', "Payroll run '{$runId}' is '{$actualStatus}' -- the run must be posted (main payroll journal entry) before statutory posting.");
    }
}
