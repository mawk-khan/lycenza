<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * Phase 1B.7C: once an EnrollmentRolloverItem has produced (or
 * reconciled) a real target Enrollment -- `target_enrollment_id` is no
 * longer null -- its configuration is frozen.
 * `EnrollmentRolloverPlanService::setItemDecision()` refuses to
 * silently re-point an already-executed Item at a different decision/
 * target Section/Roll Number, which would otherwise corrupt
 * `target_enrollment_id`'s provenance guarantee (this checkpoint's
 * brief, section 61) the moment the Item were re-executed. This is the
 * primary, fail-fast defense; EnrollmentRolloverItemExecutionService's
 * own idempotent-replay integrity check is the second, structural line
 * of defense for any path that bypasses this guard.
 */
class RolloverItemAlreadyExecutedException extends StudentException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'ROLLOVER_ITEM_ALREADY_EXECUTED',
            'This rollover Item has already produced a target Enrollment and can no longer be reconfigured.',
        );
    }
}
