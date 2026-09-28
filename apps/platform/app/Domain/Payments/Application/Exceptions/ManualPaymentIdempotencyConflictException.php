<?php

namespace App\Domain\Payments\Application\Exceptions;

/**
 * Phase 0O.11A (ADR 0031 implementation amendment section 6): the
 * request's idempotency key already recorded a DIFFERENT manual payment
 * (other content, or another User) in this School -- fail closed, never
 * replay someone else's result and never record a second payment.
 */
class ManualPaymentIdempotencyConflictException extends PaymentsException
{
    public function __construct()
    {
        parent::__construct(409, 'MANUAL_PAYMENT_IDEMPOTENCY_CONFLICT', 'This payment form was already used to record a different payment. Reload the form and try again.');
    }
}
