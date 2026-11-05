<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * OD-HR-1 (owner decision 2026-11-05): the checker reviewed a calculation
 * that is no longer the run's latest (it was recalculated, or the review was
 * opened on another calculation). Approval binds to the exact evidence
 * reviewed, so the decision is refused: open the review again.
 */
class StaleRunReviewException extends PayrollException
{
    public function __construct(public readonly string $runId)
    {
        parent::__construct(409, 'PAYROLL_REVIEW_STALE', 'This payroll run changed after you reviewed it. Open the review again before approving.');
    }
}
