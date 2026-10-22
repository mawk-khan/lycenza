<?php

namespace App\Domain\Payments\Application\Exceptions;

/**
 * ORG.8C (ADR 0076 §61): another correction was executed on the Payment after
 * this request was made, so the request no longer describes the Payment it
 * would change. Nothing is written; request it again.
 */
class PaymentCorrectionRequestStaleException extends PaymentsException
{
    public function __construct(string $paymentId)
    {
        parent::__construct(409, 'PAYMENT_CORRECTION_REQUEST_STALE', "Payment '{$paymentId}' changed after this correction was requested. Request it again.");
    }
}
