<?php

namespace App\Domain\Payments\Application\Exceptions;

/** FEE.4: the Payment has no receipt yet (settled before FEE.4 and not backfilled), or does not exist here. */
class PaymentReceiptNotFoundException extends PaymentsException
{
    public function __construct(string $paymentId)
    {
        parent::__construct(404, 'PAYMENT_RECEIPT_NOT_FOUND', "No receipt was found for payment '{$paymentId}'.");
    }
}
