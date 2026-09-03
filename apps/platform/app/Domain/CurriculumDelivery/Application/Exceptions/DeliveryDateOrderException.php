<?php

namespace App\Domain\CurriculumDelivery\Application\Exceptions;

/**
 * A unit cannot finish before it started. The database's own
 * `curriculum_deliveries_date_order_check` is the authoritative
 * guarantee; this returns the ordinary case as a clean 422.
 */
class DeliveryDateOrderException extends CurriculumDeliveryException
{
    public function __construct(public readonly string $startedOn, public readonly string $completedOn)
    {
        parent::__construct(422, 'CURRICULUM_DELIVERY_DATE_ORDER', "The completion date {$completedOn} precedes the start date {$startedOn}.");
    }
}
