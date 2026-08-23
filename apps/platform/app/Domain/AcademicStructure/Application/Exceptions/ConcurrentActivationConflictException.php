<?php

namespace App\Domain\AcademicStructure\Application\Exceptions;

/**
 * Phase 0D section 16/80: surfaced when the database's own partial
 * unique index (`academic_years_one_active_per_school`) rejects a
 * concurrent activation that lost the race -- this is the loser's
 * transaction being told "someone else's activation committed first,"
 * never a silent double-active state.
 */
class ConcurrentActivationConflictException extends AcademicStructureException
{
    public function __construct()
    {
        parent::__construct(
            409,
            'ACADEMIC_YEAR_ACTIVATION_CONFLICT',
            'Another Academic Year was activated for this School concurrently. Refresh and try again.',
        );
    }
}
