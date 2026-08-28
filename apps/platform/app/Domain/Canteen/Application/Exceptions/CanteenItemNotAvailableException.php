<?php

namespace App\Domain\Canteen\Application\Exceptions;

class CanteenItemNotAvailableException extends CanteenException
{
    public function __construct()
    {
        parent::__construct(422, 'CANTEEN_ITEM_NOT_AVAILABLE', 'One or more ordered Canteen Items are inactive.');
    }
}
