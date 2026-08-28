<?php

namespace App\Domain\Inventory\Application\Exceptions;

class InsufficientStockException extends InventoryException
{
    public function __construct()
    {
        parent::__construct(422, 'INVENTORY_INSUFFICIENT_STOCK', 'There is not enough stock on hand at this Location to complete this operation.');
    }
}
