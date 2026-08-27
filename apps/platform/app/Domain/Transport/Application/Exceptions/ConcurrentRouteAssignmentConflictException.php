<?php

namespace App\Domain\Transport\Application\Exceptions;

/**
 * Surfaced when the database's own partial unique index
 * (`transport_route_assignments_one_active_per_route`) rejects a
 * genuinely concurrent assignment attempt that lost the race -- mirrors
 * App\Domain\AcademicStructure\Application\Exceptions\ConcurrentActivationConflictException
 * exactly.
 */
class ConcurrentRouteAssignmentConflictException extends TransportException
{
    public function __construct()
    {
        parent::__construct(
            409,
            'TRANSPORT_ROUTE_ASSIGNMENT_CONFLICT',
            'This Route\'s operational assignment was changed by someone else concurrently. Refresh and try again.',
        );
    }
}
