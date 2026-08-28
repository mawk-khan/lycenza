<?php

namespace App\Domain\Canteen\Application\Exceptions;

class CanteenOrderNotFoundException extends CanteenException
{
    public function __construct(public readonly string $orderId)
    {
        parent::__construct(404, 'CANTEEN_ORDER_NOT_FOUND', "No Canteen order with id '{$orderId}' was found in this School.");
    }
}
