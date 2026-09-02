<?php

namespace App\Domain\CurriculumDelivery\Application\Exceptions;

/**
 * An in-progress delivery has no completion date, so a correction may
 * not set one -- completing is a state TRANSITION, never a side effect
 * of editing a date field. The database's
 * `curriculum_deliveries_completion_check` biconditional would reject
 * the row anyway; this returns the ordinary case as a clean 422 and
 * keeps PATCH incapable of changing state (which is why PATCH never
 * accepts `status` either).
 */
class CompletionDateNotAllowedException extends CurriculumDeliveryException
{
    public function __construct()
    {
        parent::__construct(422, 'CURRICULUM_DELIVERY_COMPLETION_DATE_NOT_ALLOWED', 'An in-progress delivery has no completion date; complete it through the transition operation instead.');
    }
}
