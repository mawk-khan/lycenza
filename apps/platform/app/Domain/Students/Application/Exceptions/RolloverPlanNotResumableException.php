<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * Phase 1B.7D: thrown by EnrollmentRolloverExecutionService::resume()
 * when the Plan's current status is not `executing` -- there is
 * nothing to resume for a Plan that was never started (`draft`/
 * `validated`), already finished (`completed`/`completed_with_errors`),
 * or `cancelled`. Kept distinct from RolloverPlanNotExecutionReadyException
 * (thrown by `start()`/the per-Item primitive for "not currently
 * validated") so a caller always gets a message describing the
 * operation it actually attempted.
 */
class RolloverPlanNotResumableException extends StudentException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'ROLLOVER_PLAN_NOT_RESUMABLE',
            'This rollover plan is not currently executing -- there is nothing to resume.',
        );
    }
}
