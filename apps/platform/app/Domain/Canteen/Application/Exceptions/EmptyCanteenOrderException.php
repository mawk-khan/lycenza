<?php

namespace App\Domain\Canteen\Application\Exceptions;

class EmptyCanteenOrderException extends CanteenException
{
    public function __construct()
    {
        parent::__construct(422, 'CANTEEN_EMPTY_ORDER', 'A Canteen order must contain at least one line.');
    }
}
