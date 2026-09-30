<?php

namespace App\Domain\CurriculumDelivery\Application\Exceptions;

/**
 * TCH.3 (ADR 0063 section 11): an acting teacher asked to write a date
 * their TeachingAssignment for this class does not cover -- starting or
 * completing outside their period, or changing a date recorded outside it.
 * Raised only for a class and delivery the teacher can already see, so it
 * discloses nothing they could not read.
 */
class DeliveryOutsideTeachingAssignmentException extends CurriculumDeliveryException
{
    public function __construct()
    {
        parent::__construct(422, 'CURRICULUM_DELIVERY_OUTSIDE_TEACHING_ASSIGNMENT', 'This date is outside your Teaching Assignment for this class.');
    }
}
