<?php

namespace App\Domain\Payments\Application\Exceptions;

/**
 * ORG.7G-2 (ADR 0076 §51): the ONE answer a Campus-scoped payment recorder
 * gets when a payment cannot be recorded (or replayed) from their Campus
 * scope -- a Charge outside it, of another Campus, whole-School (NULL
 * Campus), unknown or on an inactive Campus, a mix of Campuses, or a stored
 * replay no longer within scope. It never names which Charge or why.
 */
class CampusPaymentUnavailableException extends PaymentsException
{
    public function __construct()
    {
        parent::__construct(422, 'PAYMENT_UNAVAILABLE', 'This payment cannot be recorded from your campus. Every charge it pays must belong to one campus you collect for.');
    }
}
