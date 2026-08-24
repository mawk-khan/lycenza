<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * Phase 1B.7D: thrown by EnrollmentRolloverExecutionService::start()
 * when the Plan's row -- read under `lockForUpdate()`, the same
 * concurrency-serializing pattern `StudentEnrollmentService`'s own
 * lifecycle transitions use -- already shows `status = 'executing'`.
 * The row lock alone is what makes two concurrent `start()` calls for
 * the SAME Plan resolve deterministically: whichever transaction's
 * `SELECT ... FOR UPDATE` is blocked sees the winner's committed
 * 'executing' status the moment it unblocks, and throws this instead
 * of silently becoming a second active processor
 * (docs/modules/STUDENT-ENROLLMENT.md, "Double-start prevention").
 * `resume()` is the sanctioned way to continue an already-executing
 * Plan -- `start()` never silently behaves like `resume()`.
 */
class RolloverPlanAlreadyExecutingException extends StudentException
{
    public function __construct()
    {
        parent::__construct(
            409,
            'ROLLOVER_PLAN_ALREADY_EXECUTING',
            'This rollover plan is already executing. Use resume(), not start(), to continue it.',
        );
    }
}
