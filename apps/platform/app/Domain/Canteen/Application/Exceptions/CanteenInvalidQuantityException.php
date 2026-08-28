<?php

namespace App\Domain\Canteen\Application\Exceptions;

class CanteenInvalidQuantityException extends CanteenException
{
    public function __construct()
    {
        parent::__construct(422, 'CANTEEN_INVALID_QUANTITY', 'Every Canteen order line quantity must be a positive integer.');
    }
}
