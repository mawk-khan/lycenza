<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * Translates the database's own CHECK constraint
 * (`enrollment_rollover_plans_source_target_differ_check`, Phase
 * 1B.7A) into a predictable domain error -- the database remains the
 * authoritative guarantee (this is a translation, not a replacement,
 * of that constraint). A rollover plan whose source and target
 * Academic Year are identical is meaningless: there is no cross-year
 * move to represent.
 */
class InvalidRolloverPlanYearsException extends StudentException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'INVALID_ROLLOVER_PLAN_YEARS',
            'A rollover plan\'s source and target Academic Year must be different.',
        );
    }
}
