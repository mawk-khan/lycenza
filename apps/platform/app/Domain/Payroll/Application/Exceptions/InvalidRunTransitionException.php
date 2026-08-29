<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * Thrown when `PayrollRunService::approve()` targets a run that is not
 * `calculated` -- including the same-row race a stale in-memory model
 * can lose (someone else already approved/reversed this exact run
 * between read and write), mirroring
 * `InvalidStructureTransitionException`'s identical role for structure
 * activation. The database trigger `trg_payroll_runs_validate_transition`
 * (Checkpoint 9.1) remains the authoritative guarantee against any
 * invalid transition, including ones this class never anticipates.
 */
class InvalidRunTransitionException extends PayrollException
{
    public function __construct(string $actualStatus, string $attemptedStatus)
    {
        parent::__construct(422, 'PAYROLL_INVALID_RUN_TRANSITION', "Cannot transition a payroll run from {$actualStatus} to {$attemptedStatus}.");
    }
}
