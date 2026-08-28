<?php

namespace App\Domain\Canteen\Application\Exceptions;

class CanteenOutletNotAvailableException extends CanteenException
{
    public function __construct()
    {
        parent::__construct(422, 'CANTEEN_OUTLET_NOT_AVAILABLE', 'This Canteen Outlet is inactive and cannot accept orders.');
    }
}
