<?php

namespace App\Domain\Canteen\Application\Exceptions;

/**
 * At most one cancellation per Order -- raised both by
 * CanteenOrderService::cancel()'s own pre-check and when the loser of
 * a genuine concurrent cancel race observes, under its own row lock,
 * that the Order is no longer pending.
 */
class CanteenOrderAlreadyCancelledException extends CanteenException
{
    public function __construct(string $orderId)
    {
        parent::__construct(409, 'CANTEEN_ORDER_ALREADY_CANCELLED', "Canteen order '{$orderId}' has already been cancelled.");
    }
}
