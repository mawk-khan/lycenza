<?php

namespace App\Domain\Canteen\Application\Exceptions;

/**
 * A fulfilled Order is a terminal state -- it can never subsequently be
 * cancelled, whether fulfillment was observed sequentially or won a
 * genuine concurrent cancel-vs-fulfill race.
 */
class CanteenOrderFulfilledCannotBeCancelledException extends CanteenException
{
    public function __construct(string $orderId)
    {
        parent::__construct(409, 'CANTEEN_ORDER_FULFILLED_CANNOT_BE_CANCELLED', "Canteen order '{$orderId}' has already been fulfilled and cannot be cancelled.");
    }
}
