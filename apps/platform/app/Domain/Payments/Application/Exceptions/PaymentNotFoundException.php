<?php

namespace App\Domain\Payments\Application\Exceptions;

/**
 * Uniform not-found error for both "does not exist" and "exists in
 * another School" -- no cross-School existence oracle, mirroring
 * App\Domain\Fees\Application\Exceptions\ChargeNotFoundException's exact
 * precedent.
 */
class PaymentNotFoundException extends PaymentsException
{
    public function __construct(string $paymentId)
    {
        parent::__construct(404, 'PAYMENT_NOT_FOUND', "Payment '{$paymentId}' was not found.");
    }
}
