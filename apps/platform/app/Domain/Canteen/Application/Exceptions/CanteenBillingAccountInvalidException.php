<?php

namespace App\Domain\Canteen\Application\Exceptions;

/**
 * The configured receivable/revenue LedgerAccount no longer satisfies
 * fulfillment's requirements -- inactive, wrong `type`
 * (receivable must be `asset`, revenue must be `income`), or the two
 * accounts are no longer distinct. Raised at fulfillment time, never
 * at configuration-save time alone (an account's status can change
 * after the billing configuration was saved).
 */
class CanteenBillingAccountInvalidException extends CanteenException
{
    public function __construct(string $reason)
    {
        parent::__construct(422, 'CANTEEN_BILLING_ACCOUNT_INVALID', "Canteen billing configuration is invalid: {$reason}");
    }
}
