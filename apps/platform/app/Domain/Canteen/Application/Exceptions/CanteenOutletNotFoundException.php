<?php

namespace App\Domain\Canteen\Application\Exceptions;

/**
 * Distinct from CanteenOutletNotAvailableException (this Outlet exists
 * but is inactive) -- mirrors Inventory's
 * InventoryItemNotFoundException/ItemNotAvailableException split
 * exactly.
 */
class CanteenOutletNotFoundException extends CanteenException
{
    public function __construct(public readonly string $outletId)
    {
        parent::__construct(404, 'CANTEEN_OUTLET_NOT_FOUND', "No Canteen Outlet with id '{$outletId}' was found in this School.");
    }
}
