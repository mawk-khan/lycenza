<?php

namespace App\Domain\Payments\Application\Exceptions;

/**
 * UAT-C3: the correction form's key already recorded a DIFFERENT correction
 * (other content or another User) -- fail closed, never replay someone
 * else's result and never record a second correction.
 */
class PaymentCorrectionIdempotencyConflictException extends PaymentsException
{
    public function __construct()
    {
        parent::__construct(409, 'PAYMENT_CORRECTION_IDEMPOTENCY_CONFLICT', 'This correction form was already used for a different correction. Reload the payment and try again.');
    }
}
