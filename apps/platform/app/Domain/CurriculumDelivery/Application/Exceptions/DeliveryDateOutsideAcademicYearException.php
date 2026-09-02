<?php

namespace App\Domain\CurriculumDelivery\Application\Exceptions;

/**
 * A delivery date must fall inside its AcademicYear's inclusive
 * [starts_on, ends_on]. A Section cannot have covered a unit before
 * its own academic year began or after it ended.
 */
class DeliveryDateOutsideAcademicYearException extends CurriculumDeliveryException
{
    public function __construct(public readonly string $field, public readonly string $date)
    {
        parent::__construct(422, 'CURRICULUM_DELIVERY_DATE_OUTSIDE_ACADEMIC_YEAR', "The {$field} date {$date} falls outside this AcademicYear.");
    }
}
