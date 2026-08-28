<?php

namespace App\Domain\Inventory\Application\Exceptions;

class ItemNotAvailableException extends InventoryException
{
    public function __construct()
    {
        parent::__construct(422, 'INVENTORY_ITEM_NOT_AVAILABLE', 'This Inventory Item is not available for a stock mutation (it is inactive).');
    }
}
