<?php

namespace App\Domain\Fees\Application\Exceptions;

/** ORG.8G (ADR 0076 §65): a Charge cancel or void names one of its listed reasons. */
class ChargeCancellationReasonException extends FeesException
{
    public function __construct(string $message = 'Choose one of the listed reasons.')
    {
        parent::__construct(422, 'CHARGE_CANCELLATION_REASON_INVALID', $message);
    }
}
