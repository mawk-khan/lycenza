<?php

namespace App\Domain\CurriculumDelivery\Application\Exceptions;

/**
 * Completing a delivery requires the date it was completed. The
 * database's `curriculum_deliveries_completion_check` biconditional is
 * the authoritative guarantee that a completed row always carries a
 * `completed_on`; this returns the ordinary case as a clean 422.
 */
class CompletionDateRequiredException extends CurriculumDeliveryException
{
    public function __construct()
    {
        parent::__construct(422, 'CURRICULUM_DELIVERY_COMPLETION_DATE_REQUIRED', 'A completion date is required when completing a delivery.');
    }
}
