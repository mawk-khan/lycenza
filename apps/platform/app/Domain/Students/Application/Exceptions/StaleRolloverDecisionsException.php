<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * UAT-C2 (UAT-YR-01b): a bulk decision names the plan configuration
 * version the operator was looking at. If any configuration change (a
 * mapping, another Student's decision, another administrator's bulk
 * decision) was saved since, the request is refused whole and nothing is
 * written -- a newer decision is never silently overwritten, and a
 * repeated submission of the same request is refused the same way.
 */
class StaleRolloverDecisionsException extends StudentException
{
    public function __construct()
    {
        parent::__construct(
            409,
            'STALE_ROLLOVER_DECISIONS',
            'This rollover plan changed after you opened it, so nothing was changed. Reload the plan and choose again.',
        );
    }
}
