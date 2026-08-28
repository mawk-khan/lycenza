<?php

namespace App\Domain\Inventory\Application\Exceptions;

class SameLocationTransferException extends InventoryException
{
    public function __construct()
    {
        parent::__construct(422, 'INVENTORY_SAME_LOCATION_TRANSFER', 'A transfer requires two different Locations.');
    }
}
