<?php

namespace App\Domain\Students\Application\Bulk;

use App\Domain\Students\Application\Exceptions\StudentException;

/**
 * SIS 11.13: the confirmed batch could not run as reviewed -- a Student
 * changed, a blocker appeared, a roll number was taken, a lock conflicted --
 * so the WHOLE batch was rolled back: no Student was moved. Carries a fresh
 * preview of the same selection, so the operator sees exactly what changed.
 */
final class BulkPlacementNotExecutedException extends StudentException
{
    /** @param  array<string, mixed>|null  $preview  BulkStudentPlacementService::preview() of the same request, when it could be built */
    public function __construct(string $message, public readonly ?array $preview)
    {
        parent::__construct(409, 'BULK_PLACEMENT_NOT_EXECUTED', $message);
    }
}
