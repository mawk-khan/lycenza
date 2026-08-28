<?php

namespace App\Domain\Payments\Application\Exceptions;

/**
 * Input-shape validation for `RecordSettlementData` -- zero/negative
 * amounts, non-INR currency, an empty/duplicate-charge allocation set.
 * Mirrors `App\Domain\Fees\Application\Exceptions\InvalidChargeException`'s
 * exact role for `ChargeService::assess()`.
 */
class InvalidSettlementDataException extends PaymentsException
{
    public function __construct(string $message)
    {
        parent::__construct(422, 'INVALID_SETTLEMENT_DATA', $message);
    }
}
