<?php

namespace App\Domain\CurriculumDelivery\Application\Exceptions;

/**
 * Delivery is a record of what HAS happened, so no delivery date may be
 * in the future. Evaluated against the SCHOOL-LOCAL date
 * (`App\Support\Tenancy\SchoolTimezone`), not a UTC instant: "today" for
 * a School in Asia/Kolkata is not the same calendar day as UTC "today"
 * for several hours each day, and the user is entering a school
 * calendar date. Today itself is always allowed.
 */
class DeliveryDateInFutureException extends CurriculumDeliveryException
{
    public function __construct(public readonly string $field, public readonly string $date)
    {
        parent::__construct(422, 'CURRICULUM_DELIVERY_DATE_IN_FUTURE', "The {$field} date {$date} is in the future in this School's timezone.");
    }
}
