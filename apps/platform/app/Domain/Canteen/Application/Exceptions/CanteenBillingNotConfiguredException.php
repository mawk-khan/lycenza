<?php

namespace App\Domain\Canteen\Application\Exceptions;

class CanteenBillingNotConfiguredException extends CanteenException
{
    public function __construct()
    {
        parent::__construct(422, 'CANTEEN_BILLING_NOT_CONFIGURED', 'This School has not configured Canteen billing accounts yet -- fulfillment cannot post a Charge.');
    }
}
