<?php

namespace App\Domain\Canteen\Application\Exceptions;

/**
 * At most one fulfillment per Order -- raised both by
 * CanteenOrderService::fulfill()'s own pre-check (the common,
 * sequential case) and when the loser of a genuine concurrent
 * fulfillment race observes, under its own row lock, that the Order is
 * no longer pending. Mirrors ChargeAlreadyCancelledException's exact
 * "no distinction between who found out first" reasoning.
 */
class CanteenOrderAlreadyFulfilledException extends CanteenException
{
    public function __construct(string $orderId)
    {
        parent::__construct(409, 'CANTEEN_ORDER_ALREADY_FULFILLED', "Canteen order '{$orderId}' has already been fulfilled.");
    }
}
