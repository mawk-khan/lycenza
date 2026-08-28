<?php

namespace App\Domain\Canteen\Application\Exceptions;

class CanteenStudentNotEligibleException extends CanteenException
{
    public function __construct()
    {
        parent::__construct(422, 'CANTEEN_STUDENT_NOT_ELIGIBLE', 'This Student is not active and cannot place a Canteen order.');
    }
}
