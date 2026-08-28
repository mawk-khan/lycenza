<?php

namespace App\Domain\Visitor\Application\Exceptions;

/**
 * Surfaced when the database's own partial unique index
 * (`visitor_visits_one_active_per_visitor`) rejects a genuinely
 * concurrent check-in attempt that lost the race -- mirrors
 * App\Domain\Transport\Application\Exceptions\ConcurrentStudentAssignmentConflictException
 * exactly. See
 * tests/Feature/Visitor/VisitorVisitCheckInConcurrencyTest.php for the
 * real two-process proof this exists to make possible.
 */
class ConcurrentCheckInConflictException extends VisitorException
{
    public function __construct()
    {
        parent::__construct(
            409,
            'VISITOR_CHECK_IN_CONFLICT',
            'This Visitor was checked in by someone else concurrently. Refresh and try again.',
        );
    }
}
