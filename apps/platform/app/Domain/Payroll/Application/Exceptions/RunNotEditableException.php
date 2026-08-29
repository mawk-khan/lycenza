<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * Thrown when `PayrollRunService::calculate()` (or any other
 * pre-approval mutation) targets a run whose status is `approved` or
 * `posted` -- `approved` is the sole immutability boundary (ADR 0032);
 * there is no separate `finalized` state, and no recalculation path
 * exists once it is reached. The Application-layer clean error ahead
 * of the database trigger `trg_payroll_run_results_freeze`/
 * `trg_payroll_run_result_lines_freeze`.
 */
class RunNotEditableException extends PayrollException
{
    public function __construct(public readonly string $runId, public readonly string $actualStatus)
    {
        parent::__construct(422, 'PAYROLL_RUN_NOT_EDITABLE', "Payroll run {$runId} is {$actualStatus} -- it can no longer be calculated.");
    }
}
