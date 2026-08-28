<?php

namespace App\Domain\Canteen\Application\Exceptions;

/**
 * Distinct from CanteenItemNotAvailableException (this Item exists but
 * is inactive) -- mirrors Inventory's
 * InventoryItemNotFoundException/ItemNotAvailableException split
 * exactly.
 */
class CanteenItemNotFoundException extends CanteenException
{
    public function __construct(public readonly string $canteenItemId)
    {
        parent::__construct(404, 'CANTEEN_ITEM_NOT_FOUND', "No Canteen Item with id '{$canteenItemId}' was found in this School.");
    }
}
