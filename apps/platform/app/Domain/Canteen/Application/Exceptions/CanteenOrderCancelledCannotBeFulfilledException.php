<?php

namespace App\Domain\Canteen\Application\Exceptions;

/**
 * A cancelled Order is a terminal state -- it can never subsequently
 * be fulfilled, whether the cancellation was observed sequentially or
 * won a genuine concurrent cancel-vs-fulfill race.
 */
class CanteenOrderCancelledCannotBeFulfilledException extends CanteenException
{
    public function __construct(string $orderId)
    {
        parent::__construct(409, 'CANTEEN_ORDER_CANCELLED_CANNOT_BE_FULFILLED', "Canteen order '{$orderId}' has been cancelled and cannot be fulfilled.");
    }
}
