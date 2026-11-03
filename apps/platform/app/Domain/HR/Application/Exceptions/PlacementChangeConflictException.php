<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * HR.1A: the source assignment is no longer the primary placement covering
 * the effective date (another change committed first, or it was never the
 * primary there), another primary assignment already covers part of the
 * period, or a future-dated change would leave direct reports without a
 * manager before effective-dated reporting lines exist (HR.3). Nothing was
 * written.
 */
class PlacementChangeConflictException extends HrException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct(409, 'HR_PLACEMENT_CHANGE_CONFLICT', $message);
    }
}
