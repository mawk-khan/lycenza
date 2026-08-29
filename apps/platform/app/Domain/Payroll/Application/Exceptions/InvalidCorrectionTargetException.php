<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * ADR 0032 "Run kinds, correction model, and posting": a correction
 * run's `corrects_payroll_run_id` must reference a `regular`, already-
 * `posted` run -- never a draft/calculated/approved run, never another
 * correction (no correction-of-correction chains). Raised by
 * `PayrollRunService::createCorrectionRun()`'s Application-layer
 * pre-check, ahead of the database trigger
 * `trg_payroll_runs_validate_correction_target` (Checkpoint 9.1) that
 * remains the authoritative guarantee against any invalid target,
 * including one this class never anticipates.
 */
class InvalidCorrectionTargetException extends PayrollException
{
    public function __construct(string $reason)
    {
        parent::__construct(422, 'PAYROLL_INVALID_CORRECTION_TARGET', "Cannot create a correction run: {$reason}");
    }
}
