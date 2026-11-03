<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * HR.1A: a named placement change (transfer, promotion, designation or
 * department change) was refused before anything was written: its kind does
 * not match what changes, nothing changes, the date falls outside the
 * employment, or the target placement is not valid. `$reason` is a closed
 * code.
 */
class InvalidPlacementChangeException extends HrException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct(422, 'HR_INVALID_PLACEMENT_CHANGE', $message);
    }
}
