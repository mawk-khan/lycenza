<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * Phase 9.11 -- thrown when `PayrollPeriodService::open()`/`close()`
 * targets a period that is not in the expected prior state (`draft`
 * for open, `open` for close) -- including the same-row race a stale
 * in-memory model can lose, mirroring `InvalidRunTransitionException`'s
 * identical role for run approval/posting.
 */
class InvalidPeriodTransitionException extends PayrollException
{
    public function __construct(string $actualStatus, string $attemptedStatus)
    {
        parent::__construct(422, 'PAYROLL_INVALID_PERIOD_TRANSITION', "Cannot transition a payroll period from {$actualStatus} to {$attemptedStatus}.");
    }
}
