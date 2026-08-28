<?php

namespace App\Domain\Inventory\Application\Exceptions;

class LocationNotAvailableException extends InventoryException
{
    public function __construct()
    {
        parent::__construct(422, 'INVENTORY_LOCATION_NOT_AVAILABLE', 'This Inventory Location is not available for a stock mutation (it is inactive).');
    }
}
