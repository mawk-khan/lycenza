<?php

namespace App\Domain\Transport\Application\Exceptions;

/**
 * Surfaced when the database's own partial unique index
 * (`transport_student_assignments_one_active_per_student`) rejects a
 * genuinely concurrent assignment attempt that lost the race -- mirrors
 * App\Domain\Library\Application\Exceptions\ConcurrentCheckoutConflictException
 * exactly. See tests/Feature/Transport/TransportStudentAssignmentConcurrencyTest.php
 * for the real two-process proof this exists to make possible.
 */
class ConcurrentStudentAssignmentConflictException extends TransportException
{
    public function __construct()
    {
        parent::__construct(
            409,
            'TRANSPORT_STUDENT_ASSIGNMENT_CONFLICT',
            'This Student was assigned to Transport by someone else concurrently. Refresh and try again.',
        );
    }
}
