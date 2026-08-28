<?php

namespace App\Domain\Hostel\Application\Exceptions;

/**
 * Surfaced when the database's own partial unique indexes
 * (`hostel_residency_assignments_one_active_per_bed`/
 * `_one_active_per_student`) reject a genuinely concurrent assignment
 * attempt that lost the race -- mirrors
 * App\Domain\Visitor\Application\Exceptions\ConcurrentCheckInConflictException
 * exactly. See
 * tests/Feature/Hostel/HostelResidencyConcurrencyTest.php for the real
 * two-process proof this exists to make possible.
 */
class ConcurrentResidencyConflictException extends HostelException
{
    public function __construct()
    {
        parent::__construct(
            409,
            'HOSTEL_RESIDENCY_CONFLICT',
            'This Bed or Student was assigned a Hostel residency by someone else concurrently. Refresh and try again.',
        );
    }
}
