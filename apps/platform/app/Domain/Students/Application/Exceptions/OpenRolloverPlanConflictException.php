<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * Translates the database's partial unique index
 * (`enrollment_rollover_plans_one_open_per_year_pair`, Phase 1B.7A)
 * rejecting a second OPEN (draft/validated/executing) plan for the
 * identical (School, source year, target year) pair into a predictable
 * domain error -- the database index remains the authoritative
 * concurrency guarantee (this is a translation, not a replacement, of
 * that constraint). A completed/cancelled plan for the same year pair
 * never triggers this -- history is retained, and a School may
 * legitimately re-attempt a cancelled plan's year pair.
 */
class OpenRolloverPlanConflictException extends StudentException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'OPEN_ROLLOVER_PLAN_CONFLICT',
            'An open rollover plan already exists for this Academic Year pair.',
        );
    }
}
